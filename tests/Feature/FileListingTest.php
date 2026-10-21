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

    /**
     * Files of an uploader with no users row (accounts hard-deleted before
     * Phase 8) were hidden from every listing by an inner join on users
     */
    public function testFilesOfMissingUploaderAreListed(): void
    {
        $this->server->grantRootAccess(TestServer::BOB);
        $this->server->addFile('orphan.txt', "evidence\n", 999);

        foreach ([$this->adminClient(), $this->bobClient()] as $client) {
            $body = $client->get('')->body;
            $this->assertStringContainsString('orphan.txt', $body);
            $this->assertStringContainsString('unknown user #999', $body);
        }
    }

    public function testFoldersOfMissingCreatorAreListed(): void
    {
        $this->server->grantRootAccess(TestServer::BOB);
        $this->server->db()->exec("INSERT INTO directories (name, path, parent_id, created_by) VALUES ('case-a', '/case-a', 1, 999)");
        $this->server->db()->exec("INSERT INTO access_rights (group_id, directory_id, can_view)
                                   SELECT g.id, d.id, 1 FROM groups g, directories d WHERE g.name = 'writers' AND d.path = '/case-a'");

        foreach ([$this->adminClient(), $this->bobClient()] as $client) {
            $body = $client->get('')->body;
            $this->assertStringContainsString('case-a', $body);
            $this->assertStringContainsString('unknown user #999', $body);
        }
    }

    /** Upload names may contain <, > and &: they were printed raw (stored HTML injection) */
    public function testNamesAreEscaped(): void
    {
        $this->server->addFile('a <img src=x onerror=alert(1)> & "b".txt', "x\n");

        $body = $this->adminClient()->get('')->body;
        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringContainsString('>a &lt;img src=x onerror=alert(1)&gt; &amp; &quot;b&quot;.txt</a>', $body);
        $this->assertStringContainsString('data-name="a &lt;img src=x onerror=alert(1)&gt; &amp; &quot;b&quot;.txt"', $body);
    }
}
