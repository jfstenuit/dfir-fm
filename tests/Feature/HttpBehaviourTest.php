<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/**
 * Security headers, routing of unknown paths, configuration errors.
 */
final class HttpBehaviourTest extends FeatureTestCase
{
    private function assertSecurityHeaders(\Tests\Support\HttpResponse $response, string $case): void
    {
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'), $case);
        $this->assertSame('same-origin', $response->header('Referrer-Policy'), $case);
        $csp = (string)$response->header('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp, $case);
        $this->assertStringContainsString("style-src 'self';", $csp, $case);
        $this->assertStringNotContainsString("'unsafe-inline'", $csp, $case);
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

    /** Pages, JSON answers and redirects are never cached; downloads keep their own private, no-store */
    public function testNoStoreOnEveryResponse(): void
    {
        $bob = $this->bobClient();
        $anonymous = $this->client();

        $this->assertSame('no-store', $anonymous->get('login')->header('Cache-Control'), 'login page');
        $this->assertSame('no-store', $anonymous->get('')->header('Cache-Control'), 'redirect to login');
        $this->assertSame('no-store', $bob->get('')->header('Cache-Control'), 'file manager');
        $json = $bob->postWithCsrf('', ['a' => 'bogus']);
        $this->assertSame('application/json', explode(';', (string)$json->header('Content-Type'))[0], 'JSON answer');
        $this->assertSame('no-store', $json->header('Cache-Control'), 'JSON answer');

        $this->server->addFile('evidence.txt', 'content');
        $download = $this->adminClient()->get('dl', ['query' => ['p' => '/evidence.txt']]);
        $this->assertSame(200, $download->status);
        $this->assertSame('private, no-store', $download->header('Cache-Control'), 'download');
    }

    /** Only assets/ and the built files of the three front-end packages are served (tests/Support/router.php mirrors .htaccess) */
    public function testStaticFileAllowlist(): void
    {
        $anonymous = $this->client();
        foreach (['assets/js/site.js', 'assets/css/style.css', 'vendor/twbs/bootstrap/dist/css/bootstrap.min.css',
                  'vendor/twbs/bootstrap/dist/js/bootstrap.bundle.min.js', 'vendor/twbs/bootstrap-icons/font/bootstrap-icons.min.css',
                  'vendor/twbs/bootstrap-icons/font/fonts/bootstrap-icons.woff2', 'vendor/enyo/dropzone/dist/dropzone.mjs',
                  'vendor/enyo/dropzone/dist/dropzone.css'] as $path) {
            $this->assertSame(200, $anonymous->get($path)->status, $path);
        }
        foreach (['vendor/twbs/bootstrap/js/index.esm.js', 'vendor/twbs/bootstrap/package.json', 'vendor/twbs/bootstrap-icons/eslint.config.mjs',
                  'vendor/enyo/dropzone/README.md', 'vendor/phpmailer/phpmailer/src/PHPMailer.php', 'vendor/autoload.php',
                  'assets/js/missing.js', 'vendor/components/jquery/jquery.min.js'] as $path) {
            $this->assertSame(404, $anonymous->get($path)->status, $path);
        }
    }

    public function testUnknownRoutes(): void
    {
        $page = $this->client()->get('no/such/route?x=1');
        $this->assertSame(302, $page->status, 'anonymous: redirect to login');
        // From the base path: a relative one would resolve to no/such/login, and loop
        $this->assertSame('/login?redirect=' . urlencode('/no/such/route?x=1'), $page->header('Location'));
        $this->assertSame(404, $this->bobClient()->get('no/such/route')->status);
    }

    /** OIDC disabled (no oidc_* key): its routes don't exist, for anyone (#9) */
    public function testOidcRoutesNotFoundWhenDisabled(): void
    {
        foreach ([$this->client(), $this->bobClient()] as $client) {
            foreach (['login/oidc', 'login/oidc/callback?code=x&state=y'] as $path) {
                $this->assertSame(404, $client->get($path)->status, $path);
            }
        }
        $this->assertSame([], $this->server->logEntries('login_oidc_failure'));
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

    /** apc.enabled=0: the extension is loaded, but rate limiting would silently do nothing (#14) */
    public function testDisabledApcuStopsTheApplication(): void
    {
        $this->restartWith([], true, ['apc.enabled' => '0']);
        $client = $this->client();

        $page = $client->get('login');
        $this->assertSame(503, $page->status);
        $this->assertStringContainsString('apc.enabled=1', $page->body);
        $this->assertSecurityHeaders($page, '503 page');

        $json = $client->post('login', ['username' => TestServer::BOB, 'password' => TestServer::BOB_PASSWORD]);
        $this->assertSame(503, $json->status);
        $this->assertSame('apcu_unavailable', $json->json()['code'] ?? null);

        $log = $this->server->serverLog();
        $this->assertStringContainsString('DFIR FM configuration error: APCu unavailable', $log);
        $this->assertStringNotContainsString('Uncaught', $log);
        $this->assertSame([], $this->server->logEntries('login_password_success'), 'nothing got through');
    }

    /** Some oidc_* keys but not all: refuse to run rather than run without the sign-in (#9) */
    public function testPartialOidcConfigurationStopsTheApplication(): void
    {
        $this->restartWith(['oidc_issuer' => 'https://idp.example.com/', 'oidc_client_secret' => 'S3cr3t-value']);
        $client = $this->client();

        $page = $client->get('login');
        $this->assertSame(503, $page->status);
        $this->assertStringContainsString('OIDC sign-in configuration', $page->body);
        $this->assertStringNotContainsString('oidc_client_id', $page->body, 'the details are in the log only');
        $this->assertSecurityHeaders($page, '503 page');

        $json = $client->post('login', ['username' => TestServer::BOB, 'password' => TestServer::BOB_PASSWORD]);
        $this->assertSame(503, $json->status);
        $this->assertSame('oidc_misconfigured', $json->json()['code'] ?? null);

        $log = $this->server->serverLog();
        $this->assertStringContainsString('DFIR FM configuration error: OIDC partially configured, missing oidc_client_id, oidc_redirect_uri', $log);
        $this->assertStringNotContainsString('S3cr3t-value', $log);
        $this->assertSame([], $this->server->logEntries('login_password_success'), 'nothing got through');
    }

    public function testInvalidOidcConfigurationStopsTheApplication(): void
    {
        $this->restartWith([
            'oidc_issuer' => 'https://login.microsoftonline.com/common/v2.0',
            'oidc_client_id' => 'dfir-fm',
            'oidc_client_secret' => 'S3cr3t-value',
            'oidc_redirect_uri' => 'https://fm.example.com/login/oidc/callback',
            'oidc_email_domain' => 'example.com',
            'oidc_admin_claim' => 'roles',
            'oidc_admin_value' => 'Admin',
        ]);
        $this->assertSame(503, $this->client()->get('login')->status);
        $this->assertStringContainsString('DFIR FM configuration error: OIDC oidc_issuer is a multi-tenant endpoint', $this->server->serverLog());
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
