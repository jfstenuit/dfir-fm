<?php
// src/Backends/StorageEngineFactory.php
namespace Backends;

class StorageEngineFactory {
    public static function create($config) {
        $engine = $config['storage_engine'] ?? 'local';
        $settings = $config['storage_settings'] ?? [];

        switch ($engine) {
            case 'local':
                return new StorageEngineLocal($config['base_storage_directory']);
            case 'azure_blob':
                // Validate settings
                if (1==1) { // TODO
                    throw new \Exception('Azure settings are incomplete.');
                }
                
                return new StorageEngineAzureBlob();
            case 'amazon_s3':
                // Validate settings
                if (1==1) { // TODO
                    throw new \Exception('AWS settings are incomplete.');
                }
                
                return new StorageEngineAmazonS3();
            default:
                throw new \Exception('Invalid mail engine specified in configuration');
        }
    }
}
?>