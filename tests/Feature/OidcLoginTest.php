<?php
namespace Tests\Feature;

use Controllers\OidcController;
use Tests\Support\MockOidcTestCase;
use Tests\Support\HttpClient;
use Tests\Support\HttpResponse;
use Tests\Support\MockOidcServer;

/**
 * GET /login/oidc and /login/oidc/callback (#9, Phase 16 step 6), against
 * mock-oidc (see MockOidcTestCase)
 */
final class OidcLoginTest extends MockOidcTestCase
{
    private const CAROL = 'carol@oidc.test';

    public function testAuthorizationRequest(): void
    {
        $url = $this->start($this->client(), ['login_hint' => self::CAROL]);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('code', $params['response_type']);
        $this->assertSame(MockOidcServer::CLIENT_ID, $params['client_id']);
        $this->assertSame(MockOidcServer::REDIRECT_URI, $params['redirect_uri']);
        $this->assertSame('openid email profile', $params['scope']);
        $this->assertSame('S256', $params['code_challenge_method']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $params['code_challenge']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $params['state']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $params['nonce']);
        $this->assertNotSame($params['state'], $params['nonce']);
        $this->assertSame(self::CAROL, $params['login_hint']);
        $this->assertArrayNotHasKey('max_age', $params);
        $this->assertSame([self::CAROL], array_column($this->server->logEntries('login_oidc_start'), 'login_hint'));

        // Out of the domain, or not an address: not passed on
        foreach (['dave@example.com', 'carol@sub.oidc.test', 'not an address@oidc.test', ''] as $hint) {
            parse_str((string)parse_url($this->start($this->client(), ['login_hint' => $hint]), PHP_URL_QUERY), $params);
            $this->assertArrayNotHasKey('login_hint', $params, $hint);
        }
    }

    public function testMaxAgeSent(): void
    {
        $this->restartWith($this->idp->env(['oidc_max_age' => '300']));
        parse_str((string)parse_url($this->start($this->client()), PHP_URL_QUERY), $params);
        $this->assertSame('300', $params['max_age']);
    }

    public function testFirstSignInAsAdmin(): void
    {
        $client = $this->client();
        $response = $this->signIn($client, 'sub-carol', self::claims(self::CAROL, admin: true), ['redirect' => '/admin']);
        $this->assertSame(302, $response->status, $response->body);
        $this->assertSame('/admin', $response->header('location'));

        $cookie = (string)$response->setCookie(OidcController::EMAIL_COOKIE);
        $this->assertStringStartsWith(OidcController::EMAIL_COOKIE . '=' . rawurlencode(self::CAROL) . ';', $cookie);
        $this->assertStringContainsString('Max-Age=' . 365 * 86400, $cookie);
        $this->assertStringContainsString('path=/;', $cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);
        $this->assertStringNotContainsString('secure', strtolower($cookie), 'plain HTTP: the session cookie rule');

        $this->assertSame(200, $client->get('admin')->status, 'signed in, as an admin');
        $this->assertTrue($this->server->isAdmin(self::CAROL));
        $row = $this->server->db()->query("SELECT * FROM users WHERE username = '" . self::CAROL . "'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['oidc', $this->idp->issuer, 'sub-carol', 'Carol Example'],
            [$row['auth_source'], $row['oidc_issuer'], $row['oidc_subject'], $row['display_name']]);
        $this->assertNotNull($row['last_login_at']);

        $success = $this->server->logEntries('login_oidc_success');
        $this->assertCount(1, $success);
        $this->assertSame([$this->idp->issuer, 'sub-carol', self::CAROL], [$success[0]['iss'], $success[0]['sub'], $success[0]['username']]);
        $this->assertTrue($this->server->logEntries('oidc_user_provisioned')[0]['admin']);
    }

