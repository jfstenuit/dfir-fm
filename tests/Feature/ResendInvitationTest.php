<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;
use Tests\Support\TestServer;

/**
 * "Resend invitation" (DFM-08, D22): a new link to "/", no group or rights
 * change, open sessions end; refused for locked (unlock first) and deleted
 * accounts. Lock and unlock (users.locked_at). bin/report-domain-groups.php.
 */
final class ResendInvitationTest extends FeatureTestCase
{
    protected function serverEnv(): array
    {
        return ['mail_engine' => 'none'];
    }

    private function resend(HttpClient $admin, string $email): array
    {
        return $admin->postWithCsrf('admin', ['a' => 'resendInvite', 'email' => $email])->json();
    }

    /** @return array<string, string> email => status */
    private function statuses(HttpClient $admin): array
    {
        $users = $admin->postWithCsrf('admin', ['a' => 'listUsers'])->json();
        return array_column($users, 'status', 'email');
    }

    private function tokenOf(string $link): string
    {
        parse_str((string)parse_url($link, PHP_URL_QUERY), $query);
        return $query['token'];
    }

    private function linkPath(string $link): string
    {
        return substr($link, strlen($this->server->url) + 1);
    }

    public function testResendReplacesTheLinkAndEndsSessions(): void
    {
        $bob = $this->bobClient();
        $admin = $this->adminClient();
        $this->server->db()->exec("INSERT INTO directories (name, path, parent_id) VALUES ('case-a', '/case-a', 1)");
        $first = $admin->postWithCsrf('admin', ['a' => 'invite', 'email' => TestServer::BOB, 'cwd' => '/case-a',
                                                'addToDefaultGroup' => 'true'])->json()['link'];

        $answer = $this->resend($admin, TestServer::BOB);
        $this->assertTrue($answer['success']);
        $this->assertStringEndsWith('&directory=%2F', $answer['link']);
        $this->assertNotSame($this->tokenOf($first), $this->tokenOf($answer['link']));

        $this->assertSame(302, $bob->get('')->status, 'open sessions end at the resend');
        $this->assertSame(400, $this->client()->get($this->linkPath($first))->status, 'the old link no longer works');
        $this->assertSame(200, $this->client()->get($this->linkPath($answer['link']))->status);

        // A password reset: the new link sets a new password, the old one stops working
        $invitee = $this->client();
        $set = $invitee->postWithCsrf('login', ['action' => 'setPassword', 'email' => TestServer::BOB, 'token' => $this->tokenOf($answer['link']),
                                                'directory' => '/', 'password' => 'a brand new password'], $this->linkPath($answer['link']));
        $this->assertSame('/?p=%2F', $set->json()['redirect']);
        $this->assertFalse($this->client()->login(TestServer::BOB, TestServer::BOB_PASSWORD)->json()['success']);

        $logged = $this->server->logEntries('invitation_resent');
        $this->assertSame(['target_user' => TestServer::BOB, 'delivery' => 'manual'],
                          array_intersect_key(end($logged), ['target_user' => 1, 'delivery' => 1]));
        $this->assertStringNotContainsString($this->tokenOf($answer['link']), (string)file_get_contents($this->server->root . '/app.log'));
    }

    public function testLockedAccountsNeedAnUnlockFirst(): void
    {
        $admin = $this->adminClient();
        $this->assertSame('active', $this->statuses($admin)[TestServer::BOB]);
        $this->assertTrue($admin->postWithCsrf('admin', ['a' => 'lockUser', 'email' => TestServer::BOB])->json()['success']);
        $this->assertSame('locked', $this->statuses($admin)[TestServer::BOB]);
        $this->assertFalse($admin->postWithCsrf('admin', ['a' => 'lockUser', 'email' => TestServer::BOB])->json()['success'], 'already locked');

        $refused = $this->resend($admin, TestServer::BOB);
        $this->assertFalse($refused['success']);
        $this->assertStringContainsString('unlock it first', $refused['message']);
        $this->assertSame([], $this->server->logEntries('invitation_resent'));

        $this->assertTrue($admin->postWithCsrf('admin', ['a' => 'unlockUser', 'email' => TestServer::BOB])->json()['success']);
        $this->assertSame('invited', $this->statuses($admin)[TestServer::BOB], 'unlocked, without credentials');
        $this->assertFalse($this->client()->login(TestServer::BOB, TestServer::BOB_PASSWORD)->json()['success'], 'the lock removed the password');
        $this->assertCount(1, $this->server->logEntries('user_unlocked'));
        $this->assertFalse($admin->postWithCsrf('admin', ['a' => 'unlockUser', 'email' => TestServer::BOB])->json()['success'], 'not locked any more');

        $this->assertTrue($this->resend($admin, TestServer::BOB)['success']);
    }

