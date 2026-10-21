<?php
// src/Services/RefreshJobs.php
namespace Services;

use Backends\LogEngineInterface;
use Backends\StorageEngineInterface;
use Middleware\AccessMiddleware;
use PDO;

/**
 * "Refresh from storage": brings into the chain of custody the files placed
 * in a directory's storage outside the application, and checks the stored
 * files against their records. The HTTP side is in
 * Controllers\RefreshController; the comparison in DirectoryRefresh.
 *
 * - start() compares the directory with its records, records the new
 *   subfolders and the missing files at once, and creates a job listing
 *   the files to hash: new ones first, then existing ones.
 * - step() hashes the next file inside the request, from storage, with its
 *   progress in the job row. A new file is then recorded (origin 'import');
 *   an existing one is compared with its record, which a mismatch never
 *   changes: the recorded sha256 is the evidence.
 * - One step runs at a time per job. A step without progress for
 *   STALLED_AFTER seconds died (e.g. a crash) and is restarted by the next.
 *
 * Job states: in_progress -> completed, or canceled / expired. The caller
 * checks that the user is an administrator.
 */
final class RefreshJobs
{
    /** A step without progress for this long was interrupted */
    public const STALLED_AFTER = ChunkedUpload::VERIFY_STALLED_AFTER;

    private const PROGRESS_BYTES = 16 << 20;

    private int $staleAfter;
    private DirectoryRefresh $refresh;

    /**
     * @param array<string,string> $config upload_stale_after (seconds): jobs
     *        without activity for this long expire, as uploads do
     */
    public function __construct(
        private readonly PDO $db,
        private readonly StorageEngineInterface $storage,
        private readonly LogEngineInterface $logger,
        array $config = [],
        private readonly ?string $clientIp = null,
    ) {
        $this->staleAfter = ChunkedUpload::parseSize($config['upload_stale_after'] ?? '', 'upload_stale_after') ?? ChunkedUpload::DEFAULT_STALE_AFTER;
        $this->refresh = new DirectoryRefresh($db, $storage);
    }

