<?php
namespace Tests\Unit\Services\Oidc;

use Backends\LogEngineInterface;
use Core\Database;
use Middleware\AccessMiddleware;
use PDO;
use PHPUnit\Framework\TestCase;
use Services\Oidc\AccountResolver;
use Services\Oidc\Claims;
use Services\Oidc\OidcConfig;
use Services\Oidc\OidcRefusal;

/**
 * Account resolution and admin sync (#9, D33, D34), without HTTP: a real
 * SQLite database in a temporary directory
 */
final class AccountResolverTest extends TestCase
{
    private const ISS = 'https://idp.example.com/application/o/dfir-fm/';
    private const ADMIN_GROUP = 1;   // created by Database::initialize()
    private const CASE_GROUP = 2;
    private const CASE_FOLDER = 2;

    private string $dir;
    private PDO $db;
    /** @var list<array{string, array}> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-resolver-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        $this->db = Database::initialize("$this->dir/app.sqlite");
        $this->db->exec("INSERT INTO users (id, username, email, password) VALUES (1, 'admin@example.org', 'admin@example.org', 'hash-admin')");
        $this->db->exec("INSERT INTO groups (id, name) VALUES (2, 'case-1 contributors')");
        $this->db->exec("INSERT INTO user_group VALUES (1, 1)");
        $this->db->exec("INSERT INTO directories (id, name, path, parent_id) VALUES (2, 'case-1', '/case-1', 1)");
        $this->db->exec("INSERT INTO access_rights (group_id, directory_id, can_view, can_upload) VALUES (2, 2, 1, 1)");
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        array_map('unlink', glob("$this->dir/*"));
        rmdir($this->dir);
    }

    public function testProvisionWithTheAdminClaim(): void
    {
        $user = $this->resolve(['groups' => ['staff', 'dfir-fm-admins'], 'name' => "Alice\tExample"]);
        $this->assertSame('alice@example.com', $user['username']);
        $this->assertSame('alice@example.com', $user['email']);
        $this->assertNull($user['password']);
        $this->assertSame(['oidc', self::ISS, 'sub-alice'], [$user['auth_source'], $user['oidc_issuer'], $user['oidc_subject']]);
        $this->assertSame('Alice Example', $user['display_name']);
        $this->assertNotNull($user['last_login_at']);
        $this->assertSame([self::ADMIN_GROUP], $this->groupsOf($user['id']));
        $this->assertSame([['oidc_user_provisioned', ['admin' => true, 'iss' => self::ISS, 'sub' => 'sub-alice', 'username' => 'alice@example.com']]], $this->log);
    }

    public function testProvisionWithoutTheAdminClaimHasNoRights(): void
    {
        $user = $this->resolve(['groups' => ['staff']]);
        $this->assertSame([], $this->groupsOf($user['id']), 'no group, so no right');
        $this->assertFalse(AccessMiddleware::isAdmin($this->db, (int)$user['id']));
        $this->assertSame(['oidc_user_provisioned'], array_column($this->log, 0));
        $this->assertFalse($this->log[0][1]['admin']);
    }

    public function testReLoginFindsTheSameAccount(): void
    {
        $first = $this->resolve();
        $this->log = [];
        $again = $this->resolve(['name' => 'Alice Renamed']);
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame('Alice Renamed', $again['display_name']);
        $this->assertSame([], $this->log, 'nothing changed, nothing logged');
        $this->assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM users")->fetchColumn());
    }

    public function testAddressChange(): void
    {
        $first = $this->resolve();
        $this->log = [];
        $user = $this->resolve(['email' => 'alice.smith@example.com']);
        $this->assertSame($first['id'], $user['id']);
        $this->assertSame(['alice.smith@example.com', 'alice.smith@example.com'], [$user['username'], $user['email']]);
        $this->assertSame([['oidc_address_changed', ['from' => 'alice@example.com', 'to' => 'alice.smith@example.com',
            'iss' => self::ISS, 'sub' => 'sub-alice', 'username' => 'alice.smith@example.com']]], $this->log);
    }

    public function testAddressChangeToAnotherAccountsAddress(): void
    {
        $this->addLocal('bob@example.com');
        $this->resolve();
        $this->assertRefused('username_conflict', ['email' => 'Bob@example.com']);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM users WHERE username = 'alice@example.com'")->fetchColumn(), 'unchanged');
    }

    public function testLinkByAddressKeepsTheRights(): void
    {
        $id = $this->addLocal('Alice@Example.com', 'hash-alice', ['invitation_token' => 'tok', 'token_expiry' => '2999-01-01 00:00:00']);
        $this->db->exec("INSERT INTO user_group VALUES ($id, " . self::CASE_GROUP . ")");
        $this->db->exec("INSERT INTO files (directory_id, name, size, sha256, uploaded_by) VALUES (2, 'a.bin', 1, 'aa', $id)");

        $user = $this->resolve();
        $this->assertSame($id, (int)$user['id'], 'linked, not a new account (address matched in another case)');
        $this->assertSame(['oidc', self::ISS, 'sub-alice'], [$user['auth_source'], $user['oidc_issuer'], $user['oidc_subject']]);
        $this->assertNull($user['password'], 'the password goes');
        $this->assertNull($user['invitation_token']);
        $this->assertNull($user['token_expiry']);
        $this->assertSame(1, (int)$user['session_version'], 'open sessions end');
        $this->assertSame('Alice@Example.com', $user['username'], 'kept as it was; the address check is case-insensitive');
        $this->assertSame([self::CASE_GROUP], $this->groupsOf($id), 'folder rights kept');
        $this->assertSame([['oidc_account_linked', ['previous_auth_source' => 'password', 'had_password' => true,
            'iss' => self::ISS, 'sub' => 'sub-alice', 'username' => 'Alice@Example.com']]], $this->log);

        $this->log = [];
        $this->assertSame($id, (int)$this->resolve()['id'], 'then found by its identity');
        $this->assertSame([], $this->log, 'the case difference alone is no address change');
    }

    public function testLinkOfAnInvitedAccount(): void
    {
        $id = $this->addLocal('alice@example.com', '!', ['invitation_token' => 'tok', 'token_expiry' => '2999-01-01 00:00:00']);
        $this->assertSame($id, (int)$this->resolve()['id']);
        $this->assertFalse($this->log[0][1]['had_password']);
    }

    public function testSubjectConflict(): void
    {
        $id = $this->addLocal('alice@example.com', null, ['auth_source' => 'oidc', 'oidc_issuer' => self::ISS, 'oidc_subject' => 'sub-old']);
        $this->assertRefused('subject_conflict');
        // Same subject, another issuer (an IdP migration): another identity, refused too
        $this->db->exec("UPDATE users SET oidc_subject = 'sub-alice', oidc_issuer = 'https://old-idp.example.com/' WHERE id = $id");
        $this->assertRefused('subject_conflict');
    }

    public function testSeveralAccountsWithTheAddress(): void
    {
        $this->addLocal('alice@example.com');
        $this->addLocal('ALICE@example.com');
        $this->assertRefused('username_conflict');
    }

    public function testAddressChecks(): void
    {
        $this->assertRefused('unverified_email', ['email_verified' => false]);
        $this->assertRefused('unverified_email', ['email_verified' => null]);
        $this->assertRefused('foreign_domain', ['email' => 'guest@partner.test']);
        $this->assertRefused('foreign_domain', ['email' => 'alice@eu.example.com']);
        $this->assertRefused('claims', ['email' => null]);
        // With the check off (Authentik's default email_verified: false)
        $this->assertSame('alice@example.com', $this->resolve(['email_verified' => false], ['oidc_require_email_verified' => 'false'])['username']);
    }

    public function testLockedAndDeletedAccounts(): void
    {
        $id = $this->resolve()['id'];
        $this->db->exec("UPDATE users SET locked_at = '2026-11-01 10:00:00' WHERE id = $id");
        $this->assertRefused('locked', ['groups' => ['dfir-fm-admins']]);
        $this->assertSame([], $this->groupsOf($id), 'no admin rights given while refused');

        $this->db->exec("UPDATE users SET locked_at = NULL, deleted_at = '2026-11-02 10:00:00', username = 'deleted:x:$id', email = 'deleted:x:$id' WHERE id = $id");
        $this->assertRefused('locked');

        // A locked local account with the address: not linked
        $this->addLocal('bob@example.com', '!', ['locked_at' => '2026-11-01 10:00:00']);
        $this->assertRefused('locked', ['sub' => 'sub-bob', 'email' => 'bob@example.com']);
    }

    public function testOverage(): void
    {
        $claims = ['groups' => null, '_claim_names' => ['groups' => 'src1'], '_claim_sources' => ['src1' => ['endpoint' => 'https://graph']]];
        $this->assertRefused('claim_overage', $claims);
        // The groups from userinfo: no overage any more
        $user = $this->resolver()->resolve($this->claims($claims)->withUserinfo(['sub' => 'sub-alice', 'groups' => ['dfir-fm-admins']]));
        $this->assertSame([self::ADMIN_GROUP], $this->groupsOf($user['id']));
    }

    public function testRevokeAndRestore(): void
    {
        $id = $this->resolve(['groups' => ['dfir-fm-admins']])['id'];
        $this->db->exec("INSERT INTO user_group VALUES ($id, " . self::CASE_GROUP . ")");
        $this->log = [];

        $this->resolve(['groups' => ['staff']]);
        $this->assertSame([self::CASE_GROUP], $this->groupsOf($id), 'admin removed, folder rights kept');
        $this->assertSame(['oidc_admin_revoked'], array_column($this->log, 0));

        $this->log = [];
        $this->resolve(['groups' => []]);
        $this->assertSame([], $this->log, 'already not an admin: nothing logged');

        $this->resolve(['groups' => ['dfir-fm-admins']]);
        $this->assertSame([self::ADMIN_GROUP, self::CASE_GROUP], $this->groupsOf($id));
        $this->assertSame(['oidc_admin_restored'], array_column($this->log, 0));
    }

    public function testRevokedFromEveryAdminGroup(): void
    {
        $id = $this->resolve(['groups' => ['dfir-fm-admins']])['id'];
        $this->db->exec("INSERT INTO groups (id, name, is_admin) VALUES (3, 'second admins', 1)");
        $this->db->exec("INSERT INTO user_group VALUES ($id, 3)");
        $this->resolve(['groups' => []]);
        $this->assertSame([], $this->groupsOf($id));
    }

    public function testTheLastActiveAdminRevokedByTheIdpIsNotBlocked(): void
    {
        $id = $this->resolve(['groups' => ['dfir-fm-admins']])['id'];
        $this->db->exec("UPDATE users SET locked_at = '2026-11-01 10:00:00' WHERE id = 1"); // the local admin
        $this->assertSame(1, AccessMiddleware::countActiveAdmins($this->db));

        $this->resolve(['groups' => []]);
        $this->assertSame(0, AccessMiddleware::countActiveAdmins($this->db));
        $this->assertFalse(AccessMiddleware::isAdmin($this->db, (int)$id));
    }

    public function testAdminClaimAsAStringAndNestedPath(): void
    {
        $this->assertSame([self::ADMIN_GROUP], $this->groupsOf($this->resolve(['groups' => 'dfir-fm-admins'])['id']));
        $user = $this->resolve(['sub' => 'sub-bob', 'email' => 'bob@example.com', 'realm_access' => ['roles' => ['dfir-fm-admins']]],
                               ['oidc_admin_claim' => 'realm_access.roles']);
        $this->assertSame([self::ADMIN_GROUP], $this->groupsOf($user['id']));
    }

    public function testARefusalChangesNothing(): void
    {
        $this->addLocal('bob@example.com');
        $before = $this->db->query("SELECT * FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $this->resolve(['groups' => ['dfir-fm-admins']]);
        $this->log = [];
        $this->assertRefused('username_conflict', ['email' => 'bob@example.com', 'groups' => []]);
        $this->assertSame([], $this->log);
        $this->assertSame([self::ADMIN_GROUP], $this->groupsOf(3), 'admin not revoked by a refused login');
        $this->assertFalse($this->db->inTransaction());
        $this->assertCount(count($before) + 1, $this->db->query("SELECT * FROM users")->fetchAll());
    }

    private function resolve(array $claims = [], array $config = []): array
    {
        return $this->resolver($config)->resolve($this->claims($claims));
    }

    private function assertRefused(string $cause, array $claims = [], array $config = []): void
    {
        try {
            $this->resolve($claims, $config);
            $this->fail("accepted, expected $cause");
        } catch (OidcRefusal $e) {
            $this->assertSame($cause, $e->cause, $e->getMessage());
        }
        $this->assertFalse($this->db->inTransaction());
    }

    private function resolver(array $config = []): AccountResolver
    {
        $logger = new class($this->log) implements LogEngineInterface {
            public function __construct(private array &$log) {}
            public function log(string $action, array $context = []): void { $this->log[] = [$action, $context]; }
            public function healthCheck(): bool { return true; }
        };
        $oidc = OidcConfig::fromConfig($config + ['oidc_issuer' => self::ISS, 'oidc_admin_value' => 'dfir-fm-admins'] + OidcConfigTest::COMPLETE);
        return new AccountResolver($this->db, $oidc, $logger);
    }

    /** Validated claims; a null override removes the claim */
    private function claims(array $override): Claims
    {
        return new Claims(array_filter($override + [
            'iss' => self::ISS, 'sub' => 'sub-alice', 'aud' => 'dfir-fm', 'iat' => time(), 'exp' => time() + 300,
            'email' => 'alice@example.com', 'email_verified' => true, 'name' => 'Alice', 'groups' => ['staff'],
        ], fn($v) => $v !== null));
    }

    private function addLocal(string $address, ?string $password = 'hash', array $columns = []): int
    {
        $columns = ['username' => $address, 'email' => $address, 'password' => $password] + $columns;
        $this->db->prepare("INSERT INTO users (" . implode(', ', array_keys($columns)) . ") VALUES (:" . implode(', :', array_keys($columns)) . ")")
            ->execute(array_combine(array_map(fn($k) => ":$k", array_keys($columns)), array_values($columns)));
        return (int)$this->db->lastInsertId();
    }

    /** @return list<int> */
    private function groupsOf(int|string $userId): array
    {
        return array_map('intval', $this->db->query("SELECT group_id FROM user_group WHERE user_id = " . (int)$userId . " ORDER BY group_id")->fetchAll(PDO::FETCH_COLUMN));
    }
}
