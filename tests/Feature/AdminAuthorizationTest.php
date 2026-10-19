<?php
namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

final class AdminAuthorizationTest extends FeatureTestCase
{
    public static function adminActions(): array
    {
        $actions = ['listUsers', 'addUserToGroup', 'setAccessRight', 'deleteUser', 'lockUser', 'invite',
            'listGroupPermissions', 'listGroups', 'createGroupIfNotExists', 'removeAccessRight',
            'removeUserFromGroup', 'resendInvite', 'listGroupsWithDetails', 'deleteGroup',
            // moved to the file manager: no longer reachable through /admin
            'createFolder', 'deleteItem'];
        return array_combine($actions, array_map(fn($a) => [$a], $actions));
    }

    #[DataProvider('adminActions')]
    public function testNonAdminGetsForbiddenOnEveryAdminAction(string $action): void
    {
        $bob = $this->bobClient();
        $response = $bob->postWithCsrf('admin', [
            'a' => $action, 'email' => TestServer::BOB, 'group' => 'admin', 'name' => 'admin', 'cwd' => '/',
        ]);

        $this->assertSame(403, $response->status);
        $this->assertFalse($this->server->isAdmin(TestServer::BOB), 'bob must not have become admin');
        $denied = $this->server->logEntries('admin_access_denied');
        $this->assertSame($action, end($denied)['requested_action']);
    }

    public function testNonAdminCannotOpenAdminPage(): void
    {
        $this->assertSame(403, $this->bobClient()->get('admin')->status);
    }

    public function testRegularFileOperationsGoThroughTheFileManager(): void
    {
        $this->server->grantRootAccess(TestServer::BOB);
        $bob = $this->bobClient();

        $created = $bob->postWithCsrf('', ['a' => 'createFolder', 'cwd' => '/', 'name' => 'case-001']);
        $this->assertTrue($created->json()['success']);

        $id = $this->server->db()->query("SELECT id FROM directories WHERE path = '/case-001'")->fetchColumn();
        $deleted = $bob->postWithCsrf('', ['a' => 'deleteItem', 'type' => 'd', 'id' => $id]);
        $this->assertTrue($deleted->json()['success']);

        $this->assertSame(400, $bob->postWithCsrf('', ['a' => 'bogus'])->status);
    }

    public function testFileOperationsStillCheckDirectoryRights(): void
    {
        // bob has no group: no write access to '/'
        $response = $this->bobClient()->postWithCsrf('', ['a' => 'createFolder', 'cwd' => '/', 'name' => 'nope']);
        $this->assertFalse($response->json()['success']);
    }

    public function testAdminAccess(): void
    {
        $admin = $this->adminClient();
        $this->assertSame(200, $admin->get('admin')->status);
        $this->assertStringContainsString(TestServer::BOB, $admin->postWithCsrf('admin', ['a' => 'listUsers'])->body);
    }

    public function testAdminSafeguards(): void
    {
        $admin = $this->adminClient();
        $post = fn(array $form) => $admin->postWithCsrf('admin', $form)->json()['message'] ?? '';

        $this->assertStringContainsString('own account', $post(['a' => 'lockUser', 'email' => TestServer::ADMIN]));
        $this->assertStringContainsString('own account', $post(['a' => 'deleteUser', 'email' => TestServer::ADMIN]));
        $this->assertStringContainsString('cannot be deleted', $post(['a' => 'deleteGroup', 'name' => 'admin']));
        $this->assertStringContainsString('last active administrator', $post(['a' => 'removeUserFromGroup', 'email' => TestServer::ADMIN, 'group' => 'admin']));
    }

    public function testRevocationIsImmediate(): void
    {
        $admin = $this->adminClient();
        $bob = $this->bobClient();

        $this->assertTrue($admin->postWithCsrf('admin', ['a' => 'addUserToGroup', 'email' => TestServer::BOB, 'group' => 'admin'])->json()['success']);
        $this->assertSame(200, $bob->postWithCsrf('admin', ['a' => 'listUsers'])->status, 'bob is now admin');

        $this->assertTrue($admin->postWithCsrf('admin', ['a' => 'removeUserFromGroup', 'email' => TestServer::BOB, 'group' => 'admin'])->json()['success']);
        $this->assertSame(403, $bob->postWithCsrf('admin', ['a' => 'listUsers'])->status, 'no session cache of admin status');
    }

    public function testDefaultAdminCredentialsDoNotWork(): void
    {
        $this->assertFalse($this->client()->login('admin', 'admin')->json()['success']);
    }
}

