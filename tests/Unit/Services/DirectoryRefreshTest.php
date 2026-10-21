<?php
namespace Tests\Unit\Services;

use Backends\StorageEngineInterface;
use Backends\StorageEngineLocal;
use Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use Services\DirectoryRefresh;

/**
 * Storage against records, without HTTP: a real SQLite database and local
 * storage in a temporary directory.
 */
final class DirectoryRefreshTest extends TestCase
{
    private string $dir;
    private string $files;
    private PDO $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-refresh-' . bin2hex(random_bytes(4));
        $this->files = "$this->dir/files";
        mkdir($this->files, 0777, true);
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        $this->db = Database::initialize("$this->dir/app.sqlite");
        // The root folder (id 1) is created by Database::initialize()
        $this->db->exec("INSERT INTO directories (id, name, path, parent_id) VALUES (2, 'case', '/case', 1), (3, 'empty', '/case/empty', 2)");
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function refresh(?StorageEngineInterface $storage = null): DirectoryRefresh
    {
        return new DirectoryRefresh($this->db, $storage ?? new StorageEngineLocal($this->files));
    }

    /** A file in storage and, unless $recorded is false, its record */
    private function store(string $path, string $bytes, bool $recorded = true, int $directoryId = 2): void
    {
        @mkdir(dirname("$this->files$path"), 0777, true);
        file_put_contents("$this->files$path", $bytes);
        if ($recorded) {
            $this->db->prepare("INSERT INTO files (directory_id, name, size, sha256) VALUES (?, ?, ?, ?)")
                ->execute([$directoryId, basename($path), strlen($bytes), hash('sha256', $bytes)]);
        }
    }

    private function compareCase(): array
    {
        return $this->refresh()->compare(['id' => 2, 'path' => '/case']);
    }

    public function testNewExistingAndMissing(): void
    {
        $this->store('/case/known.bin', 'known');
        $this->store('/case/new.bin', 'new!', recorded: false);
        touch("$this->files/case/new.bin", 1700000000);
        mkdir("$this->files/case/new folder");
        $this->db->exec("INSERT INTO files (directory_id, name, size, sha256) VALUES (2, 'gone.bin', 3, 'abc')");

        $result = $this->compareCase();
        $this->assertSame(['new folder'], $result['new_folders']);
        $this->assertSame([['name' => 'new.bin', 'size' => 4, 'mtime' => 1700000000]], $result['new_files']);
        $this->assertSame(['known.bin'], array_column($result['existing_files'], 'name'));
        $this->assertSame([5, hash('sha256', 'known'), 5], [$result['existing_files'][0]['size'], $result['existing_files'][0]['sha256'], $result['existing_files'][0]['stored_size']]);
        $this->assertSame([['id' => 2, 'name' => 'gone.bin', 'size' => 3, 'sha256' => 'abc']], $result['missing_files']);
        $this->assertSame([], $result['skipped']);
    }

    public function testEmptyFoldersAreNotStoredSoNotMissing(): void
    {
        // '/case/empty' has a record and nothing in storage, '/case' itself neither
        $this->assertSame(['new_folders' => [], 'new_files' => [], 'existing_files' => [], 'missing_files' => [], 'skipped' => []],
                          $this->compareCase());
    }

    public function testOnlyTheDirectContentOfTheDirectory(): void
    {
        $this->store('/case/sub/deep.bin', 'deep', recorded: false);
        $this->store('/elsewhere.bin', 'root', recorded: false);

        $result = $this->compareCase();
        $this->assertSame(['sub'], $result['new_folders']);
        $this->assertSame([], $result['new_files']);
    }

