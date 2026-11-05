<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/** Renaming files and folders over HTTP (#21; the rules: ItemRenameTest) */
final class RenameTest extends FeatureTestCase
{
    public function testFileRenamedAndDownloadedUnderItsNewName(): void
    {
        $id = $this->server->addFile('image.E01', 'disk image bytes');
        $this->server->grantRootAccess(TestServer::BOB);
        $bob = $this->bobClient();

        $response = $bob->postWithCsrf('', ['a' => 'rename', 'type' => 'f', 'id' => (string)$id, 'name' => 'laptop.E01']);
        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame(['type' => 'f', 'id' => $id, 'name' => 'laptop.E01', 'path' => '/laptop.E01', 'renamed' => true], $response->json()['item']);

        $download = $bob->get('dl', ['query' => ['p' => '/laptop.E01']]);
        $this->assertSame([200, 'disk image bytes'], [$download->status, $download->body]);
        $this->assertSame(404, $bob->get('dl', ['query' => ['p' => '/image.E01']])->status);
        $this->assertSame(['/image.E01', '/laptop.E01', hash('sha256', 'disk image bytes')],
            array_values(array_intersect_key($this->server->logEntries('file_renamed')[0], array_flip(['from', 'to', 'sha256']))));
    }

    public function testFolderRenamedWithItsSubfoldersAndRights(): void
    {
        $this->server->addDirectory('/case');
        $this->server->addDirectory('/case/disk');
        $this->server->grantAccess(TestServer::BOB, '/case/disk', view: true);
        mkdir($this->server->root . '/storage/files/case/disk', 0777, true);
        file_put_contents($this->server->root . '/storage/files/case/disk/mft.bin', 'MFT');
        $this->server->db()->exec("INSERT INTO files (directory_id, name, size, sha256) SELECT id, 'mft.bin', 3, '" . hash('sha256', 'MFT') . "' FROM directories WHERE path = '/case/disk'");
        $folder = $this->server->db()->query("SELECT id FROM directories WHERE path = '/case'")->fetchColumn();

        $response = $this->adminClient()->postWithCsrf('', ['a' => 'rename', 'type' => 'd', 'id' => (string)$folder, 'name' => 'case-001']);
        $this->assertSame(200, $response->status, $response->body);

        // bob's rights were on the subfolder: they follow it
        $bob = $this->bobClient();
        $this->assertStringContainsString('mft.bin', $bob->get('', ['query' => ['p' => '/case-001/disk']])->body);
        $this->assertSame([200, 'MFT'], [($d = $bob->get('dl', ['query' => ['p' => '/case-001/disk/mft.bin']]))->status, $d->body]);
        $this->assertSame(404, $bob->get('', ['query' => ['p' => '/case/disk']])->status);
        $this->assertSame([['/case', '/case-001', 1]], array_map(fn($e) => [$e['from'], $e['to'], $e['subfolders']], $this->server->logEntries('folder_renamed')));
    }

    public function testRefusals(): void
    {
        $id = $this->server->addFile('image.E01', 'x');
        $this->server->addFile('notes.txt', 'y');
        $this->server->addDirectory('/case');
        $admin = $this->adminClient();

        $cases = [
            [['type' => 'f', 'id' => (string)$id, 'name' => 'notes.txt'], 409, 'NameConflict'],
            [['type' => 'f', 'id' => (string)$id, 'name' => 'case'], 409, 'NameConflict'],
            [['type' => 'f', 'id' => (string)$id, 'name' => 'a/b'], 400, 'InvalidName'],
            [['type' => 'd', 'id' => '1', 'name' => 'root'], 400, 'InvalidArgument'],
            [['type' => 'f', 'id' => 'x', 'name' => 'y'], 400, 'InvalidArgument'],
            [['type' => 'f', 'id' => '999', 'name' => 'y'], 404, 'NotFound'],
            [['type' => 'f', 'id' => (string)$id, 'name' => ['array']], 400, 'InvalidArgument'],
        ];
        foreach ($cases as [$fields, $status, $code]) {
            $response = $admin->postWithCsrf('', ['a' => 'rename'] + $fields);
            $this->assertSame([$status, $code, false], [$response->status, $response->json()['code'] ?? null, $response->json()['success'] ?? null], json_encode($fields));
        }
        $this->assertSame([], $this->server->logEntries('file_renamed'));
    }

    public function testRefusedWithoutTheWriteRight(): void
    {
        $id = $this->server->addFile('image.E01', 'x');
        $this->server->grantAccess(TestServer::BOB, '/', view: true, upload: true);
        $bob = $this->bobClient();

        $response = $bob->postWithCsrf('', ['a' => 'rename', 'type' => 'f', 'id' => (string)$id, 'name' => 'mine.E01']);
        $this->assertSame([404, 'NotFound'], [$response->status, $response->json()['code']]);
        $this->assertSame('image.E01', $this->server->db()->query("SELECT name FROM files WHERE id = $id")->fetchColumn());
        $this->assertStringNotContainsString('rename-action', $bob->get('')->body, 'no pencil without the right');

        $this->assertStringContainsString('rename-action', $this->adminClient()->get('')->body);
    }
}
