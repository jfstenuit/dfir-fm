<?php
namespace Tests\Unit\Core;

use Core\Database;
use Middleware\AccessMiddleware;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Schema creation, migrations, and the admin checks built on them.
 */
final class DatabaseTest extends TestCase
{
    private string $dir;
    private string $savedErrorLog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-db-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->savedErrorLog = (string)ini_get('error_log');
        ini_set('error_log', "$this->dir/php-errors.log");
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->savedErrorLog);
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        array_map('unlink', glob("$this->dir/*"));
        rmdir($this->dir);
    }

    private function init(string $name): PDO
    {
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        return Database::initialize("$this->dir/$name");
    }

    /** A database as created by versions before migration v1 */
    private function legacyDatabase(string $name, callable $seed): void
    {
        $db = new PDO("sqlite:$this->dir/$name");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password TEXT NOT NULL, email TEXT UNIQUE, invitation_token TEXT, token_expiry DATETIME)");
        $db->exec("CREATE TABLE groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, description TEXT)");
        $db->exec("CREATE TABLE user_group (user_id INTEGER NOT NULL, group_id INTEGER NOT NULL, PRIMARY KEY (user_id, group_id))");
        $seed($db);
    }

    private static function scalar(PDO $db, string $sql): int
    {
        return (int)$db->query($sql)->fetchColumn();
    }

    public function testFreshInstall(): void
    {
        $db = $this->init('fresh.sqlite');

        $this->assertSame(1, self::scalar($db, 'PRAGMA user_version'));
        $this->assertSame(0, self::scalar($db, 'SELECT COUNT(*) FROM users'), 'no seeded user');
        $this->assertSame(1, self::scalar($db, 'SELECT COUNT(*) FROM groups WHERE is_admin = 1'));
        $this->assertSame(['user_id', 'group_id'], $db->query('PRAGMA table_info(user_group)')->fetchAll(PDO::FETCH_COLUMN, 1));
        $this->assertContains('email', $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1));

        $db = $this->init('fresh.sqlite');
        $this->assertSame(1, self::scalar($db, 'SELECT COUNT(*) FROM groups WHERE is_admin = 1'), 're-init is idempotent');
    }

    public function testLegacyMigration(): void
    {
        $this->legacyDatabase('legacy.sqlite', function (PDO $db) {
            $db->exec("INSERT INTO groups (name, description) VALUES ('admin', 'Administrator group'), ('example.com users', NULL)");
            $admin = password_hash('admin', PASSWORD_DEFAULT);
            $other = password_hash('s3cret-other', PASSWORD_DEFAULT);
            $db->exec("INSERT INTO users (username, password) VALUES ('admin', '$admin'), ('alice@example.com', '$other'), ('bob@example.com', '$other')");
            $db->exec("INSERT INTO user_group VALUES (1, 1), (2, 1), (3, 2)");
        });

        $db = $this->init('legacy.sqlite');

        $this->assertSame(1, self::scalar($db, 'SELECT is_admin FROM groups WHERE id = 1'), 'group 1 flagged admin');
        $this->assertSame(0, self::scalar($db, 'SELECT is_admin FROM groups WHERE id = 2'));
        $this->assertSame(2, self::scalar($db, 'SELECT COUNT(*) FROM groups'), 'no extra admin group');
        $this->assertSame('!', $db->query("SELECT password FROM users WHERE username = 'admin'")->fetchColumn(), 'default admin/admin locked');
        $this->assertTrue(password_verify('s3cret-other', $db->query('SELECT password FROM users WHERE id = 2')->fetchColumn()));
        $this->assertStringContainsString("locked the 'admin' account", (string)file_get_contents("$this->dir/php-errors.log"));

        // Admin checks
        $this->assertTrue(AccessMiddleware::isAdmin($db, 2));
        $this->assertFalse(AccessMiddleware::isAdmin($db, 3));
        $this->assertFalse(AccessMiddleware::isAdmin($db, null));
        $this->assertSame(1, AccessMiddleware::countActiveAdmins($db), 'locked admin excluded');
        $this->assertSame(0, AccessMiddleware::countActiveAdmins($db, 2));
        $this->assertTrue(AccessMiddleware::checkAccess($db, '/', 2)['is_admin']);
        $this->assertFalse(AccessMiddleware::checkAccess($db, '/', 3)['is_admin']);
    }

    public function testAdminWithChangedPasswordNotLocked(): void
    {
        $this->legacyDatabase('legacy2.sqlite', function (PDO $db) {
            $hash = password_hash('changed!', PASSWORD_DEFAULT);
            $db->exec("INSERT INTO groups (name) VALUES ('admin')");
            $db->exec("INSERT INTO users (username, password) VALUES ('admin', '$hash')");
            $db->exec("INSERT INTO user_group VALUES (1, 1)");
        });

        $db = $this->init('legacy2.sqlite');
        $this->assertTrue(password_verify('changed!', $db->query("SELECT password FROM users WHERE username = 'admin'")->fetchColumn()));
    }

    public function testExistingNonAdminGroupNamedAdminNotPromoted(): void
    {
        $this->legacyDatabase('legacy3.sqlite', function (PDO $db) {
            $db->exec("INSERT INTO groups (id, name) VALUES (5, 'admin')");
        });

        $db = $this->init('legacy3.sqlite');
        $this->assertSame(0, self::scalar($db, 'SELECT is_admin FROM groups WHERE id = 5'));
        $this->assertSame('administrators', $db->query('SELECT name FROM groups WHERE is_admin = 1')->fetchColumn());
    }
}
