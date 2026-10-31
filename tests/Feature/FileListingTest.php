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

    /** Who created a folder, and from where, only for its readers (#25) */
    public function testFolderCreatorHiddenFromContributors(): void
    {
        $this->server->addDirectory('/case-a', 1, '203.0.113.7');
        $this->server->grantAccess(TestServer::BOB, '/case-a');

        $body = $this->bobClient()->get('')->body;
        $this->assertStringContainsString('case-a', $body);
        $this->assertStringNotContainsString(TestServer::ADMIN, $body);
        $this->assertStringNotContainsString('203.0.113.7', $body);

        $this->server->grantAccess(TestServer::BOB, '/case-a', view: true);
        $body = $this->bobClient()->get('')->body;
        $this->assertStringContainsString(TestServer::ADMIN, $body);
        $this->assertStringContainsString('203.0.113.7', $body);
    }

    /** A contributor sees their own uploads with their address, nobody else's; a reader sees all */
    public function testContributorSeesOnlyOwnUploads(): void
    {
        $dir = $this->server->addDirectory('/case-a');
        $bobId = $this->server->db()->query("SELECT id FROM users WHERE username = '" . TestServer::BOB . "'")->fetchColumn();
        $insert = $this->server->db()->prepare("INSERT INTO files (directory_id, name, uploaded_by, uploaded_from) VALUES (:d, :n, :by, :from)");
        $insert->execute([':d' => $dir, ':n' => 'mine.bin', ':by' => $bobId, ':from' => '198.51.100.1']);
        $insert->execute([':d' => $dir, ':n' => 'theirs.bin', ':by' => 1, ':from' => '203.0.113.7']);
        $this->server->grantAccess(TestServer::BOB, '/case-a');

        $body = $this->bobClient()->get('', ['query' => ['p' => '/case-a']])->body;
        $this->assertStringContainsString('mine.bin', $body);
        $this->assertStringContainsString('198.51.100.1', $body);
        $this->assertStringNotContainsString('theirs.bin', $body);
        $this->assertStringNotContainsString('203.0.113.7', $body);

        $this->server->grantAccess(TestServer::BOB, '/case-a', view: true);
        $body = $this->bobClient()->get('', ['query' => ['p' => '/case-a']])->body;
        $this->assertStringContainsString('theirs.bin', $body);
        $this->assertStringContainsString('203.0.113.7', $body);
    }

    /**
     * The upload zone only where the server accepts uploads: the upload or
     * write right, or an admin (#26). It used to be on every page.
     */
    public function testUploadZoneOnlyWhereUploadIsAllowed(): void
    {
        $this->server->addDirectory('/case-a');
        $this->server->addDirectory('/case-b');
        $this->server->addDirectory('/case-c');
        $this->server->grantAccess(TestServer::BOB, '/case-a');
        $this->server->grantAccess(TestServer::BOB, '/case-b', view: true, upload: false);
        $this->server->grantAccess(TestServer::BOB, '/case-c', write: true, upload: false);

        $bob = $this->bobClient();
        $page = fn(string $p) => $bob->get('', ['query' => ['p' => $p]]);
        foreach (['/case-a' => true, '/case-c' => true, '/' => false, '/case-b' => false] as $path => $allowed) {
            $response = $page($path);
            $this->assertSame(200, $response->status, $path);
            $assert = $allowed ? 'assertStringContainsString' : 'assertStringNotContainsString';
            $this->$assert('id="fileUploader"', $response->body, $path);
            $this->$assert('data-bs-target="#uploadModal"', $response->body, $path);
            // The page script loads either way, with the current directory
            $this->assertStringContainsString('data-cwd="' . $path . '"', $response->body, $path);
        }

        foreach (['/', '/case-b'] as $path) {
            $this->assertStringContainsString('id="fileUploader"', $this->adminClient()->get('', ['query' => ['p' => $path]])->body, $path);
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
