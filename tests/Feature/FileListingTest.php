<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/** What the file manager page lists, per access rights */
final class FileListingTest extends FeatureTestCase
{
    /** Root files used to be hidden from non-admins: checkAccess() got '' instead of '/' */
    public function testUserWithRootAccessSeesRootFiles(): void
    {
        $this->server->grantRootAccess(TestServer::BOB);
        $this->server->addFile('evidence.txt', "hello\n");

        $page = $this->bobClient()->get('');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('evidence.txt', $page->body);
    }

    public function testUserWithoutRootAccessDoesNotSeeRootFiles(): void
    {
        $this->server->addFile('secret.txt', "classified\n");
        $this->assertStringNotContainsString('secret.txt', $this->bobClient()->get('')->body);
    }

    public function testAdminSeesRootFiles(): void
    {
        $this->server->addFile('evidence.txt', "hello\n");
        $this->assertStringContainsString('evidence.txt', $this->adminClient()->get('')->body);
    }
}
