<?php
namespace Tests\Unit\Services;

use Backends\LogEngineInterface;
use Backends\StorageEngineLocal;
use Core\Database;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Services\ChunkedUpload;
use Services\UploadException;

/**
 * The upload state machine, without HTTP: a real SQLite database and local
 * storage in a temporary directory.
 */
final class ChunkedUploadTest extends TestCase
{
    private const ALICE = 1;   // rights on '/' and '/case'
    private const BOB = 2;     // rights on '/' only
    private const EVE = 3;     // no rights
    private const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';
    private const UUID2 = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    private string $dir;
    private PDO $db;
    private StorageEngineLocal $storage;
    /** @var list<array{string, array}> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-upload-' . bin2hex(random_bytes(4));
        mkdir("$this->dir/files", 0777, true);
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        $this->db = Database::initialize("$this->dir/app.sqlite");
        $this->db->exec("INSERT INTO users (id, username, password) VALUES (1, 'alice', '!'), (2, 'bob', '!'), (3, 'eve', '!')");
        // The root folder (id 1) is created by Database::initialize()
        $this->db->exec("INSERT INTO directories (id, name, path, parent_id) VALUES (2, 'case', '/case', 1)");
        $this->db->exec("INSERT INTO groups (id, name) VALUES (10, 'investigators'), (11, 'contributors')");
        $this->db->exec("INSERT INTO user_group (user_id, group_id) VALUES (1, 10), (2, 11)");
        $this->db->exec("INSERT INTO access_rights (group_id, directory_id, can_view, can_write, can_upload)
                         VALUES (10, 1, 1, 1, 1), (10, 2, 1, 0, 1), (11, 1, 0, 0, 1)");
        $this->storage = new StorageEngineLocal("$this->dir/files");
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        self::removeTree($this->dir);
    }

    private function service(array $config = []): ChunkedUpload
    {
        $logger = new class($this->log) implements LogEngineInterface {
            public function __construct(private array &$log) {}
            public function log(string $action, array $context = []): void { $this->log[] = [$action, $context]; }
            public function healthCheck(): bool { return true; }
        };
        return new ChunkedUpload($this->db, $this->storage, $logger, $config, '192.0.2.7');
    }

    /** Send chunks of $bytes as Dropzone would (all of them, or the given indexes) */
    private function send(string $bytes, string $name = 'dump.bin', int $chunkSize = 4, ?array $indexes = null,
                          int $user = self::ALICE, string $uuid = self::UUID, string $cwd = '', ?ChunkedUpload $service = null): array
    {
        $service ??= $this->service();
        $count = max(1, (int)ceil(strlen($bytes) / $chunkSize));
        $result = [];
        foreach ($indexes ?? range(0, $count - 1) as $index) {
            $chunk = substr($bytes, $index * $chunkSize, $chunkSize);
            $result = $service->receiveChunk($user, [
                'dzuuid' => $uuid, 'dzchunkindex' => (string)$index, 'dztotalfilesize' => (string)strlen($bytes),
                'dzchunksize' => (string)$chunkSize, 'dztotalchunkcount' => (string)$count,
                'dzchunkbyteoffset' => (string)($index * $chunkSize), 'cwd' => $cwd, 'name' => $name,
            ], self::stream($chunk), strlen($chunk));
        }
        return $result;
    }

