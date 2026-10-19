<?php
namespace Tests\Unit\Backends;

use Backends\LogEngineFactory;
use Backends\LogEngineFile;
use Backends\LogEngineStderr;
use Backends\LogEngineSyslog;
use Backends\StorageEngineFactory;
use Backends\StorageEngineLocal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FactoryTest extends TestCase
{
    public static function plannedStorageEngines(): array
    {
        return [['azure_blob'], ['amazon_s3']];
    }

    #[DataProvider('plannedStorageEngines')]
    public function testPlannedStorageEnginesRefused(string $engine): void
    {
        $this->expectExceptionMessage("Storage engine '$engine' is not implemented yet");
        StorageEngineFactory::create(['storage_engine' => $engine]);
    }

    public function testUnknownStorageEngineRefused(): void
    {
        $this->expectExceptionMessage("Invalid storage engine specified in configuration: 'ftp'");
        StorageEngineFactory::create(['storage_engine' => 'ftp']);
    }

    public function testLocalIsTheDefaultStorageEngine(): void
    {
        $this->assertInstanceOf(StorageEngineLocal::class, StorageEngineFactory::create(['base_storage_directory' => sys_get_temp_dir()]));
    }

    public function testLocalWithoutDirectoryRefused(): void
    {
        $this->expectExceptionMessage('Invalid base directory');
        StorageEngineFactory::create(['storage_engine' => 'local']);
    }

    public static function logEngineCases(): array
    {
        return [
            'default' => [[], LogEngineFile::class],
            'log_engine=stderr' => [['log_engine' => 'stderr'], LogEngineStderr::class],
            'log_engine=syslog' => [['log_engine' => 'syslog'], LogEngineSyslog::class],
            'legacy log_dest' => [['log_dest' => 'stderr'], LogEngineStderr::class],
            'log_engine wins over log_dest' => [['log_engine' => 'stderr', 'log_dest' => 'file'], LogEngineStderr::class],
            'csv format' => [['log_format' => 'csv'], LogEngineFile::class],
        ];
    }

    #[DataProvider('logEngineCases')]
    public function testLogEngineSelection(array $config, string $class): void
    {
        $this->assertInstanceOf($class, LogEngineFactory::create($config));
    }

    public function testUnknownLogEngineRefused(): void
    {
        $this->expectExceptionMessage('Unsupported log engine: kafka');
        LogEngineFactory::create(['log_engine' => 'kafka']);
    }

    public function testUnknownLogFormatRefused(): void
    {
        $this->expectExceptionMessage('Unsupported log format: xml');
        LogEngineFactory::create(['log_format' => 'xml']);
    }
}
