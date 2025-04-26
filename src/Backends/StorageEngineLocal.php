<?php
// src/Backends/StorageEngineLocal.php
namespace Backends;

class StorageEngineLocal implements StorageEngineInterface
{
    private $baseDirectory;

    public function __construct($baseDirectory) {
        $this->baseDirectory = rtrim(realpath($baseDirectory), DIRECTORY_SEPARATOR);

        if ($this->baseDirectory === false) {
            throw new \Exception("Invalid base directory: $baseDirectory");
        }
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

        // 4. Reject obvious traversal and ambiguity
        if (preg_match('/\.\.|\/{2,}|\\\\/', $path)) {
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
     * Create a storage element (file).
     *
     * @param string $path The path to create the storage element.
     * @return bool True on success, false on failure.
     */
    public function createElement(string $path): bool
    {
        $directory = $this->validatePath(dirname($path));
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                return false;
            }
        }
        return touch($this->baseDirectory . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR));
    }

    /**
     * Read data from a storage element at a specific position.
     *
     * @param string $path The path to the storage element.
     * @param int $offset The byte offset to start reading from.
     * @param int $length The number of bytes to read.
     * @return string|null The read data, or null on failure.
     */
    public function readAt(string $path, int $offset, int $length): ?string
    {
        $absolutePath = $this->validatePath($path);

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $file = fopen($absolutePath, 'rb');
        if (!$file) {
            return null;
        }

        fseek($file, $offset);
        $data = fread($file, $length);
        fclose($file);

        return $data ?: null;
    }

    /**
     * Write or append data to a storage element.
     *
     * @param string $path The path to the storage element.
     * @param string $data The data to write or append.
     * @return bool True on success, false on failure.
     */
    public function writeAppend(string $path, string $data): bool
    {
        $absolutePath = $this->validatePath($path);

        $file = fopen($absolutePath, 'ab'); // Open for append (binary-safe)
        if (!$file) {
            return false;
        }

        $written = fwrite($file, $data);
        fclose($file);

        return $written !== false;
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

        // Create a temporary test file to confirm write/read permissions
        $testFile = $this->baseDirectory . DIRECTORY_SEPARATOR . '.storage_engine_test';
        $success = file_put_contents($testFile, 'test') !== false;

        if ($success) {
            $success = is_readable($testFile) && unlink($testFile); // Ensure the file can be read and deleted
        }

        return $success;
    }    
}
?>
