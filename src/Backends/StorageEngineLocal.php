<?php
// src/Backends/StorageEngineLocal.php
namespace Backends;

class StorageEngineLocal implements StorageEngineInterface
{
    /**
     * Staging directory, under the storage root. The name is reserved in
     * the root directory (see Services\ChunkedUpload::validateName).
     */
    public const STAGING_DIRECTORY = '.uploads';

    private $baseDirectory;

    public function __construct($baseDirectory) {
        // Check before trimming: rtrim(false) would turn a failed realpath()
        // into '' and silently make '/' the storage root. An empty setting
        // must be refused too: realpath('') is the current directory.
        $baseDirectory = trim((string)$baseDirectory);
        $resolved = $baseDirectory === '' ? false : realpath($baseDirectory);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \Exception("Invalid base directory: $baseDirectory");
        }

        $this->baseDirectory = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * Normalize a path like realpath() but without requiring the target to exist.
     */
    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
            } else {
                $parts[] = $segment;
            }
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }

    /**
     * Validate and normalize the path to ensure it is within the base directory.
     *
     * @param string $path The input path to validate.
     * @return string The normalized absolute path.
     * @throws \Exception If the path is invalid or outside the base directory.
     */
    private function validatePath(string $path): string
    {
        // 1. Ensure the input is valid UTF-8
        if (!mb_check_encoding($path, 'UTF-8')) {
            throw new \Exception("Path contains invalid UTF-8 characters.");
        }

        // 2. Reject URL-encoded characters (e.g. %2e)
        if (preg_match('/%[0-9a-fA-F]{2}/', $path)) {
            throw new \Exception("Encoded characters are not allowed in paths.");
        }

        // 3. Reject null bytes and ASCII control characters
        if (preg_match('/[\x00-\x1F]/u', $path)) {
            throw new \Exception("Path contains control or null characters.");
        }

        // 4. Reject traversal ('..' segments; "report..pdf" is a valid name) and ambiguity
        if (preg_match('#(^|/)\.\.(/|$)|/{2,}|\\\\#', $path)) {
            throw new \Exception("Suspicious path detected : ".$path);
        }

        // Resolve the absolute path
        $lookupPath = $this->baseDirectory . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        $normalizedPath = $this->normalizePath($lookupPath);
        // Ensure the path stays within baseDirectory
        if (strpos($normalizedPath, $this->baseDirectory) !== 0) {
            throw new \Exception("Invalid path: $path. Directory traversal attempt detected.");
        }

        return $normalizedPath;
    }

    /**
     * Recursively prune empty directories up to the base directory.
     *
     * @param string $directory The directory to start pruning.
     */
    private function pruneEmptyDirectories(string $directory): void
    {
        // Loop to prune directories up the tree
        while ($directory !== $this->baseDirectory && is_dir($directory) && count(scandir($directory)) === 2) {
            // Remove the directory if it is empty (only '.' and '..')
            rmdir($directory);

            // Move up to the parent directory
            $directory = dirname($directory);
        }
    }

    /**
     * Check if a storage element exists.
     *
     * @param string $path The path to the storage element.
     * @return bool True if the element exists, false otherwise.
     */
    public function exists(string $path): bool
    {
        $absolutePath = $this->validatePath($path);
        return file_exists($absolutePath);
    }

    /**
     * Get the size of a storage element.
     *
     * @param string $path The path to the storage element.
     * @return int|null The size in bytes, or null if the element doesn't exist.
     */
    public function getSize(string $path): ?int
    {
        $absolutePath = $this->validatePath($path);

        if (!is_file($absolutePath)) {
            return null;
        }

        return filesize($absolutePath);
    }

    /**
     * Remove a storage element.
     *
     * @param string $path The path to the storage element.
     * @return bool True on success, false on failure.
     */
    public function remove(string $path): bool
    {
        try {
            $absolutePath = $this->validatePath($path);
        } catch (\Exception $e) {
            error_log("Failed to validate path in remove(): " . $e->getMessage());
            return false;
        }
    
        if (!file_exists($absolutePath)) {
            return true; // Already gone = success
        }
    
        if (is_file($absolutePath)) {
            if (!unlink($absolutePath)) {
                error_log("Failed to unlink file: $absolutePath");
                return false;
            }
    
            $this->pruneEmptyDirectories(dirname($absolutePath));
            return true;
        }
    
        // If it's not a file (e.g. dir, symlink?), still count as deleted
        return true;
    }

    public function readStream(string $path)
    {
        $absolutePath = $this->validatePath($path);
        if (!is_file($absolutePath)) {
            return null;
        }
        return fopen($absolutePath, 'rb') ?: null;
    }

    public function supportsNamedFiles(): bool
    {
        return true;
    }

    public function listDirectory(string $directory): array
    {
        $absolutePath = $this->validatePath($directory);
        // Folders are created when their first file is stored and pruned
        // with their last one: no directory means nothing stored there
        if (!file_exists($absolutePath) && !is_link($absolutePath)) {
            return [];
        }
        if (is_link($absolutePath) || !is_dir($absolutePath)) {
            throw new \RuntimeException("Not a directory: $directory");
        }
        $names = @scandir($absolutePath, SCANDIR_SORT_NONE);
        if ($names === false) {
            throw new \RuntimeException("Directory not readable: $directory");
        }

        $entries = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..'
                || ($absolutePath === $this->baseDirectory && $name === self::STAGING_DIRECTORY)) {
                continue;
            }
            $entryPath = $absolutePath . DIRECTORY_SEPARATOR . $name;
            // lstat: a symlink is reported, never followed
            $stat = @lstat($entryPath);
            $type = match (true) {
                $stat === false => 'other',
                is_link($entryPath) => 'link',
                is_dir($entryPath) => 'directory',
                is_file($entryPath) => 'file',
                default => 'other',
            };
            $entries[] = [
                'name' => $name,
                'type' => $type,
                'size' => $type === 'file' ? (int)$stat['size'] : null,
                'mtime' => $stat === false ? null : (int)$stat['mtime'],
            ];
        }
        usort($entries, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $entries;
    }

    /** The staging directory, created on first use; null if it can't be */
    private function stagingDirectory(): ?string
    {
        $directory = $this->baseDirectory . DIRECTORY_SEPARATOR . self::STAGING_DIRECTORY;
        if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
            error_log("Failed to create the staging directory: $directory");
            return null;
        }
        return $directory;
    }

    private function stagingPath(string $key): string
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $key)) {
            throw new \InvalidArgumentException('Invalid staging key');
        }
        $directory = $this->stagingDirectory();
        if ($directory === null) {
            throw new \RuntimeException('Staging directory unavailable');
        }
        return $directory . DIRECTORY_SEPARATOR . $key;
    }

    public function stagingCreate(string $key): bool
    {
        $path = $this->stagingPath($key);
        $handle = @fopen($path, 'xb'); // fails if it exists: keys are never reused
        if (!$handle) {
            return false;
        }
        fclose($handle);
        return true;
    }

    public function stagingWriteAt(string $key, int $offset, $source, int $length): bool
    {
        $path = $this->stagingPath($key);
        if ($offset < 0 || $length < 0 || !is_file($path)) {
            return false;
        }

        // 'c': open for writing without truncating, so chunks can arrive in
        // any order and a chunk sent again overwrites its own range
        $target = fopen($path, 'cb');
        if (!$target) {
            return false;
        }
        $copied = fseek($target, $offset) === 0 ? stream_copy_to_stream($source, $target, $length) : false;
        $flushed = fflush($target);
        fclose($target);

        return $copied === $length && $flushed;
    }

    public function stagingSize(string $key): ?int
    {
        $path = $this->stagingPath($key);
        clearstatcache(true, $path);
        return is_file($path) ? filesize($path) : null;
    }

    public function stagingOpen(string $key)
    {
        $path = $this->stagingPath($key);
        return is_file($path) ? (fopen($path, 'rb') ?: null) : null;
    }

    public function stagingCommit(string $key, string $path): bool
    {
        $source = $this->stagingPath($key);
        $target = $this->validatePath($path);
        if (!is_file($source) || file_exists($target) || is_link($target)) {
            return false;
        }

        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        // Same filesystem as the staging directory: rename() is atomic, so
        // the file appears complete or not at all
        return rename($source, $target);
    }

    public function stagingRemove(string $key): bool
    {
        $path = $this->stagingPath($key);
        return !file_exists($path) || unlink($path);
    }

    /** Free space of the file system holding the base directory (staging is on the same one) */
    public function freeSpace(): ?int
    {
        $free = @disk_free_space($this->baseDirectory);
        return $free === false ? null : (int)$free;
    }

    /**
     * Perform a health check to ensure the storage engine is functional.
     *
     * @return bool True if the base directory is writable and readable, false otherwise.
     */
    public function healthCheck(): bool
    {
        // Check if base directory exists and is writable
        if (!is_dir($this->baseDirectory) || !is_writable($this->baseDirectory)) {
            return false;
        }

        // Create a temporary test file to confirm write/read permissions.
        // In the staging directory: at the root, it could clobber a user's
        // file with the same name.
        $stagingDirectory = $this->stagingDirectory();
        if ($stagingDirectory === null) {
            return false;
        }
        $testFile = $stagingDirectory . DIRECTORY_SEPARATOR . '.health-' . bin2hex(random_bytes(4));
        $success = file_put_contents($testFile, 'test') !== false;

        if ($success) {
            $success = is_readable($testFile) && unlink($testFile); // Ensure the file can be read and deleted
        }

        return $success;
    }    
}
?>
