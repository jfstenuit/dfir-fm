<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every file of the checkout is readable by others, and every directory
 * holding one is searchable: the web server user (www-data, Docker's
 * uid 82) is not the owner. A file written with a restrictive umask
 * (mode 600) took the live site down: a 500 on the PHP code, a 403 on
 * the assets. Git only records the executable bit, so this checks the
 * working tree.
 */
final class FilePermissionsTest extends TestCase
{
    /** @return list<string> the tracked files, relative to the project root */
    private static function trackedFiles(string $root): array
    {
        if (!is_dir("$root/.git")) {
            self::markTestSkipped('not a git checkout');
        }
        exec('git -C ' . escapeshellarg($root) . ' ls-files -z 2>/dev/null', $output, $status);
        if ($status !== 0) {
            self::markTestSkipped('git ls-files failed');
        }
        // Tracked but deleted in the working tree: nothing to check
        return array_values(array_filter(explode("\0", implode("\n", $output)),
            fn($file) => $file !== '' && file_exists("$root/$file")));
    }

    public function testTrackedFilesAreWorldReadable(): void
    {
        $root = dirname(__DIR__, 2);
        $unreadable = [];
        $directories = [];
        foreach (self::trackedFiles($root) as $file) {
            if ((fileperms("$root/$file") & 0004) === 0) {
                $unreadable[] = sprintf('%o %s', fileperms("$root/$file") & 0777, $file);
            }
            for ($dir = dirname($file); $dir !== '.'; $dir = dirname($dir)) {
                $directories[$dir] = true;
            }
        }
        foreach (array_keys($directories) as $dir) {
            if ((fileperms("$root/$dir") & 0005) !== 0005) {
                $unreadable[] = sprintf('%o %s/', fileperms("$root/$dir") & 0777, $dir);
            }
        }

        sort($unreadable);
        $this->assertSame([], $unreadable, 'not readable by the web server user: chmod o+r (files), o+rx (directories)');
    }
}
