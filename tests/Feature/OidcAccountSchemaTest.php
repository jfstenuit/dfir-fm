<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/**
 * Schema v8 (#9, Phase 16 step 4): the existing code with an OIDC account,
 * which has no password (NULL), as account resolution will create it
 */
final class OidcAccountSchemaTest extends FeatureTestCase
{
    private const OIDC_USER = 'carol@example.com';

    private function addOidcAccount(bool $admin): int
    {
        $db = $this->server->db();
        $db->prepare("INSERT INTO users (username, email, password, auth_source, oidc_issuer, oidc_subject)
                      VALUES (:u, :u, NULL, 'oidc', 'https://idp.example.com/', 'sub-carol')")->execute([':u' => self::OIDC_USER]);
        $id = (int)$db->lastInsertId();
        if ($admin) {
            $db->exec("INSERT INTO user_group (user_id, group_id) SELECT $id, id FROM groups WHERE is_admin = 1");
        }
        return $id;
    }

    public function testNoPasswordLogin(): void
    {
        $this->addOidcAccount(false);
        foreach (['', 'x', '!'] as $password) {
            $answer = $this->client()->login(self::OIDC_USER, $password)->json();
            $this->assertFalse($answer['success'] ?? true, "password '$password'");
        }
        // tearDown: no PHP warning or deprecation (password_verify() with NULL)
    }

    public function testListedAsActive(): void
    {
        $this->addOidcAccount(false);
        $users = $this->adminClient()->postWithCsrf('admin', ['a' => 'listUsers'])->json();
        $carol = array_values(array_filter($users['users'] ?? $users, fn($u) => ($u['email'] ?? $u['username'] ?? null) === self::OIDC_USER));
        $this->assertSame('active', $carol[0]['status'] ?? null, json_encode($users));
    }

    public function testCountedAsAnActiveAdmin(): void
    {
        $this->addOidcAccount(true);
        // With an OIDC admin, the local admin is no longer the last one
        $answer = $this->adminClient()->postWithCsrf('admin', ['a' => 'removeUserFromGroup', 'email' => TestServer::ADMIN, 'group' => 'admin'])->json();
        $this->assertTrue($answer['success'] ?? false, json_encode($answer));
    }

    public function testLockKeepsTheNullPassword(): void
    {
        $id = $this->addOidcAccount(false);
        $admin = $this->adminClient();
        $this->assertTrue($admin->postWithCsrf('admin', ['a' => 'lockUser', 'email' => self::OIDC_USER])->json()['success']);
        $row = $this->server->db()->query("SELECT password, locked_at, oidc_subject FROM users WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['password']);
        $this->assertNotNull($row['locked_at']);
        $this->assertSame('sub-carol', $row['oidc_subject']);

        $this->assertTrue($admin->postWithCsrf('admin', ['a' => 'unlockUser', 'email' => self::OIDC_USER])->json()['success']);
        // Lock of a password account: unchanged
        $this->assertTrue($admin->postWithCsrf('admin', ['a' => 'lockUser', 'email' => TestServer::BOB])->json()['success']);
        $this->assertSame('!', $this->server->db()->query("SELECT password FROM users WHERE username = '" . TestServer::BOB . "'")->fetchColumn());
    }

    public function testDeletion(): void
    {
        $id = $this->addOidcAccount(false);
        $this->assertTrue($this->adminClient()->postWithCsrf('admin', ['a' => 'deleteUser', 'email' => self::OIDC_USER])->json()['success']);
        $row = $this->server->db()->query("SELECT username, deleted_at, auth_source FROM users WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringStartsWith('deleted:', $row['username']);
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame('oidc', $row['auth_source']);
    }
}
