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
     * @return array{events: int, sha256: string, last_event_id: ?int, complete: bool}
     */
    public function export(array $filters, string $format, callable $write): array
    {
        if (!in_array($format, self::EXPORT_FORMATS, true)) {
            throw new \InvalidArgumentException("Invalid format: $format");
        }
        $last = $this->events->query('SELECT MAX(id) FROM events')->fetchColumn();
        $last = $last === null ? null : (int)$last;

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
