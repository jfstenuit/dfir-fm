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
     * Validate and normalize the path to ensure it is within the base directory.
     *
     * @param string $path The input path to validate.
     * @return string The normalized absolute path.
     * @throws \Exception If the path is invalid or outside the base directory.
     */
    private function validatePath(string $path): string
    {
        // Resolve the absolute path
        $lookupPath = $this->baseDirectory . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        $absolutePath = realpath($this->baseDirectory . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR));
        // Check if the resolved path is within the base directory
        if ($absolutePath === false || strpos($absolutePath, $this->baseDirectory) !== 0) {
            throw new \Exception("Invalid path: $path. Directory traversal attempt detected.");
        }

        return $absolutePath;
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
        $absolutePath = $this->validatePath($path);

        if (!is_file($absolutePath)) return false;
        if (!unlink($absolutePath)) return false;

        $this->pruneEmptyDirectories(dirname($absolutePath));
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
