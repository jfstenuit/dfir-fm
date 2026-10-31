<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;
use Tests\Support\TestServer;

/**
 * #16: idle timeout and absolute lifetime from the settings, the
 * application's own session directory, logged client IP changes.
 */
final class SessionLifetimeTest extends FeatureTestCase
{
    private function assertExpired(HttpClient $client, string $cause): void
    {
        $page = $client->get('');
        $this->assertSame(302, $page->status);
        $this->assertStringStartsWith('login?redirect=', $page->header('Location'));
        $logged = $this->server->logEntries('session_expired');
        $this->assertCount(1, $logged);
        $this->assertSame($cause, $logged[0]['cause']);
        $this->assertSame(TestServer::BOB, $logged[0]['username']);
        $this->assertSame(302, $client->get('')->status, 'the session is gone, not only refused once');
    }

    public function testIdleTimeout(): void
    {
        $this->restartWith(['session_idle_timeout' => '1']);
        $bob = $this->bobClient();
        sleep(2);
        $this->assertExpired($bob, 'idle');
    }

    public function testActivityKeepsTheSessionWithinTheIdleTimeout(): void
    {
        $this->restartWith(['session_idle_timeout' => '2']);
        $bob = $this->bobClient();
        for ($i = 0; $i < 3; $i++) {
            sleep(1);
            $this->assertSame(200, $bob->get('')->status, "request $i");
        }
        $this->assertSame([], $this->server->logEntries('session_expired'));
    }

    public function testMaxLifetimeEndsAnActiveSession(): void
    {
        $this->restartWith(['session_max_lifetime' => '2']);
        $bob = $this->bobClient();
        sleep(1);
        $this->assertSame(200, $bob->get('')->status);
        sleep(2);
        $this->assertExpired($bob, 'max_lifetime');
        $this->assertSame(200, $this->bobClient()->get('')->status, 'a new login starts a new lifetime');
    }

    public function testSessionFilesInTheApplicationDirectory(): void
    {
        $this->bobClient();
        $directory = $this->server->root . '/storage/sessions';
        $this->assertSame(0700, fileperms($directory) & 0777);
        $this->assertNotEmpty(glob("$directory/sess_*"));
    }

    public function testUnwritableSessionDirectoryStopsTheApplication(): void
    {
        $this->restartWith(['session_save_path' => '/dev/null/sessions']);
        $this->assertSame(500, $this->client()->get('login')->status);
        $this->assertStringContainsString('session directory not writable: /dev/null/sessions', $this->server->serverLog());
    }

    public function testClientIpChangeIsLoggedNotRefused(): void
    {
        $this->restartWith(['trusted_proxies' => '127.0.0.1']);
        $bob = $this->client();
        $bob->defaultHeaders = ['X-Forwarded-For' => '203.0.113.7'];
        $this->assertTrue($bob->login(TestServer::BOB, TestServer::BOB_PASSWORD)->json()['success']);
        $this->assertSame(200, $bob->get('')->status);
        $this->assertSame([], $this->server->logEntries('session_ip_changed'));

        $bob->defaultHeaders = ['X-Forwarded-For' => '198.51.100.9'];
        $this->assertSame(200, $bob->get('')->status);
        $this->assertSame(200, $bob->get('')->status);

        $changes = $this->server->logEntries('session_ip_changed');
        $this->assertCount(1, $changes, 'logged once per change');
        $this->assertSame('203.0.113.7', $changes[0]['previous_ip']);
        $this->assertSame('198.51.100.9', $changes[0]['ip']);
        $this->assertSame(TestServer::BOB, $changes[0]['username']);
    }
}
