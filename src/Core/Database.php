<?php
// src/Core/Database.php
namespace Core;

use PDO;
use Exception;

class Database
{
    private static $connection;

    public static function initialize($dbPath)
    {
        if (!file_exists(dirname($dbPath))) {
            mkdir(dirname($dbPath), 0755, true);
        }

        try {
            self::$connection = new PDO("sqlite:" . $dbPath);
            self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Initialize tables if needed
            self::createTables();

            // Enforced from here on; migrations run without it, since they
            // repair rows the constraints would refuse (SQLite ignores the
            // pragma inside a transaction, so it is set per connection here)
            self::$connection->exec("PRAGMA foreign_keys = ON");

            return self::$connection;
        } catch (Exception $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    private static function createTables()
    {
        self::createSchema();

        self::migrate();

        // Ensure an admin group exists. No admin user is seeded: create the
        // first one with `php bin/create-admin.php <username>`.
        // Never promote an existing group: its members would silently become admins.
        $adminGroupCheck = self::$connection->query("SELECT COUNT(*) FROM groups WHERE is_admin = 1")->fetchColumn();
        if ($adminGroupCheck == 0) {
            $stmt = self::$connection->prepare("SELECT COUNT(*) FROM groups WHERE name = :name");
            foreach (['admin', 'administrators', 'dfir-fm-admins'] as $name) {
                $stmt->execute([':name' => $name]);
                if ($stmt->fetchColumn() == 0) {
                    $insert = self::$connection->prepare("INSERT INTO groups (name, description, is_admin) VALUES (:name, 'Administrator group', 1)");
                    $insert->execute([':name' => $name]);
                    break;
                }
            }
        }

        // The root folder: every listing and access check starts from it.
        // Nothing created it before, so on a fresh install "/" was a 404 for
        // everyone, admins included.
        self::$connection->exec("INSERT INTO directories (name, path, parent_id)
                                 SELECT '', '/', NULL WHERE NOT EXISTS (SELECT 1 FROM directories WHERE path = '/')");
    }

    /** Current schema; CREATE ... IF NOT EXISTS, so it only adds what is missing */
    private static function createSchema()
    {
        $queries = [
            "CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    email TEXT UNIQUE,
    invitation_token TEXT,
    token_expiry DATETIME,
    deleted_at DATETIME,
    deleted_by INTEGER,
    session_version INTEGER NOT NULL DEFAULT 0,
    locked_at DATETIME,
    locked_by INTEGER
            );",

            "CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    is_admin INTEGER NOT NULL DEFAULT 0
            );",

            "CREATE TABLE IF NOT EXISTS user_group (
    user_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    PRIMARY KEY (user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
            );",

            "CREATE TABLE IF NOT EXISTS directories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    path TEXT NOT NULL,
    parent_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INTEGER,
    created_from TEXT,
    FOREIGN KEY (parent_id) REFERENCES directories(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
            );",

            "CREATE TABLE IF NOT EXISTS access_rights (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL,
    directory_id INTEGER NOT NULL,
    can_view BOOLEAN NOT NULL DEFAULT FALSE,
    can_write BOOLEAN NOT NULL DEFAULT FALSE,
    can_upload BOOLEAN NOT NULL DEFAULT FALSE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE,
    UNIQUE (group_id, directory_id)
            );",

            "CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    directory_id INTEGER,
    name TEXT NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    uploaded_by INTEGER,
    uploaded_from TEXT,
    size INTEGER NOT NULL DEFAULT (0),
    sha256 TEXT,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    FOREIGN KEY (directory_id) REFERENCES directories(id)
            );",

            // Chunked uploads in progress (and their outcome, kept for audit).
            // Dropzone's dzuuid is only unique per user; staging_key, the
            // name of the staging file, is generated by the server.
            "CREATE TABLE IF NOT EXISTS uploads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    client_uuid TEXT NOT NULL,
    directory_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    size INTEGER NOT NULL,
    chunk_size INTEGER NOT NULL,
    chunk_count INTEGER NOT NULL,
    staging_key TEXT NOT NULL UNIQUE,
    sha256 TEXT,
    verified_bytes INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL CHECK (status IN ('in_progress', 'verifying', 'completed', 'aborted', 'failed', 'expired')),
    error TEXT,
    file_id INTEGER,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_update DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, client_uuid),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE
            );",

            "CREATE TABLE IF NOT EXISTS upload_chunks (
    upload_id INTEGER NOT NULL,
    chunk_index INTEGER NOT NULL,
    size INTEGER NOT NULL,
    PRIMARY KEY (upload_id, chunk_index),
    FOREIGN KEY (upload_id) REFERENCES uploads(id) ON DELETE CASCADE
            );"
        ];

        foreach ($queries as $query) {
            self::$connection->exec($query);
        }
    }

    /**
     * Schema migrations, tracked with PRAGMA user_version.
     * Each step must be idempotent: fresh databases already have the
     * current schema from createTables().
     */
    private static function migrate()
    {
        $version = (int)self::$connection->query("PRAGMA user_version")->fetchColumn();

        if ($version < 1) {
            self::$connection->beginTransaction();

            // groups.is_admin replaces the hardcoded "group_id = 1" admin check.
            // Flag group 1, which is the group that check trusted.
            if (!self::hasColumn('groups', 'is_admin')) {
                self::$connection->exec("ALTER TABLE groups ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0");
                self::$connection->exec("UPDATE groups SET is_admin = 1 WHERE id = 1");
            }

            // Lock the formerly seeded 'admin' account if it still has the
            // default password 'admin'.
            $stmt = self::$connection->query("SELECT id, password FROM users WHERE username = 'admin'");
            $seeded = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($seeded && $seeded['password'] !== '!' && password_verify('admin', $seeded['password'])) {
                $lock = self::$connection->prepare("UPDATE users SET password = '!', invitation_token = NULL, token_expiry = NULL WHERE id = :id");
                $lock->execute([':id' => $seeded['id']]);
                error_log("DFIR FM: locked the 'admin' account, which still had the default password. Create a new admin with: php bin/create-admin.php <username>");
            }

            self::$connection->exec("PRAGMA user_version = 1");
            self::$connection->commit();
        }

        if ($version < 2) {
            self::migrateToV2();
        }

        if ($version < 3) {
            self::migrateToV3();
        }

        if ($version < 4) {
            self::migrateToV4();
        }

        if ($version < 5) {
            self::migrateToV5();
        }

        if ($version < 6) {
            self::migrateToV6();
        }
    }

    /**
     * v6: users.locked_at / locked_by. A lock used to be recorded only as
     * password '!' with token_expiry 0, which can't be told from an invitee
     * whose link expired unused; those accounts get locked_at = now (UTC),
     * locked_by unknown. Resend is refused while locked; Unlock clears it.
     */
    private static function migrateToV6()
    {
        $db = self::$connection;
        $db->exec("BEGIN IMMEDIATE");
        try {
            if ((int)$db->query("PRAGMA user_version")->fetchColumn() >= 6) {
                $db->exec("COMMIT"); // another request migrated meanwhile
                return;
            }
            if (!self::hasColumn('users', 'locked_at')) {
                $db->exec("ALTER TABLE users ADD COLUMN locked_at DATETIME");
                $db->exec("ALTER TABLE users ADD COLUMN locked_by INTEGER");
            }
            $locked = $db->exec("UPDATE users SET locked_at = '" . gmdate('Y-m-d H:i:s') . "', invitation_token = NULL
                                 WHERE deleted_at IS NULL AND locked_at IS NULL AND password = '!'
                                   AND (token_expiry = 0 OR token_expiry = '0')");
            if ($locked > 0) {
                error_log("DFIR FM migration v6: $locked locked account(s) marked with locked_at");
            }
            $db->exec("PRAGMA user_version = 6");
            $db->exec("COMMIT");
        } catch (\Throwable $e) {
            $db->exec("ROLLBACK");
            throw $e;
        }
    }

    /**
     * v5: users.session_version, incremented when an account's open
     * sessions must end (lock, deletion, password change or reset)
     */
    private static function migrateToV5()
    {
        $db = self::$connection;
        $db->exec("BEGIN IMMEDIATE");
        try {
            if ((int)$db->query("PRAGMA user_version")->fetchColumn() >= 5) {
                $db->exec("COMMIT"); // another request migrated meanwhile
                return;
            }
            if (!self::hasColumn('users', 'session_version')) {
                $db->exec("ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 0");
            }
            $db->exec("PRAGMA user_version = 5");
            $db->exec("COMMIT");
        } catch (\Throwable $e) {
            $db->exec("ROLLBACK");
            throw $e;
        }
    }

    /**
     * v4: repair the rows that the foreign keys would refuse, before they
     * are enforced.
     * - Accounts removed before soft deletion (v3) get a tombstone row with
     *   their id, so that files, folders and uploads keep an uploader.
     * - Link rows to a missing user, group, folder or upload are deleted:
     *   memberships (also those of deleted accounts), access rights, uploads
     *   into a removed folder and their chunks. The upload events remain in
     *   the application log.
     * - Files and folders whose folder is missing are evidence records: they
     *   are kept and reported (bin/check-integrity.php reports them too).
     * Every change is reported in the PHP error log.
     */
    private static function migrateToV4()
    {
        $db = self::$connection;
        $db->exec("BEGIN IMMEDIATE");
        try {
            if ((int)$db->query("PRAGMA user_version")->fetchColumn() >= 4) {
                $db->exec("COMMIT"); // another request migrated meanwhile
                return;
            }

            $missingUsers = $db->query("
                SELECT uploaded_by FROM files WHERE uploaded_by IS NOT NULL
                UNION SELECT created_by FROM directories WHERE created_by IS NOT NULL
                UNION SELECT user_id FROM uploads
                EXCEPT SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $tombstone = $db->prepare("INSERT INTO users (id, username, email, password, deleted_at)
                                       VALUES (:id, :name, :name, '!', :now)");
            foreach ($missingUsers as $id) {
                $tombstone->execute([':id' => $id, ':name' => "deleted:unknown:$id", ':now' => gmdate('Y-m-d H:i:s')]);
                error_log("DFIR FM migration v4: user id $id no longer existed; added a deleted account as its uploader or creator");
            }

            $orphans = [
                // Deleted accounts (tombstones included) have no memberships
                'user_group' => "user_id NOT IN (SELECT id FROM users WHERE deleted_at IS NULL) OR group_id NOT IN (SELECT id FROM groups)",
                'access_rights' => "group_id NOT IN (SELECT id FROM groups) OR directory_id NOT IN (SELECT id FROM directories)",
                'uploads' => "directory_id NOT IN (SELECT id FROM directories)",
                'upload_chunks' => "upload_id NOT IN (SELECT id FROM uploads)",
            ];
            foreach ($orphans as $table => $where) {
                $count = $db->exec("DELETE FROM $table WHERE $where");
                if ($count) {
                    error_log("DFIR FM migration v4: deleted $count $table row(s) referring to a missing row");
                }
            }

            foreach (self::foreignKeyProblems() as $line) {
                error_log("DFIR FM migration v4: kept, to review: $line");
            }

            $db->exec("PRAGMA user_version = 4");
            $db->exec("COMMIT");
        } catch (\Throwable $e) {
            $db->exec("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Rows that refer to a missing row (PRAGMA foreign_key_check)
     * @return list<string> one line per row
     */
    public static function foreignKeyProblems(?PDO $db = null): array
    {
        $db ??= self::$connection;
        $lines = [];
        foreach ($db->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lines[] = "{$row['table']} id {$row['rowid']} refers to a missing {$row['parent']} row";
        }
        return $lines;
    }

    /**
     * v3: soft deletion of accounts (users.deleted_at, users.deleted_by).
     * Deleted accounts keep their row, so that files.uploaded_by and
     * directories.created_by keep pointing to them.
     */
    private static function migrateToV3()
    {
        $db = self::$connection;
        $db->exec("BEGIN IMMEDIATE");
        try {
            if ((int)$db->query("PRAGMA user_version")->fetchColumn() >= 3) {
                $db->exec("COMMIT"); // another request migrated meanwhile
                return;
            }
            if (!self::hasColumn('users', 'deleted_at')) {
                $db->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME");
                $db->exec("ALTER TABLE users ADD COLUMN deleted_by INTEGER");
            }
            $db->exec("PRAGMA user_version = 3");
            $db->exec("COMMIT");
        } catch (\Throwable $e) {
            $db->exec("ROLLBACK");
            throw $e;
        }
    }

    /**
     * v2: new uploads tables (Phase 3), and unique indexes on files that
     * back the name-conflict and duplicate-content rules.
     *
     * The v1 upload code could create two rows for the same name in a
     * directory (and append the second upload to the first file). Such rows
     * are evidence records: they are never dropped here. The migration
     * stops, and the application with it, until an operator resolves them.
     */
    private static function migrateToV2()
    {
        $db = self::$connection;
        $db->exec("BEGIN IMMEDIATE");
        try {
            if ((int)$db->query("PRAGMA user_version")->fetchColumn() >= 2) {
                $db->exec("COMMIT"); // another request migrated meanwhile
                return;
            }

            $problems = self::duplicateFiles();
            if ($problems) {
                throw new Exception("Migration v2 stopped: duplicate files rows (see the PHP error log; bin/check-integrity.php checks the stored files)");
            }

            // The v1 table: in-progress uploads are discarded. Their partial
            // files sit at their final path, without a files row.
            if (!self::hasColumn('uploads', 'client_uuid')) {
                $partial = $db->query("SELECT storage_path FROM uploads WHERE status <> 'completed'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($partial as $path) {
                    error_log("DFIR FM migration v2: discarded incomplete v1 upload; partial file left in storage: $path");
                }
                $db->exec("DROP TABLE uploads");
                self::createSchema();
            }

            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS files_dir_name ON files(directory_id, name)");
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS files_dir_sha256 ON files(directory_id, sha256)");
            $db->exec("CREATE INDEX IF NOT EXISTS uploads_active ON uploads(status, last_update)");
            $db->exec("PRAGMA user_version = 2");
            $db->exec("COMMIT");
        } catch (\Throwable $e) {
            $db->exec("ROLLBACK");
            foreach ($problems ?? [] as $line) {
                error_log("DFIR FM migration v2: $line");
            }
            throw $e;
        }
    }

    /**
     * Rows that the v2 unique indexes would refuse
     * @return list<string> one line per conflicting group
     */
    public static function duplicateFiles(?PDO $db = null): array
    {
        $db ??= self::$connection;
        $lines = [];
        $groups = [
            'same name' => "SELECT directory_id, name AS value, GROUP_CONCAT(id) AS ids FROM files GROUP BY directory_id, name HAVING COUNT(*) > 1",
            'same SHA-256' => "SELECT directory_id, sha256 AS value, GROUP_CONCAT(id) AS ids FROM files WHERE sha256 IS NOT NULL GROUP BY directory_id, sha256 HAVING COUNT(*) > 1",
        ];
        foreach ($groups as $what => $sql) {
            foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $lines[] = sprintf('%s in directory id %d (%s): files ids %s', $what, $row['directory_id'], $row['value'], $row['ids']);
            }
        }
        return $lines;
    }

    private static function hasColumn(string $table, string $column): bool
    {
        $columns = self::$connection->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
        return in_array($column, $columns, true);
    }
}

