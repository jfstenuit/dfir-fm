<?php

// src/Services/Oidc/ProviderCheck.php
namespace Services\Oidc;

use Firebase\JWT\JWK;

/**
 * bin/oidc-check.php: validates the IdP address and DFIR FM's client
 * registration (client id, secret, redirect URI) without a browser, so
 * that a later login failure points at our code rather than at the
 * provider. Deliberately independent of the login code: it fetches
 * without the APCu cache and parses the keys with the stock
 * firebase/php-jwt call. A sign-in with a real account is tested end to
 * end through the UI.
 */
final class ProviderCheck
{
    public const OK = 'OK';
    public const FAIL = 'FAIL';
    public const INFO = 'INFO';

    /** The asymmetric algorithms DFIR FM accepts for ID tokens */
    public const ALGORITHMS = ['RS256', 'ES256', 'PS256'];

    /** @var list<array{0:string,1:string,2:string}> */
    private array $results = [];
    private ?array $metadata = null;
    private ?array $jwks = null;

    public function __construct(private OidcConfig $config, private HttpsClient $http)
    {
    }

    /**
     * Discovery, JWKS and credential checks, in that order; a check that
     * the next ones depend on stops the run when it fails.
     * @return list<array{0:string,1:string,2:string}> [status, check, detail]
     */
    public function run(): array
    {
        $this->results = [];
        if ($this->checkDiscovery() && $this->checkJwks()) {
            $this->checkAuthorization();
            $this->checkCredentials();
        }
        return $this->results;
    }

    public function passed(): bool
    {
        return $this->results !== [] && !in_array(self::FAIL, array_column($this->results, 0), true);
    }

    public function metadata(): ?array
    {
        return $this->metadata;
    }

    private function checkDiscovery(): bool
    {
        $url = rtrim($this->config->issuer(), '/') . '/.well-known/openid-configuration';
        $metadata = $this->fetchJson($url, 'Discovery document');
        if ($metadata === null) {
            return false;
        }
        $issuer = $metadata['issuer'] ?? null;
        if ($issuer !== $this->config->issuer()) {
            return $this->result(self::FAIL, 'Discovery issuer', sprintf("'%s' isn't oidc_issuer '%s' (they must match exactly, trailing slash included)",
                is_string($issuer) ? $issuer : '', $this->config->issuer()));
        }
        $this->result(self::OK, 'Discovery document', "$url, issuer matches");

        $endpoints = true;
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $name) {
            $value = $metadata[$name] ?? null;
            if (!is_string($value) || strtolower((string)parse_url($value, PHP_URL_SCHEME)) !== 'https') {
                $endpoints = $this->result(self::FAIL, $name, is_string($value) ? "not HTTPS: $value" : 'missing');
            } else {
                $this->result(self::OK, $name, $value);
            }
        }

        $this->listCheck($metadata, 'response_types_supported', ['code'], 'code');
        $this->listCheck($metadata, 'code_challenge_methods_supported', ['S256'], 'S256 (PKCE)');
        if (isset($metadata['token_endpoint_auth_methods_supported'])) {
            $this->listCheck($metadata, 'token_endpoint_auth_methods_supported', ['client_secret_basic'], 'client_secret_basic');
        } else {
            $this->result(self::OK, 'token_endpoint_auth_methods_supported', 'not advertised: client_secret_basic is the default');
        }
        $this->listCheck($metadata, 'id_token_signing_alg_values_supported', self::ALGORITHMS, 'one of ' . implode(', ', self::ALGORITHMS));

        foreach (['userinfo_endpoint' => 'admin claim fallback', 'end_session_endpoint' => 'RP-initiated logout'] as $name => $use) {
            $this->result(self::INFO, $name, isset($metadata[$name]) ? "{$metadata[$name]} ($use)" : "not advertised (no $use)");
        }

