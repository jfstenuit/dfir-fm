<?php
// bin/check-integrity.php - Check stored files against their database records (CLI only)
//
// Usage: php bin/check-integrity.php
//
// Re-hashes every stored file and compares it with files.sha256 and
// files.size. Also reports:
//   - files rows sharing a name or a SHA-256 in one directory (these stop
//     schema migration v2; the v1 upload code could create them)
//   - with storage_engine=local, files in storage without a database record
//     (e.g. partial uploads left by v1)
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
use Backends\StorageEngineLocal;

try {
    $config = Config::load(ENV_FILE);
    // Opened directly, without the migrations: this must also work when
    // migration v2 refuses to run
    $db = new PDO('sqlite:' . Config::databasePath($config, BASE_PATH), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    ]);
    $storage = StorageEngineFactory::create($config);
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

    try {
        $stream = $storage->readStream($path);
    } catch (\Throwable $e) {
        $stream = null;
    }
    if (!$stream) {
        $report("MISSING    $path (file id {$file['id']})");
        continue;
    }
    $context = hash_init('sha256');
    $size = hash_update_stream($context, $stream);
    fclose($stream);
    $sha256 = hash_final($context);
    $checked++;

    if ($size !== (int)$file['size']) {
        $report("SIZE       $path (file id {$file['id']}): $size bytes stored, {$file['size']} recorded");
    }
    if ($sha256 !== $file['sha256']) {
        $report("HASH       $path (file id {$file['id']}): stored $sha256, recorded " . ($file['sha256'] ?? 'none'));
    }
}

if (($config['storage_engine'] ?? '') === 'local') {
    $base = rtrim(realpath($config['base_storage_directory']), '/');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        $path = substr($entry->getPathname(), strlen($base));
        if ($entry->isFile() && !isset($expected[$path]) && !str_starts_with($path, '/' . StorageEngineLocal::STAGING_DIRECTORY . '/')) {
            $report("ORPHAN     $path: in storage, no database record");
        }
    }
}

echo "$checked file(s) re-hashed, $problems problem(s).\n";
exit($problems ? 1 : 0);
