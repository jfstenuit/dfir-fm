<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;
use Tests\Support\HttpResponse;
use Tests\Support\TestServer;

/**
 * DFM-04, D23, D24: the invitation link only shows a form; the POST sets a
 * password, uses up the token and logs in. One password rule (D26) for the
 * form and the profile dialog.
 */
final class InvitationLinkTest extends FeatureTestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';
    private const PASSWORD = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->issueToken('+1 hour');
        $this->server->db()->exec("INSERT INTO directories (name, path, parent_id, created_by) VALUES ('case-a', '/case-a', 1, 1)");
    }

    /** As AccessMiddleware::generateTokenForUser() writes it: PHP's local time */
    private function issueToken(string $expiry, string $token = self::TOKEN): void
    {
        $stmt = $this->server->db()->prepare("UPDATE users SET invitation_token = :t, token_expiry = :e WHERE username = :u");
        $stmt->execute([':t' => $token, ':e' => date('Y-m-d H:i:s', strtotime($expiry)), ':u' => TestServer::BOB]);
    }

    private function link(string $token = self::TOKEN, string $email = TestServer::BOB, string $directory = '/case-a'): string
    {
        return 'login?' . http_build_query(['email' => $email, 'token' => $token, 'directory' => $directory]);
    }

    private function setPassword(HttpClient $client, string $password = self::PASSWORD, string $token = self::TOKEN,
                                 string $directory = '/case-a'): HttpResponse
    {
        return $client->postWithCsrf('login', ['action' => 'setPassword', 'email' => TestServer::BOB, 'token' => $token,
                                               'directory' => $directory, 'password' => $password], $this->link($token));
    }

    private function storedToken(): ?string
    {
        $value = $this->server->db()->query("SELECT invitation_token FROM users WHERE username = 'bob@test.local'")->fetchColumn();
        return $value === false ? null : $value;
    }

    private function lastFailureCause(): string
    {
        $failures = $this->server->logEntries('login_token_failure');
        $this->assertNotEmpty($failures);
        return end($failures)['cause'];
    }

    public function testTheLinkShowsAFormAndDoesNotLogIn(): void
    {
        $invitee = $this->client();
        $page = $invitee->get($this->link());

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('id="setPasswordForm"', $page->body);
        $this->assertSame('no-referrer', $page->header('Referrer-Policy'));
        $this->assertSame('no-store', $page->header('Cache-Control'));

        $this->assertSame(302, $invitee->get('')->status, 'not logged in');
        $this->assertSame(self::TOKEN, $this->storedToken(), 'opening the link does not use up the token');
        $this->assertSame([], $this->server->logEntries('login_token_success'));
    }

    public function testSettingThePasswordLogsInOnce(): void
    {
        $invitee = $this->client();
        $answer = $this->setPassword($invitee);

        $this->assertTrue($answer->json()['success'], $answer->body);
        $this->assertSame('/?p=%2Fcase-a', $answer->json()['redirect']);
        $this->assertSame('no-store', $answer->header('Cache-Control'));
        $this->assertSame(200, $invitee->get('')->status, 'logged in');
        $this->assertNull($this->storedToken(), 'single use');

        $this->assertSame('invitation', $this->server->logEntries('password_changed')[0]['via']);
        $success = $this->server->logEntries('login_token_success');
        $this->assertCount(1, $success);
        $this->assertSame(TestServer::BOB, $success[0]['username'], 'logged after authentication');

        // The same link again: refused, in the form and on the page
        $again = $this->setPassword($this->client(), 'another password 2');
        $this->assertFalse($again->json()['success']);
        $this->assertSame('used', $this->lastFailureCause());
        $this->assertSame(400, $this->client()->get($this->link())->status);
        $this->assertTrue($this->client()->login(TestServer::BOB, self::PASSWORD)->json()['success'], 'the first password stays');
    }

    public function testThePasswordIsNotTrimmed(): void
    {
        $this->assertTrue($this->setPassword($this->client(), '  spaced password  ')->json()['success']);
        $this->assertTrue($this->client()->login(TestServer::BOB, '  spaced password  ')->json()['success']);
        $this->assertFalse($this->client()->login(TestServer::BOB, 'spaced password')->json()['success']);
    }

    public function testExpiredWrongAndUnknownTokensAreRefused(): void
    {
        $this->assertSame(400, $this->client()->get($this->link('ffffffffffffffffffffffffffffffff'))->status);
        $this->assertSame('wrong', $this->lastFailureCause());
        $this->assertFalse($this->setPassword($this->client(), self::PASSWORD, 'ffffffffffffffffffffffffffffffff')->json()['success']);
        $this->assertSame('wrong', $this->lastFailureCause());

        $this->assertSame(400, $this->client()->get($this->link(self::TOKEN, 'nobody@test.local'))->status);
        $this->assertSame('unknown', $this->lastFailureCause());

        $this->issueToken('-1 minute');
        $page = $this->client()->get($this->link());
        $this->assertSame(400, $page->status);
        $this->assertStringContainsString('Ask the person who invited you for a new invitation', $page->body);
        $this->assertStringNotContainsString(self::TOKEN, $page->body);
        $this->assertSame('expired', $this->lastFailureCause());
        $this->assertFalse($this->setPassword($this->client())->json()['success']);
        $this->assertSame('expired', $this->lastFailureCause());

        $this->assertSame([], $this->server->logEntries('login_token_success'));
        $this->assertSame([], $this->server->logEntries('password_changed'));
    }

    public function testCsrfTokenIsRequired(): void
    {
        $refused = $this->client()->post('login', ['action' => 'setPassword', 'email' => TestServer::BOB,
                                                   'token' => self::TOKEN, 'password' => self::PASSWORD]);
        $this->assertSame(403, $refused->status);
        $this->assertSame(self::TOKEN, $this->storedToken());
    }

    public function testShortPasswordIsRefusedAndTheTokenKept(): void
    {
        $answer = $this->setPassword($this->client(), 'eleven-char');
        $this->assertFalse($answer->json()['success']);
        $this->assertStringContainsString('at least 12 characters', $answer->json()['message']);
        $this->assertSame(self::TOKEN, $this->storedToken(), 'the invitee can try again');

        $this->assertTrue($this->setPassword($this->client(), 'twelve-chars')->json()['success']);
    }

    public function testOtherSessionsEnd(): void
    {
        $elsewhere = $this->bobClient();
        $this->assertTrue($this->setPassword($this->client())->json()['success']);
        $this->assertSame(302, $elsewhere->get('')->status, 'an invitation sets a new password: older sessions end');
    }

    public function testMissingFolderRedirectsToTheRoot(): void
    {
        $answer = $this->setPassword($this->client(), self::PASSWORD, self::TOKEN, '/gone');
        $this->assertSame('/?p=%2F', $answer->json()['redirect']);
    }

    public function testProfileAppliesTheSameRule(): void
    {
        $bob = $this->bobClient();
        $short = $bob->postWithCsrf('profile', ['a' => 'updatePassword', 'password' => 'short-pass']);
        $this->assertFalse($short->json()['success']);
        $this->assertStringContainsString('at least 12 characters', $short->json()['message']);

        $this->assertTrue($bob->postWithCsrf('profile', ['a' => 'updatePassword', 'password' => ' twelve chars '])->json()['success']);
        $this->assertSame('profile', $this->server->logEntries('password_changed')[0]['via']);
        $this->assertTrue($this->client()->login(TestServer::BOB, ' twelve chars ')->json()['success']);
    }
}
