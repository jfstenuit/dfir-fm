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

    public function testDatabaseAndLogInsideTheStorageAreReserved(): void
    {
        $app = sys_get_temp_dir() . '/dfirfm-factory-' . bin2hex(random_bytes(4));
        mkdir("$app/storage", 0777, true);
        try {
            // The defaults: the database in storage/database, beside a file log
            $engine = StorageEngineFactory::create(['base_storage_directory' => "$app/storage", 'log_file_path' => "$app/storage/app.log"], $app);
            $this->assertSame(['/app.log', '/database', '/database/app.sqlite'], $engine->reservedPaths());
            $this->assertTrue($engine->isReserved('/database/app.sqlite-journal'));
            $this->assertFalse($engine->isReserved('/database.txt'));

            // A log that is not a file, and a relative database path
            $engine = StorageEngineFactory::create(['base_storage_directory' => "$app/storage", 'log_engine' => 'syslog',
                                                   'log_file_path' => "$app/storage/app.log", 'database_path' => 'storage/app.sqlite'], $app);
            $this->assertSame(['/app.sqlite'], $engine->reservedPaths(), 'the storage root itself is not reserved');

            // Kept apart, as they should be
            $engine = StorageEngineFactory::create(['base_storage_directory' => "$app/storage", 'database_path' => "$app/db/app.sqlite"], $app);
            $this->assertSame([], $engine->reservedPaths());
        } finally {
            exec('rm -rf ' . escapeshellarg($app));
        }
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
