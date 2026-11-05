<?php
namespace Tests\Unit\Services\Oidc;

use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Services\Oidc\HttpsResponse;
use Services\Oidc\IdTokenValidator;
use Services\Oidc\OidcConfig;
use Services\Oidc\OidcProvider;
use Services\Oidc\OidcRefusal;

final class IdTokenValidatorTest extends TestCase
{
    private const JWKS_URL = 'https://idp.example.com/jwks';

    private static \OpenSSLAsymmetricKey $rsa;
    private static \OpenSSLAsymmetricKey $rsa2;
    private static \OpenSSLAsymmetricKey $ec;

    private string $issuer;
    private StubHttpsClient $http;
    private OidcProvider $provider;
    private array $keys;

    public static function setUpBeforeClass(): void
    {
        self::$rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::$rsa2 = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::$ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    }

    protected function setUp(): void
    {
        // A fresh issuer per test: its own APCu cache entries
        $this->issuer = 'https://idp.example.com/application/o/t-' . bin2hex(random_bytes(6)) . '/';
        $this->http = new StubHttpsClient();
        $this->keys = [self::rsaJwk(self::$rsa, 'r1'), self::ecJwk(self::$ec, 'e1')];
        $this->serve(['RS256', 'ES256']);
    }

    protected function tearDown(): void
    {
        $this->provider->forget();
    }

    public function testValidRs256Token(): void
    {
        $claims = $this->validator()->validate($this->token(), 'n1');
        $this->assertSame('u1', $claims->subject());
        $this->assertSame($this->issuer, $claims->issuer());
        $this->assertSame('alice@example.com', $claims->email('email'));
    }

    public function testValidEs256Token(): void
    {
        $this->assertSame('u1', $this->validator()->validate($this->token([], 'ES256', 'e1', self::$ec), 'n1')->subject());
    }

    public function testAudienceListWithAzp(): void
    {
        $token = $this->token(['aud' => ['dfir-fm', 'api'], 'azp' => 'dfir-fm']);
        $this->assertSame('u1', $this->validator()->validate($token, 'n1')->subject());
    }

    public function testNoKidWithASingleKeyOfTheType(): void
    {
        $this->assertSame('u1', $this->validator()->validate($this->token([], 'RS256', null), 'n1')->subject());

        $this->keys[] = self::rsaJwk(self::$rsa2, 'r2');
        $this->serve(['RS256', 'ES256']);
        apcu_delete('oidc_' . sha1($this->issuer) . '_jwks'); // the cache has expired
        $this->assertRefused('signature', 'without kid, and 2 RSA', $this->token([], 'RS256', null));
    }

    public static function claimRefusals(): array
    {
        $now = time();
        return [
            'issuer' => [['iss' => 'https://evil.example.com/'], 'claims', "iss 'https://evil.example.com/'"],
            'issuer without its trailing slash' => [['iss' => 'TRIM'], 'claims', "isn't oidc_issuer"],
            'audience' => [['aud' => 'someone-else'], 'claims', 'aud does not contain'],
            'azp' => [['aud' => ['dfir-fm', 'api'], 'azp' => 'api'], 'claims', 'azp'],
            'azp with a single audience' => [['azp' => 'other'], 'claims', 'azp'],
            'several audiences, no azp' => [['aud' => ['dfir-fm', 'api']], 'claims', 'azp'],
            'no sub' => [['sub' => null], 'claims', 'without sub'],
            'nonce' => [['nonce' => 'other'], 'nonce', 'nonce mismatch'],
            'no nonce' => [['nonce' => null], 'nonce', 'nonce mismatch'],
            'expired' => [['exp' => $now - 120], 'claims', 'Expired token'],
            'not yet valid (nbf)' => [['nbf' => $now + 120], 'claims', 'nbf'],
            'issued in the future' => [['iat' => $now + 120], 'claims', 'iat'],
            'no exp' => [['exp' => null], 'claims', 'numeric exp'],
            'no iat' => [['iat' => null], 'claims', 'numeric iat'],
        ];
    }

    #[DataProvider('claimRefusals')]
    public function testClaimRefusals(array $claims, string $cause, string $message): void
    {
        if (($claims['iss'] ?? null) === 'TRIM') {
            $claims['iss'] = rtrim($this->issuer, '/');
        }
        $this->assertRefused($cause, $message, $this->token($claims));
    }

