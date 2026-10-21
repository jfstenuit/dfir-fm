<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;

final class DownloadTest extends FeatureTestCase
{
    private HttpClient $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server->addFile('ten.bin', '0123456789');
        $this->admin = $this->adminClient();
    }

    private function download(string $name, ?string $range = null): \Tests\Support\HttpResponse
    {
        return $this->admin->get('dl', ['query' => ['p' => "/$name"], 'headers' => $range ? ['Range' => $range] : []]);
    }

    public function testFullDownload(): void
    {
        $response = $this->download('ten.bin');
        $this->assertSame(200, $response->status);
        $this->assertSame('0123456789', $response->body);
        $this->assertSame('private, no-store', $response->header('Cache-Control'), 'never cached by shared caches');
        $this->assertSame('bytes', $response->header('Accept-Ranges'));
    }

    public function testRanges(): void
    {
        $cases = [
            'bytes=2-4' => [206, '234', 'bytes 2-4/10'],
            'bytes=0-0' => [206, '0', 'bytes 0-0/10'],
            'bytes=-3' => [206, '789', 'bytes 7-9/10'],
            'bytes=7-100' => [206, '789', 'bytes 7-9/10'],
            'bytes=0-1,4-5' => [200, '0123456789', null],
        ];
        foreach ($cases as $range => [$status, $body, $contentRange]) {
            $response = $this->download('ten.bin', $range);
            $this->assertSame($status, $response->status, $range);
            $this->assertSame($body, $response->body, $range);
            $this->assertSame($contentRange, $response->header('Content-Range'), $range);
        }

        $response = $this->download('ten.bin', 'bytes=10-');
        $this->assertSame(416, $response->status);
        $this->assertSame('bytes */10', $response->header('Content-Range'));
    }

    public function testFileWhoseLastChunkIsTheCharacterZero(): void
    {
        // A last block of "0" (falsy in PHP) used to be taken for a read failure
        $this->server->addFile('tail0.bin', str_repeat(' ', 65536) . '0');
        $response = $this->download('tail0.bin');
        $this->assertSame(200, $response->status);
        $this->assertSame(65537, strlen($response->body));
        $this->assertSame('0', substr($response->body, -1));
    }

    /** Several 64 KiB blocks read through one handle: whole file, and ranges across block boundaries */
    public function testLargeFileWholeAndRanges(): void
    {
        $bytes = random_bytes(300_000);
        $this->server->addFile('large.bin', $bytes);

        $whole = $this->download('large.bin');
        $this->assertSame([200, '300000'], [$whole->status, $whole->header('Content-Length')]);
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $whole->body));

        foreach ([[65530, 65545], [1, 299_998], [131_072, 131_072], [200_000, 299_999]] as [$start, $end]) {
            $response = $this->download('large.bin', "bytes=$start-$end");
            $this->assertSame(206, $response->status, "$start-$end");
            $this->assertSame((string)($end - $start + 1), $response->header('Content-Length'), "$start-$end");
            $this->assertSame("bytes $start-$end/300000", $response->header('Content-Range'));
            $this->assertSame(substr($bytes, $start, $end - $start + 1), $response->body, "$start-$end");
        }
        $this->assertSame(substr($bytes, -70_000), $this->download('large.bin', 'bytes=-70000')->body);
    }

    public function testFileMissingFromStorage(): void
    {
        $this->server->addFile('gone.bin', 'x');
        unlink($this->server->root . '/storage/files/gone.bin');
        $response = $this->download('gone.bin');
        $this->assertSame(500, $response->status);
        $this->assertNull($response->header('Content-Disposition'));
    }

    public function testEmptyFile(): void
    {
        $this->server->addFile('empty.bin', '');
        $this->assertSame(200, $this->download('empty.bin')->status);
    }

    public function testMissingOrInvalidPathParameter(): void
    {
        $this->assertSame(404, $this->admin->get('dl')->status);
        $this->assertSame(404, $this->admin->get('dl?p[]=x')->status);
    }

    public function testHostileFileNameInContentDisposition(): void
    {
        $name = 'evil"; filename="x.html été.txt';
        $this->server->addFile($name, 'evidence-bytes');

        $response = $this->download($name);
        $this->assertSame('evidence-bytes', $response->body);
        $disposition = (string)$response->header('Content-Disposition');
        $this->assertSame(2, substr_count($disposition, ';'), "three parameters: $disposition");
        $this->assertStringContainsString("filename*=UTF-8''evil%22%3B%20filename%3D%22x.html%20%C3%A9t%C3%A9.txt", $disposition);
    }
}
