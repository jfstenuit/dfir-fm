<?php

// src/Services/Oidc/IdTokenValidator.php
namespace Services\Oidc;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * The ID token of the code flow (OIDC Core 3.1.3.7), with the IdP's keys
 * (OidcProvider): signature, issuer, audience, lifetime, nonce, and the
 * optional max_age and MFA requirements. Each refusal is an OidcRefusal
 * with its log cause: signature, claims or nonce.
 */
final class IdTokenValidator
{
    /** The asymmetric algorithms accepted, when the IdP advertises them; never none or HS* */
    public const ALGORITHMS = ['RS256', 'ES256', 'PS256'];

    /** Clock skew allowed on exp, iat, nbf and auth_time, in seconds */
    public const LEEWAY = 60;

    /** amr values (RFC 8176) that show a second factor */
    public const MFA_AMR = ['mfa', 'otp', 'hwk', 'swk', 'sms', 'tel', 'face', 'fpt', 'iris', 'retina', 'vbm'];

    /** acr values that mean multi-factor (OpenID PAPE) */
    public const MFA_ACR = ['http://schemas.openid.net/pape/policies/2007/06/multi-factor'];

    /** Key types of each algorithm */
    private const KEY_TYPES = ['RS256' => 'RSA', 'PS256' => 'RSA', 'ES256' => 'EC'];

    public function __construct(private OidcConfig $config, private OidcProvider $provider)
    {
    }

    /**
     * @param string $nonce the nonce sent in the authorization request
     * @throws OidcRefusal
     * @throws OidcException the IdP's keys couldn't be fetched
     */
    public function validate(string $idToken, string $nonce): Claims
    {
        $header = self::header($idToken);
        $alg = $header['alg'] ?? null;
        $advertised = array_values(array_intersect(self::ALGORITHMS,
            (array)($this->provider->metadata()['id_token_signing_alg_values_supported'] ?? [])));
        if (!is_string($alg) || !in_array($alg, $advertised, true)) {
            throw new OidcRefusal('signature', sprintf('ID token algorithm %s refused (accepted: %s)',
                is_string($alg) ? self::text($alg) : 'missing', implode(', ', $advertised) ?: 'none advertised'));
        }
        if (isset($header['crit'])) {
            throw new OidcRefusal('signature', 'ID token with critical header parameters (crit), not supported');
        }
        $kid = $header['kid'] ?? null;
        if ($kid !== null && !is_string($kid)) {
            throw new OidcRefusal('signature', 'ID token kid is not a string');
        }

        $key = $this->key($this->provider->jwks($kid), $kid, $alg);
        $leeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;
        try {
            $payload = JWT::decode($idToken, $key);
        } catch (ExpiredException | BeforeValidException $e) {
            throw new OidcRefusal('claims', 'ID token outside its validity: ' . $e->getMessage(), $e);
        } catch (\Throwable $e) {
            throw new OidcRefusal('signature', 'ID token refused: ' . $e->getMessage(), $e);
        } finally {
            JWT::$leeway = $leeway;
        }
        $claims = json_decode(json_encode($payload), true);

        $this->checkClaims($claims, $nonce);
        return new Claims($claims);
    }

    private function checkClaims(array $claims, string $nonce): void
    {
        if (($claims['iss'] ?? null) !== $this->config->issuer()) {
            throw new OidcRefusal('claims', "ID token iss '" . self::text($claims['iss'] ?? '') . "' isn't oidc_issuer");
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new OidcRefusal('claims', 'ID token without sub');
        }
        $audience = $claims['aud'] ?? null;
        $audience = is_string($audience) ? [$audience] : (is_array($audience) && array_is_list($audience) ? $audience : []);
        if (!in_array($this->config->clientId(), $audience, true)) {
            throw new OidcRefusal('claims', 'ID token aud does not contain oidc_client_id');
        }
        if (array_key_exists('azp', $claims) ? $claims['azp'] !== $this->config->clientId() : count($audience) > 1) {
            throw new OidcRefusal('claims', 'ID token azp is not oidc_client_id (or missing, with several audiences)');
        }
        // JWT::decode() checks them only when present
        foreach (['exp', 'iat'] as $name) {
            if (!self::isTime($claims[$name] ?? null)) {
                throw new OidcRefusal('claims', "ID token without a numeric $name");
            }
        }
        if (!is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            throw new OidcRefusal('nonce', 'ID token nonce mismatch');
        }

        if (($maxAge = $this->config->maxAge()) !== null) {
            $authTime = $claims['auth_time'] ?? null;
            if (!self::isTime($authTime)) {
                throw new OidcRefusal('claims', 'ID token without auth_time, although max_age was sent');
            }
            if (time() - $authTime > $maxAge + self::LEEWAY) {
                throw new OidcRefusal('claims', sprintf('ID token auth_time %d s ago, over max_age %d s', time() - $authTime, $maxAge));
            }
        }
        if ($this->config->requireMfa() && !self::showsMfa($claims)) {
            throw new OidcRefusal('claims', sprintf('No second factor shown (amr %s, acr %s), and oidc_require_mfa is set',
                self::text(json_encode($claims['amr'] ?? null)), self::text(json_encode($claims['acr'] ?? null))));
        }
    }

    /** A NumericDate: a JSON number (seconds, possibly fractional) */
    private static function isTime(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    public static function showsMfa(array $claims): bool
    {
        $amr = $claims['amr'] ?? [];
        return (is_array($amr) && array_intersect(self::MFA_AMR, array_filter($amr, 'is_string')) !== [])
            || in_array($claims['acr'] ?? null, self::MFA_ACR, true);
    }

    /**
     * The JWK for this kid and algorithm. Without a kid, the only signing
     * key of the algorithm's type, if there is exactly one.
     */
    private function key(array $jwks, ?string $kid, string $alg): \Firebase\JWT\Key
    {
        $candidates = array_values(array_filter($jwks['keys'] ?? [], fn($jwk) => is_array($jwk)
            && ($kid === null || ($jwk['kid'] ?? null) === $kid)
            && ($jwk['kty'] ?? null) === self::KEY_TYPES[$alg]
            && ($jwk['use'] ?? 'sig') === 'sig'
            && ($jwk['alg'] ?? $alg) === $alg));
        if (count($candidates) !== 1) {
            throw new OidcRefusal('signature', $kid === null
                ? sprintf('ID token without kid, and %d %s signing key(s) in the JWKS', count($candidates), self::KEY_TYPES[$alg])
                : sprintf("No %s signing key with kid '%s' in the JWKS", $alg, self::text($kid)));
        }
        try {
            return JWK::parseKey($candidates[0], $alg);
        } catch (\Throwable $e) {
            throw new OidcRefusal('signature', 'Unusable JWKS key: ' . $e->getMessage(), $e);
        }
    }

    private static function header(string $idToken): array
    {
        $parts = explode('.', $idToken);
        try {
            $header = count($parts) === 3 ? json_decode(JWT::urlsafeB64Decode($parts[0]), true) : null;
        } catch (\Throwable) {
            $header = null;
        }
        if (!is_array($header) || array_is_list($header) && $header !== []) {
            throw new OidcRefusal('signature', 'Malformed ID token');
        }
        return $header;
    }

    /** IdP text for the log: printable, short */
    private static function text(mixed $value): string
    {
        return substr(preg_replace('/[^\x20-\x7E]/', '?', is_scalar($value) ? (string)$value : (string)json_encode($value)), 0, 200);
    }
}