    private static function stream(string $bytes)
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        return $stream;
    }

    private function upload(): array
    {
        return $this->db->query("SELECT * FROM uploads ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }

    private function stagingFiles(): array
    {
        return array_map('basename', glob("$this->dir/files/.uploads/*") ?: []);
    }

    private function logged(string $action): array
    {
        return array_values(array_map(fn($e) => $e[1], array_filter($this->log, fn($e) => $e[0] === $action)));
    }

    private function assertRefused(int $status, string $code, callable $call): UploadException
    {
        try {
            $call();
        } catch (UploadException $e) {
            $this->assertSame([$status, $code], [$e->httpStatus, $e->errorCode], $e->getMessage());
            return $e;
        }
        $this->fail("Expected $status $code");
    }

    // ----------------------------------------------------------------------

    public static function fileSizes(): array
    {
        // chunk size 4: empty file, one byte, exactly one chunk, k chunks + 1 byte
        return ['0 bytes' => [0], '1 byte' => [1], 'one chunk' => [4], 'k·chunk + 1' => [13]];
    }

    #[DataProvider('fileSizes')]
    public function testUploadIsStoredAndHashedFromStorage(int $size): void
    {
        $bytes = $size ? random_bytes($size) : '';
        $service = $this->service();
        $this->send($bytes, service: $service);
        $state = $service->complete(self::ALICE, self::UUID);

        $this->assertSame('completed', $state['status']);
        $this->assertSame(hash('sha256', $bytes), $state['file']['sha256']);
        $this->assertSame($size, $state['file']['size']);
        $this->assertSame('alice', $state['file']['uploaded_by']);
        $this->assertSame('192.0.2.7', $state['file']['uploaded_from']);
        $this->assertSame($bytes, file_get_contents("$this->dir/files/dump.bin"));
        $this->assertSame([], $this->stagingFiles(), 'staging file moved');

        $row = $this->db->query("SELECT size, sha256 FROM files WHERE name = 'dump.bin'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([$size, hash('sha256', $bytes)], [(int)$row['size'], $row['sha256']]);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM upload_chunks")->fetchColumn());
        $this->assertCount(1, $this->logged('upload_completed'));
        if ($size === 0) {
            // sha256sum < /dev/null
            $this->assertSame('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $state['file']['sha256']);
        }
    }

    public function testNothingVisibleBeforeCompletion(): void
    {
        $this->send('0123456789', indexes: [0, 1]);
        $this->assertFileDoesNotExist("$this->dir/files/dump.bin");
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM files")->fetchColumn());
        $this->assertCount(1, $this->stagingFiles());
    }

    public function testChunksOutOfOrderAndResent(): void
    {
        $bytes = 'abcdefghijklmnopq'; // 5 chunks of 4
        $service = $this->service();
        $this->send($bytes, indexes: [4, 2, 0, 2, 3, 1, 1], service: $service);
        $this->assertSame(17, $this->storage->stagingSize($this->upload()['staging_key']), 'resent chunks rewrite their range');
        $this->assertSame(hash('sha256', $bytes), $service->complete(self::ALICE, self::UUID)['file']['sha256']);
    }

    public function testMissingChunksRefuseCompletion(): void
    {
        $this->send('abcdefghijklmnopq', indexes: [0, 2, 4]);
        $e = $this->assertRefused(400, 'MissingChunks', fn() => $this->service()->complete(self::ALICE, self::UUID));
        $this->assertSame([1, 3], $e->details['missing']);
        $this->assertSame('in_progress', $this->upload()['status'], 'the client may still send them');
    }

    public function testCompleteIsIdempotent(): void
    {
        $service = $this->service();
        $this->send('abcdef', service: $service);
        $first = $service->complete(self::ALICE, self::UUID);
        $this->assertSame($first, $service->complete(self::ALICE, self::UUID), 'lost response: complete sent again');
        $this->assertSame($first, $service->status(self::ALICE, self::UUID));
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM files")->fetchColumn());
    }

    public function testChunkAfterCompletionRefused(): void
    {
        $service = $this->service();
        $this->send('abcdef', service: $service);
        $service->complete(self::ALICE, self::UUID);
        $this->assertRefused(409, 'InvalidState', fn() => $this->send('abcdef', indexes: [1]));
        $this->assertSame('abcdef', file_get_contents("$this->dir/files/dump.bin"));
    }

    public function testTamperedStagingIsCaughtByVerification(): void
    {
        // The hash comes from what storage holds, not from the bytes received
        $service = $this->service();
        $this->send('abcdefgh', service: $service);
        $staging = "$this->dir/files/.uploads/" . $this->upload()['staging_key'];
        file_put_contents($staging, 'abcdXfgh');

        $state = $service->complete(self::ALICE, self::UUID);
        $this->assertSame(hash('sha256', 'abcdXfgh'), $state['file']['sha256']);
        $this->assertNotSame(hash('sha256', 'abcdefgh'), $state['file']['sha256']);
    }

    public function testSizeMismatchFailsTheUpload(): void
    {
        $service = $this->service();
        $this->send('abcdefgh', service: $service);
        file_put_contents("$this->dir/files/.uploads/" . $this->upload()['staging_key'], 'xyz', FILE_APPEND);

        $this->assertRefused(422, 'SizeMismatch', fn() => $service->complete(self::ALICE, self::UUID));
        $this->assertSame('failed', $this->upload()['status']);
        $this->assertSame([], $this->stagingFiles());
        $this->assertFileDoesNotExist("$this->dir/files/dump.bin");
        $this->assertCount(1, $this->logged('upload_failed'));
    }

    // ------------------------------------------------------- name conflicts

    public function testNameConflictRefusedAtProbe(): void
    {
        $service = $this->service();
        $this->send('first', service: $service);
        $service->complete(self::ALICE, self::UUID);

        $this->assertRefused(409, 'NameConflict', fn() => $service->probe(self::ALICE, '', 'dump.bin', 5));
        $service->probe(self::ALICE, '', 'other.bin', 5);
        $service->probe(self::ALICE, '/case', 'dump.bin', 5);
        $this->assertCount(1, $this->logged('upload_name_conflict'));
    }

    public function testNameConflictRefusedAtFirstChunkNeverAppended(): void
    {
        // v1 appended a second upload of the same name to the stored file
        $service = $this->service();
        $this->send('first', service: $service);
        $service->complete(self::ALICE, self::UUID);

        $this->assertRefused(409, 'NameConflict', fn() => $this->send('second', uuid: self::UUID2));
        $this->assertSame('first', file_get_contents("$this->dir/files/dump.bin"));
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM files")->fetchColumn());
    }

    public function testNameConflictRefusedAtCompletion(): void
    {
        // Two users upload the same name at the same time: the first to complete wins
        $service = $this->service();
        $this->send('alice data', service: $service);
        $this->send('bob data', user: self::BOB, uuid: self::UUID2, service: $service);
        $service->complete(self::BOB, self::UUID2);

        $this->assertRefused(409, 'NameConflict', fn() => $service->complete(self::ALICE, self::UUID));
        $this->assertSame('bob data', file_get_contents("$this->dir/files/dump.bin"));
        $this->assertSame('failed', $this->db->query("SELECT status FROM uploads WHERE user_id = 1")->fetchColumn());
        $this->assertSame([], $this->stagingFiles());
    }

    public function testFolderNameConflict(): void
    {
        $this->assertRefused(409, 'NameConflict', fn() => $this->service()->probe(self::ALICE, '', 'case', 1));
    }

    public function testFileInStorageWithoutDatabaseRowIsNeverOverwritten(): void
    {
        file_put_contents("$this->dir/files/dump.bin", 'orphan');
        $service = $this->service();
        $this->send('new data', service: $service);
        $this->assertRefused(500, 'StorageError', fn() => $service->complete(self::ALICE, self::UUID));
        $this->assertSame('orphan', file_get_contents("$this->dir/files/dump.bin"));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM files")->fetchColumn(), 'files row rolled back');
        $this->assertSame('failed', $this->upload()['status']);
    }

    // ---------------------------------------------------- duplicate content

    public function testDuplicateContentRefusedInTheSameFolder(): void
    {
        $service = $this->service();
        $this->send('same bytes', name: 'a.bin', service: $service);
        $service->complete(self::ALICE, self::UUID);

        $this->send('same bytes', name: 'b.bin', uuid: self::UUID2, service: $service);
        $e = $this->assertRefused(409, 'DuplicateContent', fn() => $service->complete(self::ALICE, self::UUID2));
        $this->assertSame('a.bin', $e->details['file']['name']);
        $this->assertFileDoesNotExist("$this->dir/files/b.bin");
        $this->assertSame([], $this->stagingFiles());
        $this->assertCount(1, $this->logged('upload_duplicate_refused'));
    }

    public function testDuplicateContentAcceptedInAnotherFolder(): void
    {
        $service = $this->service();
        $this->send('same bytes', service: $service);
        $service->complete(self::ALICE, self::UUID);
        $this->send('same bytes', uuid: self::UUID2, cwd: '/case', service: $service);
        $this->assertSame('completed', $service->complete(self::ALICE, self::UUID2)['status']);
        $this->assertSame('same bytes', file_get_contents("$this->dir/files/case/dump.bin"));
    }

    // ------------------------------------------------- identity and rights

    public function testAnotherUsersUuidIsADifferentUpload(): void
    {
        $service = $this->service();
        $this->send('alice', name: 'a.bin', service: $service);
        // Bob reuses Alice's uuid: he gets his own upload, Alice's is untouched
        $this->send('bob!!', name: 'b.bin', user: self::BOB, service: $service);
        $this->assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM uploads")->fetchColumn());

        $this->assertSame('alice', file_get_contents($this->stagingPath(self::ALICE)));
        $this->assertRefused(404, 'NoSuchUpload', fn() => $service->complete(self::EVE, self::UUID));
        $this->assertRefused(404, 'NoSuchUpload', fn() => $service->abort(self::EVE, self::UUID));
        $this->assertSame('a.bin', $service->complete(self::ALICE, self::UUID)['file']['name']);
    }

    private function stagingPath(int $user): string
    {
        $key = $this->db->query("SELECT staging_key FROM uploads WHERE user_id = $user")->fetchColumn();
        return "$this->dir/files/.uploads/$key";
    }

    public function testRightsCheckedOnProbeChunkAndCompletion(): void
    {
        $service = $this->service();
        $this->assertRefused(403, 'AccessDenied', fn() => $service->probe(self::EVE, '', 'x', 1));
        $this->assertRefused(403, 'AccessDenied', fn() => $this->send('abc', user: self::EVE));
        $this->assertRefused(403, 'AccessDenied', fn() => $this->send('abc', user: self::BOB, cwd: '/case'));
        $this->assertRefused(404, 'NoSuchDirectory', fn() => $service->probe(self::ALICE, '/nope', 'x', 1));

        // Rights revoked between transfer and completion
        $this->send('abcdef', user: self::BOB, service: $service);
        $this->db->exec("DELETE FROM user_group WHERE user_id = 2");
        $this->assertRefused(403, 'AccessDenied', fn() => $service->complete(self::BOB, self::UUID));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM files")->fetchColumn());
    }

    public static function invalidNames(): array
    {
        return [[''], ['.'], ['..'], ["a\nb"], ['a/b'], ['a\\b'], ['100%25'], [str_repeat('x', 256)], ["\xC3\x28"], ['.uploads']];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNamesRefused(string $name): void
    {
        $this->assertRefused(400, 'InvalidName', fn() => $this->service()->probe(self::ALICE, '', $name, 1));
    }

    public function testDotNamesAreFine(): void
    {
        $service = $this->service();
        $this->send('history', name: '.bash_history', service: $service);
        $service->complete(self::ALICE, self::UUID);
        $this->send('notes', name: 'report..final.txt', uuid: self::UUID2, service: $service);
        $service->complete(self::ALICE, self::UUID2);
        $this->assertFileExists("$this->dir/files/.bash_history");
        $this->assertFileExists("$this->dir/files/report..final.txt");
        $this->service()->probe(self::ALICE, '/case', '.uploads', 1); // only reserved at the root
    }

    // ------------------------------------------------------------- geometry

    public static function badGeometry(): array
    {
        // file of 10 bytes, chunk size 4: chunks of 4, 4, 2
        $ok = ['dzchunkindex' => '0', 'dztotalfilesize' => '10', 'dzchunksize' => '4', 'dztotalchunkcount' => '3', 'dzchunkbyteoffset' => '0'];
        return [
            'chunk count' => [['dztotalchunkcount' => '4'] + $ok, 4],
            'zero chunks' => [['dztotalchunkcount' => '0'] + $ok, 4],
            'index out of range' => [['dzchunkindex' => '3', 'dzchunkbyteoffset' => '12'] + $ok, 2],
            'offset' => [['dzchunkbyteoffset' => '1'] + $ok, 4],
            'short chunk' => [$ok, 3],
            'long last chunk' => [['dzchunkindex' => '2', 'dzchunkbyteoffset' => '8'] + $ok, 4],
            'chunk size 0' => [['dzchunksize' => '0'] + $ok, 0],
            'negative' => [['dzchunkindex' => '-1'] + $ok, 4],
            'missing field' => [array_diff_key($ok, ['dzchunkbyteoffset' => 1]), 4],
            'array field' => [['dzchunkindex' => ['0']] + $ok, 4],
        ];
    }

    #[DataProvider('badGeometry')]
    public function testWrongGeometryRefused(array $fields, int $length): void
    {
        $fields += ['dzuuid' => self::UUID, 'cwd' => '', 'name' => 'x.bin'];
        $this->assertRefused(400, 'InvalidChunk',
            fn() => $this->service()->receiveChunk(self::ALICE, $fields, self::stream(str_repeat('x', $length)), $length));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM uploads")->fetchColumn());
    }

    public function testChunkNotMatchingItsUploadRefused(): void
    {
        $this->send('abcdefghij', indexes: [0]);
        // Same uuid, different declared size or name
        $this->assertRefused(400, 'InvalidChunk', fn() => $this->send('abcdefghijkl', indexes: [1]));
        $this->assertRefused(400, 'InvalidChunk', fn() => $this->send('abcdefghij', name: 'other.bin', indexes: [1]));
        $this->assertRefused(400, 'InvalidChunk', fn() => $this->send('abcdefghij', indexes: [1], cwd: '/case'));
    }

    public function testInvalidUuidAndCwdRefused(): void
    {
        $this->assertRefused(400, 'InvalidUpload', fn() => $this->send('abc', uuid: 'not-a-uuid'));
        $this->assertRefused(400, 'InvalidArgument', fn() => $this->send('abc', cwd: '2'));
    }

    public function testMaximumSize(): void
    {
        $service = $this->service(['upload_max_size' => '8']);
        $this->assertRefused(413, 'EntityTooLarge', fn() => $service->probe(self::ALICE, '', 'big.bin', 9));
        $this->assertRefused(413, 'EntityTooLarge', fn() => $this->send('123456789', service: $service));
        $this->send('12345678', service: $service);
        $this->assertSame('completed', $service->complete(self::ALICE, self::UUID)['status']);
    }

    public function testSizeSettings(): void
    {
        $this->assertSame(10 << 30, ChunkedUpload::parseSize('10G', 'x'));
        $this->assertSame(2048, ChunkedUpload::parseSize('2 KiB', 'x'));
        $this->assertNull(ChunkedUpload::parseSize(' ', 'x'));
        $this->expectException(\InvalidArgumentException::class);
        $this->service(['upload_max_size' => '-5']);
    }

    // ------------------------------------------------- abort, expiry, crash

    public function testAbort(): void
    {
        $service = $this->service();
        $this->send('abcdefgh', indexes: [0], service: $service);
        $this->assertSame('aborted', $service->abort(self::ALICE, self::UUID)['status']);
        $this->assertSame([], $this->stagingFiles());
        $this->assertRefused(409, 'InvalidState', fn() => $this->send('abcdefgh', indexes: [1]));
        $this->assertRefused(409, 'InvalidState', fn() => $service->complete(self::ALICE, self::UUID));
        $this->assertSame('aborted', $service->abort(self::ALICE, self::UUID)['status'], 'idempotent');
        $this->assertCount(1, $this->logged('upload_aborted'));
    }

    public function testCompletedUploadCannotBeAborted(): void
    {
        $service = $this->service();
        $this->send('abc', service: $service);
        $service->complete(self::ALICE, self::UUID);
        $this->assertRefused(409, 'InvalidState', fn() => $service->abort(self::ALICE, self::UUID));
        $this->assertFileExists("$this->dir/files/dump.bin");
    }

    public function testSameUserRestartingTheSameFileReplacesTheOldAttempt(): void
    {
        // e.g. page reloaded mid-upload: Dropzone starts again with a new uuid
        $service = $this->service();
        $this->send('abcdefgh', indexes: [0], service: $service);
        $this->send('abcdefgh', uuid: self::UUID2, service: $service);
        $this->assertSame('aborted', $this->db->query("SELECT status FROM uploads WHERE client_uuid = '" . self::UUID . "'")->fetchColumn());
        $this->assertCount(1, $this->stagingFiles());
        $this->assertSame('completed', $service->complete(self::ALICE, self::UUID2)['status']);
    }

    public function testStaleUploadsExpire(): void
    {
        $service = $this->service(['upload_stale_after' => '3600']);
        $this->send('abcdefgh', indexes: [0], service: $service);
        $this->assertSame(0, $service->expireStale());

        $this->db->exec("UPDATE uploads SET last_update = datetime('now', '-2 hours')");
        // Expiry runs when another upload starts
        $this->send('xyz', name: 'other.bin', uuid: self::UUID2, service: $service);
        $this->assertSame('expired', $this->db->query("SELECT status FROM uploads WHERE id = 1")->fetchColumn());
        $this->assertCount(1, $this->stagingFiles());
        $this->assertCount(1, $this->logged('upload_expired'));
        $this->assertRefused(409, 'InvalidState', fn() => $this->send('abcdefgh', indexes: [1], service: $service));
    }

    public function testInterruptedVerificationIsRestartedByStatus(): void
    {
        $service = $this->service();
        $this->send('abcdefgh', service: $service);
        // A verification that started and died (the request was killed)
        $this->db->exec("UPDATE uploads SET status = 'verifying', verified_bytes = 4");
        $this->assertSame('verifying', $service->status(self::ALICE, self::UUID)['status'], 'still running elsewhere');
        $this->assertSame('verifying', $service->complete(self::ALICE, self::UUID)['status'], 'not started twice');

        $this->db->exec("UPDATE uploads SET last_update = datetime('now', '-10 minutes')");
        $state = $service->status(self::ALICE, self::UUID);
        $this->assertSame('completed', $state['status']);
        $this->assertSame(hash('sha256', 'abcdefgh'), $state['file']['sha256']);
        $this->assertCount(1, $this->logged('upload_verify_restarted'));
    }

    public function testAbortDuringVerificationWins(): void
    {
        $service = $this->service();
        $this->send('abcdefgh', service: $service);
        $this->db->exec("UPDATE uploads SET status = 'verifying'");
        $this->assertSame('aborted', $service->abort(self::ALICE, self::UUID)['status']);
        $this->assertRefused(409, 'InvalidState', fn() => $service->complete(self::ALICE, self::UUID));
        $this->assertFileDoesNotExist("$this->dir/files/dump.bin");
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::removeTree("$path/$entry");
        }
        rmdir($path);
    }
}
