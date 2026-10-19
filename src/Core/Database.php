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

            return self::$connection;
        } catch (Exception $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    private static function createTables()
    {
        $queries = [
            "CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    email TEXT UNIQUE,
    invitation_token TEXT,
    token_expiry DATETIME
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

            "CREATE TABLE IF NOT EXISTS uploads (
    uuid TEXT PRIMARY KEY,
    file_name TEXT NOT NULL,
    file_size INTEGER NOT NULL,
    total_chunks INTEGER NOT NULL,
    last_chunk_index INTEGER NOT NULL DEFAULT 0,
    hash_state TEXT NOT NULL,
    storage_path TEXT NOT NULL,
    status TEXT CHECK (status IN ('pending', 'in_progress', 'completed', 'failed')) DEFAULT 'pending',
    last_update DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );"
        ];

        foreach ($queries as $query) {
            self::$connection->exec($query);
        }

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
    }

    private static function hasColumn(string $table, string $column): bool
    {
        $columns = self::$connection->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
        return in_array($column, $columns, true);
    }
}

