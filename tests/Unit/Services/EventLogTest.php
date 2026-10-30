<?php
namespace Tests\Unit\Services;

use Backends\LogEngineEvents;
use Core\EventStore;
use PHPUnit\Framework\TestCase;
use Services\EventLog;

final class EventLogTest extends TestCase
{
    private string $dir;
    private LogEngineEvents $writer;
    private EventLog $log;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-eventlog-' . bin2hex(random_bytes(4));
        $this->writer = new LogEngineEvents("$this->dir/events.sqlite");
        for ($i = 1; $i <= 5; $i++) {
            $this->add($i);
        }
        // The reader has its own connection, as in the application
        $this->log = new EventLog(EventStore::open("$this->dir/events.sqlite"));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function add(int|string $n): void
    {
        $this->writer->write(['ts' => '2026-09-01T10:00:00+00:00', 'action' => 'fixture', 'n' => $n]);
    }

    public function testEventsLoggedDuringTheExportAreLeftOut(): void
    {
        $out = '';
        $result = $this->log->export([], 'jsonl', function (string $line) use (&$out) {
            $out .= $line;
            $this->add('during');
            return true;
        });
        $this->assertSame([1, 2, 3, 4, 5], array_map(fn($l) => json_decode($l, true)['n'], explode("\n", rtrim($out))));
        $this->assertSame(['events' => 5, 'sha256' => hash('sha256', $out), 'last_event_id' => 5, 'complete' => true], $result);
    }

    public function testClientGoneAway(): void
    {
        $lines = 0;
        $result = $this->log->export([], 'csv', function () use (&$lines) {
            return ++$lines < 3; // the header and one event get through
        });
        $this->assertFalse($result['complete']);
        $this->assertSame(1, $result['events']);
        $this->assertSame(3, $lines, 'stops at the first failed write');
    }

    public function testEmptyStore(): void
    {
        $empty = new EventLog(EventStore::open("$this->dir/empty.sqlite"));
        $result = $empty->export([], 'jsonl', fn() => true);
        $this->assertSame(['events' => 0, 'sha256' => hash('sha256', ''), 'last_event_id' => null, 'complete' => true], $result);
    }

    public function testUnknownFormatRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->export([], 'xml', fn() => true);
    }
}
