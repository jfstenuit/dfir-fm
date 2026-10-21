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

        $this->assertSame(7, self::scalar($db, 'PRAGMA user_version'));
        $this->assertSame(0, self::scalar($db, 'SELECT COUNT(*) FROM users'), 'no seeded user');
        $this->assertSame(1, self::scalar($db, 'SELECT COUNT(*) FROM groups WHERE is_admin = 1'));
        $this->assertSame(['user_id', 'group_id'], $db->query('PRAGMA table_info(user_group)')->fetchAll(PDO::FETCH_COLUMN, 1));
        $this->assertContains('email', $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1));
        $this->assertSame([['id' => 1, 'name' => '', 'path' => '/', 'parent_id' => null]],
            $db->query("SELECT id, name, path, parent_id FROM directories")->fetchAll(PDO::FETCH_ASSOC), 'root folder created');

        $db = $this->init('fresh.sqlite');
        $this->assertSame(1, self::scalar($db, 'SELECT COUNT(*) FROM groups WHERE is_admin = 1'), 're-init is idempotent');
        $this->assertSame(1, self::scalar($db, "SELECT COUNT(*) FROM directories WHERE path = '/'"), 'one root folder');
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

    /** A database as left by version 1 of the schema (v1 uploads table, no unique indexes) */
    private function v1Database(string $name, callable $seed): void
    {
        $db = $this->init($name);
        $db->exec("DROP TABLE uploads");
        $db->exec("DROP TABLE upload_chunks");
        $db->exec("DROP INDEX files_dir_name");
        $db->exec("DROP INDEX files_dir_sha256");
        $db->exec("CREATE TABLE uploads (uuid TEXT PRIMARY KEY, file_name TEXT NOT NULL, file_size INTEGER NOT NULL,
            total_chunks INTEGER NOT NULL, last_chunk_index INTEGER NOT NULL DEFAULT 0, hash_state TEXT NOT NULL,
            storage_path TEXT NOT NULL, status TEXT DEFAULT 'pending', last_update DATETIME, created_at DATETIME, user_id INTEGER NOT NULL)");
        $db->exec("PRAGMA user_version = 1");
        $db->exec("PRAGMA foreign_keys = OFF"); // v1 databases didn't enforce them
        $seed($db);
    }

    public function testMigrationV2ReplacesTheV1UploadsTable(): void
    {
        $this->v1Database('v1.sqlite', function (PDO $db) {
            $db->exec("INSERT INTO uploads (uuid, file_name, file_size, total_chunks, hash_state, storage_path, status, user_id)
                       VALUES ('u1', 'half.bin', 10, 2, '', '/half.bin', 'in_progress', 1)");
            $db->exec("INSERT INTO files (directory_id, name, size, sha256) VALUES (1, 'a', 1, 'aa'), (1, 'b', 1, NULL), (1, 'c', 1, NULL)");
        });

        $db = $this->init('v1.sqlite');
        $this->assertSame(7, self::scalar($db, 'PRAGMA user_version'));
        $this->assertContains('client_uuid', $db->query('PRAGMA table_info(uploads)')->fetchAll(PDO::FETCH_COLUMN, 1));
        $this->assertSame(0, self::scalar($db, 'SELECT COUNT(*) FROM uploads'), 'v1 uploads discarded');
        $this->assertSame(3, self::scalar($db, 'SELECT COUNT(*) FROM files'), 'files kept; NULL hashes may repeat');
        $this->assertStringContainsString('partial file left in storage: /half.bin', (string)file_get_contents("$this->dir/php-errors.log"));

        $this->expectException(\PDOException::class);
        $db->exec("INSERT INTO files (directory_id, name, size) VALUES (1, 'a', 2)");
    }

    public function testMigrationV3AddsSoftDeletionColumns(): void
    {
        $db = $this->init('v2.sqlite');
        $db->exec("INSERT INTO users (username, password) VALUES ('alice@example.org', 'x')");
        $db->exec("ALTER TABLE users DROP COLUMN deleted_at");
        $db->exec("ALTER TABLE users DROP COLUMN deleted_by");
        $db->exec("PRAGMA user_version = 2");

        $db = $this->init('v2.sqlite');
        $this->assertSame(7, self::scalar($db, 'PRAGMA user_version'));
        $columns = $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('deleted_at', $columns);
        $this->assertContains('deleted_by', $columns);
        $this->assertSame(1, self::scalar($db, 'SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'), 'existing accounts not deleted');
    }

    public function testMigrationV6MarksLockedAccounts(): void
    {
        $db = $this->init('v5.sqlite');
        // Before v6, a lock was password '!' with token_expiry 0
        $db->exec("INSERT INTO users (username, password, invitation_token, token_expiry) VALUES
                       ('locked@example.org', '!', 'old', 0),
                       ('invited@example.org', '!', 'tok', '2020-01-01 00:00:00'),
                       ('active@example.org', 'hash', NULL, NULL)");
        $db->exec("ALTER TABLE users DROP COLUMN locked_at");
        $db->exec("ALTER TABLE users DROP COLUMN locked_by");
        $db->exec("PRAGMA user_version = 5");

        $db = $this->init('v5.sqlite');
        $this->assertSame(7, self::scalar($db, 'PRAGMA user_version'));
        $locked = $db->query("SELECT username FROM users WHERE locked_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['locked@example.org'], $locked, 'an expired invitation is not a lock');
        $this->assertSame(0, self::scalar($db, "SELECT COUNT(*) FROM users WHERE username = 'locked@example.org' AND invitation_token IS NOT NULL"));
    }

    public function testMigrationV7AddsFileOriginAndIntegrity(): void
    {
        $db = $this->init('v6.sqlite');
        $db->exec("INSERT INTO files (directory_id, name, size, sha256) VALUES (1, 'old.bin', 3, 'abc')");
        $db->exec("ALTER TABLE files DROP COLUMN origin");
        $db->exec("ALTER TABLE files DROP COLUMN integrity");
        $db->exec("ALTER TABLE files DROP COLUMN checked_at");
        $db->exec("DROP TABLE refresh_job_files");
        $db->exec("DROP TABLE refresh_jobs");
        $db->exec("PRAGMA user_version = 6");

        $db = $this->init('v6.sqlite');
        $this->assertSame(7, self::scalar($db, 'PRAGMA user_version'));
        $this->assertSame(['upload', null, null], $db->query("SELECT origin, integrity, checked_at FROM files")->fetch(PDO::FETCH_NUM), 'existing files were uploaded');
        $this->assertSame(0, self::scalar($db, 'SELECT COUNT(*) FROM refresh_jobs'));
        $this->expectException(\PDOException::class);
        $db->exec("UPDATE files SET integrity = 'fine'");
    }

    public function testMigrationV4RepairsReferencesToMissingRows(): void
    {
        $db = $this->init('v3.sqlite');
        $db->exec("PRAGMA foreign_keys = OFF"); // to create the rows a v3 database may hold
        $db->exec("INSERT INTO users (id, username, password) VALUES (2, 'kept@example.org', 'x')");
        $db->exec("INSERT INTO groups (id, name) VALUES (5, 'kept')");
        $db->exec("INSERT INTO directories (id, name, path, parent_id, created_by) VALUES (10, 'case', '/case', 1, 7)");
        // Uploader 8 and creator 7 were hard-deleted; directory 99 no longer exists
        $db->exec("INSERT INTO files (id, directory_id, name, size, sha256, uploaded_by) VALUES
                   (1, 10, 'a.bin', 1, 'aa', 8), (2, 99, 'lost.bin', 1, 'bb', 2)");
        $db->exec("INSERT INTO user_group (user_id, group_id) VALUES (2, 5), (8, 5), (2, 6)");
        $db->exec("INSERT INTO access_rights (group_id, directory_id, can_view) VALUES (5, 10, 1), (6, 10, 1), (5, 99, 1)");
        $db->exec("INSERT INTO uploads (id, user_id, client_uuid, directory_id, name, size, chunk_size, chunk_count, staging_key, status)
                   VALUES (1, 9, 'u', 99, 'x', 1, 1, 1, 'k1', 'aborted'), (2, 9, 'v', 10, 'y', 1, 1, 1, 'k2', 'aborted')");
        $db->exec("INSERT INTO upload_chunks (upload_id, chunk_index, size) VALUES (1, 0, 1), (3, 0, 1)");
        $db->exec("PRAGMA user_version = 3");

        $db = $this->init('v3.sqlite');
        $this->assertSame(7, self::scalar($db, 'PRAGMA user_version'));

        // Tombstones for the missing uploader, creator and upload owner
        $tombstones = $db->query("SELECT id, username FROM users WHERE deleted_at IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame([7 => 'deleted:unknown:7', 8 => 'deleted:unknown:8', 9 => 'deleted:unknown:9'], $tombstones);

        // Link rows to missing rows are gone, the others stay
        $this->assertSame([[2, 5]], $db->query("SELECT user_id, group_id FROM user_group")->fetchAll(PDO::FETCH_NUM));
        $this->assertSame([[5, 10]], $db->query("SELECT group_id, directory_id FROM access_rights")->fetchAll(PDO::FETCH_NUM));
        $this->assertSame([2], array_map('intval', $db->query("SELECT id FROM uploads")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(0, self::scalar($db, 'SELECT COUNT(*) FROM upload_chunks'));

        // Evidence rows are never deleted: the file in a missing folder is kept and reported
        $this->assertSame(2, self::scalar($db, 'SELECT COUNT(*) FROM files'));
        $this->assertSame(['files id 2 refers to a missing directories row'], Database::foreignKeyProblems($db));
        $log = (string)file_get_contents("$this->dir/php-errors.log");
        $this->assertStringContainsString('user id 8 no longer existed', $log);
        $this->assertStringContainsString('deleted 2 user_group row(s)', $log);
        $this->assertStringContainsString('kept, to review: files id 2 refers to a missing directories row', $log);
    }

    public function testForeignKeysAreEnforced(): void
    {
        $db = $this->init('fk.sqlite');
        $this->assertSame(1, self::scalar($db, 'PRAGMA foreign_keys'));

        $db->exec("INSERT INTO users (id, username, password) VALUES (2, 'alice@example.org', 'x')");
        $db->exec("INSERT INTO files (directory_id, name, size, sha256, uploaded_by) VALUES (1, 'a.bin', 1, 'aa', 2)");
        try {
            $db->exec("DELETE FROM users WHERE id = 2");
            $this->fail('an uploader cannot be deleted');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('FOREIGN KEY constraint failed', $e->getMessage());
        }

        $this->expectException(\PDOException::class);
        $db->exec("INSERT INTO user_group (user_id, group_id) VALUES (2, 999)");
    }

    public function testMigrationV2StopsOnDuplicateFiles(): void
    {
        $this->v1Database('dup.sqlite', function (PDO $db) {
            $db->exec("INSERT INTO files (directory_id, name, size, sha256) VALUES (1, 'dump.bin', 1, 'aa'), (1, 'dump.bin', 2, 'bb'),
                       (2, 'x', 1, 'cc'), (2, 'y', 1, 'cc')");
        });

        try {
            $this->init('dup.sqlite');
            $this->fail('migration should stop');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Migration v2 stopped', $e->getMessage());
        }

        $db = new PDO("sqlite:$this->dir/dup.sqlite");
        $this->assertSame(1, self::scalar($db, 'PRAGMA user_version'), 'rolled back');
        $this->assertSame(4, self::scalar($db, 'SELECT COUNT(*) FROM files'), 'no evidence row dropped');
        $log = (string)file_get_contents("$this->dir/php-errors.log");
        $this->assertStringContainsString('same name in directory id 1 (dump.bin): files ids 1,2', $log);
        $this->assertStringContainsString('same SHA-256 in directory id 2 (cc): files ids 3,4', $log);
    }
}
