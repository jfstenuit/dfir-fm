<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

/**
 * Security headers, routing of unknown paths, configuration errors.
 */
final class HttpBehaviourTest extends FeatureTestCase
{
    private function assertSecurityHeaders(\Tests\Support\HttpResponse $response, string $case): void
    {
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'), $case);
        $this->assertSame('same-origin', $response->header('Referrer-Policy'), $case);
        $this->assertStringContainsString("default-src 'self'", (string)$response->header('Content-Security-Policy'), $case);
    }

    public function testSecurityHeadersOnEveryKindOfResponse(): void
    {
        $bob = $this->bobClient();
        $anonymous = $this->client();

        $this->assertSecurityHeaders($anonymous->get('login'), 'login page');
        $this->assertSecurityHeaders($anonymous->get(''), 'redirect to login');
        $this->assertSecurityHeaders($anonymous->post('login'), 'CSRF 403');
        $this->assertSecurityHeaders($bob->get('no/such/route'), '404');
        $this->assertSecurityHeaders($bob->get('admin'), 'admin 403');
        $this->assertSecurityHeaders($bob->get(''), 'file manager');
    }

    public function testUnknownRoutes(): void
    {
        $this->assertSame(302, $this->client()->get('no/such/route')->status, 'anonymous: redirect to login');
        $this->assertSame(404, $this->bobClient()->get('no/such/route')->status);
    }

    public function testInvalidTrustedProxiesStopsTheApplication(): void
    {
        $this->restartWith(['trusted_proxies' => '127.0.0.1, not-an-address']);
        $this->assertSame(500, $this->client()->get('login')->status);
        $this->assertStringContainsString("Invalid trusted_proxies entry: 'not-an-address'", $this->server->serverLog());
    }

    public function testInvalidForceHttpsStopsTheApplication(): void
    {
        $this->restartWith(['force_https' => 'maybe']);
        $this->assertSame(500, $this->client()->get('login')->status);
        $this->assertStringContainsString("Invalid force_https value: 'maybe'", $this->server->serverLog());
    }

    public function testUnreadableEnvStopsTheApplication(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can read any file');
        }
        chmod($this->server->root . '/.env', 0);
        $this->assertSame(500, $this->client()->get('login')->status);
        chmod($this->server->root . '/.env', 0600);
        // The uncaught exception is the expected outcome here
        $this->assertStringContainsString('Environment file not readable', $this->server->serverLog());
        $this->server->stop();
        $this->server = (new \Tests\Support\TestServer())->start();
    }

    public function testStderrLogEngine(): void
    {
        $this->restartWith(['log_engine' => 'stderr']);
        $this->bobClient();
        usleep(200_000);

        $this->assertStringContainsString('"action":"login_password_success"', $this->server->serverLog());
        $this->assertSame([], $this->server->appLog(), 'nothing written to the file log');
    }
}
