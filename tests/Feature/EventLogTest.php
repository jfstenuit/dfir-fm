<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/**
 * The events database (D27) and "no logging, no service" (D29), on the
 * running application. The halt's recovery (probe every 30 s) is covered
 * by LogEngineTeeTest.
 */
final class EventLogTest extends FeatureTestCase
{
    private function lastAction(): ?string
    {
        $events = $this->server->events();
        return $events ? $events[array_key_last($events)]['action'] : null;
    }

    public function testEveryEventInTheLogFileAndTheEventsDatabase(): void
    {
        $this->bobClient();
        $this->client()->login(TestServer::BOB, 'wrong password');

        $lines = file($this->server->root . '/app.log', FILE_IGNORE_NEW_LINES);
        $events = $this->server->events();
        $this->assertNotEmpty($events);
        $this->assertSame($lines, array_column($events, 'record'), 'same records, same order, ts included');
        $this->assertContains('login_password_success', array_column($events, 'action'));
        $this->assertContains('cli_admin_created', array_column($events, 'action'), 'the CLI scripts write there too');
        $this->assertSame('127.0.0.1', end($events)['ip']);
    }

    public function testLogEngineNone(): void
    {
        $this->restartWith(['log_engine' => 'none']);
        $this->bobClient();

        $this->assertSame('', file_get_contents($this->server->root . '/app.log'), 'no log file written');
        $this->assertContains('login_password_success', array_column($this->server->events(), 'action'));
    }

    public function testUnwritableEventsDatabaseRefusesToServe(): void
    {
        $readOnly = sys_get_temp_dir() . '/dfirfm-ro-' . bin2hex(random_bytes(4));
        mkdir($readOnly, 0500);
        try {
            $this->restartWith(['events_database_path' => "$readOnly/events/events.sqlite"], withFixtures: false);
            $this->assertSame(500, $this->client()->get('login')->status);
            $this->assertStringContainsString('events database', $this->server->serverLog());
        } finally {
            rmdir($readOnly);
        }
    }

    /**
     * A log file that can't be written once the application runs: the
     * action is done, the request answers 503, and so does every request
     * after it, without touching the destinations until the next probe.
     */
    public function testFailedWriteStopsTheApplication(): void
    {
        $admin = $this->adminClient();
        $token = $admin->get('/')->csrfToken();

        $log = $this->server->root . '/app.log';
        unlink($log);
        mkdir($log); // healthCheck still passes (its directory is writable), writes fail

        $response = $admin->post('admin', ['a' => 'lockUser', 'email' => TestServer::BOB], ['headers' => ['X-CSRF-TOKEN' => (string)$token]]);
        $this->assertSame(503, $response->status);
        $this->assertSame('logging_failed', $response->json()['code']);
        $this->assertNotNull($this->server->db()->query("SELECT locked_at FROM users WHERE username = 'bob@test.local'")->fetchColumn(),
            'the action is done');
        $this->assertSame('user_locked', $this->lastAction(), 'the events database has it');

        $page = $admin->get('/');
        $this->assertSame(503, $page->status);
        $this->assertStringContainsString('an event could not be logged', $page->body);
        $this->assertSame(503, $this->client()->get('login')->status);
        $this->assertSame('user_locked', $this->lastAction(), 'nothing else happened, nothing else logged');
        $this->assertStringContainsString('DFIR FM: logging failed (file: ', $this->server->serverLog());

        rmdir($log);
    }

    public function testFailedWriteInTheCliExitsWithCode1(): void
    {
        $log = $this->server->root . '/app.log';
        unlink($log);
        mkdir($log);

        $result = $this->server->runCreateAdmin('carol@test.local');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Error: Logging failed: file: ', $result['stderr']);
        $this->assertTrue($this->server->isAdmin('carol@test.local'), 'the action is done');
        $this->assertSame('cli_admin_created', $this->lastAction());

        rmdir($log);
    }
}
