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
        array_map('unlink', glob("$this->dir/*"));
        rmdir($this->dir);
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

    public function testReadAtReturnsTheStringZero(): void
    {
        // `$data ?: null` used to turn the chunk "0" into a read failure
        file_put_contents("$this->dir/zero", 'abc0');
        $engine = new StorageEngineLocal($this->dir);
        $this->assertSame('0', $engine->readAt('/zero', 3, 1));
        $this->assertSame('abc0', $engine->readAt('/zero', 0, 8192));
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
        $engine->readAt($path, 0, 1);
    }
}