    public function testCreateAdminResetLiftsALock(): void
    {
        $this->adminClient()->postWithCsrf('admin', ['a' => 'lockUser', 'email' => TestServer::BOB]);
        $reset = $this->server->runCreateAdmin(TestServer::BOB);
        $this->assertSame(0, $reset['exit']);
        $this->assertSame(1, preg_match('/^Password.*: (\S+)$/m', $reset['stdout'], $m));
        $this->assertTrue($this->client()->login(TestServer::BOB, $m[1])->json()['success'], 'break-glass: the reset password works');
    }

    public function testLockClearsAPendingInvitation(): void
    {
        $admin = $this->adminClient();
        $link = $this->resend($admin, TestServer::BOB)['link'];
        $admin->postWithCsrf('admin', ['a' => 'lockUser', 'email' => TestServer::BOB]);
        $this->assertSame(400, $this->client()->get($this->linkPath($link))->status);
    }

    public function testStatuses(): void
    {
        $admin = $this->adminClient();
        $this->server->db()->exec("INSERT INTO users (username, password, invitation_token, token_expiry) VALUES
                                       ('pending@example.org', '!', 'tok', '2999-01-01 00:00:00'),
                                       ('expired@example.org', '!', 'tok2', '2000-01-01 00:00:00')");
        $users = array_column($admin->postWithCsrf('admin', ['a' => 'listUsers'])->json(), null, 'email');
        $this->assertSame(['invited', true], [$users['pending@example.org']['status'], $users['pending@example.org']['invitation_pending']]);
        $this->assertSame(['invited', false], [$users['expired@example.org']['status'], $users['expired@example.org']['invitation_pending']]);
        $this->assertSame('active', $users[TestServer::ADMIN]['status']);
    }

    public function testDeletedAndUnknownAccountsAreRefused(): void
    {
        $admin = $this->adminClient();
        $admin->postWithCsrf('admin', ['a' => 'deleteUser', 'email' => TestServer::BOB]);
        $this->assertFalse($this->resend($admin, TestServer::BOB)['success']);
        $this->assertFalse($this->resend($admin, 'nobody@example.org')['success']);
        $this->assertSame(0, (int)$this->server->db()->query("SELECT COUNT(*) FROM users WHERE username IN ('bob@test.local', 'nobody@example.org')")->fetchColumn());
    }

    public function testLockedAdminIsNotAnActiveAdmin(): void
    {
        // A second admin, locked: the remaining one is the last active administrator
        $this->assertSame(0, $this->server->runCreateAdmin('second@test.local')['exit']);
        $admin = $this->adminClient();
        $this->assertTrue($admin->postWithCsrf('admin', ['a' => 'lockUser', 'email' => 'second@test.local'])->json()['success']);
        $this->server->db()->exec("UPDATE users SET password = 'not-a-real-hash' WHERE username = 'second@test.local'");

        $other = $this->client();
        $this->assertFalse($other->login('second@test.local', 'whatever')->json()['success']);
        $this->assertStringContainsString('last active administrator',
            $admin->postWithCsrf('admin', ['a' => 'removeUserFromGroup', 'email' => TestServer::ADMIN, 'group' => 'admin'])->json()['message'] ?? '');
    }

    public function testReportDomainGroups(): void
    {
        $db = $this->server->db();
        $db->exec("INSERT INTO directories (name, path, parent_id) VALUES ('case-a', '/case-a', 1), ('case-b', '/case-b', 1)");
        $db->exec("INSERT INTO groups (name) VALUES ('example.org users'), ('/case-a contributors'), ('Analysts')");
        $db->exec("INSERT INTO users (username, password) VALUES ('carol@example.org', '!'), ('dave@example.org', '!')");
        $db->exec("INSERT INTO user_group (user_id, group_id) SELECT u.id, g.id FROM users u, groups g
                   WHERE u.username LIKE '%@example.org' AND g.name = 'example.org users'");
        $db->exec("INSERT INTO access_rights (group_id, directory_id, can_view, can_upload) SELECT g.id, d.id, 1, 1 FROM groups g, directories d
                   WHERE g.name = 'example.org users' AND d.path IN ('/case-a', '/case-b')");

        $result = $this->server->runBin('report-domain-groups.php');
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('Group "example.org users"', $result['stdout']);
        $this->assertStringContainsString('members: carol@example.org, dave@example.org', $result['stdout']);
        $this->assertStringContainsString('rights on /case-b: view upload', $result['stdout']);
        $this->assertStringContainsString('-> review: 2 folders shared by 2 members', $result['stdout']);
        $this->assertStringNotContainsString('contributors', $result['stdout']);
        $this->assertStringNotContainsString('Analysts', $result['stdout']);
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM access_rights ar JOIN groups g ON g.id = ar.group_id WHERE g.name = 'example.org users'")->fetchColumn(),
            'nothing changed');

        $db->exec("DELETE FROM groups WHERE name = 'example.org users'");
        $this->assertStringContainsString('nothing to review', $this->server->runBin('report-domain-groups.php')['stdout']);
    }
}
