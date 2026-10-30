<?php
namespace Tests\Unit\Services;

use Backends\LogEngineEvents;
use Core\EventStore;
use PHPUnit\Framework\TestCase;
use Services\EventImport;
use Services\EventLog;

final class EventImportTest extends TestCase
{
    private string $dir;
    private \PDO $events;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-import-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->events = EventStore::open("$this->dir/events.sqlite");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private static function line(int $day, string $action = 'upload_completed', array $more = []): string
    {
        return json_encode(['ts' => sprintf('2026-09-%02dT10:00:00+00:00', $day), 'action' => $action, 'day' => $day] + $more
                           + ['ip' => '127.0.0.1', 'username' => null], JSON_UNESCAPED_SLASHES);
    }

    private function file(string $name, array $lines): string
    {
        file_put_contents("$this->dir/$name", implode("\n", $lines) . "\n");
        return "$this->dir/$name";
    }

    /** @return list<string> the stored records, by id */
    private function records(): array
    {
        return $this->events->query('SELECT record FROM events ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testImportIntoAnEmptyStore(): void
    {
        // As the stderr engine wrote them before: escaped slashes, kept as they are
        $escaped = '{"ts":"2026-09-03T10:00:00+00:00","action":"download_success","file":"a\/b.txt","ip":"10.0.0.1","peer_ip":"10.0.0.2","username":"ann@x"}';
        $path = $this->file('dfir-fm.log', [
            self::line(1), '', 'not json at all', '[1,2]', '{"ts":"yesterday","action":"x"}', '{"ts":"2026-09-02T10:00:00+00:00"}',
            self::line(2), $escaped, '{"ts":"2099-01-01T00:00:00+00:00","action":"from_the_future"}',
        ]);
        $result = (new EventImport($this->events))->import($path);

        $this->assertSame(['file' => $path, 'sha256' => hash_file('sha256', $path), 'imported' => 3, 'already_stored' => 0,
                           'too_recent' => 1, 'purged_period' => 0, 'malformed' => 4], $result);
        $this->assertSame([self::line(1), self::line(2), $escaped], $this->records(), 'stored exactly as read');
        $row = $this->events->query("SELECT ts, action, username, ip, peer_ip FROM events WHERE action = 'download_success'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['ts' => '2026-09-03T10:00:00+00:00', 'action' => 'download_success', 'username' => 'ann@x',
                           'ip' => '10.0.0.1', 'peer_ip' => '10.0.0.2'], $row);
    }

    public function testSecondRunAndOverlapImportNothingTwice(): void
    {
        $import = new EventImport($this->events);
        $import->import($this->file('a.log', [self::line(1), self::line(2), self::line(3)]));

        $this->assertSame(0, $import->import("$this->dir/a.log")['imported']);
        $overlap = $import->import($this->file('b.log', [self::line(3), self::line(4), self::line(5)]));
        $this->assertSame([2, 1], [$overlap['imported'], $overlap['already_stored']]);
        $this->assertCount(5, $this->records());
    }

    public function testCompressedFiles(): void
    {
        $plain = implode("\n", [self::line(1), self::line(2)]) . "\n";
        file_put_contents("$this->dir/dfir-fm.log.2.gz", gzencode($plain));
        $result = (new EventImport($this->events))->import("$this->dir/dfir-fm.log.2.gz");
        $this->assertSame(2, $result['imported']);
        $this->assertSame(hash_file('sha256', "$this->dir/dfir-fm.log.2.gz"), $result['sha256'], 'the file as stored, compressed');
    }

    public function testFilesAreOrderedByTheirFirstEvent(): void
    {
        $current = $this->file('dfir-fm.log', ['', 'junk', self::line(9)]);
        $older = $this->file('dfir-fm.log.1', [self::line(5)]);
        file_put_contents("$this->dir/dfir-fm.log.2.gz", gzencode(self::line(1) . "\n"));
        $files = EventImport::inspect([$current, "$this->dir/dfir-fm.log.2.gz", $older]);
        $this->assertSame(["$this->dir/dfir-fm.log.2.gz", $older, $current], array_column($files, 'path'));
    }

    public function testCsvAndUnreadableFilesRefused(): void
    {
        $csv = $this->file('dfir-fm.csv', ['"2026-09-01T10:00:00+00:00","login_password_success","127.0.0.1","bob"']);
        try {
            EventImport::inspect([$csv]);
            $this->fail('CSV accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('is a CSV log', $e->getMessage());
        }
        $this->expectExceptionMessage('Cannot read');
        EventImport::inspect(["$this->dir/missing.log"]);
    }

    /** A purged period stays closed: its events are not imported again */
    public function testPurgedPeriodsAreNotImportedAgain(): void
    {
        $import = new EventImport($this->events);
        $path = $this->file('a.log', [self::line(1), self::line(2), self::line(3), self::line(4)]);
        $import->import($path);

        $log = new EventLog($this->events);
        $archive = $log->startArchive('2026-09-03T00:00:00+00:00', 'ann');
        $log->finishArchive($archive, $log->writeArchive($archive, fn() => true));
        $log->purge($archive['id'], 'ann');

        $again = $import->import($path);
        $this->assertSame([0, 2, 2], [$again['imported'], $again['purged_period'], $again['already_stored']]);
        $this->assertSame([self::line(3), self::line(4)], $this->records());
    }

    /** A store made before schema v2 started with its oldest event */
    public function testCreationTimeOfAnOlderStore(): void
    {
        $path = "$this->dir/v1.sqlite";
        $pdo = new \PDO("sqlite:$path");
        $pdo->exec('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT NOT NULL, action TEXT NOT NULL,
                    username TEXT, ip TEXT, peer_ip TEXT, record TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE archives (id INTEGER PRIMARY KEY AUTOINCREMENT, before_ts TEXT NOT NULL, max_event_id INTEGER NOT NULL,
                    events INTEGER NOT NULL, sha256 TEXT NOT NULL, created_at TEXT NOT NULL, created_by TEXT, purged_at TEXT, purged_by TEXT)');
        $pdo->exec("INSERT INTO events (ts, action, record) VALUES ('2026-10-21T08:00:00+00:00', 'a', '{}'), ('2026-10-20T07:00:00+00:00', 'b', '{}')");
        $pdo->exec('PRAGMA user_version = 1');
        $pdo = null;

        $store = EventStore::open($path);
        $this->assertSame(2, (int)$store->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame('2026-10-20T07:00:00+00:00', EventStore::createdAt($store));

        (new LogEngineEvents($path))->write(['ts' => '2026-11-01T00:00:00+00:00', 'action' => 'later']);
        $this->assertSame('2026-10-20T07:00:00+00:00', EventStore::createdAt(EventStore::open($path)), 'fixed once set');
    }
}
