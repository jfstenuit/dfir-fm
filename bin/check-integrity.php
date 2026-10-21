<?php
// bin/check-integrity.php - Check stored files against their database records (CLI only)
//
// Usage: php bin/check-integrity.php
//
// Re-hashes every stored file and compares it with files.sha256 and
// files.size. Also reports:
//   - files rows sharing a name or a SHA-256 in one directory (these stop
//     schema migration v2; the v1 upload code could create them)
//   - with a storage engine that has named files (local), files in storage
//     without a database record (e.g. partial uploads left by v1), symbolic
//     links, special files and folders that can't be read
//   - rows that refer to a missing row (PRAGMA foreign_key_check)
//
// Read-only: it never changes the database or the storage. Run it as the web
// server user. Exit status: 0 if everything matches, 1 if not, 2 on error.
// Reading every file back takes as long as the storage needs to deliver it.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
define('ENV_FILE', BASE_PATH . '/.env');

require_once BASE_PATH . '/vendor/autoload.php';

use Core\Config;
use Core\Database;
use Backends\StorageEngineFactory;
use Services\DirectoryRefresh;

try {
    $config = Config::load(ENV_FILE);
    // Opened directly, without the migrations: this must also work when
    // migration v2 refuses to run
    $db = new PDO('sqlite:' . Config::databasePath($config, BASE_PATH), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    ]);
    $storage = StorageEngineFactory::create($config);
    $refresh = new DirectoryRefresh($db, $storage);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}

$problems = 0;
$report = function (string $line) use (&$problems) {
    echo $line, "\n";
    $problems++;
};

foreach (Database::duplicateFiles($db) as $line) {
    $report("DUPLICATE  $line");
}
foreach (Database::foreignKeyProblems($db) as $line) {
    $report("REFERENCE  $line");
}

$files = $db->query("SELECT f.id, f.name, f.size, f.sha256, d.path AS directory
                     FROM files f LEFT JOIN directories d ON d.id = f.directory_id ORDER BY f.id");
$expected = [];
$checked = 0;
foreach ($files as $file) {
    if ($file['directory'] === null) {
        $report("NO-FOLDER  file id {$file['id']} ({$file['name']}): its directory no longer exists");
        continue;
    }
    $path = rtrim($file['directory'], '/') . '/' . $file['name'];
    $expected[$path] = true;

    $stored = $refresh->hash($path);
    if ($stored === null) {
        $report("MISSING    $path (file id {$file['id']})");
        continue;
    }
    $checked++;

    if ($stored['size'] !== (int)$file['size']) {
        $report("SIZE       $path (file id {$file['id']}): {$stored['size']} bytes stored, {$file['size']} recorded");
    }
    if ($stored['sha256'] !== $file['sha256']) {
        $report("HASH       $path (file id {$file['id']}): stored {$stored['sha256']}, recorded " . ($file['sha256'] ?? 'none'));
    }
}

// Orphans need a folder tree to walk: a blob store has none
if ($storage->supportsNamedFiles()) {
    foreach ($refresh->walk() as $path => $entry) {
        match ($entry['type']) {
            'file' => isset($expected[$path]) || $report("ORPHAN     $path: in storage, no database record"),
            // Downloads would follow it out of the storage
            'link' => $report(isset($expected[$path]) ? "LINK       $path: stored as a symbolic link"
                                                      : "ORPHAN     $path: symbolic link in storage, no database record"),
            'other' => $report("ORPHAN     $path: special file in storage, no database record"),
            'unreadable' => $report("UNREADABLE $path: {$entry['reason']}"),
            default => null,
        };
    }
} else {
    echo "Orphan search skipped: the storage engine has no named files.\n";
}

echo "$checked file(s) re-hashed, $problems problem(s).\n";
exit($problems ? 1 : 0);
