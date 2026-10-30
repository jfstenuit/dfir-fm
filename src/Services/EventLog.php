<?php
// src/Services/EventLog.php
namespace Services;

use PDO;

/**
 * Reading the events database (D27) for the admin page's Logs tab.
 *
 * Keyset paging, newest first: a page is the events with an id below
 * `before`, so events logged meanwhile never shift a page (no gap, no
 * repeat), and no request reads more than a page.
 */
class EventLog
{
    public const PAGE_SIZE = 100;
    public const MAX_PAGE_SIZE = 500;

    /** The filters a query accepts, all optional */
    public const FILTERS = ['from', 'until', 'action', 'username', 'ip', 'text'];

    public function __construct(private readonly PDO $events)
    {
    }

    /**
     * Normalise and check the filters of a request. Times are UTC, as in
     * the records; `from` is inclusive, `until` exclusive.
     *
     * @param array<string,mixed> $input
     * @return array<string,string> the filters that are set
     * @throws \InvalidArgumentException on a value that is not a string, or a time that can't be read
     */
    public static function filters(array $input): array
    {
        $filters = [];
        foreach (self::FILTERS as $name) {
            $value = $input[$name] ?? '';
            if (!is_string($value)) {
                throw new \InvalidArgumentException("Invalid filter: $name");
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            if ($name === 'from' || $name === 'until') {
                $value = self::utc($value, $name);
            }
            $filters[$name] = $value;
        }
        return $filters;
    }

    /** A time as the records write it (gmdate('c')), so text comparison orders it */
    private static function utc(string $value, string $name): string
    {
        try {
            $time = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new \InvalidArgumentException("Invalid time: $name");
        }
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('c');
    }

    /**
     * One page of events, newest first.
     *
     * @param array<string,string> $filters as returned by filters()
     * @param ?int $before the `next` of the previous page; null for the first page
     * @return array{events: list<array>, next: ?int} next: the `before` of the following page, null after the last
     */
    public function page(array $filters, ?int $before = null, int $limit = self::PAGE_SIZE): array
    {
        [$where, $params] = $this->where($filters);
        if ($before !== null) {
            $where[] = 'id < :before';
            $params[':before'] = $before;
        }
        $limit = max(1, min($limit, self::MAX_PAGE_SIZE));

        $sql = 'SELECT id, ts, action, username, ip, peer_ip, record FROM events'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY id DESC LIMIT ' . ($limit + 1);
        $stmt = $this->events->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
        }
        return ['events' => $rows, 'next' => $more ? $rows[$limit - 1]['id'] : null];
    }

    public const EXPORT_FORMATS = ['jsonl', 'csv'];
    private const CSV_HEADER = ['ts', 'action', 'username', 'ip', 'peer_ip', 'record'];

