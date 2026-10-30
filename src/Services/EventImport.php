<?php
// src/Services/EventImport.php
namespace Services;

use Core\EventStore;
use PDO;

/**
 * Import JSONL log files written before the events database existed
 * (bin/import-log.php). Each line is stored exactly as read.
 *
 * Imported: the events older than the store's creation (later ones are in
 * the store already, written live) and not older than the cutoff of a
 * purged archive (those periods were archived and purged: importing them
 * again would undo the purge). An event already stored (same ts, same
 * record) is skipped, so a second run, or overlapping files, import nothing.
 */
class EventImport
{
    /** Rows per write transaction: short, so live requests never wait long for the lock */
    private const BATCH = 500;

    public function __construct(private readonly PDO $events)
    {
    }

    /**
     * Refuse files that aren't JSONL logs, and order them by their first
     * event: rotated files are imported oldest first, so ids follow time.
     *
     * @param list<string> $paths
     * @return list<array{path: string, first_ts: ?string}>
     * @throws \InvalidArgumentException a file that can't be read, or a CSV log
     */
    public static function inspect(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $first = null;
            $firstLine = null;
            foreach (self::lines($path) as $line) {
                $firstLine ??= $line;
                if (($entry = self::parse($line)) !== null) {
                    $first = $entry['ts'];
                    break;
                }
            }
            if ($firstLine !== null && str_starts_with($firstLine, '"')) {
                throw new \InvalidArgumentException("$path is a CSV log (log_format=csv): its fields have no names and differ per action, so it can't be read back into events. Keep it as it is.");
            }
            $files[] = ['path' => $path, 'first_ts' => $first];
        }
        usort($files, fn($a, $b) => strcmp($a['first_ts'] ?? '', $b['first_ts'] ?? ''));
        return $files;
    }

    /**
     * Import one file.
     *
     * @return array{file: string, sha256: string, imported: int, already_stored: int, too_recent: int, purged_period: int, malformed: int}
     */
    public function import(string $path): array
    {
        $until = EventStore::createdAt($this->events);
        $closedBefore = $this->events->query('SELECT MAX(before_ts) FROM archives WHERE purged_at IS NOT NULL')->fetchColumn() ?: null;
        $exists = $this->events->prepare('SELECT 1 FROM events WHERE ts = :ts AND record = :record LIMIT 1');
        $insert = $this->events->prepare('INSERT INTO events (ts, action, username, ip, peer_ip, record)
                                          VALUES (:ts, :action, :username, :ip, :peer_ip, :record)');

        $counts = ['imported' => 0, 'already_stored' => 0, 'too_recent' => 0, 'purged_period' => 0, 'malformed' => 0];
        $hash = hash_init('sha256');
        $pending = 0;
        try {
            foreach (self::lines($path, $hash) as $line) {
                $entry = self::parse($line);
                if ($entry === null) {
                    $counts['malformed']++;
                    continue;
                }
                if (strcmp($entry['ts'], $until) >= 0) {
                    $counts['too_recent']++;
                    continue;
                }
                if ($closedBefore !== null && strcmp($entry['ts'], $closedBefore) < 0) {
                    $counts['purged_period']++;
                    continue;
                }
                if ($pending === 0) {
                    $this->events->exec('BEGIN IMMEDIATE');
                }
                $exists->execute([':ts' => $entry['ts'], ':record' => $line]);
                if ($exists->fetchColumn()) {
                    $counts['already_stored']++;
                } else {
                    $text = fn($key) => isset($entry[$key]) && is_scalar($entry[$key]) ? (string)$entry[$key] : null;
                    $insert->execute([':ts' => $entry['ts'], ':action' => $entry['action'], ':username' => $text('username'),
                                      ':ip' => $text('ip'), ':peer_ip' => $text('peer_ip'), ':record' => $line]);
                    $counts['imported']++;
                }
                $exists->closeCursor();
                if (++$pending >= self::BATCH) {
                    $this->events->exec('COMMIT');
                    $pending = 0;
                }
            }
            if ($pending > 0) {
                $this->events->exec('COMMIT');
            }
        } catch (\Throwable $e) {
            if ($this->events->inTransaction()) {
                $this->events->exec('ROLLBACK');
            }
            throw $e;
        }
        return ['file' => $path, 'sha256' => hash_final($hash)] + $counts;
    }

    /**
     * A log line as an event: a JSON object with a ts as the application
     * writes it (UTC, ISO 8601) and an action. Null otherwise.
     */
    private static function parse(string $line): ?array
    {
        $entry = json_decode($line, true);
        if (!is_array($entry) || array_is_list($entry)
            || !is_string($entry['ts'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $entry['ts'])
            || !is_string($entry['action'] ?? null) || $entry['action'] === '') {
            return null;
        }
        return $entry;
    }

    /**
     * The non-empty lines of a file, without their line end; .gz files are
     * read uncompressed. $hash, if given, gets every byte of the file as
     * stored (compressed for .gz).
     *
     * @return \Generator<string>
     */
    private static function lines(string $path, $hash = null): \Generator
    {
        $raw = @fopen($path, 'rb');
        if ($raw === false) {
            throw new \InvalidArgumentException("Cannot read $path");
        }
        if ($hash !== null) {
            // Hash the file as stored, then read it again for its lines
            while (!feof($raw)) {
                hash_update($hash, (string)fread($raw, 1 << 20));
            }
        }
        fclose($raw);

        $stream = str_ends_with($path, '.gz') ? @gzopen($path, 'rb') : @fopen($path, 'rb');
        if ($stream === false) {
            throw new \InvalidArgumentException("Cannot read $path");
        }
        try {
            $gz = str_ends_with($path, '.gz');
            while (($line = $gz ? gzgets($stream) : fgets($stream)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line !== '') {
                    yield $line;
                }
            }
        } finally {
            $gz ? gzclose($stream) : fclose($stream);
        }
    }
}
?>
