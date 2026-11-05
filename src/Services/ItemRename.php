<?php
// src/Services/ItemRename.php
namespace Services;

use Backends\LogEngineInterface;
use Backends\LogWriteException;
use Backends\StorageEngineInterface;
use Backends\StorageEngineLocal;
use Middleware\AccessMiddleware;
use PDO;

/**
 * Renaming a file or a folder within its folder (#21, D25). The HTTP side
 * is FileManagerController's `rename` action.
 *
 * - Needs the write right on the parent folder, or admin, as deleting does.
 * - The new name follows the rules of uploads (files) or of "New folder"
 *   (folders), and must not be used by a file or a folder of the same
 *   folder (D2), in the records or in storage.
 * - The storage element is renamed first, then the records, in one
 *   BEGIN IMMEDIATE transaction; if the records can't be updated, the
 *   storage rename is undone.
 * - A folder carries its subfolders: their paths change, their ids don't,
 *   so access rights (keyed by directory id) follow. Its default group
 *   keeps the name it was created with (D20).
 * - Refused for a folder while an upload into it or a subfolder is in
 *   progress, and while a refresh runs in the renamed item's folder.
 * - Content and SHA-256 never change: the event records the old and the
 *   new path, for the chain of custody.
 */
final class ItemRename
{
    public function __construct(
        private readonly PDO $db,
        private readonly StorageEngineInterface $storage,
        private readonly LogEngineInterface $logger,
        private readonly array $config = [],
    ) {
    }

    /**
     * @param mixed $type 'f' (file) or 'd' (folder)
     * @return array{type:string, id:int, name:string, path:string, renamed:bool}
     *   the item after the rename; renamed is false if the name was already that one
     * @throws RenameException
     */
    public function rename(int $userId, mixed $type, mixed $id, mixed $name): array
    {
        if (!in_array($type, ['f', 'd'], true) || !is_string($id) || !preg_match('/^[1-9]\d{0,17}$/', $id) || !is_string($name)) {
            throw new RenameException(400, 'InvalidArgument', 'Missing or invalid item type, id or name.');
        }

        // Expired uploads don't keep a folder from being renamed
        (new ChunkedUpload($this->db, $this->storage, $this->logger, $this->config))->expireStale();

        [$item, $from, $to, $subfolders] = $this->transaction(function () use ($userId, $type, $id, $name) {
            $item = $this->item($userId, $type, $id);
            $from = $item['path'];
            $to = self::joinPath($item['parent_path'], $name);
            if ($name === $item['name']) {
                return [$item, $from, null, 0];
            }

            $this->validateName($type, $name, $item['parent_path']);
            $this->checkNameFree($item, $name, $to);
            $this->checkNothingRunning($type, $item);

            // A folder with nothing stored under it has no storage element:
            // only its records change
            if ($type === 'f' || $this->stored($from)) {
                if (!$this->storage->rename($from, $to)) {
                    throw new RenameException(500, 'StorageError', $type === 'f'
                        ? 'The file could not be renamed in storage (is it missing from storage?).'
                        : 'The folder could not be renamed in storage.');
                }
            }
            try {
                $subfolders = $this->updateRecords($type, $item, $name, $to);
            } catch (\Throwable $e) {
                $this->undoStorage($from, $to);
                throw $e;
            }
            return [$item, $from, $to, $subfolders];
        }, fn($result) => $result[2] === null ? null : $this->undoStorage($result[1], $result[2]));

        if ($to === null) {
            return ['type' => $type, 'id' => (int)$item['id'], 'name' => $item['name'], 'path' => $from, 'renamed' => false];
        }

        // After the commit: the event never tells of a rename that was undone
        if ($type === 'f') {
            $this->logger->log('file_renamed', ['file_id' => (int)$item['id'], 'from' => $from, 'to' => $to,
                                                'size' => (int)$item['size'], 'sha256' => $item['sha256']]);
        } else {
            $this->logger->log('folder_renamed', ['directory_id' => (int)$item['id'], 'from' => $from, 'to' => $to,
                                                  'subfolders' => $subfolders]);
        }
        return ['type' => $type, 'id' => (int)$item['id'], 'name' => $name, 'path' => $to, 'renamed' => true];
    }

    /**
     * The item, if the user may rename it. A missing item and one without
     * rights get the same answer: ids must not reveal what exists (#24).
     *
     * @return array the record, with 'path' and 'parent_path'
     */
    private function item(int $userId, string $type, string $id): array
    {
        if ($type === 'f') {
            $item = AccessMiddleware::getFileInfo($this->db, $id);
            if ($item) {
                $item['parent_path'] = $item['directory_path'];
                $item['path'] = self::joinPath($item['directory_path'], $item['name']);
            }
        } else {
            $item = AccessMiddleware::getDirectoryInfo($this->db, $id);
            if ($item && $item['parent_path'] === null) {
                throw new RenameException(400, 'InvalidArgument', 'The root folder cannot be renamed.');
            }
        }

        $rights = $item ? AccessMiddleware::checkAccess($this->db, $item['parent_path'], $userId) : null;
        if (!$item || !$rights['can_write']) {
            $this->logger->log('rename_denied', ['type' => $type === 'f' ? 'file' : 'folder', 'id' => (int)$id,
                                                 'cause' => $item ? 'Access denied' : 'No such item']);
            throw new RenameException(404, 'NotFound', ($type === 'f' ? 'File' : 'Folder') . ' not found or access denied.');
        }
        return $item;
    }

