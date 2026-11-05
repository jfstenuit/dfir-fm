<?php

// src/Services/Oidc/CodeFlow.php
namespace Services\Oidc;

/**
 * The authorization code flow with PKCE (S256), as a confidential client
 * (client_secret_basic), without HTTP of its own: start() gives the
 * authorization URL and the one-time values the callback needs, finish()
 * checks the IdP's answer and returns the validated claims.
 *
 * The one-time values are kept in the session, several at a time (one per
 * tab that started a sign-in), each used once and for PENDING_TTL at most.
 */
final class CodeFlow
{
    /** Seconds a started sign-in may take at the IdP */
    public const PENDING_TTL = 600;

    /** Started sign-ins kept per session; the oldest goes first */
    public const MAX_PENDING = 5;

    public function __construct(
        private readonly OidcConfig $config,
        private readonly OidcProvider $provider,
    ) {
    }

    /**
     * @param ?string $loginHint an in-domain address, passed on to the IdP
     * @param bool $reauthenticate prompt=login: the IdP asks for the credentials
     *   even if it has a session (after a logout, or to use another account)
     * @return array{0:string,1:array{state:string,nonce:string,verifier:string}} the authorization URL, and the one-time values
     * @throws OidcException discovery failed
     */
    public function start(?string $loginHint, bool $reauthenticate = false): array
    {
        $endpoint = $this->provider->metadata()['authorization_endpoint'];
        $pending = ['state' => self::random(32), 'nonce' => self::random(32), 'verifier' => self::random(48)];
        $params = [
            'response_type' => 'code',
            'client_id' => $this->config->clientId(),
            'redirect_uri' => $this->config->redirectUri(),
            'scope' => $this->config->scopes(),
            'state' => $pending['state'],
            'nonce' => $pending['nonce'],
            'code_challenge' => self::base64url(hash('sha256', $pending['verifier'], true)),
            'code_challenge_method' => 'S256',
        ];
        if ($loginHint !== null) {
            $params['login_hint'] = $loginHint;
        }
        if ($reauthenticate) {
            $params['prompt'] = 'login';
        }
        if ($this->config->maxAge() !== null) {
            $params['max_age'] = $this->config->maxAge();
        }
        $url = $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return [$url, $pending];
    }

    /**
     * The callback's query, for the sign-in it answers (its state already
     * matched, see take()): the IdP's error, or the code, exchanged and its
     * ID token validated. The admin claim is read from userinfo when the
     * token doesn't carry it.
     * @param array{state:string,nonce:string,verifier:string} $pending
     * @return array{0:Claims,1:string} the claims, and the ID token (for logout)
     * @throws OidcRefusal
     * @throws OidcException the IdP's keys or userinfo couldn't be fetched
     */
    public function finish(array $pending, array $query): array
    {
        if (isset($query['error'])) {
            throw new OidcRefusal('idp_error', sprintf('The IdP answered %s: %s',
                self::text($query['error']), self::text($query['error_description'] ?? '')));
        }
        $code = $query['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new OidcRefusal('token_exchange', 'No code in the callback');
        }

        $tokens = $this->exchange($code, $pending['verifier']);
        $claims = (new IdTokenValidator($this->config, $this->provider))->validate($tokens['id_token'], $pending['nonce']);
        if (!$claims->hasInToken($this->config->adminClaim()) && is_string($tokens['access_token'] ?? null)) {
            $userinfo = $this->provider->userinfo($tokens['access_token']);
            if ($userinfo !== null) {
                $claims = $claims->withUserinfo($userinfo);
            }
        }
        return [$claims, $tokens['id_token']];
    }

    /** @return array the token response, with a string id_token */
    private function exchange(string $code, string $verifier): array
    {
        try {
            $response = $this->provider->http()->postForm($this->provider->metadata()['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->config->redirectUri(),
                'code_verifier' => $verifier,
            ], [$this->config->clientId(), $this->config->clientSecret()]);
        } catch (OidcException $e) {
            throw new OidcRefusal('token_exchange', 'Token request failed: ' . $e->getMessage(), $e);
        }
        $tokens = $response->json();
        if ($response->status !== 200) {
            throw new OidcRefusal('token_exchange', sprintf('The token endpoint answered HTTP %d: %s %s', $response->status,
                self::text($tokens['error'] ?? ''), self::text($tokens['error_description'] ?? '')));
        }
        if (!is_string($tokens['id_token'] ?? null)) {
            throw new OidcRefusal('token_exchange', 'The token answer has no id_token');
        }
        return $tokens;
    }

    /**
     * Keep a started sign-in in the session's store, with what the
     * callback does afterwards ($redirect)
     */
    public static function remember(array &$store, array $pending, string $redirect): void
    {
        $store = self::current($store);
        $store[$pending['state']] = $pending + ['redirect' => $redirect, 'started' => time()];
        $store = array_slice($store, -self::MAX_PENDING, null, true);
    }

    /**
     * The started sign-in a callback's state names, removed from the store
     * (a replayed callback finds nothing); null when none matches or it
     * has expired
     */
    public static function take(array &$store, mixed $state): ?array
    {
        $store = self::current($store);
        if (!is_string($state) || !isset($store[$state])) {
            return null;
        }
        $pending = $store[$state];
        unset($store[$state]);
        return $pending;
    }

    /** The sign-ins started less than PENDING_TTL ago */
    private static function current(array $store): array
    {
        return array_filter($store, fn($p) => is_array($p) && time() - ($p['started'] ?? 0) <= self::PENDING_TTL);
    }

    private static function random(int $bytes): string
    {
        return self::base64url(random_bytes($bytes));
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** IdP text for the log: printable, short */
    private static function text(mixed $value): string
    {
        return substr(preg_replace('/[^\x20-\x7E]/', '?', is_scalar($value) ? (string)$value : (string)json_encode($value)), 0, 200);
    }
}
