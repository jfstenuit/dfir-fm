<?php
// src/Services/DirectoryRefresh.php
namespace Services;

use Backends\StorageEngineInterface;
use PDO;

/**
 * Compares what the storage holds with the database records, for the
 * "Refresh from storage" action (one directory, not recursive) and for
 * bin/check-integrity.php (the whole storage). Read-only: inserting and
 * logging are the caller's.
 *
 * All storage access goes through the engine: listDirectory() for the
 * folder tree, readStream() for the contents. Only engines with named
 * files (supportsNamedFiles()) can be compared.
 */
class DirectoryRefresh
{
    private const HASH_BLOCK = 1 << 20;

    public function __construct(
        private readonly PDO $db,
        private readonly StorageEngineInterface $storage,
    ) {
    }

    /** @throws \LogicException If the engine has no named files */
    private function requireNamedFiles(): void
    {
        if (!$this->storage->supportsNamedFiles()) {
            throw new \LogicException('The storage engine has no named files: there is no folder tree to compare.');
        }
    }

    /**
     * The direct content of one directory, storage against records.
     *
     * Empty folders are not stored (a folder appears with its first file),
     * so a subfolder record without a stored directory is not reported.
     *
     * @param array{id:int|string, path:string} $directory A directories row
     * @return array{
     *   new_folders: list<string>,
     *   new_files: list<array{name:string, size:int, mtime:?int}>,
     *   existing_files: list<array{id:int, name:string, size:int, sha256:?string, stored_size:int, mtime:?int}>,
     *   missing_files: list<array{id:int, name:string, size:int, sha256:?string}>,
     *   skipped: list<array{name:string, type:string, reason:string}>
     * }
     * @throws \LogicException If the engine has no named files.
     * @throws \RuntimeException If the directory can't be listed.
     */
    public function compare(array $directory): array
    {
        $this->requireNamedFiles();
        $entries = $this->storage->listDirectory($directory['path']);

        $stmt = $this->db->prepare("SELECT id, name, size, sha256 FROM files WHERE directory_id = :dir");
        $stmt->execute([':dir' => $directory['id']]);
        $files = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $files[$row['name']] = $row;
        }
        $stmt = $this->db->prepare("SELECT name FROM directories WHERE parent_id = :dir");
        $stmt->execute([':dir' => $directory['id']]);
        $folders = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

        $result = ['new_folders' => [], 'new_files' => [], 'existing_files' => [], 'missing_files' => [], 'skipped' => []];
        $skip = function (array $entry, string $reason) use (&$result) {
            $result['skipped'][] = ['name' => $entry['name'], 'type' => $entry['type'], 'reason' => $reason];
        };
        $storedFiles = [];

        foreach ($entries as $entry) {
            $name = $entry['name'];
            switch ($entry['type']) {
                case 'directory':
                    if (isset($folders[$name])) {
                        break;
                    }
                    if (isset($files[$name])) {
                        $skip($entry, 'name used by a file');
                    } elseif (!NameRules::validFolderName($name) || !NameRules::validFileName($name)) {
                        $skip($entry, 'name not supported');
                    } else {
                        $result['new_folders'][] = $name;
                    }
                    break;

                case 'file':
                    if (isset($files[$name])) {
                        $row = $files[$name];
                        $storedFiles[$name] = true;
                        $result['existing_files'][] = [
                            'id' => (int)$row['id'], 'name' => $name, 'size' => (int)$row['size'], 'sha256' => $row['sha256'],
                            'stored_size' => $entry['size'], 'mtime' => $entry['mtime'],
                        ];
                    } elseif (isset($folders[$name])) {
                        $skip($entry, 'name used by a folder');
                    } elseif (!NameRules::validFileName($name)) {
                        $skip($entry, 'name not supported');
                    } else {
                        $result['new_files'][] = ['name' => $name, 'size' => $entry['size'], 'mtime' => $entry['mtime']];
                    }
                    break;

                case 'link':
                    $skip($entry, 'symbolic link');
                    break;

                default:
                    $skip($entry, 'special file');
            }
        }

        foreach ($files as $name => $row) {
            if (!isset($storedFiles[$name])) {
                $result['missing_files'][] = ['id' => (int)$row['id'], 'name' => $name, 'size' => (int)$row['size'], 'sha256' => $row['sha256']];
            }
        }
        return $result;
    }

    /**
     * Hash a stored file, reading it from storage.
     *
     * @param callable(int):bool|null $progress Called after each block with
     *   the bytes read so far; returning false stops hashing (null result).
     * @return array{size:int, sha256:string}|null Null if the file can't be
     *   opened or read, or hashing was stopped.
     */
    public function hash(string $path, ?callable $progress = null): ?array
    {
        try {
            $stream = $this->storage->readStream($path);
        } catch (\Throwable $e) {
            return null;
        }
        if ($stream === null) {
            return null;
        }

        $context = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($stream)) {
                $block = fread($stream, self::HASH_BLOCK);
                if ($block === false) {
                    return null;
                }
                hash_update($context, $block);
                $bytes += strlen($block);
                if ($progress !== null && $progress($bytes) === false) {
                    return null;
                }
            }
        } finally {
            fclose($stream);
        }
        return ['size' => $bytes, 'sha256' => hash_final($context)];
    }

    /**
     * The file of a directory that already holds this content (D6), if any.
     *
     * @return array{id:int, name:string}|null
     */
    public function contentInUse(int $directoryId, string $sha256): ?array
    {
        $stmt = $this->db->prepare("SELECT id, name FROM files WHERE directory_id = :dir AND sha256 = :sha256");
        $stmt->execute([':dir' => $directoryId, ':sha256' => $sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['id' => (int)$row['id'], 'name' => $row['name']] : null;
    }

    /**
     * Every entry of the storage, depth first, through the engine.
     *
     * @return \Generator<string, array> path => listDirectory() entry; a
     *   directory that can't be listed yields type 'unreadable' and its
     *   error as 'reason'.
     * @throws \LogicException If the engine has no named files.
     */
    public function walk(string $directory = '/'): \Generator
    {
        $this->requireNamedFiles();
        try {
            $entries = $this->storage->listDirectory($directory);
        } catch (\Exception $e) {
            // Not readable, or a name the engine refuses in a path (e.g. "%41")
            yield $directory => ['name' => basename($directory), 'type' => 'unreadable', 'size' => null, 'mtime' => null, 'reason' => $e->getMessage()];
            return;
        }

        foreach ($entries as $entry) {
            $path = rtrim($directory, '/') . '/' . $entry['name'];
            yield $path => $entry;
            if ($entry['type'] === 'directory') {
                yield from $this->walk($path);
            }
        }
    }
}
?>