    /** The rules of uploads for a file, of "New folder" for a folder */
    private function validateName(string $type, string $name, string $parentPath): void
    {
        if ($type === 'f') {
            if (!NameRules::validFileName($name)) {
                throw new RenameException(400, 'InvalidName', 'This file name is not supported (control characters, slashes, "%" followed by two hex digits, or longer than 255 bytes).');
            }
            if ($parentPath === '/' && $name === StorageEngineLocal::STAGING_DIRECTORY) {
                throw new RenameException(400, 'InvalidName', "The name \"$name\" is reserved in the root folder.");
            }
        } elseif (!NameRules::validFolderName($name)) {
            throw new RenameException(400, 'InvalidName', 'Invalid folder name (no slashes, backslashes, "<", ">", "&", control characters, nor a leading dot).');
        }
        if ($this->storage->isReserved(self::joinPath($parentPath, $name))) {
            throw new RenameException(400, 'InvalidName', "The name \"$name\" is reserved in this folder: the application keeps its own files there.");
        }
    }

    /** D2: files and folders share the names of a folder, in the records and in storage */
    private function checkNameFree(array $item, string $name, string $to): void
    {
        $stmt = $this->db->prepare("SELECT 'file' FROM files f JOIN directories d ON d.id = f.directory_id WHERE d.path = :parent AND f.name = :name
                                    UNION ALL SELECT 'folder' FROM directories WHERE path = :path");
        $stmt->execute([':parent' => $item['parent_path'], ':name' => $name, ':path' => $to]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            throw new RenameException(409, 'NameConflict', "A $existing named \"$name\" already exists in this folder.");
        }
        if ($this->stored($to)) {
            throw new RenameException(409, 'NameConflict',
                "Something named \"$name\" is in this folder's storage without being in the application's records (a refresh from storage would show it).");
        }
    }

    /**
     * A folder: no upload in progress into it or a subfolder (the upload
     * would land under the old path). No refresh hashing files by name in
     * the folder of a renamed file, nor in a renamed folder or a subfolder.
     */
    private function checkNothingRunning(string $type, array $item): void
    {
        if ($type === 'd') {
            $uploads = $this->db->prepare("SELECT COUNT(*) FROM uploads u JOIN directories d ON d.id = u.directory_id
                                           WHERE u.status IN ('in_progress', 'verifying')
                                             AND (d.id = :id OR substr(d.path, 1, length(:prefix)) = :prefix)");
            $uploads->execute([':id' => $item['id'], ':prefix' => $item['path'] . '/']);
            if ($uploads->fetchColumn() > 0) {
                throw new RenameException(409, 'UploadInProgress', 'An upload into this folder or one of its subfolders is in progress: rename it once the upload is done.');
            }
        }

        $directory = $type === 'f' ? ['id' => $item['directory_id'], 'path' => $item['parent_path']] : $item;
        try {
            (new RefreshJobs($this->db, $this->storage, $this->logger, $this->config))->releaseDirectory($directory, $type === 'd',
                $type === 'f' ? 'A refresh from storage is running in this folder: wait for its end, or stop it, to rename.'
                              : 'A refresh from storage is running in this folder or one of its subfolders: wait for its end, or stop it, to rename.',
                'Left, and an item in its folder was renamed');
        } catch (RefreshException $e) {
            throw new RenameException($e->httpStatus, $e->errorCode, $e->getMessage(), $e->details);
        }
    }

    /** Whether the storage holds an element at a path; a path it refuses counts as an invalid name */
    private function stored(string $path): bool
    {
        try {
            return $this->storage->exists($path);
        } catch (\Exception $e) {
            throw new RenameException(400, 'InvalidName', 'This name cannot be used in storage.');
        }
    }

    /** @return int the subfolders whose path changed */
    private function updateRecords(string $type, array $item, string $name, string $to): int
    {
        if ($type === 'f') {
            $this->db->prepare("UPDATE files SET name = :name WHERE id = :id")->execute([':name' => $name, ':id' => $item['id']]);
            return 0;
        }
        // Subfolders first, matched by the old path
        $subfolders = $this->db->prepare("UPDATE directories SET path = :to || substr(path, length(:from) + 1)
                                          WHERE substr(path, 1, length(:prefix)) = :prefix");
        $subfolders->execute([':to' => $to, ':from' => $item['path'], ':prefix' => $item['path'] . '/']);
        $this->db->prepare("UPDATE directories SET name = :name, path = :to WHERE id = :id")
            ->execute([':name' => $name, ':to' => $to, ':id' => $item['id']]);
        return $subfolders->rowCount();
    }

    /** Put the storage element back under its old name */
    private function undoStorage(string $from, string $to): void
    {
        if (!$this->storage->rename($to, $from) && $this->storage->exists($to)) {
            error_log("DFIR FM: rename of $from to $to failed in the database, and could not be undone in storage");
        }
    }

    private static function joinPath(string $directory, string $name): string
    {
        return rtrim($directory, '/') . '/' . $name;
    }

    /**
     * BEGIN IMMEDIATE: SQLite's write lock up front (see ChunkedUpload).
     * $undo runs if the work succeeded but the commit failed.
     */
    private function transaction(callable $work, callable $undo): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
        } catch (LogWriteException $e) {
            // No logging, no service (D29): what was logged stays done (an
            // abandoned refresh expired); the rename itself never started
            $this->db->exec('COMMIT');
            throw $e;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
        try {
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $undo($result);
            if ($this->db->inTransaction()) {
                $this->db->exec('ROLLBACK');
            }
            throw $e;
        }
        return $result;
    }
}
?>
