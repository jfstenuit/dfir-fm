<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

final class CreateAdminCliTest extends FeatureTestCase
{
    public function testCreatesAdminWithWorkingPassword(): void
    {
        // The fixture admin was created by bin/create-admin.php
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $this->server->adminPassword);
        $this->adminClient();
        $this->assertNotEmpty($this->server->logEntries('cli_admin_created'));
    }

    public function testRejectsMissingArgument(): void
    {
        $result = $this->server->runCreateAdmin();
        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('Usage', $result['stderr']);
    }

    public function testResetsExistingUserAndPromotesToAdmin(): void
    {
        $result = $this->server->runCreateAdmin(TestServer::BOB);
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('Updated existing', $result['stdout']);
        preg_match('/^Password.*: (\S+)$/m', $result['stdout'], $m);

        $bob = $this->client();
        $this->assertTrue($bob->login(TestServer::BOB, $m[1])->json()['success']);
        $this->assertSame(200, $bob->get('admin')->status);
    }

    public function testRefusesToRunOverHttp(): void
    {
        // bin/ isn't routed by the application; requesting it ends in index.php
        $this->assertNotSame(200, $this->client()->get('bin/create-admin.php')->status);
    }
}
