<?php
// bin/import-log.php - Import log files into the events database (CLI only)
//
// Usage: php bin/import-log.php <file>...
//
// For installations upgraded from a version without the events database:
// the events written to the log file before it existed (log_engine=file,
// log_format=jsonl) can be brought into it, so the admin page's Logs tab
// shows them too. Rotated and gzip-compressed files are accepted, in any
// order: they are imported oldest first.
//
// Each line is stored exactly as read. Only events older than the events
// database itself are imported (later ones are in it already), never events
// before the cutoff of a purged archive, and never an event already stored:
// running it twice imports nothing. Lines that are not events are counted
// and skipped. CSV logs (log_format=csv) can't be read back and are refused.
//
// Logged as events_imported, per file (SHA-256 of the file, counts). Run it
// as the web server user. Exit status: 0 on success, 1 when a file is
// refused, 2 on a usage or configuration error.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
define('ENV_FILE', BASE_PATH . '/.env');

require_once BASE_PATH . '/vendor/autoload.php';

use Core\Config;
use Core\EventStore;
use Backends\LogEngineFactory;
use Backends\LogWriteException;
use Services\EventImport;

$paths = array_slice($argv, 1);
if (!$paths || array_filter($paths, fn($p) => str_starts_with($p, '-'))) {
    fwrite(STDERR, "Usage: php bin/import-log.php <file>...\n");
    exit(2);
}

try {
    $config = Config::load(ENV_FILE);
    $events = EventStore::open(Config::eventsDatabasePath($config, BASE_PATH));
    $logger = LogEngineFactory::create($config);
    // A failed write ends the script with exit code 1 (D29)
    LogWriteException::handleInCli();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}
$osUser = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? (string)posix_geteuid()) : get_current_user();

try {
    $files = EventImport::inspect($paths);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\nNothing was imported.\n");
    exit(1);
}

echo 'Importing the events older than ' . EventStore::createdAt($events) . " (when the events database started).\n";
$import = new EventImport($events);
foreach ($files as $file) {
    $result = $import->import($file['path']);
    $logger->log('events_imported', $result + ['username' => 'cli:' . $osUser]);
    printf("%s: %d imported, %d already stored, %d newer than the events database, %d in a purged period, %d malformed line(s)\n",
           $result['file'], $result['imported'], $result['already_stored'], $result['too_recent'], $result['purged_period'], $result['malformed']);
}
exit(0);
