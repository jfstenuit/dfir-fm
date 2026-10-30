<?php
// src/Core/EventStore.php
namespace Core;

use PDO;

/**
 * The events database (D27): a SQLite file of its own, separate from the
 * application database, so the log never shares a resource with users,
 * rights and files. Opened only here, never through App::getDb().
 */
class EventStore
{
    /** Milliseconds a write waits for another request's lock (D29: a timeout is a failure) */
    public const BUSY_TIMEOUT_MS = 5000;

    private const SCHEMA_VERSION = 1;

    public static function open(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create the events database directory $dir");
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => (int)ceil(self::BUSY_TIMEOUT_MS / 1000),
        ]);
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        // Readers (the Logs tab) don't block the writers, and the reverse
        $pdo->exec('PRAGMA journal_mode = WAL');
        self::migrate($pdo);
        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= self::SCHEMA_VERSION) {
            return;
        }

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Another request may have created it while this one waited for the lock
            if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 1) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts TEXT NOT NULL,
    action TEXT NOT NULL,
    username TEXT,
    ip TEXT,
    peer_ip TEXT,
    record TEXT NOT NULL
                )");
                $pdo->exec('CREATE INDEX IF NOT EXISTS events_ts ON events(ts)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS events_action ON events(action, ts)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS events_username ON events(username, ts)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS events_ip ON events(ip, ts)');
                $pdo->exec("CREATE TABLE IF NOT EXISTS archives (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    before_ts TEXT NOT NULL,
    max_event_id INTEGER NOT NULL,
    events INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    created_at TEXT NOT NULL,
    created_by TEXT,
    purged_at TEXT,
    purged_by TEXT
                )");
                $pdo->exec('PRAGMA user_version = 1');
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }
}
?>
