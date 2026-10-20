<?php
namespace Tests\Unit\Core;

use Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $dir;
    private string $errorLog;
    private string $savedErrorLog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-config-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents("$this->dir/test.env", implode("\n", [
            "\xEF\xBB\xBFfirst=1",
            '# comment',
            '   # indented comment',
            '',
            'plain=value',
            'spaced = value with spaces ',
            'dq="quoted # not comment"',
            "sq='single'",
            'mismatch="half',
            'empty=',
            'hash=pa#ss',
            'export exp=yes',
            'no_equals_line',
            '=novalue',
            'bad key=x',
            'secret_line_without_equals_P4ss',
        ]) . "\n");

        // Capture error_log() output
        $this->errorLog = "$this->dir/php-errors.log";
        $this->savedErrorLog = (string)ini_get('error_log');
        ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->savedErrorLog);
        foreach (['DFIRFM_PLAIN', 'DFIRFM_NEW_KEY', 'UNRELATED'] as $name) {
            putenv($name);
        }
        array_map('unlink', glob("$this->dir/*"));
        rmdir($this->dir);
    }

    public function testParsing(): void
    {
        $config = Config::load("$this->dir/test.env");

        $this->assertSame([
            'first' => '1',
            'plain' => 'value',
            'spaced' => 'value with spaces',
            'dq' => 'quoted # not comment',
            'sq' => 'single',
            'mismatch' => '"half',
            'empty' => '',
            'hash' => 'pa#ss',
            'exp' => 'yes',
        ], $config);
    }

    public function testMalformedLinesReportedWithoutContent(): void
    {
        Config::load("$this->dir/test.env");
        $log = (string)file_get_contents($this->errorLog);

        foreach ([13, 14, 15, 16] as $line) {
            $this->assertStringContainsString("ignoring malformed line $line ", $log);
        }
        $this->assertStringNotContainsString('P4ss', $log, 'line content must never be logged');
    }

    public function testEnvironmentOverrides(): void
    {
        putenv('DFIRFM_PLAIN=from-env');
        putenv('DFIRFM_NEW_KEY="q"');
        putenv('UNRELATED=x');

        $config = Config::load("$this->dir/test.env");
        $this->assertSame('from-env', $config['plain']);
        $this->assertSame('q', $config['new_key']);
        $this->assertArrayNotHasKey('unrelated', $config);
    }

    public function testMissingFileAllowedWithEnvironmentOverrides(): void
    {
        putenv('DFIRFM_PLAIN=from-env');
        $this->assertSame(['plain' => 'from-env'], Config::load("$this->dir/missing.env"));
    }

    public function testDatabasePath(): void
    {
        $this->assertSame('/app/storage/database/app.sqlite', Config::databasePath([], '/app'));
        $this->assertSame('/app/storage/database/app.sqlite', Config::databasePath(['database_path' => ' '], '/app'));
        $this->assertSame('/data/database/app.sqlite', Config::databasePath(['database_path' => '/data/database/app.sqlite'], '/app'));
        $this->assertSame('/app/db/app.sqlite', Config::databasePath(['database_path' => 'db/app.sqlite'], '/app'));
    }

    public function testMissingFileWithoutOverridesRefused(): void
    {
        $this->expectExceptionMessage('Environment file not found');
        Config::load("$this->dir/missing.env");
    }

    public function testUnreadableFileRefused(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can read any file');
        }
        chmod("$this->dir/test.env", 0);
        try {
            $this->expectExceptionMessage('Environment file not readable');
            @Config::load("$this->dir/test.env");
        } finally {
            chmod("$this->dir/test.env", 0600);
        }
    }
}
