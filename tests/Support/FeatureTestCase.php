<?php
// tests/Support/FeatureTestCase.php
namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base class for HTTP-level tests. Each test gets a fresh, isolated server
 * (database, storage, APCu counters), configured by serverEnv().
 */
abstract class FeatureTestCase extends TestCase
{
    protected TestServer $server;

    /** Extra .env settings for this test class */
    protected function serverEnv(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        $this->server = (new TestServer($this->serverEnv()))->start();
    }

    protected function tearDown(): void
    {
        $errors = $this->server->phpErrors();
        $this->server->stop();
        $this->assertSame([], $errors, 'PHP errors in the server log');
    }

    protected function client(): HttpClient
    {
        return $this->server->client();
    }

    protected function adminClient(): HttpClient
    {
        $client = $this->client();
        $this->assertTrue($client->login(TestServer::ADMIN, $this->server->adminPassword)->json()['success'] ?? false, 'admin login');
        return $client;
    }

    protected function bobClient(): HttpClient
    {
        $client = $this->client();
        $this->assertTrue($client->login(TestServer::BOB, TestServer::BOB_PASSWORD)->json()['success'] ?? false, 'bob login');
        return $client;
    }

    /** Restart with a different configuration, e.g. for config-error tests */
    protected function restartWith(array $env, bool $withFixtures = true): void
    {
        $this->server->stop();
        $this->server = (new TestServer($env, $withFixtures))->start();
    }
}
