<?php
namespace Tests\Unit\Services\Oidc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Services\Oidc\HttpsResponse;
use Services\Oidc\OidcConfig;
use Services\Oidc\ProviderCheck;

final class ProviderCheckTest extends TestCase
{
    private const ISSUER = 'https://idp.example.com/application/o/dfir-fm/';
    private const DISCOVERY = 'https://idp.example.com/application/o/dfir-fm/.well-known/openid-configuration';
    private const AUTHORIZE = 'https://idp.example.com/authorize/';
    private const TOKEN = 'https://idp.example.com/token/';
    private const JWKS = 'https://idp.example.com/jwks/';
    private const USERINFO = 'https://idp.example.com/userinfo/';

    /** A real RSA public key: firebase/php-jwt parses the set */
    private static array $jwk;

    private StubHttpsClient $http;

    public static function setUpBeforeClass(): void
    {
        $rsa = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]))['rsa'];
        $b64 = fn(string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        self::$jwk = ['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])];
    }

    protected function setUp(): void
    {
        $this->http = new StubHttpsClient();
        $this->http->answers[self::DISCOVERY] = new HttpsResponse(200, json_encode(self::metadata()));
        $this->http->answers[self::JWKS] = new HttpsResponse(200, json_encode(['keys' => [self::$jwk]]));
        $this->http->answers[self::AUTHORIZE] = new HttpsResponse(302, '', 'https://idp.example.com/flows/login/');
        $this->http->answers[self::TOKEN] = new HttpsResponse(400, '{"error":"invalid_grant"}');
    }

    public function testAllChecksPass(): void
    {
        $check = $this->check();
        $results = $check->run();
        $this->assertTrue($check->passed(), print_r($results, true));
        $this->assertSame(['Discovery document', 'authorization_endpoint', 'token_endpoint', 'jwks_uri', 'response_types_supported',
            'code_challenge_methods_supported', 'token_endpoint_auth_methods_supported', 'id_token_signing_alg_values_supported',
            'userinfo_endpoint', 'end_session_endpoint', 'JWKS', 'Authorization request', 'Client credentials'], array_column($results, 1));

        // The credential probe: a random code, our redirect URI and Basic credentials
        $probe = end($this->http->calls);
        $this->assertSame(self::TOKEN, $probe['url']);
        $this->assertSame(['dfir-fm', 'S3cr3t-value'], $probe['basicAuth']);
        $this->assertSame('authorization_code', $probe['fields']['grant_type']);
        $this->assertSame(OidcConfigTest::COMPLETE['oidc_redirect_uri'], $probe['fields']['redirect_uri']);
    }

    public static function discoveryFailures(): array
    {
        return [
            'issuer without its trailing slash' => [['issuer' => rtrim(self::ISSUER, '/')], 'Discovery issuer'],
            'no S256' => [['code_challenge_methods_supported' => ['plain']], 'code_challenge_methods_supported'],
            'no PKCE advertised' => [['code_challenge_methods_supported' => null], 'code_challenge_methods_supported'],
            'no code flow' => [['response_types_supported' => ['id_token']], 'response_types_supported'],
            'only client_secret_post' => [['token_endpoint_auth_methods_supported' => ['client_secret_post']], 'token_endpoint_auth_methods_supported'],
            'HS256 only' => [['id_token_signing_alg_values_supported' => ['HS256']], 'id_token_signing_alg_values_supported'],
            'http token endpoint' => [['token_endpoint' => 'http://idp.example.com/token/'], 'token_endpoint'],
        ];
    }

    #[DataProvider('discoveryFailures')]
    public function testDiscoveryFailures(array $change, string $failed): void
    {
        $this->http->answers[self::DISCOVERY] = new HttpsResponse(200, json_encode(array_filter($change + self::metadata(), fn($v) => $v !== null)));
        $check = $this->check();
        $results = $check->run();
        $this->assertFalse($check->passed());
        $this->assertContains($failed, array_column(array_filter($results, fn($r) => $r[0] === ProviderCheck::FAIL), 1));
    }

    public function testAuthMethodsNotAdvertisedIsTheDefault(): void
    {
        $metadata = self::metadata();
        unset($metadata['token_endpoint_auth_methods_supported']);
        $this->http->answers[self::DISCOVERY] = new HttpsResponse(200, json_encode($metadata));
        $check = $this->check();
        $check->run();
        $this->assertTrue($check->passed());
    }

    public function testUnreachableDiscoveryStopsTheRun(): void
    {
        $this->http->answers[self::DISCOVERY] = new HttpsResponse(404, 'Not Found');
        $check = $this->check();
        $this->assertSame([[ProviderCheck::FAIL, 'Discovery document', 'HTTP 404 from ' . self::DISCOVERY]], $check->run());
        $this->assertFalse($check->passed());
    }

    public static function jwksFailures(): array
    {
        return [
            'no kid' => [['keys' => [['kty' => 'RSA', 'n' => 'x', 'e' => 'AQAB']]], 'has no kid'],
            'empty' => [['keys' => []], 'empty'],
            'only an encryption key' => [['keys' => [['kid' => 'e1', 'kty' => 'RSA', 'use' => 'enc', 'alg' => 'RS256', 'n' => 'x', 'e' => 'AQAB']]], 'no signing key'],
            'only an HS256 key' => [['keys' => [['kid' => 'h1', 'kty' => 'oct', 'alg' => 'HS256', 'k' => 'c2VjcmV0']]], 'no signing key'],
        ];
    }

    #[DataProvider('jwksFailures')]
    public function testJwksFailures(array $jwks, string $message): void
    {
        $this->http->answers[self::JWKS] = new HttpsResponse(200, json_encode($jwks));
        $check = $this->check();
        $results = $check->run();
        $this->assertSame([ProviderCheck::FAIL, 'JWKS'], array_slice(end($results), 0, 2));
        $this->assertStringContainsString($message, end($results)[2]);
    }

    public static function authorizationAnswers(): array
    {
        return [
            'login page' => [new HttpsResponse(200, '<html>'), ProviderCheck::OK, 'accepted'],
            'redirect to the login flow' => [new HttpsResponse(302, '', 'https://idp.example.com/flows/login/'), ProviderCheck::OK, 'accepted'],
            'error page (unregistered redirect URI)' => [new HttpsResponse(400, '<title>Redirect URI Error</title>'), ProviderCheck::FAIL, "('Redirect URI Error'): does the IdP know oidc_client_id, with oidc_redirect_uri"],
            'error sent back to us' => [new HttpsResponse(302, '', OidcConfigTest::COMPLETE['oidc_redirect_uri'] . '?error=invalid_scope&error_description=groups'), ProviderCheck::FAIL, 'invalid_scope: groups'],
        ];
    }

    #[DataProvider('authorizationAnswers')]
    public function testAuthorizationRequest(HttpsResponse $answer, string $status, string $detail): void
    {
        $this->http->answers[self::AUTHORIZE] = $answer;
        $results = $this->check()->run();
        $row = array_values(array_filter($results, fn($r) => $r[1] === 'Authorization request'))[0];
        $this->assertSame($status, $row[0]);
        $this->assertStringContainsString($detail, $row[2]);
    }

    public static function credentialAnswers(): array
    {
        return [
            'invalid_grant: accepted' => [new HttpsResponse(400, '{"error":"invalid_grant"}'), ProviderCheck::OK, 'accepted'],
            'invalid_client' => [new HttpsResponse(400, '{"error":"invalid_client"}'), ProviderCheck::FAIL, 'invalid_client): check oidc_client_id'],
            '401 without a body' => [new HttpsResponse(401, ''), ProviderCheck::FAIL, 'refused (HTTP 401)'],
            'anything else' => [new HttpsResponse(500, "<html>\x01oops"), ProviderCheck::FAIL, 'unexpected answer, HTTP 500: <html>?oops'],
        ];
    }

    #[DataProvider('credentialAnswers')]
    public function testCredentialProbe(HttpsResponse $answer, string $status, string $detail): void
    {
        $this->http->answers[self::TOKEN] = $answer;
        $results = $this->check()->run();
        $this->assertSame([$status, 'Client credentials'], array_slice(end($results), 0, 2));
        $this->assertStringContainsString($detail, end($results)[2]);
    }

    public function testAuthorizationRequestParameters(): void
    {
        $this->check(['oidc_max_age' => '600', 'oidc_scopes' => 'openid email profile groups'])->run();
        $url = array_values(array_filter($this->http->requests, fn($u) => str_starts_with($u, self::AUTHORIZE)))[0];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('dfir-fm', $query['client_id']);
        $this->assertSame(OidcConfigTest::COMPLETE['oidc_redirect_uri'], $query['redirect_uri']);
        $this->assertSame('openid email profile groups', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['code_challenge']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['state']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['nonce']);
        $this->assertSame('600', $query['max_age']);
    }

    private function check(array $config = []): ProviderCheck
    {
        return new ProviderCheck(OidcConfig::fromConfig($config + ['oidc_issuer' => self::ISSUER, 'oidc_admin_value' => 'dfir-fm-admins'] + OidcConfigTest::COMPLETE), $this->http);
    }

    private static function metadata(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::AUTHORIZE,
            'token_endpoint' => self::TOKEN,
            'jwks_uri' => self::JWKS,
            'userinfo_endpoint' => self::USERINFO,
            'response_types_supported' => ['code', 'id_token'],
            'code_challenge_methods_supported' => ['plain', 'S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ];
    }
}