    public function testNonAdminSignsInWithoutRights(): void
    {
        $client = $this->client();
        $response = $this->signIn($client, 'sub-carol', self::claims(self::CAROL));
        $this->assertSame(302, $response->status, $response->body);
        $this->assertSame('/', $response->header('location'), 'no redirect asked: the root');
        $this->assertSame(200, $client->get('/')->status);
        $this->assertSame(403, $client->get('admin')->status);
        $this->assertFalse($this->server->isAdmin(self::CAROL));
        $this->assertFalse($this->server->logEntries('oidc_user_provisioned')[0]['admin']);
    }

    public function testRedirectSanitized(): void
    {
        // Another host: only its path is kept, if it's one of ours
        foreach (['https://evil.example/admin' => '/admin', '//evil.example/x' => '/', '/files/../admin' => '/'] as $redirect => $expected) {
            $response = $this->signIn($this->client(), 'sub-carol', self::claims(self::CAROL), ['redirect' => $redirect]);
            $this->assertSame($expected, $response->header('location'), $redirect);
        }
    }

    public function testMissingOrWrongState(): void
    {
        $client = $this->client();
        $answer = $this->idp->login($this->start($client), 'sub-carol', self::claims(self::CAROL));

        $this->assertFailurePage($client->get('login/oidc/callback', ['query' => ['code' => $answer['code']]]), 'state');
        $this->assertFailurePage($client->get('login/oidc/callback', ['query' => ['code' => $answer['code'], 'state' => 'forged']]), 'state');
        // Another browser (session) can't use this one's state
        $this->assertFailurePage($this->client()->get('login/oidc/callback', ['query' => $answer]), 'state');
        $this->assertSame(0, (int)$this->server->db()->query("SELECT COUNT(*) FROM users WHERE username = '" . self::CAROL . "'")->fetchColumn());
    }

    public function testReplayedCallback(): void
    {
        $client = $this->client();
        $answer = $this->idp->login($this->start($client), 'sub-carol', self::claims(self::CAROL));
        $query = ['code' => $answer['code'], 'state' => $answer['state']];
        $this->assertSame(302, $client->get('login/oidc/callback', ['query' => $query])->status);
        $this->assertFailurePage($client->get('login/oidc/callback', ['query' => $query]), 'state');
    }

    public function testSeveralTabs(): void
    {
        $client = $this->client();
        $first = $this->start($client);
        $second = $this->start($client);
        $answer = $this->idp->login($first, 'sub-carol', self::claims(self::CAROL));
        $response = $client->get('login/oidc/callback', ['query' => ['code' => $answer['code'], 'state' => $answer['state']]]);
        $this->assertSame(302, $response->status, 'the first tab\'s sign-in still completes');
        $this->assertNotSame($first, $second);
    }

    public function testIdpError(): void
    {
        $client = $this->client();
        parse_str((string)parse_url($this->start($client), PHP_URL_QUERY), $params);
        $query = ['error' => 'access_denied', 'error_description' => 'User not assigned', 'state' => $params['state']];
        $this->assertFailurePage($client->get('login/oidc/callback', ['query' => $query]), 'idp_error');
        $this->assertStringContainsString('access_denied: User not assigned', $this->server->logEntries('login_oidc_failure')[0]['detail']);
        // The state is used up
        $this->assertFailurePage($client->get('login/oidc/callback', ['query' => $query]), 'state');
    }

    public function testRefusedIdentity(): void
    {
        $cases = [
            'foreign_domain' => self::claims('dave@example.com'),
            'unverified_email' => self::claims(self::CAROL, extra: ['email_verified' => false]),
            'claims' => ['groups' => ['staff']],
            'claim_overage' => ['email' => self::CAROL, 'email_verified' => true, '_claim_names' => ['groups' => 'src1']],
        ];
        foreach ($cases as $cause => $claims) {
            $this->assertFailurePage($this->signIn($this->client(), "sub-$cause", $claims), $cause);
        }
        $this->assertSame(2, (int)$this->server->db()->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'no account created');
    }

    public function testLockedAccountRefused(): void
    {
        $this->assertSame(302, $this->signIn($this->client(), 'sub-carol', self::claims(self::CAROL))->status);
        $this->server->db()->exec("UPDATE users SET locked_at = CURRENT_TIMESTAMP WHERE username = '" . self::CAROL . "'");
        $this->assertFailurePage($this->signIn($this->client(), 'sub-carol', self::claims(self::CAROL)), 'locked');
    }

