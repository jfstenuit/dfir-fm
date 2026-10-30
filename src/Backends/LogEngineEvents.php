<?php
// src/Backends/LogEngineEvents.php
namespace Backends;

use Core\EventStore;
use PDO;

/**
 * The events database (D27), written for every event whatever log_engine
 * is; the Logs tab reads it. The record column holds the record exactly as
 * the JSON log engines write it.
 */
class LogEngineEvents implements LogDestination {
    private ?PDO $pdo = null;
    private ?\PDOStatement $insert = null;

    public function __construct(private readonly string $path) {
    }

    public function name(): string {
        return 'events';
    }

    public function path(): string {
        return $this->path;
    }

    /** Opened (and created, the first time) on first use */
    public function pdo(): PDO {
        return $this->pdo ??= EventStore::open($this->path);
    }

    public function write(array $entry): void {
        $this->insert ??= $this->pdo()->prepare(
            'INSERT INTO events (ts, action, username, ip, peer_ip, record)
             VALUES (:ts, :action, :username, :ip, :peer_ip, :record)');
        $text = fn($key) => isset($entry[$key]) && is_scalar($entry[$key]) ? (string)$entry[$key] : null;
        $this->insert->execute([
            ':ts' => (string)$entry['ts'],
            ':action' => (string)$entry['action'],
            ':username' => $text('username'),
            ':ip' => $text('ip'),
            ':peer_ip' => $text('peer_ip'),
            ':record' => LogEntry::encode($entry),
        ]);
    }

    /** The file can be opened and written, its directory too (WAL's -wal and -shm files) */
    public function healthCheck(): bool {
        try {
            $this->pdo();
        } catch (\Throwable $e) {
            error_log("DFIR FM: events database $this->path: " . $e->getMessage());
            return false;
        }
        return is_writable($this->path) && is_writable(dirname($this->path));
    }
}
?>
