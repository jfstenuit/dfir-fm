<?php
namespace Tests\Unit\Services;

use Backends\LogEngineInterface;
use Backends\StorageEngineLocal;
use Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use Services\RefreshException;
use Services\RefreshJobs;

/**
 * The refresh jobs, without HTTP: a real SQLite database and local storage
 * in a temporary directory.
 */
final class RefreshJobsTest extends TestCase
{
    private const ADMIN = 1;
    private const OTHER_ADMIN = 2;

    private string $dir;
    private string $files;
    private PDO $db;
    private StorageEngineLocal $storage;
    /** @var list<array{string, array}> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-refreshjobs-' . bin2hex(random_bytes(4));
        $this->files = "$this->dir/files";
        mkdir($this->files, 0777, true);
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        $this->db = Database::initialize("$this->dir/app.sqlite");
        $this->db->exec("INSERT INTO users (id, username, password) VALUES (1, 'admin', '!'), (2, 'other', '!')");
        // The root folder (id 1) is created by Database::initialize()
        $this->db->exec("INSERT INTO directories (id, name, path, parent_id) VALUES (2, 'case', '/case', 1)");
        $this->storage = new StorageEngineLocal($this->files);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function jobs(): RefreshJobs
    {
        $logger = new class($this->log) implements LogEngineInterface {
            public function __construct(private array &$log) {}
            public function log(string $action, array $context = []): void { $this->log[] = [$action, $context]; }
            public function healthCheck(): bool { return true; }
        };
        return new RefreshJobs($this->db, $this->storage, $logger, [], '192.0.2.9');
    }

    /** A file in storage and, if $recorded, its record */
    private function store(string $path, string $bytes, bool $recorded = false, int $directoryId = 2): void
    {
        @mkdir(dirname("$this->files$path"), 0777, true);
        file_put_contents("$this->files$path", $bytes);
        if ($recorded) {
            $this->db->prepare("INSERT INTO files (directory_id, name, size, sha256, uploaded_by) VALUES (?, ?, ?, ?, 1)")
                ->execute([$directoryId, basename($path), strlen($bytes), hash('sha256', $bytes)]);
        }
    }

    /** Steps until the job is done */
    private function runJob(int $jobId, int $user = self::ADMIN): array
    {
        $results = [];
        do {
            $state = $this->jobs()->step($user, $jobId);
            if ($state['file'] !== null) {
                $results[$state['file']['name']] = $state['file'];
            }
        } while ($state['status'] === 'in_progress');
        return [$state, $results];
    }

    private function logged(string $action): array
    {
        return array_values(array_map(fn($e) => $e[1], array_filter($this->log, fn($e) => $e[0] === $action)));
    }