    public function testSkippedEntries(): void
    {
        mkdir("$this->files/case");
        file_put_contents("$this->files/case/a%41b", 'encoded');   // refused by the storage paths
        mkdir("$this->files/case/.hidden");                         // folder rules: no leading dot
        file_put_contents("$this->files/case/.bash_history", 'ok'); // fine for a file
        symlink('/etc/passwd', "$this->files/case/passwd");
        posix_mkfifo("$this->files/case/fifo", 0600);
        file_put_contents("$this->files/case/empty", 'a file where a folder is recorded');
        mkdir("$this->files/case/report.pdf");                      // a folder where a file is recorded
        $this->db->exec("INSERT INTO files (directory_id, name, size, sha256) VALUES (2, 'report.pdf', 1, 'x')");

        $result = $this->compareCase();
        $this->assertSame(['.bash_history'], array_column($result['new_files'], 'name'));
        $this->assertSame([], $result['new_folders']);
        $this->assertSame([
            ['name' => '.hidden', 'type' => 'directory', 'reason' => 'name not supported'],
            ['name' => 'a%41b', 'type' => 'file', 'reason' => 'name not supported'],
            ['name' => 'empty', 'type' => 'file', 'reason' => 'name used by a folder'],
            ['name' => 'fifo', 'type' => 'other', 'reason' => 'special file'],
            ['name' => 'passwd', 'type' => 'link', 'reason' => 'symbolic link'],
            ['name' => 'report.pdf', 'type' => 'directory', 'reason' => 'name used by a file'],
        ], $result['skipped']);
        $this->assertSame(['report.pdf'], array_column($result['missing_files'], 'name'));
    }

    public function testStagingDirectoryNeverListed(): void
    {
        mkdir("$this->files/.uploads");
        touch("$this->files/.uploads/0123456789abcdef0123456789abcdef");

        $result = $this->refresh()->compare(['id' => 1, 'path' => '/']);
        $this->assertSame([], $result['new_folders']);
        $this->assertSame([], $result['skipped']);
    }

    public function testHashReadsFromStorage(): void
    {
        $this->store('/case/known.bin', str_repeat('x', (1 << 20) + 5));
        $seen = [];
        $hash = $this->refresh()->hash('/case/known.bin', function (int $bytes) use (&$seen) { $seen[] = $bytes; return true; });

        $this->assertSame(['size' => (1 << 20) + 5, 'sha256' => hash('sha256', str_repeat('x', (1 << 20) + 5))], $hash);
        $this->assertSame([1 << 20, (1 << 20) + 5], $seen, 'progress per block');
    }

    public function testHashStoppedOrUnreadable(): void
    {
        $this->store('/case/known.bin', str_repeat('x', (1 << 20) + 5));
        $this->assertNull($this->refresh()->hash('/case/known.bin', fn() => false), 'stopped');
        $this->assertNull($this->refresh()->hash('/case/none.bin'), 'missing');
        $this->assertNull($this->refresh()->hash('/../etc/passwd'), 'refused path');
    }

    public function testContentInUse(): void
    {
        $this->store('/case/known.bin', 'known');
        $this->assertSame(['id' => 1, 'name' => 'known.bin'], $this->refresh()->contentInUse(2, hash('sha256', 'known')));
        $this->assertNull($this->refresh()->contentInUse(1, hash('sha256', 'known')), 'another directory');
    }

    public function testWalkGoesThroughTheWholeTree(): void
    {
        $this->store('/top.bin', 'top', directoryId: 1);
        $this->store('/case/sub/deep.bin', 'deep', recorded: false);
        mkdir("$this->files/.uploads");
        touch("$this->files/.uploads/0123456789abcdef0123456789abcdef");
        mkdir("$this->files/a%41b");

        $walk = [];
        foreach ($this->refresh()->walk() as $path => $entry) {
            $walk[] = "$path {$entry['type']}";
        }
        // A folder the engine can't enter is listed, then reported unreadable
        $this->assertSame(['/a%41b directory', '/a%41b unreadable', '/case directory', '/case/sub directory',
                           '/case/sub/deep.bin file', '/top.bin file'], $walk);
    }

    public function testEngineWithoutNamedFilesRefused(): void
    {
        $blobs = new class("$this->files") extends StorageEngineLocal {
            public function supportsNamedFiles(): bool { return false; }
            public function listDirectory(string $directory): array { throw new \LogicException('no folders'); }
        };
        try {
            $this->refresh($blobs)->compare(['id' => 2, 'path' => '/case']);
            $this->fail('compared');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('no named files', $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        iterator_to_array($this->refresh($blobs)->walk());
    }
}
