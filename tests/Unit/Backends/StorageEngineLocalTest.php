<?php
namespace Tests\Unit\Backends;

use Backends\StorageEngineLocal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageEngineLocalTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-storage-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        touch("$this->dir/file");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public static function invalidBaseDirectories(): array
    {
        return [
            'missing' => ['/nope-' . bin2hex(random_bytes(4))],
            'empty (realpath would be the cwd)' => [''],
            'null' => [null],
            'a file' => ['FILE'],
        ];
    }

    #[DataProvider('invalidBaseDirectories')]
    public function testInvalidBaseDirectoryRefused(?string $dir): void
    {
        $this->expectExceptionMessage('Invalid base directory');
        new StorageEngineLocal($dir === 'FILE' ? "$this->dir/file" : $dir);
    }

    public function testValidBaseDirectoryTrailingSlashTrimmed(): void
    {
        $engine = new StorageEngineLocal("$this->dir/");
        $this->assertSame(realpath($this->dir), (new \ReflectionProperty($engine, 'baseDirectory'))->getValue($engine));
    }

    public function testFreeSpaceOfTheBaseDirectory(): void
    {
        // Other processes write meanwhile: close enough
        $this->assertEqualsWithDelta(disk_free_space($this->dir), (new StorageEngineLocal($this->dir))->freeSpace(), 64 << 20);
    }

    public static function traversalPaths(): array
    {
        return [['/../etc/passwd'], ['/a/../../etc'], ['/%2e%2e/x'], ["/a\0b"], ['//x'], ['/a\\b']];
    }

    #[DataProvider('traversalPaths')]
    public function testPathTraversalRefused(string $path): void
    {
        $engine = new StorageEngineLocal($this->dir);
        $this->expectException(\Exception::class);
        $engine->readStream($path);
    }

    public function testDotsInsideANameAreNotTraversal(): void
    {
        file_put_contents("$this->dir/report..final", 'x');
        $this->assertSame('x', stream_get_contents((new StorageEngineLocal($this->dir))->readStream('/report..final')));
    }

    private const KEY = '0123456789abcdef0123456789abcdef';

    private static function stream(string $bytes)
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        return $stream;
    }

    public function testStagingWritesAtOffsetsAndCommits(): void
    {
        $engine = new StorageEngineLocal($this->dir);
        $this->assertTrue($engine->stagingCreate(self::KEY));
        $this->assertFalse($engine->stagingCreate(self::KEY), 'keys are never reused');

        $this->assertTrue($engine->stagingWriteAt(self::KEY, 4, self::stream('efgh'), 4));
        $this->assertTrue($engine->stagingWriteAt(self::KEY, 0, self::stream('abcd'), 4));
        $this->assertTrue($engine->stagingWriteAt(self::KEY, 4, self::stream('EFGH'), 4), 'rewrite');
        $this->assertFalse($engine->stagingWriteAt(self::KEY, 8, self::stream('ij'), 4), 'short source');
        $this->assertSame(10, $engine->stagingSize(self::KEY));

        $stream = $engine->stagingOpen(self::KEY);
        $this->assertSame('abcdEFGHij', stream_get_contents($stream));
        fclose($stream);

        $this->assertFileDoesNotExist("$this->dir/case/dump.bin");
        $this->assertTrue($engine->stagingCommit(self::KEY, '/case/dump.bin'));
        $this->assertSame('abcdEFGHij', file_get_contents("$this->dir/case/dump.bin"));
        $this->assertNull($engine->stagingSize(self::KEY));
    }

    public function testStagingCommitNeverReplacesAFile(): void
    {
        $engine = new StorageEngineLocal($this->dir);
        $engine->stagingCreate(self::KEY);
        $this->assertFalse($engine->stagingCommit(self::KEY, '/file'));
        $this->assertSame('', file_get_contents("$this->dir/file"));
        $this->assertTrue($engine->stagingRemove(self::KEY));
        $this->assertTrue($engine->stagingRemove(self::KEY), 'already gone');
        $this->assertFalse($engine->stagingWriteAt(self::KEY, 0, self::stream('x'), 1), 'removed');
    }

    public function testStagingKeysAreValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StorageEngineLocal($this->dir))->stagingCreate('../../etc/x');
    }

    public function testHealthCheckLeavesTheRootAlone(): void
    {
        $engine = new StorageEngineLocal($this->dir);
        $this->assertTrue($engine->healthCheck());
        $this->assertSame(['.uploads', 'file'], array_values(array_diff(scandir($this->dir), ['.', '..'])));
        $this->assertSame(['.', '..'], scandir("$this->dir/.uploads"));
    }
}
