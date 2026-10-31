<?php
namespace Tests\Unit\Backends;

use Backends\LogDestination;
use Backends\LogEngineEvents;
use Backends\LogEngineFile;
use Backends\LogEngineTee;
use Backends\LogHalt;
use Backends\LogWriteException;
use PHPUnit\Framework\TestCase;

/**
 * The logger (D27) and the halt (D29). APCu is enabled for the CLI by
 * `composer test` (apc.enable_cli=1, required by tests/bootstrap.php);
 * each test uses its own halt scope.
 */
final class LogEngineTeeTest extends TestCase
{
    private string $dir;
    private LogHalt $halt;
    private string $scope;
    private string|false $savedErrorLog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-tee-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->scope = $this->dir;
        $this->halt = new LogHalt($this->scope);
        // The halt's messages for the operator, kept out of the test output
        $this->savedErrorLog = ini_set('error_log', "$this->dir/php-error.log");
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string)$this->savedErrorLog);
        $this->halt->clear();
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . '; rm -rf ' . escapeshellarg($this->dir));
    }

    private function events(): LogEngineEvents
    {
        return new LogEngineEvents("$this->dir/events/events.sqlite");
    }

    /** @return list<array> rows of the events table */
    private function rows(LogEngineEvents $events): array
    {
        return $events->pdo()->query('SELECT * FROM events ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** A destination that fails while $failing is set, and keeps what it got */
    private function switchable(string $name): LogDestination
    {
        return new class($name) implements LogDestination {
            public bool $failing = false;
            public array $written = [];
            public function __construct(private string $label) {}
            public function name(): string { return $this->label; }
            public function write(array $entry): void
            {
                if ($this->failing) {
                    throw new \RuntimeException("$this->label is down");
                }
                $this->written[] = $entry;
            }
            public function healthCheck(): bool { return true; }
        };
    }

    public function testSameRecordInTheFileAndTheEventsDatabase(): void
    {
        $events = $this->events();
        $file = new LogEngineFile("$this->dir/app.log", 'jsonl');
        $tee = new LogEngineTee([$events, $file], $this->halt);

        $tee->log('upload_completed', ['file' => 'disk image.E01', 'sha256' => str_repeat('ab', 32), 'username' => 'ann', 'ip' => '203.0.113.9']);
        $tee->log('group_deleted', ['group' => 'g', 'members' => 2, 'nested' => ['a' => 1]]);

        $lines = file("$this->dir/app.log", FILE_IGNORE_NEW_LINES);
        $rows = $this->rows($events);
        $this->assertCount(2, $rows);
        $this->assertSame($lines, array_column($rows, 'record'), 'the record column is the log line, ts included');

        $this->assertSame(['upload_completed', 'ann', '203.0.113.9', null], [$rows[0]['action'], $rows[0]['username'], $rows[0]['ip'], $rows[0]['peer_ip']]);
        $this->assertSame(json_decode($lines[0], true)['ts'], $rows[0]['ts']);
        $this->assertNull($rows[1]['username'], 'no session: no username');
    }

    public function testEventsOnly(): void
    {
        $events = $this->events();
        $tee = new LogEngineTee([$events], $this->halt);
        $tee->log('login_password_success', ['username' => 'bob']);
        $this->assertSame(['login_password_success'], array_column($this->rows($events), 'action'));
        $this->assertSame([], glob("$this->dir/*.log"));
    }

    public function testSchemaVersionAndWal(): void
    {
        $pdo = $this->events()->pdo();
        $this->assertSame(2, (int)$pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        $this->assertSame(['archives', 'events', 'store'], $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN));
        // A second open finds the schema in place
        $this->assertSame(2, (int)$this->events()->pdo()->query('PRAGMA user_version')->fetchColumn());
    }

    public function testCsvWithNullAndNestedValues(): void
    {
        $file = new LogEngineFile("$this->dir/app.csv", 'csv');
        $file->write(['ts' => 't', 'action' => 'a', 'username' => null, 'members' => ['x', 'y'], 'n' => 3]);
        $this->assertSame('"t","a","","[\\"x\\",\\"y\\"]","3"' . "\n", file_get_contents("$this->dir/app.csv"));
    }

    public function testInvalidUtf8IsReplacedNotLost(): void
    {
        $events = $this->events();
        (new LogEngineTee([$events], $this->halt))->log('upload_completed', ['file' => "caf\xE9.txt"]);
        $this->assertSame("caf\u{FFFD}.txt", json_decode($this->rows($events)[0]['record'], true)['file']);
    }

    public function testUnwritableEventsDatabaseFailsTheHealthCheck(): void
    {
        mkdir("$this->dir/events", 0500);
        $this->assertFalse(@$this->events()->healthCheck());
        $this->assertTrue((new LogEngineEvents("$this->dir/ok/events.sqlite"))->healthCheck());
    }

    public function testFailedWriteReachesTheOthersThenHalts(): void
    {
        $events = $this->events();
        $copy = $this->switchable('file');
        $copy->failing = true;
        $tee = new LogEngineTee([$events, $copy], $this->halt);

        try {
            @$tee->log('file_deleted', ['file' => 'x']);
            $this->fail('no exception');
        } catch (LogWriteException $e) {
            $this->assertSame(['file' => 'file is down'], $e->failures);
        }
        $this->assertSame(['file_deleted'], array_column($this->rows($events), 'action'), 'the events database still has it');

        $halt = $this->halt->current();
        $this->assertSame(['file' => 'file is down'], $halt['failures']);
        $this->assertSame([], $halt['recorded']);
        $this->assertFalse($tee->resume(), 'halted, and the first probe waits a full interval');
    }

    public function testTheEventsDatabaseFailingToo(): void
    {
        mkdir("$this->dir/events", 0500);
        $copy = $this->switchable('file');
        $tee = new LogEngineTee([$this->events(), $copy], $this->halt);

        $this->expectException(LogWriteException::class);
        try {
            @$tee->log('file_deleted');
        } finally {
            $this->assertCount(1, $copy->written, 'the copy still has it');
            $this->assertArrayHasKey('events', $this->halt->current()['failures']);
        }
    }

    public function testRecoveryLogsTheGap(): void
    {
        $events = $this->events();
        $copy = $this->switchable('file');
        $tee = new LogEngineTee([$events, $copy], $this->halt);
        $copy->failing = true;
        try {
            @$tee->log('upload_completed');
        } catch (LogWriteException) {
        }
        $since = $this->halt->current()['since'];

        // Still failing at the next probe: halted, the events database already holds the record
        $this->expireProbe();
        $this->assertFalse(@$tee->resume());
        $this->assertSame(['events'], $this->halt->current()['recorded']);
        $this->assertFalse($tee->resume(), 'at most one probe per interval');
        $this->assertStringContainsString('logging still failing (file: file is down)', file_get_contents("$this->dir/php-error.log"));

        // Fixed: the copy gets the record too, and the application serves again
        $copy->failing = false;
        $this->expireProbe();
        $this->assertTrue(@$tee->resume());
        $this->assertNull($this->halt->current());
        $this->assertTrue($tee->resume(), 'not halted any more');

        $recorded = array_values(array_filter($this->rows($events), fn($r) => $r['action'] === 'logging_failure'));
        $this->assertCount(1, $recorded, 'recorded once in each destination');
        $record = json_decode($recorded[0]['record'], true);
        $this->assertSame($since, $record['failed_from']);
        $this->assertSame(['file' => 'file is down'], $record['failures']);
        $this->assertSame("logging failed between $since and {$record['failed_until']} - application was stopped", $record['message']);
        $this->assertCount(1, $copy->written);
        $this->assertSame('logging_failure', $copy->written[0]['action']);

        $tee->log('login_password_success');
        $this->assertSame('login_password_success', $copy->written[1]['action']);
    }

    public function testNoHaltOutsideTheWebServer(): void
    {
        $copy = $this->switchable('file');
        $copy->failing = true;
        $tee = new LogEngineTee([$copy]);
        try {
            @$tee->log('cli_admin_created');
        } catch (LogWriteException) {
        }
        $this->assertTrue($tee->resume());
    }

    public function testHaltsAreKeptApartPerInstallation(): void
    {
        $this->halt->start(['file' => 'down']);
        $this->assertNull((new LogHalt("$this->scope-other"))->current());
    }

    /** As if PROBE_INTERVAL had passed */
    private function expireProbe(): void
    {
        apcu_delete('log_halt_probe:' . substr(hash('sha256', $this->scope), 0, 16));
    }
}