    private function file(string $name): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM files WHERE name = ?");
        $stmt->execute([$name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function testImportOfANewFileAndANewFolder(): void
    {
        $this->store('/case/image.E01', 'disk image bytes');
        touch("$this->files/case/image.E01", 1700000000);
        $this->store('/case/sub/deep.bin', 'not imported: in a subfolder');
        $this->store('/elsewhere.bin', 'not imported: in the parent');

        $start = $this->jobs()->start(self::ADMIN, '/case');
        $this->assertSame(['in_progress', 1], [$start['status'], $start['total']]);
        $this->assertSame(['sub'], $start['comparison']['new_folders']);
        $this->assertSame([['name' => 'image.E01', 'size' => 16]], $start['comparison']['new_files']);

        $folder = $this->db->query("SELECT * FROM directories WHERE path = '/case/sub'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['sub', 2, 1, '192.0.2.9'], [$folder['name'], (int)$folder['parent_id'], (int)$folder['created_by'], $folder['created_from']]);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM access_rights")->fetchColumn(), 'no rights of its own');

        [$state, $results] = $this->runJob($start['refresh_id']);
        $this->assertSame('completed', $state['status']);
        $this->assertSame(['imported', hash('sha256', 'disk image bytes')], [$results['image.E01']['status'], $results['image.E01']['sha256']]);

        $file = $this->file('image.E01');
        $this->assertSame(['import', 16, hash('sha256', 'disk image bytes'), 1, '192.0.2.9', 2],
            [$file['origin'], (int)$file['size'], $file['sha256'], (int)$file['uploaded_by'], $file['uploaded_from'], (int)$file['directory_id']]);
        $this->assertNotNull($file['checked_at']);
        $this->assertFalse($this->file('deep.bin'), 'nothing outside the current directory');
        $this->assertFalse($this->file('elsewhere.bin'));

        $imported = $this->logged('file_imported')[0];
        $this->assertSame(['/case/image.E01', 16, hash('sha256', 'disk image bytes'), '2023-11-14T22:13:20Z'],
            [$imported['file'], $imported['size'], $imported['sha256'], $imported['mtime']]);
        $this->assertSame(['/case/sub'], array_column($this->logged('directory_imported'), 'directory'));
        $this->assertSame(['new_folders' => 1, 'imported' => 1, 'verified' => 0, 'mismatches' => 0, 'missing' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => 0],
            array_diff_key($this->logged('directory_refreshed')[0], ['refresh_id' => 1, 'directory' => 1]));
    }

    public function testMismatchKeepsTheRecord(): void
    {
        $this->store('/case/a.bin', 'original', recorded: true);
        file_put_contents("$this->files/case/a.bin", 'tampered!');

        [, $results] = $this->runJob($this->jobs()->start(self::ADMIN, '/case')['refresh_id']);
        $this->assertSame('mismatch', $results['a.bin']['status']);
        $file = $this->file('a.bin');
        $this->assertSame([hash('sha256', 'original'), 8, 'mismatch', 'upload'], [$file['sha256'], (int)$file['size'], $file['integrity'], $file['origin']]);
        $logged = $this->logged('integrity_mismatch')[0];
        $this->assertSame([8, 9, hash('sha256', 'original'), hash('sha256', 'tampered!')],
            [$logged['recorded_size'], $logged['stored_size'], $logged['recorded_sha256'], $logged['stored_sha256']]);

        // Restored: the next refresh clears the flag
        file_put_contents("$this->files/case/a.bin", 'original');
        [, $results] = $this->runJob($this->jobs()->start(self::ADMIN, '/case')['refresh_id']);
        $this->assertSame('verified', $results['a.bin']['status']);
        $this->assertNull($this->file('a.bin')['integrity']);
    }

    public function testMissingFileKeptAndFlagged(): void
    {
        $this->store('/case/gone.bin', 'gone', recorded: true);
        unlink("$this->files/case/gone.bin");

        $start = $this->jobs()->start(self::ADMIN, '/case');
        $this->assertSame('completed', $start['status'], 'nothing to hash');
        $this->assertSame([['name' => 'gone.bin', 'size' => 4]], $start['comparison']['missing_files']);
        $this->assertSame('missing', $this->file('gone.bin')['integrity']);
        $this->assertSame(['/case/gone.bin'], array_column($this->logged('integrity_missing'), 'file'));
        $this->assertSame(1, $this->logged('directory_refreshed')[0]['missing']);
    }

    public function testDuplicateContentSkipped(): void
    {
        $this->store('/case/a.bin', 'same', recorded: true);
        $this->store('/case/copy.bin', 'same');

        [$state, $results] = $this->runJob($this->jobs()->start(self::ADMIN, '/case')['refresh_id']);
        $this->assertSame(['skipped', 'This folder already holds the same content, as "a.bin".'],
            [$results['copy.bin']['status'], $results['copy.bin']['reason']]);
        $this->assertFalse($this->file('copy.bin'));
        $this->assertSame([1, 1], [$state['counts']['verified'], $state['counts']['skipped']]);
    }

    public function testNameTakenOrFileChangedMeanwhileSkipped(): void
    {
        $this->store('/case/taken.bin', 'taken');
        $this->store('/case/growing.bin', 'part');
        $jobId = $this->jobs()->start(self::ADMIN, '/case')['refresh_id'];

        $this->db->exec("INSERT INTO directories (name, path, parent_id) VALUES ('taken.bin', '/case/taken.bin', 2)");
        file_put_contents("$this->files/case/growing.bin", 'part and more');

        [, $results] = $this->runJob($jobId);
        $this->assertStringContainsString('still being copied', $results['growing.bin']['reason']);
        $this->assertStringContainsString('added meanwhile', $results['taken.bin']['reason']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM files")->fetchColumn());
    }

    public function testRefusedAndIgnoredEntries(): void
    {
        $this->store('/case/a%41b', 'refused name');
        symlink('/etc/passwd', "$this->files/case/passwd");

        $start = $this->jobs()->start(self::ADMIN, '/case');
        $this->assertSame(['name not supported', 'symbolic link'], array_column($start['comparison']['skipped'], 'reason'));
        $this->assertSame(0, $start['total']);
        $this->assertSame(2, $this->logged('directory_refreshed')[0]['skipped']);
    }

    public function testCancelLeavesTheRemainingFiles(): void
    {
        $this->store('/case/a.bin', 'first');
        $this->store('/case/b.bin', 'second');
        $jobId = $this->jobs()->start(self::ADMIN, '/case')['refresh_id'];

        $this->assertSame('imported', $this->jobs()->step(self::ADMIN, $jobId)['file']['status']);
        $canceled = $this->jobs()->cancel(self::ADMIN, $jobId);
        $this->assertSame(['canceled', 1], [$canceled['status'], $canceled['counts']['remaining']]);
        $this->assertNull($this->jobs()->step(self::ADMIN, $jobId)['file'], 'nothing more once canceled');
        $this->assertFalse($this->file('b.bin'));
        $this->assertSame(1, $this->logged('directory_refresh_canceled')[0]['imported']);
    }

    public function testCancelStopsTheFileBeingHashed(): void
    {
        // Over 16 MiB: the first progress report comes after 16 blocks
        $this->store('/case/big.bin', str_repeat('x', (16 << 20) + 1));
        $jobId = $this->jobs()->start(self::ADMIN, '/case')['refresh_id'];

        // The engine hands out a stream; cancel once hashing has begun
        $this->storage = new class($this->files, $this->db, $jobId) extends StorageEngineLocal {
            public function __construct(string $dir, private PDO $db, private int $jobId) { parent::__construct($dir); }
            public function readStream(string $path)
            {
                $this->db->exec("UPDATE refresh_jobs SET status = 'canceled' WHERE id = $this->jobId");
                return parent::readStream($path);
            }
        };
        $this->assertNull($this->jobs()->step(self::ADMIN, $jobId)['file']);
        $this->assertFalse($this->file('big.bin'));
    }

    public function testProgressReportedWhileHashing(): void
    {
        $this->store('/case/big.bin', str_repeat('x', (16 << 20) + 1));
        $jobId = $this->jobs()->start(self::ADMIN, '/case')['refresh_id'];
        $seen = [];
        // A storage whose stream records the job row as hashing goes on
        $this->storage = new class($this->files, $this->db, $jobId, $seen) extends StorageEngineLocal {
            public function __construct(string $dir, private PDO $db, private int $jobId, private array &$seen) { parent::__construct($dir); }
            public function readStream(string $path)
            {
                $source = parent::readStream($path);
                $db = $this->db; $jobId = $this->jobId; $seen = &$this->seen;
                $stream = fopen('php://temp', 'w+b');
                while (!feof($source)) {
                    fwrite($stream, fread($source, 1 << 20));
                }
                rewind($stream);
                stream_filter_register('refreshprobe', ProgressProbe::class);
                ProgressProbe::$probe = function () use ($db, $jobId, &$seen) {
                    $seen[] = $db->query("SELECT current_file, hashed_bytes FROM refresh_jobs WHERE id = $jobId")->fetch(PDO::FETCH_NUM);
                };
                stream_filter_append($stream, 'refreshprobe', STREAM_FILTER_READ);
                return $stream;
            }
        };
        $this->assertSame('imported', $this->jobs()->step(self::ADMIN, $jobId)['file']['status']);
        $this->assertContains(['big.bin', 16 << 20], $seen, 'hashed bytes in the job row');
        $this->assertSame([null, 0], $this->db->query("SELECT current_file, hashed_bytes FROM refresh_jobs WHERE id = $jobId")->fetch(PDO::FETCH_NUM));
    }

    public function testStalledStepRestartedOtherwiseBusy(): void
    {
        $this->store('/case/a.bin', 'first');
        $jobId = $this->jobs()->start(self::ADMIN, '/case')['refresh_id'];
        // A step that took the file, then died
        $this->db->exec("UPDATE refresh_job_files SET status = 'hashing' WHERE job_id = $jobId");
        $this->db->exec("UPDATE refresh_jobs SET current_file = 'a.bin' WHERE id = $jobId");

        $busy = $this->jobs()->step(self::ADMIN, $jobId);
        $this->assertSame([true, null], [$busy['busy'], $busy['file']]);
        $this->assertSame('a.bin', $busy['current']['name']);

        $this->db->exec("UPDATE refresh_jobs SET last_update = datetime('now', '-1 hour') WHERE id = $jobId");
        $this->assertSame('imported', $this->jobs()->step(self::ADMIN, $jobId)['file']['status']);
        $this->assertCount(1, $this->logged('directory_refresh_step_restarted'));
    }

    public function testOneRefreshPerFolderUnlessAbandoned(): void
    {
        $this->store('/case/a.bin', 'first');
        $first = $this->jobs()->start(self::ADMIN, '/case')['refresh_id'];
        try {
            $this->jobs()->start(self::OTHER_ADMIN, '/case');
            $this->fail('second refresh started');
        } catch (RefreshException $e) {
            $this->assertSame([409, 'RefreshRunning', $first], [$e->httpStatus, $e->errorCode, $e->details['refresh_id']]);
        }

        $this->db->exec("UPDATE refresh_jobs SET last_update = datetime('now', '-1 hour') WHERE id = $first");
        $second = $this->jobs()->start(self::OTHER_ADMIN, '/case')['refresh_id'];
        $this->assertNotSame($first, $second);
        $this->assertSame('expired', $this->db->query("SELECT status FROM refresh_jobs WHERE id = $first")->fetchColumn());
        $this->assertSame('Replaced by a new refresh', $this->logged('directory_refresh_expired')[0]['cause']);
    }

    public function testAJobBelongsToItsAdministrator(): void
    {
        $this->store('/case/a.bin', 'first');
        $jobId = $this->jobs()->start(self::ADMIN, '/case')['refresh_id'];
        foreach (['step', 'status', 'cancel'] as $method) {
            try {
                $this->jobs()->$method(self::OTHER_ADMIN, $jobId);
                $this->fail("$method on another's job");
            } catch (RefreshException $e) {
                $this->assertSame([404, 'NoSuchRefresh'], [$e->httpStatus, $e->errorCode]);
            }
        }
    }

    public function testStatusReturnsEachResultOnce(): void
    {
        $this->store('/case/a.bin', 'first');
        $this->store('/case/b.bin', 'second');
        $jobId = $this->jobs()->start(self::ADMIN, '/case')['refresh_id'];
        $this->runJob($jobId);

        $this->assertSame(['a.bin', 'b.bin'], array_column($this->jobs()->status(self::ADMIN, $jobId)['files'], 'name'));
        $this->assertSame(['b.bin'], array_column($this->jobs()->status(self::ADMIN, $jobId, 1)['files'], 'name'));
    }

    public function testExpireStale(): void
    {
        $this->store('/case/a.bin', 'first');
        $jobId = $this->jobs()->start(self::ADMIN, '/case')['refresh_id'];
        $this->assertSame(0, $this->jobs()->expireStale());
        $this->db->exec("UPDATE refresh_jobs SET last_update = datetime('now', '-2 days') WHERE id = $jobId");
        $this->assertSame(1, $this->jobs()->expireStale());
        $this->assertSame('expired', $this->jobs()->status(self::ADMIN, $jobId)['status']);
    }

    public function testRefusals(): void
    {
        $cases = [
            'unknown folder' => [fn() => $this->jobs()->start(self::ADMIN, '/nope'), 404, 'NoSuchFolder'],
            'folder by id' => [fn() => $this->jobs()->start(self::ADMIN, '2'), 400, 'InvalidArgument'],
            'unknown job' => [fn() => $this->jobs()->step(self::ADMIN, 99), 404, 'NoSuchRefresh'],
        ];
        $this->storage = new class($this->files) extends StorageEngineLocal {
            public function supportsNamedFiles(): bool { return false; }
        };
        $cases['engine without named files'] = [fn() => $this->jobs()->start(self::ADMIN, '/case'), 409, 'NotSupported'];

        foreach ($cases as $label => [$call, $status, $code]) {
            try {
                $call();
                $this->fail($label);
            } catch (RefreshException $e) {
                $this->assertSame([$status, $code], [$e->httpStatus, $e->errorCode], $label);
            }
        }
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM refresh_jobs")->fetchColumn());
    }
}

/** A read filter that calls $probe for each bucket read: a look at the database while hashing goes on */
final class ProgressProbe extends \php_user_filter
{
    public static ?\Closure $probe = null;

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            (self::$probe)();
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
