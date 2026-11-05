<?php
namespace Tests\Unit\Services\Oidc;

use PHPUnit\Framework\TestCase;
use Services\Oidc\CurlHttpsClient;
use Services\Oidc\HttpsClient;
use Services\Oidc\HttpsResponse;
use Services\Oidc\OidcConfig;
use Services\Oidc\OidcException;
use Services\Oidc\OidcProvider;

final class OidcProviderTest extends TestCase
{
    private string $issuer;
    private StubHttpsClient $http;
    private OidcProvider $provider;

    protected function setUp(): void
    {
        // A fresh issuer per test: its own APCu cache entries
        $this->issuer = 'https://idp.example.com/application/o/test-' . bin2hex(random_bytes(6)) . '/';
        $this->http = new StubHttpsClient();
        $this->http->answers[$this->discoveryUrl()] = new HttpsResponse(200, json_encode($this->metadata()));
        $this->http->answers['https://idp.example.com/jwks'] = new HttpsResponse(200, json_encode(['keys' => [['kid' => 'k1', 'kty' => 'RSA']]]));
        $this->provider = $this->provider();
    }

    protected function tearDown(): void
    {
        $this->provider->forget();
    }

    public function testDiscoveryIsCached(): void
    {
        $this->assertSame($this->issuer, $this->provider->metadata()['issuer']);
        $this->assertSame('https://idp.example.com/token', $this->provider()->metadata()['token_endpoint'], 'another instance');
        $this->assertSame([$this->discoveryUrl()], $this->http->requests, 'fetched once');
    }

    public function testDiscoveryUrlWithoutTrailingSlash(): void
    {
        $this->issuer = rtrim($this->issuer, '/');
        $this->http->answers[$this->discoveryUrl()] = new HttpsResponse(200, json_encode($this->metadata()));
        $this->assertSame($this->issuer, $this->provider()->metadata()['issuer']);
        $this->assertStringEndsWith('/.well-known/openid-configuration', $this->http->requests[0]);
        $this->assertStringNotContainsString('//.well-known', $this->http->requests[0]);
    }

    public function testDiscoveryIssuerMismatch(): void
    {
        foreach ([rtrim($this->issuer, '/'), strtoupper($this->issuer), 'https://evil.example.com/', null] as $issuer) {
            $this->http->answers[$this->discoveryUrl()] = new HttpsResponse(200, json_encode(['issuer' => $issuer] + $this->metadata()));
            try {
                $this->provider->metadata();
                $this->fail('accepted the issuer ' . var_export($issuer, true));
            } catch (OidcException $e) {
                $this->assertStringContainsString("isn't oidc_issuer", $e->getMessage());
            }
        }
        $this->assertCount(4, $this->http->requests, 'a refused document is not cached');
    }

    public function testDiscoveryRequiresHttpsEndpoints(): void
    {
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $name) {
            foreach ([null, 'http://idp.example.com/x'] as $value) {
                $metadata = $this->metadata();
                $metadata[$name] = $value;
                $this->http->answers[$this->discoveryUrl()] = new HttpsResponse(200, json_encode($metadata));
                try {
                    $this->provider->metadata();
                    $this->fail("accepted $name " . var_export($value, true));
                } catch (OidcException $e) {
                    $this->assertStringContainsString("no HTTPS $name", $e->getMessage());
                }
            }
        }
    }

    public function testDiscoveryHttpErrorsAndBadJson(): void
    {
        foreach ([new HttpsResponse(404, 'Not Found'), new HttpsResponse(200, '<html>'), new HttpsResponse(200, '[1,2]')] as $answer) {
            $this->http->answers[$this->discoveryUrl()] = $answer;
            $this->assertMetadataRefused($answer);
        }
    }

    public function testJwksIsCached(): void
    {
        $this->assertSame('k1', $this->provider->jwks()['keys'][0]['kid']);
        $this->assertSame('k1', $this->provider()->jwks('k1')['keys'][0]['kid'], 'known kid, another instance');
        $this->assertSame([$this->discoveryUrl(), 'https://idp.example.com/jwks'], $this->http->requests);
    }

    public function testUnknownKidRefetchesAtMostOncePerInterval(): void
    {
        $this->provider->jwks();
        $this->http->answers['https://idp.example.com/jwks'] = new HttpsResponse(200, json_encode(['keys' => [['kid' => 'k1'], ['kid' => 'k2']]]));

        // Just fetched: no refetch within the interval
        $this->assertCount(1, $this->provider->jwks('k2')['keys']);
        $this->assertSame(1, $this->jwksFetches());

        // The interval has passed (its marker expired)
        apcu_delete('oidc_' . sha1($this->issuer) . '_jwks_fetched');
        $this->assertCount(2, $this->provider->jwks('k2')['keys'], 'rotated key found');
        $this->assertSame(2, $this->jwksFetches());

        apcu_delete('oidc_' . sha1($this->issuer) . '_jwks_fetched');
        $this->provider->jwks('forged-1');
        $this->provider->jwks('forged-2');
        $this->provider->jwks('forged-3');
        $this->assertSame(3, $this->jwksFetches(), 'unknown kids: one refetch per interval');
        $this->assertCount(2, $this->provider->jwks()['keys'], 'cache kept');
    }

    public function testJwksWithoutKeys(): void
    {
        foreach (['{"keys": {"a": 1}}', '{"other": []}', '{"keys": ["x"]}'] as $body) {
            $this->http->answers['https://idp.example.com/jwks'] = new HttpsResponse(200, $body);
            try {
                $this->provider->jwks();
                $this->fail("accepted $body");
            } catch (OidcException $e) {
                $this->assertStringContainsString('JWKS', $e->getMessage());
            }
        }
    }

    public function testCurlClientRefusesPlainHttp(): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('non-HTTPS');
        (new CurlHttpsClient())->get('http://idp.example.com/.well-known/openid-configuration');
    }

    private function assertMetadataRefused(HttpsResponse $answer): void
    {
        try {
            $this->provider->metadata();
            $this->fail("accepted HTTP {$answer->status} {$answer->body}");
        } catch (OidcException $e) {
            $this->assertMatchesRegularExpression('/answered HTTP 404|not a JSON object/', $e->getMessage());
        }
    }

    private function jwksFetches(): int
    {
        return count(array_keys($this->http->requests, 'https://idp.example.com/jwks', true));
    }

    private function provider(): OidcProvider
    {
        return new OidcProvider(OidcConfig::fromConfig(['oidc_issuer' => $this->issuer] + OidcConfigTest::COMPLETE), $this->http);
    }

    private function discoveryUrl(): string
    {
        return rtrim($this->issuer, '/') . '/.well-known/openid-configuration';
    }

    private function metadata(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => 'https://idp.example.com/authorize',
            'token_endpoint' => 'https://idp.example.com/token',
            'jwks_uri' => 'https://idp.example.com/jwks',
        ];
    }
}

final class StubHttpsClient implements HttpsClient
{
    /** @var array<string,HttpsResponse> */
    public array $answers = [];
    /** @var list<string> */
    public array $requests = [];

    public function get(string $url): HttpsResponse
    {
        $this->requests[] = $url;
        return $this->answers[$url] ?? throw new OidcException("unreachable: $url");
    }

    public function postForm(string $url, array $fields, ?array $basicAuth = null): HttpsResponse
    {
        return $this->get($url);
    }
}
