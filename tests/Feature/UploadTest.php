<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;
use Tests\Support\HttpResponse;
use Tests\Support\TestServer;

/** POST /upload over HTTP, as Dropzone sends it (the state machine itself: ChunkedUploadTest) */
final class UploadTest extends FeatureTestCase
{
    private const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    private function uploader(): HttpClient
    {
        $this->server->grantRootAccess(TestServer::BOB);
        $client = $this->bobClient();
        $client->defaultHeaders['X-CSRF-TOKEN'] = (string)$client->get('')->csrfToken();
        return $client;
    }

    /** One Dropzone chunk request */
    private function chunk(HttpClient $client, string $bytes, int $index, int $chunkSize, string $name = 'dump.bin', array $override = []): HttpResponse
    {
        $count = max(1, (int)ceil(strlen($bytes) / $chunkSize));
        $data = substr($bytes, $index * $chunkSize, $chunkSize);
        return $client->post('upload', [], ['multipart' => $override + [
            'dzuuid' => self::UUID, 'dzchunkindex' => (string)$index, 'dztotalfilesize' => (string)strlen($bytes),
            'dzchunksize' => (string)$chunkSize, 'dztotalchunkcount' => (string)$count,
            'dzchunkbyteoffset' => (string)($index * $chunkSize), 'cwd' => '',
            'file' => new \CURLStringFile($data, $name, 'application/octet-stream'),
        ]]);
    }

    private function sendAll(HttpClient $client, string $bytes, int $chunkSize, string $name = 'dump.bin'): void
    {
        $count = max(1, (int)ceil(strlen($bytes) / $chunkSize));
        for ($i = 0; $i < $count; $i++) {
            $response = $this->chunk($client, $bytes, $i, $chunkSize, $name);
            $this->assertSame(200, $response->status, $response->body);
            $this->assertSame(['status' => 'progress', 'received' => $i + 1, 'chunk_count' => $count], $response->json());
        }
    }

    public function testUploadThenDownload(): void
    {
        $client = $this->uploader();
        $bytes = random_bytes(100_000);

        $probe = $client->post('upload', ['a' => 'checkRights', 'cwd' => '', 'name' => 'dump.bin', 'size' => '100000']);
        $this->assertSame(200, $probe->status, $probe->body);

        $this->sendAll($client, $bytes, 32_768);
        $this->assertFileDoesNotExist($this->server->root . '/storage/files/dump.bin', 'not visible before completion');

        $done = $client->post('upload', ['a' => 'complete', 'dzuuid' => self::UUID]);
        $this->assertSame(200, $done->status, $done->body);
        $file = $done->json()['file'];
        $this->assertSame(['dump.bin', 100_000, hash('sha256', $bytes), TestServer::BOB, '127.0.0.1'],
            [$file['name'], $file['size'], $file['sha256'], $file['uploaded_by'], $file['uploaded_from']]);

        $this->assertSame('completed', $client->post('upload', ['a' => 'status', 'dzuuid' => self::UUID])->json()['status']);
        $this->assertSame($bytes, $client->get('dl', ['query' => ['p' => '/dump.bin']])->body);
        $this->assertCount(1, $this->server->logEntries('upload_completed'));
    }

    public function testZeroByteFile(): void
    {
        // Dropzone 6 sends one empty chunk; Dropzone 5 never sent anything
        $client = $this->uploader();
        $this->sendAll($client, '', 2 * 1024 * 1024, 'empty.txt');
        $done = $client->post('upload', ['a' => 'complete', 'dzuuid' => self::UUID]);
        $this->assertSame(200, $done->status, $done->body);
        $this->assertSame([0, hash('sha256', '')], [$done->json()['file']['size'], $done->json()['file']['sha256']]);
        $this->assertSame('', file_get_contents($this->server->root . '/storage/files/empty.txt'));
    }

    public function testNameConflictRefusedBeforeTransfer(): void
    {
        $this->server->addFile('dump.bin', 'evidence');
        $client = $this->uploader();

        $probe = $client->post('upload', ['a' => 'checkRights', 'cwd' => '', 'name' => 'dump.bin', 'size' => '3']);
        $this->assertSame(409, $probe->status);
        $this->assertSame(['error', 'NameConflict'], [$probe->json()['status'], $probe->json()['code']]);

        $chunk = $this->chunk($client, 'new', 0, 1024);
        $this->assertSame(409, $chunk->status);
        $this->assertSame('evidence', file_get_contents($this->server->root . '/storage/files/dump.bin'), 'never appended to');
    }

