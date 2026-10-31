<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;
use Tests\Support\TestServer;

/**
 * A folder the user has no right on must answer exactly like one that does
 * not exist, or a contributor can probe folder names (#24)
 */
final class DirectoryEnumerationTest extends FeatureTestCase
{
    /** bob is a contributor of /case-a only; /case-b exists too */
    private function contributor(): HttpClient
    {
        $this->server->addDirectory('/case-a');
        $this->server->addDirectory('/case-b');
        $this->server->grantAccess(TestServer::BOB, '/case-a');
        return $this->bobClient();
    }

    public function testFolderWithoutRightsAnswersLikeMissingOne(): void
    {
        $bob = $this->contributor();
        // On disk but never imported: absent from the database
        mkdir($this->server->root . '/storage/files/on-disk-only');

        $missing = $bob->get('', ['query' => ['p' => '/no-such-folder']]);
        $this->assertSame(404, $missing->status);
        foreach (['/case-b', '/on-disk-only', '/case-a/../case-b'] as $path) {
            $response = $bob->get('', ['query' => ['p' => $path]]);
            $this->assertSame([404, $missing->body], [$response->status, $response->body], $path);
        }

        $causes = array_column($this->server->logEntries('filemanager_failure'), 'cause', 'directory');
        $this->assertSame('Tried to access directory without rights', $causes['/case-b'], 'the attempt is logged');
        $this->assertSame('Tried to access invalid directory', $causes['/no-such-folder']);
    }

    public function testOwnFolderRootAndParentsStayReachable(): void
    {
        $bob = $this->contributor();
        $this->server->addDirectory('/case-b/inner');
        $this->server->grantAccess(TestServer::BOB, '/case-b/inner');

        foreach (['/', '/case-a', '/case-b/inner', '/case-b'] as $path) {
            $this->assertSame(200, $bob->get('', ['query' => ['p' => $path]])->status, $path);
        }
        // A parent lists the user's folder, nothing else
        $this->assertStringContainsString('inner', $bob->get('', ['query' => ['p' => '/case-b']])->body);
    }

    public function testAdminSeesEveryFolder(): void
    {
        $this->contributor();
        $this->assertSame(200, $this->adminClient()->get('', ['query' => ['p' => '/case-b']])->status);
    }

    public function testCreateFolderDoesNotTellWhichNamesExist(): void
    {
        $bob = $this->contributor();
        $existing = $bob->postWithCsrf('', ['a' => 'createFolder', 'cwd' => '/', 'name' => 'case-b'])->json();
        $missing = $bob->postWithCsrf('', ['a' => 'createFolder', 'cwd' => '/', 'name' => 'no-such-folder'])->json();
        $this->assertSame(['success' => false, 'message' => 'Access denied to directory'], $existing);
        $this->assertSame($missing, $existing);
    }

    public function testDeleteDoesNotTellWhichIdsExist(): void
    {
        $bob = $this->contributor();
        $folder = $this->server->db()->query("SELECT id FROM directories WHERE path = '/case-b'")->fetchColumn();
        $file = $this->server->addFile('secret.txt', "classified\n");

        foreach (['d' => $folder, 'f' => $file] as $type => $id) {
            $existing = $bob->postWithCsrf('', ['a' => 'deleteItem', 'type' => $type, 'id' => $id])->json();
            $missing = $bob->postWithCsrf('', ['a' => 'deleteItem', 'type' => $type, 'id' => 9999])->json();
            $this->assertFalse($existing['success']);
            $this->assertSame($missing, $existing, $type);
        }
        $this->assertFileExists($this->server->root . '/storage/files/secret.txt');
    }

    public function testUploadDoesNotTellWhichFoldersExist(): void
    {
        $bob = $this->contributor();
        $bob->defaultHeaders['X-CSRF-TOKEN'] = (string)$bob->get('')->csrfToken();
        $probe = fn(string $cwd) => $bob->post('upload', ['a' => 'checkRights', 'cwd' => $cwd, 'name' => 'x', 'size' => '1']);

        $existing = $probe('/case-b');
        $missing = $probe('/no-such-folder');
        $this->assertSame([404, 'NoSuchDirectory'], [$existing->status, $existing->json()['code']]);
        $this->assertSame([$missing->status, $missing->body], [$existing->status, $existing->body]);
        $this->assertSame(200, $probe('/case-a')->status);
    }
}