    /**
     * Export the events matching $filters, oldest first, without loading
     * them in memory: each line is handed to $write as it is built, and
     * hashed. Events logged after the export started are left out (the
     * export's own logs_exported among them).
     *
     * JSONL: each record as stored, one per line, as in the log file.
     * CSV (RFC 4180): ts, action, username, ip, peer_ip and the full record.
     * The user-supplied values (file names...) are only in the record, a
     * JSON object that starts with "{", so no cell is read as a formula.
     *
     * @param callable(string): bool $write false: stop (the client went away)
     * @param ?int $last export up to this event id (an archive's); default: the last one now
     * @return array{events: int, sha256: string, last_event_id: ?int, complete: bool}
     */
    public function export(array $filters, string $format, callable $write, ?int $last = null): array
    {
        if (!in_array($format, self::EXPORT_FORMATS, true)) {
            throw new \InvalidArgumentException("Invalid format: $format");
        }
        if ($last === null) {
            $max = $this->events->query('SELECT MAX(id) FROM events')->fetchColumn();
            $last = $max === null ? null : (int)$max;
        }

        [$where, $params] = $this->where($filters);
        $where[] = 'id <= :last';
        $params[':last'] = $last ?? 0;
        $stmt = $this->events->prepare('SELECT id, ts, action, username, ip, peer_ip, record FROM events WHERE '
                                       . implode(' AND ', $where) . ' ORDER BY id');
        $stmt->execute($params);

        $hash = hash_init('sha256');
        $emit = function (string $line) use ($hash, $write): bool {
            hash_update($hash, $line);
            return $write($line);
        };

        $count = 0;
        $complete = $format === 'csv' ? $emit(self::csvLine(self::CSV_HEADER)) : true;
        while ($complete && ($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $line = $format === 'csv'
                ? self::csvLine([$row['ts'], $row['action'], $row['username'], $row['ip'], $row['peer_ip'], $row['record']])
                : $row['record'] . "\n";
            $complete = $emit($line);
            $count += $complete ? 1 : 0;
        }
        $stmt->closeCursor();

        return ['events' => $count, 'sha256' => hash_final($hash), 'last_event_id' => $last, 'complete' => $complete];
    }

    /** One CSV line, RFC 4180 (no backslash escape), CRLF */
    private static function csvLine(array $fields): string
    {
        static $buffer = null;
        $buffer ??= fopen('php://memory', 'w+');
        ftruncate($buffer, 0);
        rewind($buffer);
        fputcsv($buffer, $fields, ',', '"', '', "\r\n");
        rewind($buffer);
        return stream_get_contents($buffer);
    }

    // ------------------------------------------------------------ archives (D28)

    /**
     * Start an archive of the events before $before (UTC, as filters()
     * returns it): fixes the last event id it covers and records it, not
     * yet complete (sha256 empty). Events logged later, or imported later
     * with older times (higher ids), are not part of it.
     *
     * @return array{id: int, before_ts: string, max_event_id: int, file: string}
     * @throws \DomainException when there is no event before $before
     */
    public function startArchive(string $before, string $createdBy): array
    {
        $stmt = $this->events->prepare('SELECT MAX(id) FROM events WHERE ts < :before');
        $stmt->execute([':before' => $before]);
        $max = $stmt->fetchColumn();
        if ($max === null) {
            throw new \DomainException('There is no event before ' . $before . '.');
        }
        $this->events->prepare("INSERT INTO archives (before_ts, max_event_id, events, sha256, created_at, created_by)
                                VALUES (:before, :max, 0, '', :at, :by)")
            ->execute([':before' => $before, ':max' => (int)$max, ':at' => gmdate('c'), ':by' => $createdBy]);
        $id = (int)$this->events->lastInsertId();
        return ['id' => $id, 'before_ts' => $before, 'max_event_id' => (int)$max, 'file' => self::archiveFileName($id, $before)];
    }

    /**
     * Stream an archive's events as JSONL, oldest first (finishArchive()
     * once the file is sent).
     *
     * @param callable(string): bool $write
     * @return array{events: int, sha256: string, last_event_id: ?int, complete: bool}
     */
    public function writeArchive(array $archive, callable $write): array
    {
        return $this->export(['until' => $archive['before_ts']], 'jsonl', $write, $archive['max_event_id']);
    }

    /**
     * Record the count and SHA-256 of an archive sent completely. One not
     * sent completely is removed: only a file the administrator could have
     * kept can be purged.
     */
    public function finishArchive(array $archive, array $result): void
    {
        if ($result['complete']) {
            $this->events->prepare('UPDATE archives SET events = :events, sha256 = :sha256 WHERE id = :id')
                ->execute([':events' => $result['events'], ':sha256' => $result['sha256'], ':id' => $archive['id']]);
        } else {
            $this->events->prepare('DELETE FROM archives WHERE id = :id')->execute([':id' => $archive['id']]);
        }
    }

    /** The number of events an archive before $before would hold now */
    public function countBefore(string $before): int
    {
        $stmt = $this->events->prepare('SELECT COUNT(*) FROM events WHERE ts < :before');
        $stmt->execute([':before' => $before]);
        return (int)$stmt->fetchColumn();
    }

    /** dfir-fm-archive-12_before_2026-09-01T000000Z.jsonl */
    public static function archiveFileName(int $id, string $before): string
    {
        return sprintf('dfir-fm-archive-%d_before_%s.jsonl', $id, str_replace(':', '', str_replace('+00:00', 'Z', $before)));
    }

    /** @return list<array> the complete archives, newest first, with their file name */
    public function archives(): array
    {
        $rows = $this->events->query("SELECT * FROM archives WHERE sha256 != '' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($row) => [
            'id' => (int)$row['id'], 'before_ts' => $row['before_ts'], 'max_event_id' => (int)$row['max_event_id'],
            'events' => (int)$row['events'], 'sha256' => $row['sha256'], 'created_at' => $row['created_at'],
            'created_by' => $row['created_by'], 'purged_at' => $row['purged_at'], 'purged_by' => $row['purged_by'],
            'file' => self::archiveFileName((int)$row['id'], $row['before_ts']),
        ], $rows);
    }

    /**
     * Delete the events of an archive, once, and only if they are still
     * exactly what was archived: the same events give the same file, so
     * the count and the SHA-256 are computed again and compared. Runs in
     * one write transaction; the caller logs events_purged afterwards (the
     * logger's own connection would wait for this one's lock).
     *
     * @return array the archive, purged
     * @throws \DomainException when refused (unknown, already purged, changed since)
     */
    public function purge(int $id, string $purgedBy): array
    {
        $this->events->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->events->prepare("SELECT * FROM archives WHERE id = :id AND sha256 != ''");
            $stmt->execute([':id' => $id]);
            $archive = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($archive === false) {
                throw new \DomainException("No archive #$id.");
            }
            if ($archive['purged_at'] !== null) {
                throw new \DomainException("Archive #$id was already purged on {$archive['purged_at']}.");
            }
            $now = $this->export(['until' => $archive['before_ts']], 'jsonl', fn() => true, (int)$archive['max_event_id']);
            if ($now['events'] !== (int)$archive['events'] || $now['sha256'] !== $archive['sha256']) {
                throw new \DomainException(sprintf('The events of archive #%d changed since it was made (%d events now, %d archived, %s): nothing was purged. Make a new archive.',
                    $id, $now['events'], $archive['events'], $now['sha256'] === $archive['sha256'] ? 'same SHA-256' : 'different SHA-256'));
            }

            $this->events->prepare('DELETE FROM events WHERE id <= :max AND ts < :before')
                ->execute([':max' => $archive['max_event_id'], ':before' => $archive['before_ts']]);
            $this->events->prepare('UPDATE archives SET purged_at = :at, purged_by = :by WHERE id = :id')
                ->execute([':at' => gmdate('c'), ':by' => $purgedBy, ':id' => $id]);
            $this->events->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->events->exec('ROLLBACK');
            throw $e;
        }
        return current(array_filter($this->archives(), fn($a) => $a['id'] === $id));
    }

    /** @return list<string> the actions present, for the filter list */
    public function actions(): array
    {
        return $this->events->query('SELECT DISTINCT action FROM events ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array{list<string>, array<string,mixed>} */
    private function where(array $filters): array
    {
        $where = [];
        $params = [];
        if (isset($filters['from'])) {
            $where[] = 'ts >= :from';
            $params[':from'] = $filters['from'];
        }
        if (isset($filters['until'])) {
            $where[] = 'ts < :until';
            $params[':until'] = $filters['until'];
        }
        if (isset($filters['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = $filters['action'];
        }
        if (isset($filters['username'])) {
            $where[] = 'username = :username';
            $params[':username'] = $filters['username'];
        }
        if (isset($filters['ip'])) {
            // The client, or the proxy that relayed the request
            $where[] = '(ip = :ip OR peer_ip = :ip)';
            $params[':ip'] = $filters['ip'];
        }
        if (isset($filters['text'])) {
            // Anywhere in the record: a file name, a SHA-256 (LIKE ignores ASCII case)
            $where[] = "record LIKE :text ESCAPE '\\'";
            $params[':text'] = '%' . addcslashes($filters['text'], '%_\\') . '%';
        }
        return [$where, $params];
    }
}
?>
