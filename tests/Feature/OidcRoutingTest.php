<?php
namespace Tests\Feature;

use Controllers\OidcController;
use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;
use Tests\Support\HttpResponse;

/**
 * Domain routing and enforcement (#9, Phase 16 step 7, D31, D32): the
 * login page, checkUser and the password refusals. None of it calls the
 * IdP, so OIDC is configured with one that doesn't exist.
 */
final class OidcRoutingTest extends FeatureTestCase
{
    private const OIDC = [
        'oidc_issuer' => 'https://idp.example.invalid/',
        'oidc_client_id' => 'dfir-fm',
        'oidc_client_secret' => 'S3cr3t-value',
        'oidc_redirect_uri' => 'https://fm.example.com/login/oidc/callback',
        'oidc_email_domain' => 'oidc.test',
        'oidc_admin_claim' => 'groups',
        'oidc_admin_value' => 'dfir-fm-admins',
        'oidc_display_name' => 'Corp SSO',
    ];
    private const CAROL = 'carol@oidc.test';

    protected function serverEnv(): array
    {
        return self::OIDC;
    }

    private function remembering(string $email): HttpClient
    {
        $client = $this->client();
        $client->cookies[OidcController::EMAIL_COOKIE] = rawurlencode($email);
        return $client;
    }

    private function checkUser(string $email): ?string
    {
        return $this->client()->postWithCsrf('login', ['action' => 'checkUser', 'email' => $email], 'login')->json()['login_type'] ?? null;
    }

    private function assertCleared(HttpResponse $response): void
    {
        $cookie = (string)$response->setCookie(OidcController::EMAIL_COOKIE);
        $this->assertStringStartsWith(OidcController::EMAIL_COOKIE . '=deleted;', $cookie, 'cleared');
        $this->assertStringContainsString('Max-Age=0', $cookie);
        $this->assertStringContainsString('path=/;', $cookie);
    }

    private function assertEmailStep(HttpResponse $response): void
    {
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('id="step-login"', $response->body);
        $this->assertStringContainsString('Redirecting to Corp SSO', $response->body);
    }

    private function addPasswordAccount(string $username, string $authSource = 'password'): void
    {
        $this->server->db()->prepare("INSERT INTO users (username, email, password, auth_source) VALUES (:u, :u, :p, :s)")
            ->execute([':u' => $username, ':p' => password_hash('a-long-password', PASSWORD_DEFAULT), ':s' => $authSource]);
    }

    public function testCheckUserByDomain(): void
    {
        foreach ([self::CAROL => 'oidc', 'Carol@OIDC.Test' => 'oidc', 'nobody-yet@oidc.test' => 'oidc',
                  'bob@test.local' => 'password', 'carol@sub.oidc.test' => 'password', 'oidc.test' => 'password', '@oidc.test' => 'password'] as $email => $expected) {
            $this->assertSame($expected, $this->checkUser($email), $email);
        }
        $this->restartWith([]);
        $this->assertSame('password', $this->checkUser(self::CAROL), 'OIDC disabled');
    }

    public function testRememberedEmailGoesToTheIdp(): void
    {
        $response = $this->remembering(self::CAROL)->get('login', ['query' => ['redirect' => '/admin']]);
        $this->assertSame(302, $response->status);
        $this->assertSame('/login/oidc?login_hint=' . rawurlencode(self::CAROL) . '&redirect=%2Fadmin', $response->header('location'));

        $this->assertSame('/login/oidc?login_hint=' . rawurlencode(self::CAROL), $this->remembering(self::CAROL)->get('login')->header('location'));
    }

    public function testForeignOrMalformedCookieCleared(): void
    {
        foreach (['dave@example.com', 'carol@sub.oidc.test', 'not an address', ''] as $value) {
            $response = $this->remembering($value)->get('login');
            $this->assertEmailStep($response);
            $this->assertCleared($response);
        }
        $this->assertNull($this->client()->get('login')->setCookie(OidcController::EMAIL_COOKIE), 'no cookie: nothing to clear');
    }

    public function testCookieIgnoredWhileDisabled(): void
    {
        $this->restartWith([]);
        $response = $this->remembering(self::CAROL)->get('login');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('id="step-login"', $response->body);
        $this->assertStringNotContainsString('step-oidc', $response->body);
        $this->assertCleared($response);
    }

