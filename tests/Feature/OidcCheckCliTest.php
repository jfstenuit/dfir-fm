<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Unit\Services\Oidc\OidcConfigTest;

/**
 * bin/oidc-check.php (#9, Phase 16 step 2), without an IdP: the checks
 * themselves are unit-tested (ProviderCheckTest), and were run against the
 * dev IdP by hand
 */
final class OidcCheckCliTest extends FeatureTestCase
{
    public function testUsage(): void
    {
        foreach ([['--login'], ['--json'], ['extra']] as $args) {
            $this->assertSame(2, $this->server->runBin('oidc-check.php', ...$args)['exit'], implode(' ', $args));
        }
        $this->assertSame([], $this->server->logEntries('cli_oidc_check'));
    }

    public function testNotConfigured(): void
    {
        $result = $this->server->runBin('oidc-check.php');
        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('FAIL  Configuration: OIDC is not configured', $result['stderr']);

        $logged = $this->server->logEntries('cli_oidc_check');
        $this->assertCount(1, $logged);
        $this->assertSame('config_error', $logged[0]['result']);
        $this->assertStringStartsWith('cli:', $logged[0]['username']);
    }

    public function testPartialConfiguration(): void
    {
        $this->restartWith(['oidc_issuer' => 'https://idp.example.com/', 'oidc_client_secret' => 'S3cr3t-value']);
        $result = $this->server->runBin('oidc-check.php');
        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('missing oidc_client_id, oidc_redirect_uri, oidc_email_domain, oidc_admin_claim, oidc_admin_value', $result['stderr']);
        $this->assertStringNotContainsString('S3cr3t-value', $result['stdout'] . $result['stderr']);
        $this->assertStringNotContainsString('S3cr3t-value', json_encode($this->server->logEntries('cli_oidc_check')));
    }

    public function testUnreachableIdp(): void
    {
        // Port 1 on the loopback: refused at once
        $this->restartWith(['oidc_issuer' => 'https://127.0.0.1:1/'] + OidcConfigTest::COMPLETE);
        $result = $this->server->runBin('oidc-check.php');
        $this->assertSame(1, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('OK    Configuration: https://127.0.0.1:1/, client dfir-fm, domain example.com', $result['stdout']);
        $this->assertStringContainsString('FAIL  Discovery document: IdP request failed: https://127.0.0.1:1/.well-known/openid-configuration', $result['stdout']);
        $this->assertStringNotContainsString('S3cr3t-value', $result['stdout']);

        $logged = $this->server->logEntries('cli_oidc_check');
        $this->assertSame(['fail', ['Discovery document'], 'https://127.0.0.1:1/'], [$logged[0]['result'], $logged[0]['failed'], $logged[0]['issuer']]);
    }
}
