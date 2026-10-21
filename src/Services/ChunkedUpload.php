<?php
// src/Services/ChunkedUpload.php
namespace Services;

use Backends\LogEngineInterface;
use Backends\StorageEngineInterface;
use Backends\StorageEngineLocal;
use Middleware\AccessMiddleware;
use PDO;

/**
 * Chunked uploads, behind Dropzone's wire format (POST /upload with the
 * dz* fields). The HTTP side is in Controllers\UploadController.
 *
 * - An upload is keyed by (user, Dropzone's dzuuid): another user's uuid is
 *   a different upload. Its bytes go to a staging element whose key is
 *   generated here, never derived from client input.
 * - Each chunk is written at its own offset and recorded in upload_chunks,
 *   so a chunk sent again (Dropzone retries) or out of order is harmless.
 * - complete() hashes the staged file *from storage*, then moves it to its
 *   final path and records it in files with its real size. A partial or
 *   unverified file is never visible at its final path.
 *
 * States: in_progress -> verifying -> completed, or aborted / failed /
 * expired. Terminal rows are kept for the audit trail; their chunk rows
 * are deleted.
 */
final class ChunkedUpload
{
    /** Uploads without activity for this long expire (upload_stale_after) */
    public const DEFAULT_STALE_AFTER = 86400;

    /** A verification without progress for this long was interrupted, e.g. by a crash */
    public const VERIFY_STALLED_AFTER = 120;

    private const HASH_BLOCK = 1 << 20;
    private const PROGRESS_BYTES = 16 << 20;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const ACTIVE = "('in_progress', 'verifying')";

    private int $staleAfter;
    private ?int $maxSize;

    /**
     * @param array<string,string> $config upload_stale_after (seconds), upload_max_size (bytes, K/M/G/T suffixes)
     * @throws \InvalidArgumentException on an invalid setting
     */
    public function __construct(
        private readonly PDO $db,
        private readonly StorageEngineInterface $storage,
        private readonly LogEngineInterface $logger,
        array $config = [],
        private readonly ?string $clientIp = null,
    ) {
        $this->staleAfter = self::parseSize($config['upload_stale_after'] ?? '', 'upload_stale_after') ?? self::DEFAULT_STALE_AFTER;
        $this->maxSize = self::parseSize($config['upload_max_size'] ?? '', 'upload_max_size');
    }

