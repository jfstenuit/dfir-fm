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
        $this->server->addFile('tail0.bin', str_repeat(' ', 8192) . '0');
        $response = $this->download('tail0.bin');
        $this->assertSame(200, $response->status);
        $this->assertSame(8193, strlen($response->body));
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
