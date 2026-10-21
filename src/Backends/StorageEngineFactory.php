<?php
// src/Backends/StorageEngineFactory.php
namespace Backends;

use Core\Config;

class StorageEngineFactory {
    /**
     * @param ?string $basePath The application directory, which relative
     *   paths in the configuration are relative to (default: BASE_PATH)
     */
    public static function create($config, ?string $basePath = null) {
        $engine = $config['storage_engine'] ?? 'local';
        $basePath ??= defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);

        switch ($engine) {
            case 'local':
                return new StorageEngineLocal($config['base_storage_directory'] ?? '', self::reservedPaths($config, $basePath));
            case 'azure_blob':
            case 'amazon_s3':
                // Planned (see README, "Future Features"), not implemented yet
                throw new \Exception("Storage engine '$engine' is not implemented yet; use storage_engine=local");
            default:
                throw new \Exception("Invalid storage engine specified in configuration: '$engine'");
        }
    }

    /**
     * The application's own files, which must never be served, listed or
     * overwritten as evidence if they were configured inside the storage:
     * the database and its directory, and the log file.
     *
     * @return list<string> absolute paths; a directory ends with '/'
     */
    private static function reservedPaths(array $config, string $basePath): array
    {
        $database = Config::databasePath($config, $basePath);
        $paths = [$database, dirname($database) . '/'];

        // As LogEngineFactory chooses the engine and the file
        if (($config['log_engine'] ?? $config['log_dest'] ?? 'file') === 'file') {
            $log = $config['log_file_path'] ?? '/tmp/dfir.log';
            $paths[] = str_starts_with($log, '/') ? $log : $basePath . '/' . $log;
        }
        return $paths;
    }
}
?>
