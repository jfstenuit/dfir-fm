<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;
use Tests\Support\TestServer;

/**
 * Login throttling per client IP and per username, and the client IP
 * resolution it depends on (X-Forwarded-For bypass).
 */
final class LoginRateLimitTest extends FeatureTestCase
{
    private array $env = [];

    protected function serverEnv(): array
    {
        return $this->env;
    }

    /** Restart with settings; 127.0.0.1 trusted so X-Forwarded-For simulates client IPs */
    private function withSettings(array $env): void
    {
        $this->env = $env;
        $this->restartWith($env);
    }

    /** A fresh browser behind the given X-Forwarded-For header */
    private function from(string $xff): HttpClient
    {
        $client = $this->client();
        $client->defaultHeaders = ['X-Forwarded-For' => $xff, 'Client-IP' => $xff];
        return $client;
    }

    private function tryLogin(string $xff, string $user, string $password = 'wrong'): string
    {
        return $this->from($xff)->login($user, $password)->json()['message'] ?? 'success';
    }

    private function assertBlocked(string $message): void
    {
        $this->assertStringContainsString('Too many login attempts', $message);
    }

    private function assertNotBlocked(string $message): void
    {
        $this->assertStringNotContainsString('Too many login attempts', $message);
    }

    public function testForgedForwardedForDoesNotResetCounterWithoutTrustedProxy(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->tryLogin("10.0.0.$i", 'nobody@test.local');
        }
        $this->assertBlocked($this->tryLogin('10.0.0.99', 'nobody@test.local'));

        $failures = $this->server->logEntries('login_password_failure');
        $this->assertCount(6, $failures);
        $this->assertSame(['127.0.0.1'], array_values(array_unique(array_column($failures, 'ip'))), 'forged addresses never logged');
        $this->assertSame([], array_column($failures, 'peer_ip'), 'no peer_ip without a trusted proxy');
    }

    public function testForgedLeftEntriesIgnoredBehindTrustedProxy(): void
    {
        $this->withSettings(['trusted_proxies' => '127.0.0.1, ::1']);

        for ($i = 1; $i <= 5; $i++) {
            $this->tryLogin("10.0.0.$i, 198.51.100.20", 'nobody@test.local');
        }
        $this->assertBlocked($this->tryLogin('10.0.0.99, 198.51.100.20', 'nobody@test.local'));
        $this->assertNotBlocked($this->tryLogin('198.51.100.21', 'nobody@test.local'), 'another real client');

        $failures = $this->server->logEntries('login_password_failure');
        $this->assertSame('198.51.100.20', $failures[0]['ip']);
        $this->assertSame('127.0.0.1', $failures[0]['peer_ip']);
    }

    public function testConfiguredLimitsPerIpAndPerUsername(): void
    {
        $this->withSettings(['trusted_proxies' => '127.0.0.1', 'max_login_attempts' => '3',
            'max_login_attempts_per_user' => '4', 'login_lockout_duration' => '300']);

        // Per IP: 3, not the former hardcoded 5
        foreach (['a', 'b', 'c'] as $user) {
            $this->tryLogin('198.51.100.1', "$user@x");
        }
        $this->assertBlocked($this->tryLogin('198.51.100.1', 'd@x'));
        $this->assertNotBlocked($this->tryLogin('198.51.100.2', 'e@x'));

        // Per username, across IPs: 4, case-insensitive
        foreach ([11, 12, 13, 14] as $i) {
            $this->tryLogin("198.51.100.$i", 'victim@x');
        }
        $this->assertBlocked($this->tryLogin('198.51.100.15', 'victim@x'));
        $this->assertBlocked($this->tryLogin('198.51.100.16', 'VICTIM@X'));
        $this->assertNotBlocked($this->tryLogin('198.51.100.17', 'other@x'));

        $limits = array_column($this->server->logEntries('login_password_failure'), 'limit');
        $this->assertContains('ip', $limits);
        $this->assertContains('username', $limits);
    }

    public function testBlockedIpDoesNotLockOutTheAccountOwner(): void
    {
        $this->withSettings(['trusted_proxies' => '127.0.0.1', 'max_login_attempts' => '3', 'max_login_attempts_per_user' => '4']);

        for ($i = 0; $i < 8; $i++) {
            $this->tryLogin('198.51.100.1', TestServer::BOB);   // blocked after 3
        }
        $this->assertSame('success', $this->tryLogin('198.51.100.30', TestServer::BOB, TestServer::BOB_PASSWORD));
    }

    public function testSuccessResetsUsernameCounterButNotIpCounter(): void
    {
        $this->withSettings(['trusted_proxies' => '127.0.0.1', 'max_login_attempts' => '3']);

        $this->tryLogin('198.51.100.40', TestServer::BOB);
        $this->tryLogin('198.51.100.40', TestServer::BOB);
        $this->assertSame('success', $this->tryLogin('198.51.100.40', TestServer::BOB, TestServer::BOB_PASSWORD));
        // Otherwise an attacker could clear the IP counter by logging into their own account
        $this->assertBlocked($this->tryLogin('198.51.100.40', TestServer::BOB, TestServer::BOB_PASSWORD));
    }

    public function testInvalidSettingsFallBackToDefaults(): void
    {
        $this->withSettings(['max_login_attempts' => 'abc', 'login_lockout_duration' => '-5']);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertNotBlocked($this->tryLogin('ignored', "u$i@x"));
        }
        $this->assertBlocked($this->tryLogin('ignored', 'z@x'));
    }
}
