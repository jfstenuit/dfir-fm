<?php
namespace Tests\Unit\Backends;

use Backends\LogEntry;
use Core\Request;
use PHPUnit\Framework\TestCase;

final class LogEntryTest extends TestCase
{
    private array $savedServer;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        Request::configureTrustedProxies('');
    }

    public function testActionCannotBeOverriddenByContext(): void
    {
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.7'];
        $entry = LogEntry::build('test_event', ['action' => 'override-attempt', 'x' => 1]);
        $this->assertSame('test_event', $entry['action']);
        $this->assertSame(1, $entry['x']);
    }

    public function testClientAndPeerBehindTrustedProxy(): void
    {
        Request::configureTrustedProxies('127.0.0.1');
        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.20'];
        $entry = LogEntry::build('test_event');
        $this->assertSame('198.51.100.20', $entry['ip']);
        $this->assertSame('127.0.0.1', $entry['peer_ip']);
    }

    public function testNoPeerIpOnDirectConnection(): void
    {
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.7'];
        $entry = LogEntry::build('test_event');
        $this->assertSame('203.0.113.7', $entry['ip']);
        $this->assertArrayNotHasKey('peer_ip', $entry);
    }
}
