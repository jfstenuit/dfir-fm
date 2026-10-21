<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/**
 * Deleted accounts keep their row (D17): their files stay listed, the
 * address is replaced by a pseudonym, and the account can't be used again
 */
final class AccountDeletionTest extends FeatureTestCase
{
    private function userId(string $username): int
    {
        $stmt = $this->server->db()->prepare('SELECT id FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        return (int)$stmt->fetchColumn();
    }

    /** An invitation into a subfolder (D14), mail_engine=none: no mail needed */
    private function invite(string $email): array
    {
        $this->server->db()->exec("INSERT INTO directories (name, path, parent_id)
                                   SELECT 'case-1', '/case-1', 1 WHERE NOT EXISTS (SELECT 1 FROM directories WHERE path = '/case-1')");
        return $this->adminClient()->postWithCsrf('admin', ['a' => 'invite', 'cwd' => '/case-1', 'email' => $email,
                                                            'addToDefaultGroup' => 'true'])->json();
    }

    protected function serverEnv(): array
    {
        return ['mail_engine' => 'none'];
    }

    private function deleteBob(): int
    {
        $id = $this->userId(TestServer::BOB);
        $response = $this->adminClient()->postWithCsrf('admin', ['a' => 'deleteUser', 'email' => TestServer::BOB]);
        $this->assertTrue($response->json()['success']);
        return $id;
    }

    public function testDeletionKeepsTheRowAndPseudonymisesIt(): void
    {
        $this->server->grantRootAccess(TestServer::BOB);
        $this->server->db()->exec("UPDATE users SET invitation_token = 'abc', token_expiry = '2999-01-01 00:00:00'");
        $id = $this->deleteBob();

        $row = $this->server->db()->query("SELECT * FROM users WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $pseudonym = 'deleted:' . hash('sha256', TestServer::BOB) . ":$id";
        $this->assertSame($pseudonym, $row['username']);
        $this->assertSame($pseudonym, $row['email']);
        $this->assertSame('!', $row['password']);
        $this->assertNull($row['invitation_token']);
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame($this->userId(TestServer::ADMIN), (int)$row['deleted_by']);
        $this->assertSame(0, (int)$this->server->db()->query("SELECT COUNT(*) FROM user_group WHERE user_id = $id")->fetchColumn());

        $logged = $this->server->logEntries('user_deleted');
        $this->assertSame($pseudonym, end($logged)['pseudonym']);
        $this->assertSame($id, end($logged)['target_user_id']);
    }

    public function testFilesOfDeletedUserStayListed(): void
    {
        $this->server->addFile('evidence.bin', "data\n", $this->userId(TestServer::BOB));
        $id = $this->deleteBob();

        $body = $this->adminClient()->get('')->body;
        $this->assertStringContainsString('evidence.bin', $body);
        $this->assertStringContainsString("deleted user #$id", $body);
        $this->assertStringNotContainsString(TestServer::BOB, $body);
    }

    public function testDeletedUserIsHiddenFromTheUserList(): void
    {
        $this->deleteBob();
        $list = $this->adminClient()->postWithCsrf('admin', ['a' => 'listUsers'])->body;
        $this->assertStringNotContainsString(TestServer::BOB, $list);
        $this->assertStringNotContainsString('deleted:', $list);
    }

    public function testDeletedAccountCannotLogIn(): void
    {
        $this->server->db()->exec("UPDATE users SET invitation_token = 'abc', token_expiry = '2999-01-01 00:00:00'");
        $this->deleteBob();

        $this->assertFalse($this->client()->login(TestServer::BOB, TestServer::BOB_PASSWORD)->json()['success']);
        $token = $this->client()->get('login?email=' . urlencode(TestServer::BOB) . '&token=abc&directory=/');
        $this->assertSame(400, $token->status);
    }

    public function testDeletedAccountCannotBeChangedByAdminActions(): void
    {
        $id = $this->deleteBob();
        $pseudonym = 'deleted:' . hash('sha256', TestServer::BOB) . ":$id";
        $admin = $this->adminClient();

        foreach (['addUserToGroup', 'lockUser', 'deleteUser'] as $action) {
            $answer = $admin->postWithCsrf('admin', ['a' => $action, 'email' => $pseudonym, 'group' => 'admin'])->json();
            $this->assertFalse($answer['success'], $action);
        }
        $this->assertFalse($this->server->isAdmin($pseudonym));

        $cli = $this->server->runCreateAdmin($pseudonym);
        $this->assertSame(2, $cli['exit']);
        $this->assertStringContainsString('cannot be reactivated', $cli['stderr']);
    }

    public function testReinvitingTheAddressCreatesANewAccount(): void
    {
        $oldId = $this->deleteBob();
        $answer = $this->invite(TestServer::BOB);
        $this->assertTrue($answer['success'], json_encode($answer));

        $newId = $this->userId(TestServer::BOB);
        $this->assertGreaterThan($oldId, $newId);
    }

    public function testInvestigatorFindsTheDeletedAccountsOfAnAddress(): void
    {
        $firstId = $this->deleteBob();
        // The address is deleted a second time, after a new invitation
        $this->invite(TestServer::BOB);
        $secondId = $this->deleteBob();

        $found = $this->server->runBin('find-deleted-user.php', ' Bob@Test.local ');
        $this->assertSame(0, $found['exit'], $found['stderr']);
        $this->assertStringContainsString("user id $firstId: deleted", $found['stdout']);
        $this->assertStringContainsString("user id $secondId: deleted", $found['stdout']);
        $this->assertStringContainsString('by ' . TestServer::ADMIN, $found['stdout']);

        $lookup = $this->server->logEntries('cli_deleted_user_lookup');
        $this->assertSame([$firstId, $secondId], end($lookup)['accounts']);
        $this->assertStringNotContainsString(TestServer::BOB, json_encode(end($lookup)), 'the address itself is not logged');

        $none = $this->server->runBin('find-deleted-user.php', 'nobody@test.local');
        $this->assertSame(1, $none['exit']);
    }

    public function testSafeguardsStillApply(): void
    {
        $admin = $this->adminClient();
        $answer = $admin->postWithCsrf('admin', ['a' => 'deleteUser', 'email' => TestServer::ADMIN])->json();
        $this->assertStringContainsString('own account', $answer['message']);
        $this->assertSame(0, (int)$this->server->db()->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL")->fetchColumn());
    }
}