    public function testWithinTheSkew(): void
    {
        $now = time();
        $token = $this->token(['exp' => $now - 30, 'nbf' => $now + 30, 'iat' => $now + 30]);
        $this->assertSame('u1', $this->validator()->validate($token, 'n1')->subject());
        $this->assertSame(0, JWT::$leeway, 'the global leeway is restored');
    }

    public function testAlgNone(): void
    {
        $b64 = fn(array $part) => JWT::urlsafeB64Encode(json_encode($part));
        $token = $b64(['alg' => 'none', 'kid' => 'r1']) . '.' . $b64(self::claims($this->issuer)) . '.';
        $this->assertRefused('signature', 'algorithm none refused', $token);
    }

    public function testHs256WithThePublicKeyAsSecret(): void
    {
        $pem = openssl_pkey_get_details(self::$rsa)['key'];
        $token = JWT::encode(self::claims($this->issuer), $pem, 'HS256', 'r1');
        $this->assertRefused('signature', 'algorithm HS256 refused', $token);
    }

    public function testAlgorithmNotAdvertised(): void
    {
        $this->serve(['RS256']);
        $this->assertRefused('signature', 'algorithm ES256 refused (accepted: RS256)', $this->token([], 'ES256', 'e1', self::$ec));
    }

    public function testRs512NeverAccepted(): void
    {
        $this->serve(['RS256', 'RS512']);
        $this->assertRefused('signature', 'algorithm RS512 refused', $this->token([], 'RS512'));
    }

    public function testKeyOfTheWrongType(): void
    {
        // An ES256 header with the RSA key's kid
        $this->assertRefused('signature', "No ES256 signing key with kid 'r1'", $this->token([], 'ES256', 'r1', self::$ec));
    }

    public function testTamperedPayload(): void
    {
        [$header, , $signature] = explode('.', $this->token());
        $payload = JWT::urlsafeB64Encode(json_encode(self::claims($this->issuer, ['sub' => 'admin'])));
        $this->assertRefused('signature', 'Signature verification failed', "$header.$payload.$signature");
    }

    public function testSignedWithAnotherKey(): void
    {
        $this->assertRefused('signature', 'Signature verification failed', $this->token([], 'RS256', 'r1', self::$rsa2));
    }

    public function testCriticalHeader(): void
    {
        $token = JWT::encode(self::claims($this->issuer), self::$rsa, 'RS256', 'r1', ['crit' => ['exp'], 'exp' => 1]);
        $this->assertRefused('signature', 'crit', $token);
    }

    public function testMalformedTokens(): void
    {
        foreach (['', 'abc', 'a.b', 'a.b.c', '!!!.e30.x', 'W10.e30.x'] as $token) {
            $this->assertRefused('signature', '', $token);
        }
    }

    public function testUnknownKidRefetchesTheJwksOnce(): void
    {
        $validator = $this->validator();
        $validator->validate($this->token(), 'n1');
        $this->assertSame(1, $this->jwksFetches());

        // Rotation: the new key is published, and found by the refetch
        apcu_delete('oidc_' . sha1($this->issuer) . '_jwks_fetched');
        $this->keys[] = self::rsaJwk(self::$rsa2, 'r2');
        $this->serve(['RS256', 'ES256']);
        $this->assertSame('u1', $validator->validate($this->token([], 'RS256', 'r2', self::$rsa2), 'n1')->subject());
        $this->assertSame(2, $this->jwksFetches());

        // A kid nobody publishes: one more refetch at most, then refused
        apcu_delete('oidc_' . sha1($this->issuer) . '_jwks_fetched');
        $this->assertRefused('signature', "kid 'forged'", $this->token([], 'RS256', 'forged'));
        $this->assertRefused('signature', "kid 'forged'", $this->token([], 'RS256', 'forged'));
        $this->assertSame(3, $this->jwksFetches());
    }

    public function testMaxAge(): void
    {
        $validator = $this->validator(['oidc_max_age' => '600']);
        $this->assertSame('u1', $validator->validate($this->token(['auth_time' => time() - 300]), 'n1')->subject());
        $this->assertSame('u1', $validator->validate($this->token(['auth_time' => time() - 630]), 'n1')->subject(), 'within the skew');
        $this->assertRefused('claims', 'over max_age 600 s', $this->token(['auth_time' => time() - 700]), $validator);
        $this->assertRefused('claims', 'without auth_time', $this->token(), $validator);
        // Without max_age, auth_time isn't checked
        $this->assertSame('u1', $this->validator()->validate($this->token(['auth_time' => time() - 86400]), 'n1')->subject());
    }