    public function testSignedOut(): void
    {
        $response = $this->remembering(self::CAROL)->get('login?signed_out', ['query' => ['redirect' => '/admin']]);
        $this->assertSame(200, $response->status);
        $this->assertNull($response->header('location'), 'no automatic redirect');
        $this->assertNull($response->setCookie(OidcController::EMAIL_COOKIE), 'kept');
        $this->assertStringContainsString('<h2>Signed out</h2>', $response->body);
        $this->assertStringContainsString('id="oidcBtn" href="/login/oidc?login_hint=' . rawurlencode(self::CAROL) . '&amp;redirect=%2Fadmin">Sign in with Corp SSO</a>', $response->body);
        $this->assertStringContainsString('id="otherEmail" href="login?other=&amp;redirect=%2Fadmin"', $response->body);

        $this->assertStringContainsString('id="oidcBtn" href="/login/oidc"', $this->client()->get('login?signed_out')->body, 'no cookie: no hint');
    }

    public function testOtherEmail(): void
    {
        $response = $this->remembering(self::CAROL)->get('login?other');
        $this->assertEmailStep($response);
        $this->assertCleared($response);
    }

    public function testNoRedirectAfterAFailedSignIn(): void
    {
        $client = $this->remembering(self::CAROL);
        $this->assertSame(403, $client->get('login/oidc/callback', ['query' => ['code' => 'x', 'state' => 'forged']])->status);

        $response = $client->get('login');
        $this->assertSame(200, $response->status, 'the choice, not the IdP');
        $this->assertStringContainsString('<h2>Please authenticate</h2>', $response->body);
        $this->assertStringContainsString('Sign in with Corp SSO', $response->body);
        $this->assertSame(302, $client->get('login')->status, 'once: the next visit goes to the IdP again');
    }

    public function testPasswordRefusedInTheDomain(): void
    {
        // An in-domain account not linked yet, with a password
        $this->addPasswordAccount('dave@oidc.test');
        $answer = $this->client()->login('dave@oidc.test', 'a-long-password')->json();
        $this->assertSame(['success' => false, 'message' => 'Invalid username or password.'], $answer, 'as a wrong password');
        // Unknown in-domain address: the same
        $this->assertSame('Invalid username or password.', $this->client()->login('nobody@oidc.test', 'x')->json()['message']);

        // An OIDC account (linked) outside the domain, with a password somehow set
        $this->addPasswordAccount('erin@example.com', 'oidc');
        $this->assertFalse($this->client()->login('erin@example.com', 'a-long-password')->json()['success']);

        $refusals = $this->server->logEntries('login_password_refused_oidc');
        $this->assertSame([['dave@oidc.test', 'domain'], ['nobody@oidc.test', 'domain'], ['erin@example.com', 'oidc_account']],
            array_map(fn($e) => [$e['target_user'], $e['cause']], $refusals));
        $this->assertSame([], $this->server->logEntries('login_password_success'));

        // Out of the domain: unchanged, the admin included
        $this->adminClient();
        $this->bobClient();
        $this->assertCount(2, $this->server->logEntries('login_password_success'));
    }

    public function testInvitationLinkRefusedInTheDomain(): void
    {
        $this->addPasswordAccount('dave@oidc.test');
        $this->server->db()->exec("UPDATE users SET invitation_token = 'tok', token_expiry = datetime('now', '+1 hour') WHERE username = 'dave@oidc.test'");

        $page = $this->client()->get('login', ['query' => ['email' => 'dave@oidc.test', 'token' => 'tok']]);
        $this->assertSame(400, $page->status);
        $this->assertStringContainsString('This address signs in through Corp SSO', $page->body);

        $answer = $this->client()->postWithCsrf('login', ['action' => 'setPassword', 'email' => 'dave@oidc.test', 'token' => 'tok', 'password' => 'another-long-password'], 'login')->json();
        $this->assertFalse($answer['success']);
        $this->assertStringContainsString('signs in through Corp SSO', $answer['message']);
        $this->assertSame('tok', $this->server->db()->query("SELECT invitation_token FROM users WHERE username = 'dave@oidc.test'")->fetchColumn(), 'not used');
        $this->assertSame(['link', 'invitation'], array_column($this->server->logEntries('login_password_refused_oidc'), 'step'));
    }
}
