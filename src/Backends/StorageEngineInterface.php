<?php
// src/Backends/StorageEngineInterface.php
namespace Backends;

interface StorageEngineInterface
{
    /**
     * Create a storage element (file or blob).
     *
     * @param string $path The path or key for the storage element.
     * @return bool True on success, false on failure.
     */
    public function createElement(string $path): bool;

    /**
     * Read data from a storage element at a specific position.
     *
     * @param string $path The path or key of the storage element.
     * @param int $offset The byte offset to start reading from.
     * @param int $length The number of bytes to read.
     * @return string|null The read data, or null on failure.
     */
    public function readAt(string $path, int $offset, int $length): ?string;

    /**
     * Write or append data to a storage element.
     *
     * @param string $path The path or key of the storage element.
     * @param string $data The data to write or append.
     * @return bool True on success, false on failure.
     */
    public function writeAppend(string $path, string $data): bool;

    /**
     * Check if a storage element exists.
     *
     * @param string $path The path or key of the storage element.
     * @return bool True if the element exists, false otherwise.
     */
    public function exists(string $path): bool;

    /**
     * Get the size of a storage element.
     *
     * @param string $path The path or key of the storage element.
     * @return int|null The size in bytes, or null if the element doesn't exist.
     */
    public function getSize(string $path): ?int;

    /**
     * Remove a storage element.
     *
     * @param string $path The path or key of the storage element.
     * @return bool True on success, false on failure.
     */
    public function remove(string $path): bool;

    /**
     * Perform a health check to ensure the storage engine is functional.
     *
     * @return bool True if the storage engine is working, false otherwise.
     */
    public function healthCheck(): bool;
}
?>
