<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/** Deletions through the application, with the foreign keys enforced */
final class CascadeDeletionTest extends FeatureTestCase
{
    private function scalar(string $sql): int
    {
        return (int)$this->server->db()->query($sql)->fetchColumn();
    }

    /** D18: a group with members can be deleted; its memberships and rights go with it */
    public function testDeletingAGroupRemovesItsMembershipsAndRights(): void
    {
        $this->server->grantRootAccess(TestServer::BOB);
        $this->server->addFile('evidence.txt', "x\n");
        $this->assertStringContainsString('evidence.txt', $this->bobClient()->get('')->body);

        $answer = $this->adminClient()->postWithCsrf('admin', ['a' => 'deleteGroup', 'name' => 'writers'])->json();
        $this->assertTrue($answer['success']);

        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM groups WHERE name = 'writers'"));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM user_group ug JOIN users u ON u.id = ug.user_id WHERE u.username = 'bob@test.local'"));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM access_rights"));
        $this->assertStringNotContainsString('evidence.txt', $this->bobClient()->get('')->body);

        $logged = $this->server->logEntries('group_deleted');
        $this->assertSame(1, end($logged)['members']);
    }

    public function testDeletingAFolderRemovesItsAccessRights(): void
    {
        $this->server->grantRootAccess(TestServer::BOB);
        $bob = $this->bobClient();
        $this->assertTrue($bob->postWithCsrf('', ['a' => 'createFolder', 'cwd' => '/', 'name' => 'case-001'])->json()['success']);
        $id = $this->scalar("SELECT id FROM directories WHERE path = '/case-001'");
        $this->server->db()->exec("INSERT INTO access_rights (group_id, directory_id, can_view) SELECT id, $id, 1 FROM groups WHERE name = 'writers'");

        $this->assertTrue($bob->postWithCsrf('', ['a' => 'deleteItem', 'type' => 'd', 'id' => $id])->json()['success']);
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM access_rights WHERE directory_id = $id"));
    }

    public function testFolderWithAnUploadInProgressIsNotDeleted(): void
    {
        $this->server->grantRootAccess(TestServer::BOB);
        $bob = $this->bobClient();
        $this->assertTrue($bob->postWithCsrf('', ['a' => 'createFolder', 'cwd' => '/', 'name' => 'case-002'])->json()['success']);
        $id = $this->scalar("SELECT id FROM directories WHERE path = '/case-002'");
        $this->server->db()->exec("INSERT INTO uploads (user_id, client_uuid, directory_id, name, size, chunk_size, chunk_count, staging_key, status)
                                   VALUES (1, 'u1', $id, 'big.bin', 10, 4, 3, '0123456789abcdef0123456789abcdef', 'in_progress')");

        $answer = $bob->postWithCsrf('', ['a' => 'deleteItem', 'type' => 'd', 'id' => $id])->json();
        $this->assertFalse($answer['success']);
        $this->assertStringContainsString('upload', $answer['message']);
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM directories WHERE id = $id"));
    }
}
