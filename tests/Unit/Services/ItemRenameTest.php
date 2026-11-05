<?php
namespace Tests\Unit\Services;

use Backends\LogEngineInterface;
use Backends\StorageEngineLocal;
use Core\Database;
use Middleware\AccessMiddleware;
use PDO;
use PHPUnit\Framework\TestCase;
use Services\ItemRename;
use Services\RenameException;

/**
 * Renaming files and folders (#21), without HTTP: a real SQLite database
 * and local storage in a temporary directory.
 */
final class ItemRenameTest extends TestCase
{
    private const ADMIN = 1;
    private const WRITER = 2;
    private const VIEWER = 3;

    private string $dir;
    private string $files;
    private PDO $db;
    /** @var list<array{string, array}> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-rename-' . bin2hex(random_bytes(4));
        $this->files = "$this->dir/files";
        mkdir($this->files, 0777, true);
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        $this->db = Database::initialize("$this->dir/app.sqlite");
        $this->db->exec("INSERT INTO users (id, username, password) VALUES (1, 'admin', '!'), (2, 'writer', '!'), (3, 'viewer', '!')");
        // The administrator group (id 1) is created by Database::initialize()
        $this->db->exec("INSERT INTO groups (id, name, is_admin) VALUES (2, 'writers', 0), (3, 'viewers', 0)");
        $this->db->exec("INSERT INTO user_group (user_id, group_id) VALUES (1, 1), (2, 2), (3, 3)");
        // The root folder (id 1) is created by Database::initialize()
        $this->db->exec("INSERT INTO directories (id, name, path, parent_id) VALUES
                         (2, 'case', '/case', 1), (3, 'disk', '/case/disk', 2), (4, 'p1', '/case/disk/p1', 3), (5, 'case-2', '/case-2', 1)");
        $this->db->exec("INSERT INTO access_rights (group_id, directory_id, can_view, can_write, can_upload) VALUES
                         (2, 2, 1, 1, 1), (3, 2, 1, 0, 0), (2, 4, 1, 0, 1)");
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . '; rm -rf ' . escapeshellarg($this->dir));
    }

    private function rename(int $user, string $type, int $id, string $name): array
    {
        $logger = new class($this->log) implements LogEngineInterface {
            public function __construct(private array &$log) {}
            public function log(string $action, array $context = []): void { $this->log[] = [$action, $context]; }
            public function healthCheck(): bool { return true; }
        };
        return (new ItemRename($this->db, new StorageEngineLocal($this->files), $logger))->rename($user, $type, (string)$id, $name);
    }

    /** The refusal of a rename, as [HTTP status, code] */
    private function refusal(int $user, string $type, int $id, string $name): array
    {
        try {
            $this->rename($user, $type, $id, $name);
        } catch (RenameException $e) {
            return [$e->httpStatus, $e->errorCode];
        }
        $this->fail("The rename to \"$name\" was accepted");
    }

    /** A file in storage and its record; returns its id */
    private function store(string $path, string $bytes, int $directoryId): int
    {
        @mkdir(dirname("$this->files$path"), 0777, true);
        file_put_contents("$this->files$path", $bytes);
        $this->db->prepare("INSERT INTO files (directory_id, name, size, sha256, uploaded_by) VALUES (?, ?, ?, ?, 1)")
            ->execute([$directoryId, basename($path), strlen($bytes), hash('sha256', $bytes)]);
        return (int)$this->db->lastInsertId();
    }

    private function logged(string $action): array
    {
        return array_values(array_map(fn($e) => $e[1], array_filter($this->log, fn($e) => $e[0] === $action)));
    }

