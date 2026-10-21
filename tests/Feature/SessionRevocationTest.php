<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/**
 * DFM-03: locking, deleting, or changing the password of an account ends
 * its open sessions at the next request
 */
final class SessionRevocationTest extends FeatureTestCase
{
    private function assertRevoked(\Tests\Support\HttpClient $client, string $cause): void
    {
        $page = $client->get('');
        $this->assertSame(302, $page->status);
        $this->assertStringStartsWith('login?redirect=', $page->header('Location'));
        $logged = $this->server->logEntries('session_revoked');
        $this->assertSame($cause, end($logged)['cause']);
        $this->assertSame(302, $client->get('')->status, 'the session is gone, not only refused once');
    }

    public function testLockEndsTheSession(): void
    {
        $bob = $this->bobClient();
        $admin = $this->adminClient();
        $this->assertSame(200, $bob->get('')->status);

        $this->assertTrue($admin->postWithCsrf('admin', ['a' => 'lockUser', 'email' => TestServer::BOB])->json()['success']);
        $this->assertRevoked($bob, 'revoked');
        $this->assertSame(200, $admin->get('')->status, "the admin's own session is unaffected");
    }

    public function testDeletionEndsTheSession(): void
    {
        $bob = $this->bobClient();
        $this->assertTrue($this->adminClient()->postWithCsrf('admin', ['a' => 'deleteUser', 'email' => TestServer::BOB])->json()['success']);
        $this->assertRevoked($bob, 'deleted');
    }

    public function testMissingAccountEndsTheSession(): void
    {
        $bob = $this->bobClient();
        $this->server->db()->exec("DELETE FROM users WHERE username = 'bob@test.local'");
        $this->assertRevoked($bob, 'missing');
    }

    public function testPasswordResetEndsTheSession(): void
    {
        $bob = $this->bobClient();
        $this->assertSame(0, $this->server->runCreateAdmin(TestServer::BOB)['exit']);
        $this->assertRevoked($bob, 'revoked');
    }

    public function testPasswordChangeEndsOnlyTheOtherSessions(): void
    {
        $here = $this->bobClient();
        $elsewhere = $this->bobClient();

        $answer = $here->postWithCsrf('profile', ['a' => 'updatePassword', 'password' => 'a-new-password-1']);
        $this->assertTrue($answer->json()['success']);

        $this->assertSame(200, $here->get('')->status, 'the session that changed the password is kept');
        $this->assertRevoked($elsewhere, 'revoked');
        $this->assertTrue($this->client()->login(TestServer::BOB, 'a-new-password-1')->json()['success']);
    }

    /** An invitee logged in through the invitation form keeps the session it opened */
    public function testInvitationSessionIsNotRevoked(): void
    {
        $this->server->db()->exec("UPDATE users SET password = '!', invitation_token = 'abc', token_expiry = '2999-01-01 00:00:00'
                                   WHERE username = 'bob@test.local'");
        $invitee = $this->client();
        $link = 'login?email=' . urlencode(TestServer::BOB) . '&token=abc&directory=/';
        $answer = $invitee->postWithCsrf('login', ['action' => 'setPassword', 'email' => TestServer::BOB, 'token' => 'abc',
                                                   'directory' => '/', 'password' => 'a-new-password-1'], $link);
        $this->assertTrue($answer->json()['success']);

        $this->assertSame(200, $invitee->get('')->status);
        $this->assertSame([], $this->server->logEntries('session_revoked'));
    }
}
