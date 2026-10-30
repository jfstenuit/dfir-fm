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
