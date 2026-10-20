<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

/** database_path: the web application and the CLI tools use the same file, wherever it is */
final class DatabasePathTest extends FeatureTestCase
{
    public function testDatabaseOutsideTheApplicationDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/dfirfm-db-' . bin2hex(random_bytes(4));
        try {
            $this->restartWith(['database_path' => "$dir/data/app.sqlite"]);
            $this->assertFileExists("$dir/data/app.sqlite", 'created by bin/create-admin.php, directory included');
            $this->assertFileDoesNotExist($this->server->root . '/storage/database/app.sqlite');

            $this->assertSame(200, $this->adminClient()->get('')->status, 'the web application reads the same database');
            $this->server->addFile('a.bin', 'alpha');
            $result = $this->server->runBin('check-integrity.php');
            $this->assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
            $this->assertStringContainsString('1 file(s) re-hashed', $result['stdout']);
        } finally {
            $this->server->stop();
            @unlink("$dir/data/app.sqlite");
            @rmdir("$dir/data");
            @rmdir($dir);
        }
    }

    public function testRelativePathIsUnderTheApplicationDirectory(): void
    {
        $this->restartWith(['database_path' => 'db/custom.sqlite']);
        $this->assertFileExists($this->server->root . '/db/custom.sqlite');
        $this->assertSame($this->server->root . '/db/custom.sqlite', $this->server->databasePath);
        $this->bobClient();
    }
}
