<?php
namespace Tests\Unit\Services\Oidc;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Services\Oidc\CodeFlow;
use Services\Oidc\HttpsResponse;
use Services\Oidc\OidcConfig;
use Services\Oidc\OidcProvider;
use Services\Oidc\OidcRefusal;

final class CodeFlowTest extends TestCase
{
    private const TOKEN_URL = 'https://idp.example.com/token';
    private const USERINFO_URL = 'https://idp.example.com/userinfo';

    private static \OpenSSLAsymmetricKey $key;

    private string $issuer;
    private StubHttpsClient $http;
    private OidcProvider $provider;

    public static function setUpBeforeClass(): void
    {
        self::$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    protected function setUp(): void
    {
        // A fresh issuer per test: its own APCu cache entries
        $this->issuer = 'https://idp.example.com/application/o/f-' . bin2hex(random_bytes(6)) . '/';
        $this->http = new StubHttpsClient();
        $this->http->answers[$this->issuer . '.well-known/openid-configuration'] = new HttpsResponse(200, json_encode([
            'issuer' => $this->issuer,
            'authorization_endpoint' => 'https://idp.example.com/authorize?tenant=t1',
            'token_endpoint' => self::TOKEN_URL,
            'userinfo_endpoint' => self::USERINFO_URL,
            'jwks_uri' => 'https://idp.example.com/jwks',
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]));
        $rsa = openssl_pkey_get_details(self::$key)['rsa'];
        $this->http->answers['https://idp.example.com/jwks'] = new HttpsResponse(200, json_encode(['keys' => [
            ['kty' => 'RSA', 'kid' => 'r1', 'use' => 'sig', 'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e'])],
        ]]));
    }

    protected function tearDown(): void
    {
        $this->provider->forget();
    }

    private function flow(array $config = []): CodeFlow
    {
        $oidc = OidcConfig::fromConfig($config + ['oidc_issuer' => $this->issuer] + OidcConfigTest::COMPLETE);
        $this->provider = new OidcProvider($oidc, $this->http);
        return new CodeFlow($oidc, $this->provider);
    }

    private function idToken(array $claims = []): string
    {
        return JWT::encode($claims + [
            'iss' => $this->issuer, 'sub' => 'u1', 'aud' => 'dfir-fm', 'iat' => time(), 'exp' => time() + 300, 'nonce' => 'n1',
            'email' => 'alice@example.com', 'email_verified' => true,
        ], self::$key, 'RS256', 'r1');
    }

    private function tokenAnswer(int $status, array $body): void
    {
        $this->http->answers[self::TOKEN_URL] = new HttpsResponse($status, json_encode($body));
    }

    private const PENDING = ['state' => 's1', 'nonce' => 'n1', 'verifier' => 'v1'];

    private function assertRefused(string $cause, string $message, callable $call): void
    {
        try {
            $call();
            $this->fail("not refused ($cause)");
        } catch (OidcRefusal $e) {
            $this->assertSame($cause, $e->cause, $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testStart(): void
    {
        [$url, $pending] = $this->flow(['oidc_max_age' => '600'])->start('alice@example.com');
        $this->assertStringStartsWith('https://idp.example.com/authorize?tenant=t1&', $url, 'the endpoint\'s own query kept');
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame([
            'tenant' => 't1', 'response_type' => 'code', 'client_id' => 'dfir-fm', 'redirect_uri' => 'https://fm.example.com/login/oidc/callback',
            'scope' => 'openid email profile', 'state' => $pending['state'], 'nonce' => $pending['nonce'],
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'login_hint' => 'alice@example.com', 'max_age' => '600',
        ], $params);
        $this->assertStringContainsString('scope=openid%20email%20profile', $url);
        $this->assertGreaterThanOrEqual(43, strlen($pending['verifier']), 'RFC 7636: 43 to 128 characters');

        [$other] = $this->flow()->start(null);
        parse_str(parse_url($other, PHP_URL_QUERY), $params);
        $this->assertArrayNotHasKey('login_hint', $params);
        $this->assertArrayNotHasKey('max_age', $params);
        $this->assertNotSame($pending['state'], $params['state']);
    }

    public function testFinish(): void
    {
        $this->tokenAnswer(200, ['id_token' => $token = $this->idToken(['groups' => ['dfir-fm-admins']]), 'access_token' => 'at']);
        [$claims, $idToken] = $this->flow()->finish(self::PENDING, ['code' => 'c1', 'state' => 's1']);
        $this->assertSame('u1', $claims->subject());
        $this->assertSame($token, $idToken);

        $calls = array_values(array_filter($this->http->calls, fn($c) => $c['url'] === self::TOKEN_URL));
        $this->assertCount(1, $calls);
        $call = $calls[0];
        $this->assertSame(['grant_type' => 'authorization_code', 'code' => 'c1', 'redirect_uri' => 'https://fm.example.com/login/oidc/callback', 'code_verifier' => 'v1'], $call['fields']);
        $this->assertSame(['dfir-fm', 'S3cr3t-value'], $call['basicAuth']);
        $this->assertNotContains(self::USERINFO_URL, $this->http->requests, 'the admin claim is in the token');
    }

    public function testAdminClaimFromUserinfo(): void
    {
        $this->tokenAnswer(200, ['id_token' => $this->idToken(), 'access_token' => 'at']);
        $this->http->answers[self::USERINFO_URL] = new HttpsResponse(200, json_encode(['sub' => 'u1', 'groups' => ['dfir-fm-admins']]));
        [$claims] = $this->flow()->finish(self::PENDING, ['code' => 'c1']);
        $this->assertTrue($claims->contains('groups', 'dfir-fm-admins'));
        $this->assertSame(['Authorization: Bearer at'], $this->http->calls[array_key_last($this->http->calls)]['headers']);

        $this->http->answers[self::USERINFO_URL] = new HttpsResponse(200, json_encode(['sub' => 'u2', 'groups' => ['dfir-fm-admins']]));
        $this->assertRefused('claims', 'userinfo sub', fn() => $this->flow()->finish(self::PENDING, ['code' => 'c1']));
    }

    public function testIdpError(): void
    {
        $this->assertRefused('idp_error', 'The IdP answered access_denied: no?',
            fn() => $this->flow()->finish(self::PENDING, ['error' => 'access_denied', 'error_description' => "no\n", 'code' => 'c1']));
        $this->assertSame([], $this->http->requests, 'no token request');
    }

    public function testTokenExchangeRefusals(): void
    {
        $this->assertRefused('token_exchange', 'No code', fn() => $this->flow()->finish(self::PENDING, ['code' => ['c1']]));

        $this->tokenAnswer(400, ['error' => 'invalid_grant', 'error_description' => 'expired code']);
        $this->assertRefused('token_exchange', 'HTTP 400: invalid_grant expired code', fn() => $this->flow()->finish(self::PENDING, ['code' => 'c1']));

        $this->tokenAnswer(200, ['access_token' => 'at']);
        $this->assertRefused('token_exchange', 'no id_token', fn() => $this->flow()->finish(self::PENDING, ['code' => 'c1']));

        unset($this->http->answers[self::TOKEN_URL]);
        $this->assertRefused('token_exchange', 'Token request failed: unreachable', fn() => $this->flow()->finish(self::PENDING, ['code' => 'c1']));
    }

    public function testNonceChecked(): void
    {
        $this->tokenAnswer(200, ['id_token' => $this->idToken(['groups' => []])]);
        $this->assertRefused('nonce', 'nonce', fn() => $this->flow()->finish(['nonce' => 'other'] + self::PENDING, ['code' => 'c1']));
    }

    public function testPendingStore(): void
    {
        $store = [];
        $this->flow(); // for tearDown
        foreach (range(1, CodeFlow::MAX_PENDING + 2) as $i) {
            CodeFlow::remember($store, ['state' => "s$i", 'nonce' => "n$i", 'verifier' => "v$i"], "/r$i");
        }
        $this->assertSame(['s3', 's4', 's5', 's6', 's7'], array_keys($store), 'the newest MAX_PENDING');

        $pending = CodeFlow::take($store, 's4');
        $this->assertSame(['state' => 's4', 'nonce' => 'n4', 'verifier' => 'v4', 'redirect' => '/r4'], array_diff_key($pending, ['started' => 1]));
        $this->assertNull(CodeFlow::take($store, 's4'), 'used once');
        $this->assertNull(CodeFlow::take($store, 's1'), 'dropped');
        $this->assertNull(CodeFlow::take($store, null));
        $this->assertNull(CodeFlow::take($store, ['s5']));

        $store['s5']['started'] = time() - CodeFlow::PENDING_TTL - 1;
        $this->assertNull(CodeFlow::take($store, 's5'), 'expired');
        $this->assertArrayNotHasKey('s5', $store, 'and removed');
        $this->assertNotNull(CodeFlow::take($store, 's6'));
    }
}
