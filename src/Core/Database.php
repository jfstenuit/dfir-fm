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
    description TEXT
            );",

            "CREATE TABLE IF NOT EXISTS user_group (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT
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

        // Insert initial admin group and admin user
        $adminGroupCheck = self::$connection->query("SELECT COUNT(*) FROM groups WHERE name = 'admin'")->fetchColumn();
        if ($adminGroupCheck == 0) {
            self::$connection->exec("INSERT INTO groups (name, description) VALUES ('admin', 'Administrator group')");
        }

        $adminUserCheck = self::$connection->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
        if ($adminUserCheck == 0) {
            $hashedPassword = password_hash('admin', PASSWORD_DEFAULT);
            self::$connection->exec("INSERT INTO users (username, password, email) VALUES ('admin', '$hashedPassword', 'admin@example.com')");

            $adminGroupId = self::$connection->query("SELECT id FROM groups WHERE name = 'admin'")->fetchColumn();
            $adminUserId = self::$connection->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();

            self::$connection->exec("INSERT INTO user_group (user_id, group_id) VALUES ($adminUserId, $adminGroupId)");
        }
    }
}