    /** "", "1024", "10M", "2G": null for empty */
    public static function parseSize(string $value, string $setting): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,15})\s*([KMGT]?)i?B?$/i', $value, $m) || (int)$m[1] === 0) {
            throw new \InvalidArgumentException("Invalid $setting: expected a positive number");
        }
        $shift = ['' => 0, 'K' => 10, 'M' => 20, 'G' => 30, 'T' => 40][strtoupper($m[2])];
        return (int)$m[1] << $shift;
    }

    /**
     * The rights probe, from Dropzone's accept hook: refuses a file before
     * any byte is transferred. $name and $size are optional (a client that
     * doesn't send them gets the rights check only).
     */
    public function probe(int $userId, mixed $cwd, ?string $name, ?int $size): void
    {
        $directory = $this->writableDirectory($userId, self::cwd($cwd));
        if ($size !== null) {
            $this->checkSize($size, $name ?? '', $directory);
        }
        if ($name !== null) {
            $this->validateName($name, $directory);
            $this->checkNameFree($directory, $name);
        }
    }

    /**
     * Store one chunk.
     *
     * @param array<string,mixed> $fields dzuuid, dzchunkindex, dztotalchunkcount,
     *        dztotalfilesize, dzchunksize, dzchunkbyteoffset, cwd, and name (the
     *        chunk's file name)
     * @param resource $data the chunk bytes
     * @return array{received:int, chunk_count:int}
     */
    public function receiveChunk(int $userId, array $fields, $data, int $length): array
    {
        $uuid = self::uuid($fields['dzuuid'] ?? null);
        $name = is_string($fields['name'] ?? null) ? $fields['name'] : '';
        $cwd = self::cwd($fields['cwd'] ?? null);
        try {
            [$index, $size, $chunkSize, $count, $offset] = self::geometry($fields, $length);
        } catch (UploadException $e) {
            $this->logger->log('upload_chunk_rejected', ['file' => $name, 'directory' => $cwd, 'cause' => $e->getMessage()]);
            throw $e;
        }

        $upload = $this->transaction(function () use ($userId, $uuid, $name, $cwd, $size, $chunkSize, $count) {
            $upload = $this->find($userId, $uuid);
            if (!$upload) {
                return $this->start($userId, $uuid, $name, $cwd, $size, $chunkSize, $count);
            }

            if ($upload['status'] !== 'in_progress') {
                throw new UploadException(409, 'InvalidState', self::stateMessage($upload));
            }
            $directory = $this->writableDirectory($userId, (string)$upload['directory_id']);
            $declared = [$directory['path'], $upload['name'], (int)$upload['size'], (int)$upload['chunk_size'], (int)$upload['chunk_count']];
            if ($declared !== [$cwd, $name, $size, $chunkSize, $count]) {
                $this->logger->log('upload_chunk_rejected', ['file' => $upload['name'], 'directory' => $directory['path'], 'cause' => 'Chunk does not match the upload']);
                throw new UploadException(400, 'InvalidChunk', 'This chunk does not match the upload it claims to belong to.');
            }
            return $upload;
        });

        // Outside the transaction: other requests shouldn't wait for the write
        if (!$this->storage->stagingWriteAt($upload['staging_key'], $offset, $data, $length)) {
            throw new UploadException(500, 'StorageError', 'The chunk could not be stored.');
        }

        return $this->transaction(function () use ($upload, $index, $length) {
            $status = $this->db->prepare("SELECT status FROM uploads WHERE id = :id");
            $status->execute([':id' => $upload['id']]);
            if ($status->fetchColumn() !== 'in_progress') {
                // Aborted or expired while this chunk was being written
                $this->storage->stagingRemove($upload['staging_key']);
                throw new UploadException(409, 'InvalidState', 'This upload is no longer in progress.');
            }
            $this->db->prepare("INSERT OR REPLACE INTO upload_chunks (upload_id, chunk_index, size) VALUES (:id, :index, :size)")
                ->execute([':id' => $upload['id'], ':index' => $index, ':size' => $length]);
            $this->touch((int)$upload['id']);

            $received = $this->db->prepare("SELECT COUNT(*) FROM upload_chunks WHERE upload_id = :id");
            $received->execute([':id' => $upload['id']]);
            return ['received' => (int)$received->fetchColumn(), 'chunk_count' => (int)$upload['chunk_count']];
        });
    }

    /**
     * Verify and commit an upload whose chunks have all been received.
     * Runs the verification in this call, which takes as long as reading
     * the file back from storage; meanwhile status() reports the progress.
     *
     * Idempotent: a completed upload returns its result again, and while
     * another request is verifying it, this returns the current state.
     *
     * @return array the state, as status() describes it
     */
    public function complete(int $userId, string $uuid): array
    {
        $uuid = self::uuid($uuid);
        try {
            [$upload, $claimed] = $this->claimForVerification($userId, $uuid);
            if ($claimed) {
                $this->verifyAndCommit($userId, $upload);
            }
        } catch (UploadException $e) {
            if ($e->terminal && ($upload ??= $this->find($userId, $uuid))) {
                $this->markFailed($upload, $e);
            }
            throw $e;
        }

        $state = $this->describe($this->find($userId, $uuid));
        if (in_array($state['status'], ['aborted', 'failed', 'expired'], true)) {
            throw new UploadException(409, 'InvalidState', self::stateMessage($state), details: ['upload' => $state]);
        }
        return $state;
    }

    /**
     * Move the upload to 'verifying'
     * @return array{array, bool} the upload, and whether this request now verifies it
     */
    private function claimForVerification(int $userId, string $uuid): array
    {
        return $this->transaction(function () use ($userId, $uuid) {
            $upload = $this->find($userId, $uuid) ?? throw self::notFound();

            if ($upload['status'] === 'verifying') {
                // Another request is verifying it, unless that one died
                if (!$this->stalled($upload)) {
                    return [$upload, false];
                }
                $this->logger->log('upload_verify_restarted', self::logContext($upload));
            } elseif ($upload['status'] !== 'in_progress') {
                return [$upload, false];
            } else {
                $this->checkAllChunksReceived($upload);
            }

            $this->writableDirectory($userId, (string)$upload['directory_id']);
            $this->db->prepare("UPDATE uploads SET status = 'verifying', verified_bytes = 0, last_update = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':id' => $upload['id']]);
            $this->logger->log('upload_verifying', self::logContext($upload));
            return [$upload, true];
        });
    }

    /**
     * State of an upload: status, verified_bytes, and the stored file once
     * completed. A verification that stopped making progress (the request
     * running it died) is restarted here, in this call.
     */
    public function status(int $userId, string $uuid): array
    {
        $uuid = self::uuid($uuid);
        $upload = $this->find($userId, $uuid) ?? throw self::notFound();
        if ($upload['status'] === 'verifying' && $this->stalled($upload)) {
            return $this->complete($userId, $uuid);
        }
        return $this->describe($upload);
    }

    /** Abort an upload (Dropzone's canceled event): its staging file is deleted */
    public function abort(int $userId, string $uuid, string $cause = 'Canceled by the user'): array
    {
        $uuid = self::uuid($uuid);
        $upload = $this->transaction(function () use ($userId, $uuid) {
            $upload = $this->find($userId, $uuid) ?? throw self::notFound();
            if ($upload['status'] === 'completed') {
                throw new UploadException(409, 'InvalidState', 'This upload is already completed.');
            }
            if (in_array($upload['status'], ['in_progress', 'verifying'], true)) {
                $this->end($upload, 'aborted', null);
            }
            return $upload;
        });

        if (in_array($upload['status'], ['in_progress', 'verifying'], true)) {
            $this->logger->log('upload_aborted', self::logContext($upload) + ['cause' => $cause]);
        }
        return $this->describe($this->find($userId, $uuid));
    }

    /**
     * Expire uploads without activity for upload_stale_after seconds.
     * Runs when an upload starts; bin/expire-uploads.php runs it on demand.
     *
     * @return int the number of uploads expired
     */
    public function expireStale(): int
    {
        $stmt = $this->db->prepare("SELECT * FROM uploads WHERE status IN " . self::ACTIVE . "
                                    AND last_update < datetime('now', :age)");
        $stmt->execute([':age' => "-$this->staleAfter seconds"]);

        $expired = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $upload) {
            if ($this->end($upload, 'expired', 'No activity for ' . $this->staleAfter . ' seconds')) {
                $this->logger->log('upload_expired', self::logContext($upload) + ['last_update' => $upload['last_update']]);
                $expired++;
            }
        }
        return $expired;
    }

    // ---------------------------------------------------------------- steps

    /** First chunk received (any index): create the upload */
    private function start(int $userId, string $uuid, string $name, string $cwd, int $size, int $chunkSize, int $count): array
    {
        $directory = $this->writableDirectory($userId, $cwd);
        $this->checkSize($size, $name, $directory);
        $this->validateName($name, $directory);
        $this->checkNameFree($directory, $name);

        $this->expireStale();

        // The same user uploading the same name again (e.g. after a page
        // reload) replaces their earlier, unfinished attempt
        $previous = $this->db->prepare("SELECT * FROM uploads WHERE user_id = :user AND directory_id = :dir AND name = :name
                                        AND status IN " . self::ACTIVE);
        $previous->execute([':user' => $userId, ':dir' => $directory['id'], ':name' => $name]);
        foreach ($previous->fetchAll(PDO::FETCH_ASSOC) as $old) {
            if ($this->end($old, 'aborted', 'Replaced by a new upload of the same file')) {
                $this->logger->log('upload_aborted', self::logContext($old) + ['cause' => 'Replaced by a new upload of the same file']);
            }
        }

        $key = bin2hex(random_bytes(16));
        if (!$this->storage->stagingCreate($key)) {
            throw new UploadException(500, 'StorageError', 'The upload could not be started in storage.');
        }

        $this->db->prepare("INSERT INTO uploads (user_id, client_uuid, directory_id, name, size, chunk_size, chunk_count, staging_key, status)
                            VALUES (:user, :uuid, :dir, :name, :size, :chunk_size, :count, :key, 'in_progress')")
            ->execute([':user' => $userId, ':uuid' => $uuid, ':dir' => $directory['id'], ':name' => $name, ':size' => $size,
                       ':chunk_size' => $chunkSize, ':count' => $count, ':key' => $key]);

        $upload = $this->find($userId, $uuid);
        $this->logger->log('upload_start', self::logContext($upload) + ['size' => $size, 'chunks' => $count]);
        return $upload;
    }

    private function checkAllChunksReceived(array $upload): void
    {
        $stmt = $this->db->prepare("SELECT chunk_index FROM upload_chunks WHERE upload_id = :id ORDER BY chunk_index");
        $stmt->execute([':id' => $upload['id']]);
        $received = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff(range(0, (int)$upload['chunk_count'] - 1), $received));
        if ($missing) {
            throw new UploadException(400, 'MissingChunks', sprintf('%d chunk(s) missing, starting at index %d.', count($missing), $missing[0]),
                details: ['missing' => array_slice($missing, 0, 100)]);
        }

        $staged = $this->storage->stagingSize($upload['staging_key']);
        if ($staged !== (int)$upload['size']) {
            throw new UploadException(422, 'SizeMismatch',
                sprintf('The stored data is %s bytes, but the file has %d.', $staged ?? 'no', $upload['size']), terminal: true);
        }
    }

    /** Hash the staged file from storage, then commit it */
    private function verifyAndCommit(int $userId, array $upload): void
    {
        $stream = $this->storage->stagingOpen($upload['staging_key'])
            ?? throw new UploadException(500, 'StorageError', 'The staged file is missing from storage.', terminal: true);

        $context = hash_init('sha256');
        $bytes = 0;
        $reported = 0;
        $reportedAt = microtime(true);
        try {
            while (!feof($stream)) {
                $block = fread($stream, self::HASH_BLOCK);
                if ($block === false) {
                    throw new UploadException(500, 'StorageError', 'The staged file could not be read back.', terminal: true);
                }
                hash_update($context, $block);
                $bytes += strlen($block);

                if ($bytes - $reported >= self::PROGRESS_BYTES || microtime(true) - $reportedAt >= 1) {
                    if (!$this->reportProgress((int)$upload['id'], $bytes)) {
                        return; // aborted or expired meanwhile: nothing left to do
                    }
                    [$reported, $reportedAt] = [$bytes, microtime(true)];
                }
            }
        } finally {
            fclose($stream);
        }
        $sha256 = hash_final($context);

        if ($bytes !== (int)$upload['size']) {
            throw new UploadException(422, 'SizeMismatch', sprintf('The stored file has %d bytes instead of %d.', $bytes, $upload['size']), terminal: true);
        }

        $this->transaction(function () use ($userId, $upload, $sha256, $bytes) {
            $status = $this->db->prepare("SELECT status FROM uploads WHERE id = :id");
            $status->execute([':id' => $upload['id']]);
            if ($status->fetchColumn() !== 'verifying') {
                return; // completed by a parallel verification, or aborted
            }

            $directory = $this->writableDirectory($userId, (string)$upload['directory_id'], terminal: true);
            $this->checkNameFree($directory, $upload['name'], terminal: true);
            $this->checkContentNew($directory, $upload['name'], $sha256);

            $this->db->prepare("INSERT INTO files (directory_id, name, uploaded_at, uploaded_by, uploaded_from, size, sha256)
                                VALUES (:dir, :name, :at, :by, :from, :size, :sha256)")
                ->execute([':dir' => $directory['id'], ':name' => $upload['name'], ':at' => gmdate('Y-m-d H:i:s'), ':by' => $userId,
                           ':from' => $this->clientIp, ':size' => $bytes, ':sha256' => $sha256]);
            $fileId = (int)$this->db->lastInsertId();

            // Last step in the transaction: if it fails, the files row goes too
            if (!$this->storage->stagingCommit($upload['staging_key'], self::joinPath($directory['path'], $upload['name']))) {
                throw new UploadException(500, 'StorageError',
                    'The verified file could not be moved to its final place (is there a file with this name in storage but not in the database?).',
                    terminal: true);
            }

            $this->db->prepare("UPDATE uploads SET status = 'completed', sha256 = :sha256, verified_bytes = :bytes, file_id = :file,
                                last_update = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':sha256' => $sha256, ':bytes' => $bytes, ':file' => $fileId, ':id' => $upload['id']]);
            $this->db->prepare("DELETE FROM upload_chunks WHERE upload_id = :id")->execute([':id' => $upload['id']]);

            $this->logger->log('upload_completed', self::logContext($upload) + ['size' => $bytes, 'sha256' => $sha256, 'file_id' => $fileId]);
        });
    }

    /** False if the upload is no longer being verified */
    private function reportProgress(int $id, int $bytes): bool
    {
        $stmt = $this->db->prepare("UPDATE uploads SET verified_bytes = :bytes, last_update = CURRENT_TIMESTAMP
                                    WHERE id = :id AND status = 'verifying'");
        $stmt->execute([':bytes' => $bytes, ':id' => $id]);
        return $stmt->rowCount() === 1;
    }

    private function markFailed(array $upload, UploadException $e): void
    {
        if ($this->end($upload, 'failed', $e->getMessage())) {
            $this->logger->log('upload_failed', self::logContext($upload) + ['code' => $e->errorCode, 'cause' => $e->getMessage()]);
        }
    }

    /**
     * Move an active upload to a final state and delete its staging file.
     * False if it was no longer active (another request ended it first).
     */
    private function end(array $upload, string $status, ?string $error): bool
    {
        $stmt = $this->db->prepare("UPDATE uploads SET status = :status, error = :error, last_update = CURRENT_TIMESTAMP
                                    WHERE id = :id AND status IN " . self::ACTIVE);
        $stmt->execute([':status' => $status, ':error' => $error, ':id' => $upload['id']]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->db->prepare("DELETE FROM upload_chunks WHERE upload_id = :id")->execute([':id' => $upload['id']]);
        if (!$this->storage->stagingRemove($upload['staging_key'])) {
            error_log("DFIR FM: failed to remove staging file {$upload['staging_key']} of upload {$upload['id']}");
        }
        return true;
    }

    // --------------------------------------------------------------- checks

    /**
     * The target directory, if the user may upload into it
     * @param string $selector a path, or a directory id
     */
    private function writableDirectory(int $userId, string $selector, bool $terminal = false): array
    {
        $directory = AccessMiddleware::getDirectoryInfo($this->db, $selector);
        if (!$directory) {
            $this->logger->log('upload_denied', ['directory' => $selector, 'cause' => 'Directory does not exist']);
            throw new UploadException(404, 'NoSuchDirectory', 'This folder does not exist.', $terminal);
        }
        $rights = AccessMiddleware::checkAccess($this->db, $directory['path'], $userId);
        if (!($rights['can_write'] || $rights['can_upload'])) {
            $this->logger->log('upload_denied', ['directory' => $directory['path'], 'cause' => 'Access denied']);
            throw new UploadException(403, 'AccessDenied', "You don't have permission to upload files in this folder.", $terminal);
        }
        return $directory;
    }

    private function checkSize(int $size, string $name, array $directory): void
    {
        if ($this->maxSize !== null && $size > $this->maxSize) {
            $this->logger->log('upload_denied', ['file' => $name, 'directory' => $directory['path'], 'size' => $size, 'cause' => 'File too large']);
            throw new UploadException(413, 'EntityTooLarge', sprintf('The file is larger than the maximum upload size (%d bytes).', $this->maxSize));
        }
    }

    /** Names the storage can hold, the same on every engine */
    private function validateName(string $name, array $directory): void
    {
        $valid = $name !== '' && $name !== '.' && $name !== '..' && strlen($name) <= 255
            && mb_check_encoding($name, 'UTF-8')
            && !preg_match('#[\x00-\x1F\x7F/\\\\]#', $name)
            && !preg_match('/%[0-9a-fA-F]{2}/', $name); // the storage refuses URL-encoded sequences
        if (!$valid) {
            throw new UploadException(400, 'InvalidName', 'This file name is not supported (control characters, slashes, "%" followed by two hex digits, or longer than 255 bytes).');
        }
        if ($directory['path'] === '/' && $name === StorageEngineLocal::STAGING_DIRECTORY) {
            throw new UploadException(400, 'InvalidName', "The name \"$name\" is reserved in the root folder.");
        }
    }

    /** D2: same name in the target directory is refused, never overwritten or appended to */
    private function checkNameFree(array $directory, string $name, bool $terminal = false): void
    {
        $stmt = $this->db->prepare("SELECT 'file' FROM files WHERE directory_id = :dir AND name = :name
                                    UNION ALL SELECT 'folder' FROM directories WHERE parent_id = :dir AND name = :name");
        $stmt->execute([':dir' => $directory['id'], ':name' => $name]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            $this->logger->log('upload_name_conflict', ['file' => $name, 'directory' => $directory['path']]);
            throw new UploadException(409, 'NameConflict', "A $existing named \"$name\" already exists in this folder.", $terminal);
        }
    }

    /** D6/D7: the same content twice in one directory is refused */
    private function checkContentNew(array $directory, string $name, string $sha256): void
    {
        $stmt = $this->db->prepare("SELECT id, name FROM files WHERE directory_id = :dir AND sha256 = :sha256");
        $stmt->execute([':dir' => $directory['id'], ':sha256' => $sha256]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $this->logger->log('upload_duplicate_refused', ['file' => $name, 'directory' => $directory['path'],
                                                             'sha256' => $sha256, 'existing_file' => $existing['name']]);
            throw new UploadException(409, 'DuplicateContent', "This folder already holds the same content, as \"{$existing['name']}\" (SHA-256 $sha256).",
                terminal: true, details: ['file' => ['id' => (int)$existing['id'], 'name' => $existing['name']]]);
        }
    }

    /**
     * Chunk geometry, as Dropzone computes it: every chunk but the last has
     * exactly chunkSize bytes, the last one the remainder; an empty file is
     * one empty chunk.
     *
     * @return array{int,int,int,int,int} index, file size, chunk size, chunk count, offset
     */
    private static function geometry(array $fields, int $length): array
    {
        $values = [];
        foreach (['dzchunkindex', 'dztotalfilesize', 'dzchunksize', 'dztotalchunkcount', 'dzchunkbyteoffset'] as $field) {
            $value = $fields[$field] ?? null;
            if (!is_string($value) && !is_int($value) || !preg_match('/^\d{1,15}$/', (string)$value)) {
                throw new UploadException(400, 'InvalidChunk', "Missing or invalid $field.");
            }
            $values[] = (int)$value;
        }
        [$index, $size, $chunkSize, $count, $offset] = $values;

        $problem = match (true) {
            $chunkSize < 1 => 'invalid chunk size',
            $count !== max(1, intdiv($size + $chunkSize - 1, $chunkSize)) => 'chunk count does not match the file size',
            $index >= $count => 'chunk index out of range',
            $offset !== $index * $chunkSize => 'byte offset does not match the chunk index',
            $length !== ($index < $count - 1 ? $chunkSize : $size - $offset) => 'chunk length does not match its position',
            default => null,
        };
        if ($problem !== null) {
            throw new UploadException(400, 'InvalidChunk', "Invalid chunk: $problem.");
        }
        return [$index, $size, $chunkSize, $count, $offset];
    }

    // -------------------------------------------------------------- helpers

    private function find(int $userId, string $uuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM uploads WHERE user_id = :user AND client_uuid = :uuid");
        $stmt->execute([':user' => $userId, ':uuid' => $uuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function stalled(array $upload): bool
    {
        $stmt = $this->db->prepare("SELECT :last < datetime('now', :age)");
        $stmt->execute([':last' => $upload['last_update'], ':age' => '-' . self::VERIFY_STALLED_AFTER . ' seconds']);
        return (bool)$stmt->fetchColumn();
    }

    private function touch(int $id): void
    {
        $this->db->prepare("UPDATE uploads SET last_update = CURRENT_TIMESTAMP WHERE id = :id")->execute([':id' => $id]);
    }

    /** The client's view of an upload */
    private function describe(array $upload): array
    {
        $state = [
            'status' => $upload['status'],
            'name' => $upload['name'],
            'size' => (int)$upload['size'],
            'verified_bytes' => (int)$upload['verified_bytes'],
        ];
        if ($upload['error'] !== null) {
            $state['message'] = $upload['error'];
        }
        if ($upload['status'] === 'completed') {
            $stmt = $this->db->prepare("SELECT f.id, f.name, f.size, f.sha256, f.uploaded_at, " . AccessMiddleware::userLabel('f.uploaded_by') . " AS uploaded_by, f.uploaded_from
                                        FROM files f LEFT JOIN users u ON u.id = f.uploaded_by WHERE f.id = :id");
            $stmt->execute([':id' => $upload['file_id']]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($file) {
                $file['id'] = (int)$file['id'];
                $file['size'] = (int)$file['size'];
                $state['file'] = $file;
            }
        }
        return $state;
    }

    private static function stateMessage(array $upload): string
    {
        return match ($upload['status']) {
            'completed' => 'This upload is already completed',
            'verifying' => 'This upload is being verified',
            'in_progress' => 'This upload is still in progress',
            'aborted' => 'This upload was canceled',
            'expired' => 'This upload expired',
            default => 'This upload failed',
        } . (empty($upload['error']) ? '.' : ': ' . $upload['error']);
    }

    private static function logContext(array $upload): array
    {
        return ['upload_id' => (int)$upload['id'], 'file' => $upload['name'], 'directory_id' => (int)$upload['directory_id']];
    }

    private static function notFound(): UploadException
    {
        return new UploadException(404, 'NoSuchUpload', 'Unknown upload.');
    }

    private static function uuid(mixed $value): string
    {
        if (!is_string($value) || !preg_match(self::UUID, $value)) {
            throw new UploadException(400, 'InvalidUpload', 'Missing or invalid upload id (dzuuid).');
        }
        return $value;
    }

    /** The target folder's path ('' is the root, as the upload form sends it) */
    public static function cwd(mixed $value): string
    {
        $cwd = is_string($value) ? trim($value) : '';
        if ($cwd === '') {
            return '/';
        }
        if ($cwd[0] !== '/') {
            // A bare number would select a directory by id
            throw new UploadException(400, 'InvalidArgument', 'Invalid folder path.');
        }
        return $cwd;
    }

    private static function joinPath(string $directory, string $name): string
    {
        return rtrim($directory, '/') . '/' . $name;
    }

    /**
     * BEGIN IMMEDIATE: takes SQLite's write lock up front, so two requests
     * can't both read a state and then both act on it
     */
    private function transaction(callable $work): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $this->db->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
}