    private function paths(): array
    {
        return $this->db->query("SELECT id, path FROM directories ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function testFileRename(): void
    {
        $id = $this->store('/case/image.E01', 'disk image bytes', 2);

        $result = $this->rename(self::WRITER, 'f', $id, 'laptop.E01');

        $this->assertSame(['type' => 'f', 'id' => $id, 'name' => 'laptop.E01', 'path' => '/case/laptop.E01', 'renamed' => true], $result);
        $this->assertFileDoesNotExist("$this->files/case/image.E01");
        $this->assertSame('disk image bytes', file_get_contents("$this->files/case/laptop.E01"));
        $file = $this->db->query("SELECT * FROM files WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['laptop.E01', hash('sha256', 'disk image bytes'), 16, 2],
            [$file['name'], $file['sha256'], (int)$file['size'], (int)$file['directory_id']]);
        $this->assertSame([['file_id' => $id, 'from' => '/case/image.E01', 'to' => '/case/laptop.E01',
                            'size' => 16, 'sha256' => hash('sha256', 'disk image bytes')]], $this->logged('file_renamed'));
    }

    public function testFolderRenameCarriesItsSubfolders(): void
    {
        $deep = $this->store('/case/disk/p1/mft.bin', 'MFT', 4);
        $this->store('/case-2/other.bin', 'other case', 5);

        $result = $this->rename(self::ADMIN, 'd', 2, 'case-1');

        $this->assertSame(['type' => 'd', 'id' => 2, 'name' => 'case-1', 'path' => '/case-1', 'renamed' => true], $result);
        $this->assertSame([1 => '/', 2 => '/case-1', 3 => '/case-1/disk', 4 => '/case-1/disk/p1', 5 => '/case-2'], $this->paths(),
            'subfolders follow, a folder whose name starts the same does not');
        $this->assertSame('case-1', $this->db->query("SELECT name FROM directories WHERE id = 2")->fetchColumn());
        $this->assertDirectoryDoesNotExist("$this->files/case");
        $this->assertSame('other case', file_get_contents("$this->files/case-2/other.bin"));

        // Rights are keyed by directory id: they follow
        $this->assertTrue(AccessMiddleware::checkAccess($this->db, '/case-1', self::WRITER)['can_write']);
        $this->assertTrue(AccessMiddleware::checkAccess($this->db, '/case-1/disk/p1', self::WRITER)['can_upload']);
        $this->assertFalse(AccessMiddleware::checkAccess($this->db, '/case', self::WRITER)['can_read']);

        // The deep file is still found by its path, and readable from storage
        $file = AccessMiddleware::getFileInfo($this->db, '/case-1/disk/p1/mft.bin');
        $this->assertSame($deep, (int)$file['id']);
        $stream = (new StorageEngineLocal($this->files))->readStream('/case-1/disk/p1/mft.bin');
        $this->assertSame('MFT', stream_get_contents($stream));
        fclose($stream);

        $this->assertSame([['directory_id' => 2, 'from' => '/case', 'to' => '/case-1', 'subfolders' => 2]], $this->logged('folder_renamed'));
    }

    public function testFolderWithNothingStoredHasOnlyItsRecordsRenamed(): void
    {
        $this->rename(self::WRITER, 'd', 3, 'image');

        $this->assertSame('/case/image/p1', $this->paths()[4]);
        $this->assertDirectoryDoesNotExist("$this->files/case/image");
    }

    public function testSameNameChangesNothing(): void
    {
        $id = $this->store('/case/image.E01', 'x', 2);

        $this->assertFalse($this->rename(self::WRITER, 'f', $id, 'image.E01')['renamed']);
        $this->assertSame([], $this->log);
    }

    public function testNameConflicts(): void
    {
        $id = $this->store('/case/image.E01', 'x', 2);
        $this->store('/case/notes.txt', 'y', 2);

        $this->assertSame([409, 'NameConflict'], $this->refusal(self::WRITER, 'f', $id, 'notes.txt'), 'a file');
        $this->assertSame([409, 'NameConflict'], $this->refusal(self::WRITER, 'f', $id, 'disk'), 'a folder');
        $this->assertSame([409, 'NameConflict'], $this->refusal(self::ADMIN, 'd', 3, 'image.E01'), 'a folder onto a file');
        $this->assertSame([409, 'NameConflict'], $this->refusal(self::ADMIN, 'd', 5, 'case'), 'a folder onto a folder');

        // In storage without a record (never replaced)
        file_put_contents("$this->files/case/rsynced.bin", 'placed outside the application');
        $this->assertSame([409, 'NameConflict'], $this->refusal(self::WRITER, 'f', $id, 'rsynced.bin'));
        $this->assertSame('placed outside the application', file_get_contents("$this->files/case/rsynced.bin"));
        $this->assertSame('x', file_get_contents("$this->files/case/image.E01"));
        $this->assertSame([], $this->logged('file_renamed'));
    }

    public function testInvalidNames(): void
    {
        $file = $this->store('/top.bin', 'x', 1);
        $this->store('/case/image.E01', 'y', 2);

        foreach (['', 'a/b', '..', "tab\tname", 'x%2e'] as $name) {
            $this->assertSame([400, 'InvalidName'], $this->refusal(self::ADMIN, 'f', $file, $name), json_encode($name));
        }
        $this->assertSame([400, 'InvalidName'], $this->refusal(self::ADMIN, 'f', $file, '.uploads'), 'the staging area');
        foreach (['.hidden', 'a<b', 'a&b', 'a\\b'] as $name) {
            $this->assertSame([400, 'InvalidName'], $this->refusal(self::ADMIN, 'd', 2, $name), $name);
        }
        $this->assertSame([400, 'InvalidArgument'], $this->refusal(self::ADMIN, 'd', 1, 'root'), 'the root folder');
        $this->assertSame([400, 'InvalidArgument'], $this->refusal(self::ADMIN, 'x', 2, 'name'));
    }

    public function testReservedName(): void
    {
        $logger = $this->createStub(LogEngineInterface::class);
        $storage = new StorageEngineLocal($this->files, ["$this->files/case/app.sqlite"]);
        $id = $this->store('/case/image.E01', 'x', 2);
        $this->expectExceptionObject(new RenameException(400, 'InvalidName', 'The name "app.sqlite-wal" is reserved in this folder: the application keeps its own files there.'));
        (new ItemRename($this->db, $storage, $logger))->rename(self::ADMIN, 'f', (string)$id, 'app.sqlite-wal');
    }

    public function testRightsOnTheParentFolder(): void
    {
        $id = $this->store('/case/image.E01', 'x', 2);
        $top = $this->store('/top.bin', 'y', 1);

        $this->assertSame([404, 'NotFound'], $this->refusal(self::VIEWER, 'f', $id, 'renamed.E01'), 'view only');
        $this->assertSame([404, 'NotFound'], $this->refusal(self::WRITER, 'f', $top, 'renamed.bin'), 'no right on the root');
        $this->assertSame([404, 'NotFound'], $this->refusal(self::WRITER, 'd', 2, 'renamed'), 'its own folder is not its parent');
        $this->assertSame([404, 'NotFound'], $this->refusal(self::WRITER, 'f', 999, 'renamed'), 'missing answers the same');
        $this->assertSame(['Access denied', 'Access denied', 'Access denied', 'No such item'], array_column($this->logged('rename_denied'), 'cause'));
        $this->assertSame('/case', $this->paths()[2]);

        $this->assertTrue($this->rename(self::WRITER, 'd', 3, 'image')['renamed'], 'write on the parent');
        $this->assertTrue($this->rename(self::ADMIN, 'f', $top, 'renamed.bin')['renamed'], 'admin');
    }

    public function testRefusedDuringAnUploadIntoTheFolderOrASubfolder(): void
    {
        $this->db->exec("INSERT INTO uploads (user_id, client_uuid, directory_id, name, size, chunk_size, chunk_count, staging_key, status)
                         VALUES (2, 'u1', 4, 'part.bin', 10, 10, 1, '" . str_repeat('a', 32) . "', 'verifying')");

        $this->assertSame([409, 'UploadInProgress'], $this->refusal(self::ADMIN, 'd', 2, 'renamed'));
        $this->assertSame([409, 'UploadInProgress'], $this->refusal(self::ADMIN, 'd', 4, 'renamed'));
        $this->assertSame('/case', $this->paths()[2]);
        $this->assertTrue($this->rename(self::ADMIN, 'd', 5, 'case-3')['renamed'], 'another folder');

        // An upload left for longer than upload_stale_after expires first
        $this->db->exec("UPDATE uploads SET last_update = datetime('now', '-2 days')");
        $this->assertTrue($this->rename(self::ADMIN, 'd', 2, 'renamed')['renamed']);
        $this->assertSame('expired', $this->db->query("SELECT status FROM uploads")->fetchColumn());
    }

    public function testRefusedWhileARefreshRuns(): void
    {
        $id = $this->store('/case/image.E01', 'x', 2);
        $this->db->exec("INSERT INTO refresh_jobs (id, user_id, directory_id, status) VALUES (7, 1, 4, 'in_progress'), (8, 1, 2, 'in_progress')");

        $this->assertSame([409, 'RefreshRunning'], $this->refusal(self::ADMIN, 'f', $id, 'renamed.E01'), 'in the file\'s folder');
        $this->db->exec("UPDATE refresh_jobs SET status = 'completed' WHERE id = 8");
        $this->assertTrue($this->rename(self::ADMIN, 'f', $id, 'renamed.E01')['renamed'], 'a refresh in a subfolder');
        $this->assertSame([409, 'RefreshRunning'], $this->refusal(self::ADMIN, 'd', 2, 'renamed'), 'in a subfolder');

        // A refresh without activity for two minutes was left: it expires
        $this->db->exec("UPDATE refresh_jobs SET last_update = datetime('now', '-5 minutes') WHERE id = 7");
        $this->assertTrue($this->rename(self::ADMIN, 'd', 2, 'renamed')['renamed']);
        $this->assertSame('expired', $this->db->query("SELECT status FROM refresh_jobs WHERE id = 7")->fetchColumn());
        $this->assertSame([7, '/case/disk/p1'], [$this->logged('directory_refresh_expired')[0]['refresh_id'], $this->logged('directory_refresh_expired')[0]['directory']]);
    }

    public function testStorageFailureLeavesTheRecordsUnchanged(): void
    {
        $id = $this->store('/case/image.E01', 'x', 2);
        mkdir("$this->files/case/disk");
        chmod("$this->files/case", 0555);

        $this->assertSame([500, 'StorageError'], $this->refusal(self::ADMIN, 'f', $id, 'renamed.E01'));
        $this->assertSame([500, 'StorageError'], $this->refusal(self::ADMIN, 'd', 3, 'image'));

        $this->assertSame('image.E01', $this->db->query("SELECT name FROM files WHERE id = $id")->fetchColumn());
        $this->assertSame('/case/disk/p1', $this->paths()[4]);
        $this->assertSame([], $this->logged('file_renamed'));
        $this->assertSame([], $this->logged('folder_renamed'));
    }

    public function testStorageMissingFileRefused(): void
    {
        $id = $this->store('/case/image.E01', 'x', 2);
        unlink("$this->files/case/image.E01");

        $this->assertSame([500, 'StorageError'], $this->refusal(self::ADMIN, 'f', $id, 'renamed.E01'));
        $this->assertSame('image.E01', $this->db->query("SELECT name FROM files WHERE id = $id")->fetchColumn());
    }

    public function testDatabaseFailureUndoesTheStorageRename(): void
    {
        $id = $this->store('/case/image.E01', 'x', 2);
        $this->store('/case/disk/p1/mft.bin', 'MFT', 4);
        $this->db->exec("CREATE TRIGGER no_rename_file BEFORE UPDATE ON files BEGIN SELECT RAISE(ABORT, 'refused'); END");
        $this->db->exec("CREATE TRIGGER no_rename_folder BEFORE UPDATE OF name ON directories BEGIN SELECT RAISE(ABORT, 'refused'); END");

        foreach ([['f', $id, 'renamed.E01'], ['d', 2, 'renamed']] as [$type, $itemId, $name]) {
            try {
                $this->rename(self::ADMIN, $type, $itemId, $name);
                $this->fail('The rename was accepted');
            } catch (\PDOException $e) {
                $this->assertStringContainsString('refused', $e->getMessage());
            }
        }

        $this->assertSame('x', file_get_contents("$this->files/case/image.E01"));
        $this->assertFileDoesNotExist("$this->files/case/renamed.E01");
        $this->assertSame('MFT', file_get_contents("$this->files/case/disk/p1/mft.bin"));
        $this->assertDirectoryDoesNotExist("$this->files/renamed");
        $this->assertSame('/case/disk/p1', $this->paths()[4], 'the subfolders\' update is rolled back too');
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame([], $this->logged('file_renamed'));
    }
}