    public function testErrorsUseHttpStatusCodes(): void
    {
        $client = $this->uploader();
        $this->assertSame(400, $this->chunk($client, 'abcdef', 0, 4, override: ['dzchunkbyteoffset' => '2'])->status, 'geometry');
        $this->assertSame(400, $client->post('upload', ['dzuuid' => self::UUID, 'cwd' => ''])->status, 'no file field');
        $this->assertSame(404, $client->post('upload', ['a' => 'status', 'dzuuid' => self::UUID])->status);
        $this->assertSame(400, $client->post('upload', ['a' => 'nope'])->status);
        $this->assertSame(405, $client->get('upload')->status);

        $this->sendAll($client, 'abcdef', 4);
        $this->assertSame(200, $client->post('upload', ['a' => 'abort', 'dzuuid' => self::UUID])->status);
        $late = $client->post('upload', ['a' => 'complete', 'dzuuid' => self::UUID]);
        $this->assertSame([409, 'InvalidState'], [$late->status, $late->json()['code']]);
        $this->assertCount(1, $this->server->logEntries('upload_aborted'));
    }

    public function testUserWithoutRightsRefused(): void
    {
        $client = $this->bobClient();
        $client->defaultHeaders['X-CSRF-TOKEN'] = (string)$client->get('')->csrfToken();

        $probe = $client->post('upload', ['a' => 'checkRights', 'cwd' => '', 'name' => 'x', 'size' => '1']);
        $this->assertSame([403, 'AccessDenied'], [$probe->status, $probe->json()['code']]);
        $this->assertSame(403, $this->chunk($client, 'abc', 0, 4)->status);
        $this->assertSame([], glob($this->server->root . '/storage/files/.uploads/*'));
    }

    public function testCsrfTokenRequired(): void
    {
        $client = $this->uploader();
        unset($client->defaultHeaders['X-CSRF-TOKEN']);
        $this->assertSame(403, $this->chunk($client, 'abc', 0, 4)->status);
    }

    public function testMaximumSize(): void
    {
        $this->restartWith(['upload_max_size' => '1K']);
        $client = $this->uploader();
        $probe = $client->post('upload', ['a' => 'checkRights', 'cwd' => '', 'name' => 'big.bin', 'size' => '1025']);
        $this->assertSame([413, 'EntityTooLarge'], [$probe->status, $probe->json()['code']]);
    }

    /** Larger than any disk the tests run on: refused for the real free space, at the probe and at the first chunk */
    public function testLargerThanFreeSpaceRefused(): void
    {
        $client = $this->uploader();
        $huge = '900000000000000';   // 900 TB, within the 15 digits a chunk may declare
        $probe = $client->post('upload', ['a' => 'checkRights', 'cwd' => '', 'name' => 'image.E01', 'size' => $huge]);
        $this->assertSame([507, 'InsufficientStorage'], [$probe->status, $probe->json()['code']], $probe->body);
        $this->assertStringContainsString('Not enough free space on the server', $probe->json()['message']);
        $this->assertStringContainsString('900.0 TB needed', $probe->json()['message']);

        // A client that skips the probe: refused before anything is staged
        $chunkSize = 1 << 20;
        $first = $this->chunk($client, str_repeat('x', $chunkSize), 0, $chunkSize, 'image.E01', [
            'dztotalfilesize' => $huge, 'dztotalchunkcount' => (string)intdiv((int)$huge + $chunkSize - 1, $chunkSize)]);
        $this->assertSame([507, 'InsufficientStorage'], [$first->status, $first->json()['code']], $first->body);
        $this->assertSame([], glob($this->server->root . '/storage/files/.uploads/*'));
        $this->assertSame('0', (string)$this->server->db()->query("SELECT COUNT(*) FROM uploads")->fetchColumn());
        $this->assertSame(['Insufficient storage', 'Insufficient storage'],
            array_column($this->server->logEntries('upload_denied'), 'cause'));
    }

    public function testFolderNamesStartingWithADotRefused(): void
    {
        $this->server->grantRootAccess(TestServer::BOB);
        $client = $this->bobClient();
        foreach (['.uploads', '..', '.hidden'] as $name) {
            $response = $client->postWithCsrf('', ['a' => 'createFolder', 'cwd' => '/', 'name' => $name]);
            $this->assertFalse($response->json()['success'], $name);
        }
    }
}