    public function testExpiredToken(): void
    {
        // Issued two hours ago, for an hour
        $this->idp->freezeClock(new \DateTimeImmutable('-2 hours'));
        $this->assertFailurePage($this->signIn($this->client(), 'sub-carol', self::claims(self::CAROL)), 'claims');
        $this->assertStringContainsString('validity', $this->server->logEntries('login_oidc_failure')[0]['detail']);
    }

    public function testCodeExchangeRefused(): void
    {
        $client = $this->client();
        parse_str((string)parse_url($this->start($client), PHP_URL_QUERY), $params);
        $response = $client->get('login/oidc/callback', ['query' => ['code' => 'not-a-code', 'state' => $params['state']]]);
        $this->assertFailurePage($response, 'token_exchange');
        $this->assertStringContainsString('invalid_grant', $this->server->logEntries('login_oidc_failure')[0]['detail']);
    }

    public function testIdpUnreachable(): void
    {
        $this->restartWith($this->idp->env(['oidc_issuer' => 'https://127.0.0.1:' . \Tests\Support\TestServer::freePort() . '/' . MockOidcServer::ISSUER_ID]));
        $this->assertFailurePage($this->client()->get('login/oidc'), 'provider', 502);
        $this->assertSame([], $this->server->logEntries('login_oidc_start'));
    }

    public function testSessionAfterSignIn(): void
    {
        $client = $this->client();
        $client->get('login'); // a session before the sign-in
        $before = $client->cookies['PHPSESSID'] ?? null;
        $this->assertNotNull($before);
        $this->signIn($client, 'sub-carol', self::claims(self::CAROL));
        $this->assertNotSame($before, $client->cookies['PHPSESSID'], 'new session ID (fixation)');

        // Revocation applies as for any account
        $this->server->db()->exec("UPDATE users SET session_version = session_version + 1 WHERE username = '" . self::CAROL . "'");
        $this->assertSame(302, $client->get('/')->status);
        $this->assertSame('revoked', $this->server->logEntries('session_revoked')[0]['cause']);
    }

    public function testNoPasswordChange(): void
    {
        $client = $this->client();
        $this->signIn($client, 'sub-carol', self::claims(self::CAROL, admin: true));
        foreach (['/', 'admin'] as $page) {
            $body = $client->get($page)->body;
            $this->assertStringNotContainsString('profileModal', $body, $page);
            $this->assertStringNotContainsString('Change Password', $body, $page);
        }
        $response = $client->postWithCsrf('profile', ['a' => 'updatePassword', 'password' => 'a-long-enough-password']);
        $this->assertSame(403, $response->status);
        $this->assertFalse($response->json()['success']);
        $this->assertNull($this->server->db()->query("SELECT password FROM users WHERE username = '" . self::CAROL . "'")->fetchColumn());
        $this->assertSame('oidc_account', $this->server->logEntries('password_change_refused')[0]['cause']);

        $this->assertStringContainsString('Change Password', $this->bobClient()->get('/')->body, 'password accounts keep it');
    }