        $this->metadata = $metadata;
        return $endpoints;
    }

    private function checkJwks(): bool
    {
        $jwks = $this->fetchJson($this->metadata['jwks_uri'], 'JWKS');
        if ($jwks === null) {
            return false;
        }
        if (!is_array($jwks['keys'] ?? null) || !array_is_list($jwks['keys']) || $jwks['keys'] === []) {
            return $this->result(self::FAIL, 'JWKS', 'no keys list, or an empty one');
        }

        $advertised = array_intersect(self::ALGORITHMS, (array)($this->metadata['id_token_signing_alg_values_supported'] ?? []));
        $usable = [];
        foreach ($jwks['keys'] as $i => $key) {
            $kid = is_array($key) && is_string($key['kid'] ?? null) && $key['kid'] !== '' ? $key['kid'] : null;
            if ($kid === null) {
                return $this->result(self::FAIL, 'JWKS', "key #$i has no kid");
            }
            if (($key['use'] ?? 'sig') !== 'sig') {
                continue;
            }
            $alg = $key['alg'] ?? self::defaultAlg($key, $advertised);
            if ($alg !== null && in_array($alg, $advertised, true)) {
                $usable[] = "$kid ($alg)";
            }
        }
        if ($usable === []) {
            return $this->result(self::FAIL, 'JWKS', 'no signing key for an advertised algorithm (' . implode(', ', $advertised) . ')');
        }
        try {
            JWK::parseKeySet($jwks, self::defaultAlg(['kty' => 'RSA'], $advertised));
        } catch (\Throwable $e) {
            return $this->result(self::FAIL, 'JWKS', 'firebase/php-jwt refuses the key set: ' . $e->getMessage());
        }
        $this->jwks = $jwks;
        $this->result(self::OK, 'JWKS', count($jwks['keys']) . ' key(s), signing: ' . implode(', ', $usable));
        return true;
    }

    /**
     * The authorization request, without a browser session: an IdP that
     * knows the client and the redirect URI answers with its login page
     * (or a redirect to it), one that doesn't with an error page
     */
    private function checkAuthorization(): void
    {
        try {
            $response = $this->http->get($this->authorizationUrl(), ['Accept: text/html']);
        } catch (OidcException $e) {
            $this->result(self::FAIL, 'Authorization request', $e->getMessage());
            return;
        }
        $location = (string)$response->location;
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        if ($response->status >= 300 && $response->status < 400 && str_starts_with($location, $this->config->redirectUri()) && isset($query['error'])) {
            $this->result(self::FAIL, 'Authorization request', 'the IdP answered ' . self::text($query['error'])
                . (isset($query['error_description']) ? ': ' . self::text($query['error_description']) : ''));
        } elseif ($response->status >= 200 && $response->status < 400) {
            $this->result(self::OK, 'Authorization request', "client and redirect URI accepted (HTTP {$response->status}, to the IdP's login)");
        } else {
            preg_match('#<title>(.*?)</title>#si', $response->body, $title);
            $this->result(self::FAIL, 'Authorization request', "refused, HTTP {$response->status}"
                . (isset($title[1]) ? " ('" . self::text(trim(preg_replace('/\s+/', ' ', $title[1]))) . "')" : '')
                . ': does the IdP know oidc_client_id, with oidc_redirect_uri (' . $this->config->redirectUri() . ') registered for it exactly?');
        }
    }

    /**
     * Exchange a random, invalid code with our credentials: invalid_grant
     * means the client was authenticated and only the code was refused
     */
    private function checkCredentials(): void
    {
        try {
            $response = $this->http->postForm($this->metadata['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => bin2hex(random_bytes(16)),
                'redirect_uri' => $this->config->redirectUri(),
                'code_verifier' => self::randomToken(),
            ], [$this->config->clientId(), $this->config->clientSecret()]);
        } catch (OidcException $e) {
            $this->result(self::FAIL, 'Client credentials', $e->getMessage());
            return;
        }
        $error = $response->json()['error'] ?? null;
        match (true) {
            $error === 'invalid_grant' => $this->result(self::OK, 'Client credentials',
                'accepted (a random code was refused with invalid_grant, as expected)'),
            $error === 'invalid_client' || $response->status === 401 => $this->result(self::FAIL, 'Client credentials',
                "refused (HTTP {$response->status}" . ($error ? ", $error" : '') . '): check oidc_client_id and oidc_client_secret'
                . ' (and oidc_redirect_uri if the authorization request failed too: some IdPs, Authentik among them, answer invalid_client for an unregistered redirect URI)'),
            default => $this->result(self::FAIL, 'Client credentials',
                "unexpected answer, HTTP {$response->status}: " . self::excerpt($response->body)),
        };
    }

    /** An authorization request as the login will send it (PKCE S256, state, nonce) */
    private function authorizationUrl(): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->config->clientId(),
            'redirect_uri' => $this->config->redirectUri(),
            'scope' => $this->config->scopes(),
            'state' => self::randomToken(),
            'nonce' => self::randomToken(),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::randomToken(), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
        if ($this->config->maxAge() !== null) {
            $params['max_age'] = (string)$this->config->maxAge();
        }
        $endpoint = $this->metadata['authorization_endpoint'];
        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function fetchJson(string $url, string $what): ?array
    {
        try {
            $response = $this->http->get($url);
        } catch (OidcException $e) {
            $this->result(self::FAIL, $what, $e->getMessage());
            return null;
        }
        if ($response->status !== 200) {
            $this->result(self::FAIL, $what, "HTTP {$response->status} from $url");
            return null;
        }
        $data = $response->json();
        if ($data === null) {
            $this->result(self::FAIL, $what, "not a JSON object: $url");
        }
        return $data;
    }

    private function listCheck(array $metadata, string $name, array $wanted, string $label): void
    {
        $list = $metadata[$name] ?? null;
        if (!is_array($list)) {
            $this->result(self::FAIL, $name, "not advertised (needed: $label)");
        } elseif (array_intersect($wanted, $list) === []) {
            $this->result(self::FAIL, $name, 'needed: ' . $label . '; advertised: ' . implode(', ', array_map(self::text(...), $list)));
        } else {
            $this->result(self::OK, $name, implode(', ', array_intersect($wanted, $list)));
        }
    }

    /** Always false, so that a failing check can `return $this->result(FAIL, …)` */
    private function result(string $status, string $check, string $detail): bool
    {
        $this->results[] = [$status, $check, $detail];
        return false;
    }

    /** RSA keys without alg: the advertised RSA algorithm (RS256 first) */
    private static function defaultAlg(array $key, array $advertised): ?string
    {
        $family = ['RSA' => ['RS256', 'PS256'], 'EC' => ['ES256']][$key['kty'] ?? ''] ?? [];
        return array_values(array_intersect($family, $advertised))[0] ?? null;
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** IdP text for the terminal: printable, short */
    private static function text(mixed $value): string
    {
        return substr(preg_replace('/[^\x20-\x7E]/', '?', is_scalar($value) ? (string)$value : json_encode($value)), 0, 200);
    }

    private static function excerpt(string $body): string
    {
        return self::text($body === '' ? '(empty body)' : $body);
    }
}