    /**
     * Compare a directory with its records and start hashing its files.
     *
     * @return array the job (see describe()) and 'comparison': the new
     *   folders (recorded), the files to import and to verify, the missing
     *   files (flagged) and the skipped entries with their reason
     */
    public function start(int $userId, mixed $cwd): array
    {
        try {
            $path = ChunkedUpload::cwd($cwd);
        } catch (UploadException $e) {
            throw new RefreshException(400, 'InvalidArgument', $e->getMessage());
        }
        $directory = AccessMiddleware::getDirectoryInfo($this->db, $path);
        if (!$directory) {
            throw new RefreshException(404, 'NoSuchFolder', 'This folder does not exist.');
        }
        if (!$this->storage->supportsNamedFiles()) {
            throw new RefreshException(409, 'NotSupported', 'The storage engine has no named files: there is no folder content to refresh from.');
        }
        $this->expireStale();

        try {
            $comparison = $this->refresh->compare($directory);
        } catch (\RuntimeException $e) {
            error_log("Refresh of $path: " . $e->getMessage());
            throw new RefreshException(500, 'StorageError', 'The folder could not be listed in storage.');
        }

        $jobId = $this->transaction(function () use ($userId, $directory, $comparison) {
            $this->replaceAbandoned($directory);
            $folders = $this->recordFolders($userId, $directory, $comparison['new_folders']);
            $this->flagMissing($directory, $comparison['missing_files']);

            $this->db->prepare("INSERT INTO refresh_jobs (user_id, directory_id, status, new_folders, missing, skipped)
                                VALUES (:user, :dir, 'in_progress', :folders, :missing, :skipped)")
                ->execute([':user' => $userId, ':dir' => $directory['id'], ':folders' => $folders,
                           ':missing' => count($comparison['missing_files']), ':skipped' => count($comparison['skipped'])]);
            $jobId = (int)$this->db->lastInsertId();

            $insert = $this->db->prepare("INSERT INTO refresh_job_files (job_id, position, name, file_id, size, mtime)
                                          VALUES (:job, :position, :name, :file, :size, :mtime)");
            $position = 0;
            foreach ([...$comparison['new_files'], ...$comparison['existing_files']] as $file) {
                $insert->execute([':job' => $jobId, ':position' => ++$position, ':name' => $file['name'], ':file' => $file['id'] ?? null,
                                  ':size' => $file['stored_size'] ?? $file['size'], ':mtime' => $file['mtime']]);
            }
            $this->logger->log('directory_refresh_started', ['refresh_id' => $jobId, 'directory' => $directory['path'], 'files' => $position]);
            if ($position === 0) {
                $this->finish($jobId, $directory['path']);
            }
            return $jobId;
        });

        return $this->describe($this->job($userId, $jobId)) + ['comparison' => [
            'new_folders' => $comparison['new_folders'],
            'new_files' => array_map(fn($f) => ['name' => $f['name'], 'size' => $f['size']], $comparison['new_files']),
            'existing_files' => array_map(fn($f) => ['name' => $f['name'], 'size' => $f['size']], $comparison['existing_files']),
            'missing_files' => array_map(fn($f) => ['name' => $f['name'], 'size' => $f['size']], $comparison['missing_files']),
            'skipped' => $comparison['skipped'],
        ]];
    }

    /**
     * Hash the next file of a job and record the result.
     *
     * @return array the job (see describe()), with 'file': this step's
     *   result (null if there was nothing to do), and 'busy': true if
     *   another request is hashing a file of this job
     */
    public function step(int $userId, int $jobId): array
    {
        [$job, $row, $busy] = $this->claim($userId, $jobId);
        if ($row === null) {
            return $this->describe($this->job($userId, $jobId)) + ['file' => null, 'busy' => $busy];
        }

        $path = self::joinPath($job['directory_path'], $row['name']);
        $reported = 0;
        $reportedAt = microtime(true);
        $hash = $this->refresh->hash($path, function (int $bytes) use ($jobId, &$reported, &$reportedAt) {
            if ($bytes - $reported < self::PROGRESS_BYTES && microtime(true) - $reportedAt < 1) {
                return true;
            }
            [$reported, $reportedAt] = [$bytes, microtime(true)];
            return $this->reportProgress($jobId, $bytes);
        });

        $result = $this->transaction(fn() => $this->record($job, $row, $path, $hash));
        return $this->describe($this->job($userId, $jobId)) + ['file' => $result, 'busy' => false];
    }

    /**
     * State of a job, with the results of the files done after position
     * $after (a client polling during a long step gets each result once)
     */
    public function status(int $userId, int $jobId, int $after = 0): array
    {
        $job = $this->job($userId, $jobId);
        $stmt = $this->db->prepare("SELECT * FROM refresh_job_files WHERE job_id = :job AND position > :after
                                    AND status NOT IN ('pending', 'hashing') ORDER BY position");
        $stmt->execute([':job' => $jobId, ':after' => $after]);
        return $this->describe($job) + ['files' => array_map(self::fileResult(...), $stmt->fetchAll(PDO::FETCH_ASSOC))];
    }

    /** Cancel a job: the step in progress stops, the remaining files are left as they are */
    public function cancel(int $userId, int $jobId): array
    {
        $this->transaction(function () use ($userId, $jobId) {
            $job = $this->job($userId, $jobId);
            if ($job['status'] !== 'in_progress') {
                return;
            }
            $this->end($job, 'canceled');
            $this->logger->log('directory_refresh_canceled', ['refresh_id' => $jobId, 'directory' => $job['directory_path']] + $this->counts($jobId));
        });
        return $this->describe($this->job($userId, $jobId));
    }

    /**
     * Expire jobs without activity for upload_stale_after seconds (the
     * administrator closed the page). Runs when a refresh starts;
     * bin/expire-uploads.php runs it on demand.
     *
     * @return int the number of jobs expired
     */
    public function expireStale(): int
    {
        $stmt = $this->db->prepare("SELECT j.*, d.path AS directory_path FROM refresh_jobs j JOIN directories d ON d.id = j.directory_id
                                    WHERE j.status = 'in_progress' AND j.last_update < datetime('now', :age)");
        $stmt->execute([':age' => "-$this->staleAfter seconds"]);
        $expired = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
            if ($this->end($job, 'expired')) {
                $this->logger->log('directory_refresh_expired', ['refresh_id' => (int)$job['id'], 'directory' => $job['directory_path'],
                                                                 'last_update' => $job['last_update']]);
                $expired++;
            }
        }
        return $expired;
    }

    // ---------------------------------------------------------------- steps

    /**
     * One refresh per directory at a time. A job without activity for
     * STALLED_AFTER seconds was left (page closed): the new one replaces it.
     */
    private function replaceAbandoned(array $directory): void
    {
        $stmt = $this->db->prepare("SELECT j.*, :path AS directory_path FROM refresh_jobs j WHERE directory_id = :dir AND status = 'in_progress'");
        $stmt->execute([':dir' => $directory['id'], ':path' => $directory['path']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
            if (!$this->stalled($job)) {
                throw new RefreshException(409, 'RefreshRunning', 'A refresh of this folder is already running.', ['refresh_id' => (int)$job['id']]);
            }
            $this->end($job, 'expired');
            $this->logger->log('directory_refresh_expired', ['refresh_id' => (int)$job['id'], 'directory' => $directory['path'],
                                                             'last_update' => $job['last_update'], 'cause' => 'Replaced by a new refresh']);
        }
    }

    /** @return int the folders recorded (one may have been created meanwhile) */
    private function recordFolders(int $userId, array $directory, array $names): int
    {
        $recorded = 0;
        foreach ($names as $name) {
            $path = self::joinPath($directory['path'], $name);
            if ($this->nameInUse((int)$directory['id'], $name)) {
                continue;
            }
            $this->db->prepare("INSERT INTO directories (name, path, parent_id, created_at, created_by, created_from)
                                VALUES (:name, :path, :parent, :at, :by, :from)")
                ->execute([':name' => $name, ':path' => $path, ':parent' => $directory['id'], ':at' => gmdate('Y-m-d H:i:s'),
                           ':by' => $userId, ':from' => $this->clientIp]);
            $this->logger->log('directory_imported', ['directory' => $path, 'directory_id' => (int)$this->db->lastInsertId()]);
            $recorded++;
        }
        return $recorded;
    }

    /** The records are kept: a missing file is flagged in the listing, and logged */
    private function flagMissing(array $directory, array $missing): void
    {
        $update = $this->db->prepare("UPDATE files SET integrity = 'missing', checked_at = :at WHERE id = :id");
        foreach ($missing as $file) {
            $update->execute([':at' => gmdate('Y-m-d H:i:s'), ':id' => $file['id']]);
            $this->logger->log('integrity_missing', ['file' => self::joinPath($directory['path'], $file['name']), 'file_id' => $file['id'],
                                                      'size' => $file['size'], 'sha256' => $file['sha256']]);
        }
    }

    /**
     * Take the next file of a job for hashing
     * @return array{array, ?array, bool} the job, the file row to hash
     *         (null: nothing to do), and whether another request hashes one
     */
    private function claim(int $userId, int $jobId): array
    {
        return $this->transaction(function () use ($userId, $jobId) {
            $job = $this->job($userId, $jobId);
            if ($job['status'] !== 'in_progress') {
                return [$job, null, false];
            }

            $stmt = $this->db->prepare("SELECT * FROM refresh_job_files WHERE job_id = :job AND status = 'hashing'");
            $stmt->execute([':job' => $jobId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!$this->stalled($job)) {
                    return [$job, null, true];
                }
                $this->logger->log('directory_refresh_step_restarted', ['refresh_id' => $jobId, 'file' => self::joinPath($job['directory_path'], $row['name'])]);
            } else {
                $stmt = $this->db->prepare("SELECT * FROM refresh_job_files WHERE job_id = :job AND status = 'pending' ORDER BY position LIMIT 1");
                $stmt->execute([':job' => $jobId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $this->finish($jobId, $job['directory_path']);
                    return [$this->job($userId, $jobId), null, false];
                }
                $this->db->prepare("UPDATE refresh_job_files SET status = 'hashing' WHERE job_id = :job AND position = :position")
                    ->execute([':job' => $jobId, ':position' => $row['position']]);
            }
            $this->db->prepare("UPDATE refresh_jobs SET current_file = :name, hashed_bytes = 0, last_update = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':name' => $row['name'], ':id' => $jobId]);
            return [$job, $row, false];
        });
    }

    /**
     * Record a hashed file (in a transaction). Nothing is recorded if the
     * job was canceled or the step restarted meanwhile.
     *
     * @param array{size:int, sha256:string}|null $hash null: not readable, or stopped
     */
    private function record(array $job, array $row, string $path, ?array $hash): ?array
    {
        $stmt = $this->db->prepare("SELECT j.status, f.status AS file_status FROM refresh_jobs j
                                    JOIN refresh_job_files f ON f.job_id = j.id AND f.position = :position WHERE j.id = :job");
        $stmt->execute([':job' => $job['id'], ':position' => $row['position']]);
        $state = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$state || $state['status'] !== 'in_progress' || $state['file_status'] !== 'hashing') {
            return null;
        }

        [$status, $reason] = $hash === null ? ['failed', 'The file could not be read from storage.']
            : ($row['file_id'] === null ? $this->import($job, $row, $path, $hash) : $this->verify($job, $row, $path, $hash));

        $this->db->prepare("UPDATE refresh_job_files SET status = :status, sha256 = :sha256, reason = :reason WHERE job_id = :job AND position = :position")
            ->execute([':status' => $status, ':sha256' => $hash['sha256'] ?? null, ':reason' => $reason, ':job' => $job['id'], ':position' => $row['position']]);
        $this->db->prepare("UPDATE refresh_jobs SET current_file = NULL, hashed_bytes = 0, last_update = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':id' => $job['id']]);

        $pending = $this->db->prepare("SELECT COUNT(*) FROM refresh_job_files WHERE job_id = :job AND status IN ('pending', 'hashing')");
        $pending->execute([':job' => $job['id']]);
        if ((int)$pending->fetchColumn() === 0) {
            $this->finish((int)$job['id'], $job['directory_path']);
        }

        return self::fileResult(['status' => $status, 'sha256' => $hash['sha256'] ?? null, 'reason' => $reason] + $row);
    }

    /** A new file: recorded unless its name or its content is already in the directory (D2, D6) */
    private function import(array $job, array $row, string $path, array $hash): array
    {
        // Still being copied (rsync): the bytes hashed are not the file's
        $size = $this->storage->getSize($path);
        if ($size !== $hash['size'] || $hash['size'] !== (int)$row['size']) {
            return ['skipped', 'The file changed during the refresh (still being copied?). Refresh again once it is complete.'];
        }
        if ($this->nameInUse((int)$job['directory_id'], $row['name'])) {
            return ['skipped', 'A file or folder with this name was added meanwhile.'];
        }
        $existing = $this->refresh->contentInUse((int)$job['directory_id'], $hash['sha256']);
        if ($existing) {
            return ['skipped', "This folder already holds the same content, as \"{$existing['name']}\"."];
        }

        $this->db->prepare("INSERT INTO files (directory_id, name, uploaded_at, uploaded_by, uploaded_from, size, sha256, origin, checked_at)
                            VALUES (:dir, :name, :at, :by, :from, :size, :sha256, 'import', :at)")
            ->execute([':dir' => $job['directory_id'], ':name' => $row['name'], ':at' => gmdate('Y-m-d H:i:s'), ':by' => $job['user_id'],
                       ':from' => $this->clientIp, ':size' => $hash['size'], ':sha256' => $hash['sha256']]);
        $this->logger->log('file_imported', [
            'refresh_id' => (int)$job['id'], 'file' => $path, 'file_id' => (int)$this->db->lastInsertId(),
            'size' => $hash['size'], 'sha256' => $hash['sha256'],
            'mtime' => $row['mtime'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int)$row['mtime']),
        ]);
        return ['imported', null];
    }

    /** An existing file: compared with its record, which is never changed */
    private function verify(array $job, array $row, string $path, array $hash): array
    {
        $stmt = $this->db->prepare("SELECT * FROM files WHERE id = :id AND directory_id = :dir AND name = :name");
        $stmt->execute([':id' => $row['file_id'], ':dir' => $job['directory_id'], ':name' => $row['name']]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file) {
            return ['skipped', 'The record of this file was deleted meanwhile.'];
        }

        $matches = $hash['size'] === (int)$file['size'] && $hash['sha256'] === $file['sha256'];
        $this->db->prepare("UPDATE files SET integrity = :integrity, checked_at = :at WHERE id = :id")
            ->execute([':integrity' => $matches ? null : 'mismatch', ':at' => gmdate('Y-m-d H:i:s'), ':id' => $file['id']]);
        if ($matches) {
            return ['verified', null];
        }

        $this->logger->log('integrity_mismatch', [
            'refresh_id' => (int)$job['id'], 'file' => $path, 'file_id' => (int)$file['id'],
            'recorded_size' => (int)$file['size'], 'stored_size' => $hash['size'],
            'recorded_sha256' => $file['sha256'], 'stored_sha256' => $hash['sha256'],
        ]);
        return ['mismatch', 'The stored file differs from its record: the record is kept.'];
    }

    /** All files done: the job is completed, with its counts in the log */
    private function finish(int $jobId, string $directoryPath): void
    {
        $this->db->prepare("UPDATE refresh_jobs SET status = 'completed', current_file = NULL, hashed_bytes = 0, last_update = CURRENT_TIMESTAMP
                            WHERE id = :id AND status = 'in_progress'")->execute([':id' => $jobId]);
        $this->logger->log('directory_refreshed', ['refresh_id' => $jobId, 'directory' => $directoryPath] + $this->counts($jobId));
    }

    /** False if the job is no longer in progress (canceled): hashing stops */
    private function reportProgress(int $jobId, int $bytes): bool
    {
        $stmt = $this->db->prepare("UPDATE refresh_jobs SET hashed_bytes = :bytes, last_update = CURRENT_TIMESTAMP
                                    WHERE id = :id AND status = 'in_progress'");
        $stmt->execute([':bytes' => $bytes, ':id' => $jobId]);
        return $stmt->rowCount() === 1;
    }

    /** End an active job. False if it had already ended. */
    private function end(array $job, string $status): bool
    {
        $stmt = $this->db->prepare("UPDATE refresh_jobs SET status = :status, current_file = NULL, last_update = CURRENT_TIMESTAMP
                                    WHERE id = :id AND status = 'in_progress'");
        $stmt->execute([':status' => $status, ':id' => $job['id']]);
        // The file being hashed is left as it is, like the remaining ones
        $this->db->prepare("UPDATE refresh_job_files SET status = 'pending' WHERE job_id = :id AND status = 'hashing'")
            ->execute([':id' => $job['id']]);
        return $stmt->rowCount() === 1;
    }

    // -------------------------------------------------------------- helpers

    /** A job of this user, with its directory's path */
    private function job(int $userId, int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT j.*, d.path AS directory_path FROM refresh_jobs j JOIN directories d ON d.id = j.directory_id
                                    WHERE j.id = :id AND j.user_id = :user");
        $stmt->execute([':id' => $jobId, ':user' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new RefreshException(404, 'NoSuchRefresh', 'Unknown refresh.');
    }

    /** Files and folders share the names of a directory */
    private function nameInUse(int $directoryId, string $name): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM files WHERE directory_id = :dir AND name = :name
                                    UNION ALL SELECT 1 FROM directories WHERE parent_id = :dir AND name = :name");
        $stmt->execute([':dir' => $directoryId, ':name' => $name]);
        return (bool)$stmt->fetchColumn();
    }

    private function stalled(array $job): bool
    {
        $stmt = $this->db->prepare("SELECT :last < datetime('now', :age)");
        $stmt->execute([':last' => $job['last_update'], ':age' => '-' . self::STALLED_AFTER . ' seconds']);
        return (bool)$stmt->fetchColumn();
    }

    /** @return array<string,int> what the job did, for the log and the client */
    private function counts(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT new_folders, missing, skipped FROM refresh_jobs WHERE id = :id");
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        $counts = ['new_folders' => (int)$job['new_folders'], 'imported' => 0, 'verified' => 0, 'mismatches' => 0,
                   'missing' => (int)$job['missing'], 'skipped' => (int)$job['skipped'], 'failed' => 0, 'remaining' => 0];

        $stmt = $this->db->prepare("SELECT status, COUNT(*) FROM refresh_job_files WHERE job_id = :id GROUP BY status");
        $stmt->execute([':id' => $jobId]);
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $status => $count) {
            $key = match ($status) {
                'mismatch' => 'mismatches',
                'pending', 'hashing' => 'remaining',
                default => $status,
            };
            $counts[$key] += (int)$count;
        }
        return $counts;
    }

    /** The client's view of a job */
    private function describe(array $job): array
    {
        $current = null;
        if ($job['current_file'] !== null) {
            $stmt = $this->db->prepare("SELECT size FROM refresh_job_files WHERE job_id = :job AND status = 'hashing'");
            $stmt->execute([':job' => $job['id']]);
            $size = $stmt->fetchColumn();
            $current = ['name' => $job['current_file'], 'size' => $size === false ? null : (int)$size, 'hashed_bytes' => (int)$job['hashed_bytes']];
        }
        $counts = $this->counts((int)$job['id']);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM refresh_job_files WHERE job_id = :id");
        $stmt->execute([':id' => $job['id']]);
        return [
            'refresh_id' => (int)$job['id'],
            'status' => $job['status'],
            'directory' => $job['directory_path'],
            'total' => (int)$stmt->fetchColumn(),
            'counts' => $counts,
            'current' => $current,
        ];
    }

    private static function fileResult(array $row): array
    {
        return ['position' => (int)$row['position'], 'name' => $row['name'], 'kind' => $row['file_id'] === null ? 'new' : 'existing',
                'status' => $row['status'], 'size' => (int)$row['size'], 'sha256' => $row['sha256'], 'reason' => $row['reason']];
    }

    private static function joinPath(string $directory, string $name): string
    {
        return rtrim($directory, '/') . '/' . $name;
    }

    /** BEGIN IMMEDIATE: SQLite's write lock up front (see ChunkedUpload) */
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
?>
