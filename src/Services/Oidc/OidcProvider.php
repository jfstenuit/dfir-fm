<?php

// src/Services/Oidc/OidcProvider.php
namespace Services\Oidc;

/**
 * The IdP's discovery document and signing keys, cached in APCu for
 * CACHE_TTL. A token signed with a key we don't know (key rotation)
 * refetches the JWKS, at most once per JWKS_REFETCH_INTERVAL, so that
 * forged kids can't make us hammer the IdP.
 */
final class OidcProvider
{
    public const CACHE_TTL = 3600;
    public const JWKS_REFETCH_INTERVAL = 60;

    private const REQUIRED_ENDPOINTS = ['authorization_endpoint', 'token_endpoint', 'jwks_uri'];

    private string $cacheKey;

    public function __construct(private OidcConfig $config, private HttpsClient $http)
    {
        // Per issuer: a configuration change never reuses another IdP's keys
        $this->cacheKey = 'oidc_' . sha1($config->issuer());
    }

    public static function create(OidcConfig $config): self
    {
        return new self($config, new CurlHttpsClient());
    }

    public function http(): HttpsClient
    {
        return $this->http;
    }

    /**
     * The discovery document. Its issuer equals oidc_issuer exactly, and
     * the authorization, token and JWKS endpoints are HTTPS URLs.
     * @throws OidcException
     */
    public function metadata(): array
    {
        $metadata = apcu_fetch("{$this->cacheKey}_discovery");
        if (is_array($metadata)) {
            return $metadata;
        }

        $url = rtrim($this->config->issuer(), '/') . '/.well-known/openid-configuration';
        $metadata = $this->fetchJson($url, 'discovery document');
        if (($metadata['issuer'] ?? null) !== $this->config->issuer()) {
            throw new OidcException(sprintf("The discovery document's issuer '%s' isn't oidc_issuer '%s' (they must match exactly, trailing slash included)",
                is_string($metadata['issuer'] ?? null) ? $metadata['issuer'] : '', $this->config->issuer()));
        }
        foreach (self::REQUIRED_ENDPOINTS as $name) {
            if (!is_string($metadata[$name] ?? null) || strtolower((string)parse_url($metadata[$name], PHP_URL_SCHEME)) !== 'https') {
                throw new OidcException("The discovery document has no HTTPS $name");
            }
        }

        apcu_store("{$this->cacheKey}_discovery", $metadata, self::CACHE_TTL);
        return $metadata;
    }

    /**
     * The JWKS, as the IdP publishes it ({"keys": [...]}). With a $kid
     * that isn't in the cached set, it is fetched again, unless it was
     * fetched less than JWKS_REFETCH_INTERVAL ago; the caller then finds
     * the kid missing and refuses the token.
     * @throws OidcException
     */
    public function jwks(?string $kid = null): array
    {
        $jwks = apcu_fetch("{$this->cacheKey}_jwks");
        if (!is_array($jwks)) {
            return $this->fetchJwks();
        }
        if ($kid !== null && !self::hasKid($jwks, $kid) && apcu_add("{$this->cacheKey}_jwks_fetched", 1, self::JWKS_REFETCH_INTERVAL)) {
            return $this->fetchJwks();
        }
        return $jwks;
    }

    /**
     * The userinfo answer for an access token, for a claim the ID token
     * doesn't carry. Null when the IdP has no userinfo endpoint.
     * @throws OidcException when the endpoint fails: a missing admin claim
     *   must not be read as "not an admin" because of an outage
     */
    public function userinfo(string $accessToken): ?array
    {
        $endpoint = $this->metadata()['userinfo_endpoint'] ?? null;
        if (!is_string($endpoint)) {
            return null;
        }
        if (strtolower((string)parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
            throw new OidcException("The discovery document's userinfo_endpoint isn't HTTPS");
        }
        $response = $this->http->get($endpoint, ["Authorization: Bearer $accessToken"]);
        if ($response->status !== 200) {
            throw new OidcException("The userinfo endpoint answered HTTP {$response->status}");
        }
        // A signed (application/jwt) answer isn't supported: we never register for one
        return $response->json() ?? throw new OidcException('The userinfo answer is not a JSON object');
    }

    /** Drop the cached discovery document and JWKS */
    public function forget(): void
    {
        apcu_delete(["{$this->cacheKey}_discovery", "{$this->cacheKey}_jwks", "{$this->cacheKey}_jwks_fetched"]);
    }

    private function fetchJwks(): array
    {
        $jwks = $this->fetchJson($this->metadata()['jwks_uri'], 'JWKS');
        if (!is_array($jwks['keys'] ?? null) || !array_is_list($jwks['keys'])) {
            throw new OidcException('The JWKS has no keys list');
        }
        foreach ($jwks['keys'] as $key) {
            if (!is_array($key)) {
                throw new OidcException('The JWKS has a key that is not an object');
            }
        }
        apcu_store("{$this->cacheKey}_jwks", $jwks, self::CACHE_TTL);
        apcu_store("{$this->cacheKey}_jwks_fetched", 1, self::JWKS_REFETCH_INTERVAL);
        return $jwks;
    }

    private static function hasKid(array $jwks, string $kid): bool
    {
        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return true;
            }
        }
        return false;
    }

    private function fetchJson(string $url, string $what): array
    {
        $response = $this->http->get($url);
        if ($response->status !== 200) {
            throw new OidcException("The $what answered HTTP {$response->status}: $url");
        }
        $data = $response->json();
        if ($data === null) {
            throw new OidcException("The $what is not a JSON object: $url");
        }
        return $data;
    }
}
