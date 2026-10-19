<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/**
 * Session fixation, cookie attributes, strict mode, CSRF on the login form.
 */
final class SessionSecurityTest extends FeatureTestCase
{
    public function testSessionIdRegeneratedAtLoginAndOldIdsRejected(): void
    {
        $victim = $this->client();
        $forged = 'forged' . bin2hex(random_bytes(12));
        $victim->cookies['PHPSESSID'] = $forged;

        $loginPage = $victim->get('login');
        $preLoginId = $victim->cookies['PHPSESSID'];
        $this->assertNotSame($forged, $preLoginId, 'strict mode: unknown ID replaced');

        $this->assertTrue($victim->post('login', ['action' => 'login', 'username' => TestServer::BOB, 'password' => TestServer::BOB_PASSWORD],
            ['headers' => ['X-CSRF-TOKEN' => $loginPage->csrfToken()]])->json()['success']);
        $postLoginId = $victim->cookies['PHPSESSID'];
        $this->assertNotSame($preLoginId, $postLoginId, 'new session ID at login');

        foreach ([$forged, $preLoginId] as $oldId) {
            $attacker = $this->client();
            $attacker->cookies['PHPSESSID'] = $oldId;
            $this->assertSame(302, $attacker->get('')->status, "old ID $oldId not authenticated");
        }
        $this->assertSame(200, $victim->get('')->status);
    }

    public function testCsrfTokenRotatedAtLogin(): void
    {
        $client = $this->client();
        $preLoginToken = $client->get('login')->csrfToken();
        $client->post('login', ['action' => 'login', 'username' => TestServer::BOB, 'password' => TestServer::BOB_PASSWORD],
            ['headers' => ['X-CSRF-TOKEN' => $preLoginToken]]);

        $newToken = $client->get('')->csrfToken();
        $this->assertNotSame($preLoginToken, $newToken);
        $this->assertSame(403, $client->post('', ['a' => 'bogus'], ['headers' => ['X-CSRF-TOKEN' => $preLoginToken]])->status);
        $this->assertSame(400, $client->post('', ['a' => 'bogus'], ['headers' => ['X-CSRF-TOKEN' => $newToken]])->status);
    }

    public function testCookieAttributesOverPlainHttp(): void
    {
        $cookie = $this->client()->get('login')->setCookie('PHPSESSID');
        $this->assertStringContainsString('; HttpOnly', $cookie);
        $this->assertStringContainsString('; SameSite=Lax', $cookie);
        $this->assertStringContainsString('; path=/', $cookie);
        $this->assertStringNotContainsStringIgnoringCase('; secure', $cookie);
    }

    public function testForgedForwardedProtoIgnoredFromUntrustedPeer(): void
    {
        $cookie = $this->client()->get('login', ['headers' => ['X-Forwarded-Proto' => 'https']])->setCookie('PHPSESSID');
        $this->assertStringNotContainsStringIgnoringCase('; secure', $cookie);
    }

    public function testSecureCookieBehindTrustedHttpsProxy(): void
    {
        $this->restartWith(['trusted_proxies' => '127.0.0.1']);
        $cookie = $this->client()->get('login', ['headers' => ['X-Forwarded-Proto' => 'https']])->setCookie('PHPSESSID');
        $this->assertStringContainsStringIgnoringCase('; secure', $cookie);
    }

    public function testSecureCookieWithForceHttps(): void
    {
        $this->restartWith(['force_https' => 'true']);
        $cookie = $this->client()->get('login')->setCookie('PHPSESSID');
        $this->assertStringContainsStringIgnoringCase('; secure', $cookie);
    }

    public function testLoginFormRequiresCsrfToken(): void
    {
        $client = $this->client();
        $token = $client->get('login')->csrfToken();
        $this->assertSame(64, strlen((string)$token), 'login page carries a token');

        $credentials = ['action' => 'login', 'username' => TestServer::BOB, 'password' => TestServer::BOB_PASSWORD];
        $this->assertSame(403, $client->post('login', $credentials)->status, 'no token');
        $this->assertSame(403, $client->post('login', $credentials, ['headers' => ['X-CSRF-TOKEN' => 'deadbeef']])->status, 'wrong token');
        $this->assertSame(403, $client->post('login', $credentials + ['csrf_token' => ['x']])->status, 'array token');
        $this->assertSame(403, $client->post('login', ['action' => 'checkUser', 'email' => 'a@b'])->status, 'checkUser without token');
        $this->assertSame(200, $client->post('login', ['action' => 'checkUser', 'email' => 'a@b'], ['headers' => ['X-CSRF-TOKEN' => $token]])->status);
        $this->assertTrue($client->post('login', $credentials, ['headers' => ['X-CSRF-TOKEN' => $token]])->json()['success']);
    }

    public function testInvitationLinkLoginStillWorks(): void
    {
        $this->server->db()->exec("UPDATE users SET invitation_token = 'invtok123', token_expiry = datetime('now', '+1 hour') WHERE username = 'bob@test.local'");
        $response = $this->client()->get('login', ['query' => ['email' => TestServer::BOB, 'token' => 'invtok123', 'directory' => '/']]);
        $this->assertSame(302, $response->status);
    }
}
