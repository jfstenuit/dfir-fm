<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;

/**
 * X-Forwarded-For with no trusted proxy: config_warning logged at most once
 * per hour, and a banner on the admin page (DFM-11).
 */
final class ProxyWarningTest extends FeatureTestCase
{
    private function behindProxy(HttpClient $client): HttpClient
    {
        $client->defaultHeaders = ['X-Forwarded-For' => '203.0.113.7'];
        return $client;
    }

    public function testWarningLoggedOnceWithForwardedHeader(): void
    {
        $client = $this->behindProxy($this->client());
        $client->get('login');
        $client->get('login');
        $this->behindProxy($this->client())->get('');

        $warnings = $this->server->logEntries('config_warning');
        $this->assertCount(1, $warnings);
        $this->assertSame('trusted_proxies', $warnings[0]['setting']);
    }

    public function testNoWarningWithoutForwardedHeader(): void
    {
        $this->client()->get('login');
        $this->assertSame([], $this->server->logEntries('config_warning'));
        $this->assertStringNotContainsString('id="proxyWarning"', $this->adminClient()->get('admin')->body);
    }

    public function testNoWarningWithTrustedProxy(): void
    {
        $this->restartWith(['trusted_proxies' => '127.0.0.1']);
        $admin = $this->behindProxy($this->adminClient());
        $this->assertStringNotContainsString('id="proxyWarning"', $admin->get('admin')->body);
        $this->assertSame([], $this->server->logEntries('config_warning'));
    }

    public function testBannerOnAdminPage(): void
    {
        // Seen once through the proxy: the banner stays for the period, even on a direct request
        $this->behindProxy($this->client())->get('login');
        $page = $this->adminClient()->get('admin');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('id="proxyWarning"', $page->body);
        $this->assertStringContainsString('trusted_proxies', $page->body);

        // Not shown to anyone else
        $this->assertStringNotContainsString('id="proxyWarning"', $this->bobClient()->get('')->body);
    }
}
