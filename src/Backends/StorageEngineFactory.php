<?php
// src/Backends/StorageEngineFactory.php
namespace Backends;

class StorageEngineFactory {
    public static function create($config) {
        $engine = $config['storage_engine'] ?? 'local';

        switch ($engine) {
            case 'local':
                return new StorageEngineLocal($config['base_storage_directory'] ?? '');
            case 'azure_blob':
            case 'amazon_s3':
                // Planned (see README, "Future Features"), not implemented yet
                throw new \Exception("Storage engine '$engine' is not implemented yet; use storage_engine=local");
            default:
                throw new \Exception("Invalid storage engine specified in configuration: '$engine'");
        }
    }
}
?>