    public function testLogoutAtTheIdp(): void
    {
        $client = $this->client();
        $this->signIn($client, 'sub-carol', self::claims(self::CAROL));
        $response = $client->get('logout');
        $this->assertSame(302, $response->status);
        $location = (string)$response->header('location');
        $this->assertStringStartsWith($this->idp->issuer . '/endsession?', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame('https://dfirfm.test/login?signed_out', $params['post_logout_redirect_uri'], 'from oidc_redirect_uri');
        $this->assertSame(MockOidcServer::CLIENT_ID, $params['client_id']);
        $payload = json_decode(base64_decode(strtr(explode('.', $params['id_token_hint'])[1], '-_', '+/')), true);
        $this->assertSame('sub-carol', $payload['sub'], 'the ID token of the sign-in');

        $this->assertNull($response->setCookie(OidcController::EMAIL_COOKIE), 'the remembered e-mail is kept');
        $this->assertSame(302, $client->get('/')->status, 'the local session has ended');
        $entries = $this->server->logEntries('logout_oidc');
        $this->assertCount(1, $entries);
        $this->assertSame([true, self::CAROL], [$entries[0]['idp_logout'], $entries[0]['username']]);

        // The IdP accepts it, and comes back to the signed-out page
        $this->assertSame('https://dfirfm.test/login?signed_out', $this->idp->endSession($location)['location']);
    }

    /**
     * prompt=login when the IdP may still have the previous user's session
     * (an IdP that keeps it would sign them in again), not after the IdP's
     * own logout (authentik would then ask twice)
     */
    public function testReauthentication(): void
    {
        $this->restartWith($this->idp->env(['max_login_attempts' => '20'])); // over 10 sign-ins started
        $prompt = function (HttpClient $client): ?string {
            parse_str((string)parse_url($this->start($client), PHP_URL_QUERY), $params);
            return $params['prompt'] ?? null;
        };
        $this->assertNull($prompt($this->client()), 'a first sign-in uses the IdP session (SSO)');

        $cases = [
            'back from the IdP logout' => [['login?signed_out'], null],
            'then another e-mail' => [['login?signed_out', 'login?other'], null],
            'local-only logout' => [['login?signed_out=local'], 'login'],
            'another e-mail, no logout' => [['login?other'], 'login'],
        ];
        foreach ($cases as $case => [$pages, $expected]) {
            $client = $this->client();
            foreach ($pages as $page) {
                $this->assertSame(200, $client->get($page)->status, $page);
            }
            $this->assertSame($expected, $prompt($client), $case);
            $this->assertNull($prompt($client), "$case: once");
        }
        $this->assertSame([false, false, false, false, false, true, false, true, false],
            array_column($this->server->logEntries('login_oidc_start'), 'reauthenticate'));

        // The whole round: sign in, log out at the IdP, back to the signed-out page, sign in
        $client = $this->client();
        $this->signIn($client, 'sub-carol', self::claims(self::CAROL));
        $logout = $client->get('logout')->header('location');
        $this->assertSame('https://dfirfm.test/login?signed_out', $this->idp->endSession($logout)['location']);
        $client->get('login?signed_out');
        $this->assertNull($prompt($client));
    }

    public function testPasswordLogoutUnchanged(): void
    {
        $response = $this->bobClient()->get('logout');
        $this->assertSame('/', $response->header('location'));
        $this->assertCount(1, $this->server->logEntries('logout'));
        $this->assertSame([], $this->server->logEntries('logout_oidc'));
    }

    /** D35: only an OIDC admin can see it, as a local admin outside the domain is the break-glass account */
    public function testNoBreakGlassWarning(): void
    {
        $client = $this->client();
        $this->signIn($client, 'sub-carol', self::claims(self::CAROL, admin: true));
        $this->assertStringNotContainsString('breakGlassWarning', $client->get('admin')->body, 'admin@test.local');

        $this->server->db()->exec("UPDATE users SET locked_at = CURRENT_TIMESTAMP WHERE username = '" . \Tests\Support\TestServer::ADMIN . "'");
        $page = $client->get('admin')->body;
        $this->assertStringContainsString('id="breakGlassWarning"', $page);
        $this->assertStringContainsString('No local administrator outside the SSO domain', $page);
    }

    public function testThrottled(): void
    {
        $this->restartWith($this->idp->env(['max_login_attempts' => '2']));
        $client = $this->client();
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(302, $client->get('login/oidc')->status, "attempt $i");
        }
        $response = $client->get('login/oidc');
        $this->assertSame(429, $response->status);
        $this->assertNotNull($response->header('retry-after'));
        $this->assertSame('throttled', $this->server->logEntries('login_oidc_failure')[0]['cause']);
    }

    public function testOnlyGet(): void
    {
        $this->assertSame(405, $this->client()->postWithCsrf('login/oidc/callback', [], 'login')->status);
    }
}