    public function testRequireMfa(): void
    {
        $validator = $this->validator(['oidc_require_mfa' => 'true']);
        $this->assertRefused('claims', 'No second factor shown (amr ["pwd"]', $this->token(['amr' => ['pwd']]), $validator);
        $this->assertRefused('claims', 'No second factor', $this->token(), $validator);
        $this->assertSame('u1', $validator->validate($this->token(['amr' => ['pwd', 'otp']]), 'n1')->subject());
        $this->assertSame('u1', $validator->validate($this->token(['amr' => ['mfa']]), 'n1')->subject());
        $this->assertSame('u1', $validator->validate($this->token(['acr' => IdTokenValidator::MFA_ACR[0]]), 'n1')->subject());
        // Without the setting, password only is fine
        $this->assertSame('u1', $this->validator()->validate($this->token(['amr' => ['pwd']]), 'n1')->subject());
    }

    private function assertRefused(string $cause, string $message, string $token, ?IdTokenValidator $validator = null): void
    {
        try {
            ($validator ?? $this->validator())->validate($token, 'n1');
            $this->fail("accepted, expected a refusal: $message");
        } catch (OidcRefusal $e) {
            $this->assertSame($cause, $e->cause, $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function validator(array $config = []): IdTokenValidator
    {
        $oidc = OidcConfig::fromConfig($config + ['oidc_issuer' => $this->issuer] + OidcConfigTest::COMPLETE);
        $this->provider = new OidcProvider($oidc, $this->http);
        return new IdTokenValidator($oidc, $this->provider);
    }

    /** Discovery and JWKS answers; the cache of a previous answer is kept, as in production */
    private function serve(array $algorithms): void
    {
        $this->http->answers[rtrim($this->issuer, '/') . '/.well-known/openid-configuration'] = new HttpsResponse(200, json_encode([
            'issuer' => $this->issuer,
            'authorization_endpoint' => 'https://idp.example.com/authorize',
            'token_endpoint' => 'https://idp.example.com/token',
            'jwks_uri' => self::JWKS_URL,
            'id_token_signing_alg_values_supported' => $algorithms,
        ]));
        $this->http->answers[self::JWKS_URL] = new HttpsResponse(200, json_encode(['keys' => $this->keys]));
        if (isset($this->provider)) {
            apcu_delete('oidc_' . sha1($this->issuer) . '_discovery');
        }
    }

    private function jwksFetches(): int
    {
        return count(array_keys($this->http->requests, self::JWKS_URL, true));
    }

    private function token(array $claims = [], string $alg = 'RS256', ?string $kid = 'r1', ?\OpenSSLAsymmetricKey $key = null): string
    {
        return JWT::encode(self::claims($this->issuer, $claims), $key ?? self::$rsa, $alg, $kid);
    }

    /** A null override removes the claim */
    private static function claims(string $issuer, array $override = []): array
    {
        return array_filter($override + [
            'iss' => $issuer, 'sub' => 'u1', 'aud' => 'dfir-fm', 'iat' => time(), 'exp' => time() + 300, 'nonce' => 'n1',
            'email' => 'alice@example.com', 'email_verified' => true,
        ], fn($v) => $v !== null);
    }

    private static function rsaJwk(\OpenSSLAsymmetricKey $key, string $kid): array
    {
        $rsa = openssl_pkey_get_details($key)['rsa'];
        return ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e'])];
    }

    /** Without alg, as some IdPs publish their keys */
    private static function ecJwk(\OpenSSLAsymmetricKey $key, string $kid): array
    {
        $ec = openssl_pkey_get_details($key)['ec'];
        $pad = fn(string $v) => JWT::urlsafeB64Encode(str_pad($v, 32, "\0", STR_PAD_LEFT));
        return ['kty' => 'EC', 'kid' => $kid, 'use' => 'sig', 'crv' => 'P-256', 'x' => $pad($ec['x']), 'y' => $pad($ec['y'])];
    }
}
