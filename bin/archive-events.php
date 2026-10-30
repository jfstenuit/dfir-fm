<?php
// bin/archive-events.php - Archive and purge events of the events database (CLI only)
//
// Usage:
//   php bin/archive-events.php --list
//   php bin/archive-events.php --before <date> --out <file>
//   php bin/archive-events.php --purge <archive id>
//
// The events database keeps every event until it is archived and purged
// (D28), here or from the admin page's Logs tab:
//   --before  writes every event before <date> (UTC unless the date gives an
//             offset; e.g. 2026-01-01) to <file> as JSONL, oldest first, and
//             records the archive with its count and SHA-256. <file> must
//             not exist. Logged as events_archived.
//   --purge   deletes the events of that archive, once, and only if they are
//             still exactly what was archived (same count, same SHA-256).
//             Keep the file in cold storage first. Logged as events_purged.
//   --list    lists the archives.
//
// Run it as the web server user. Exit status: 0 on success, 1 when refused,
// 2 on a usage or configuration error.

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
use Services\EventLog;

$usage = "Usage: php bin/archive-events.php --list\n"
       . "       php bin/archive-events.php --before <date> --out <file>\n"
       . "       php bin/archive-events.php --purge <archive id>\n";
$options = getopt('', ['list', 'before:', 'out:', 'purge:'], $rest);
$mode = match (true) {
    isset($options['list']) && count($options) === 1 => 'list',
    isset($options['before'], $options['out']) && count($options) === 2 => 'archive',
    isset($options['purge']) && count($options) === 1 => 'purge',
    default => null,
};
if ($mode === null || $rest !== $argc || array_filter($options, 'is_array')) {
    fwrite(STDERR, $usage);
    exit(2);
}

try {
    $config = Config::load(ENV_FILE);
    $log = new EventLog(EventStore::open(Config::eventsDatabasePath($config, BASE_PATH)));
    $logger = LogEngineFactory::create($config);
    // A failed write ends the script with exit code 1 (D29)
    LogWriteException::handleInCli();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}
$osUser = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? (string)posix_geteuid()) : get_current_user();
$by = 'cli:' . $osUser;

if ($mode === 'list') {
    $archives = $log->archives();
    if (!$archives) {
        echo "No archives.\n";
    }
    foreach ($archives as $a) {
        printf("#%d  before %s  %d event(s)  sha256 %s  made %s by %s  %s\n", $a['id'], $a['before_ts'], $a['events'], $a['sha256'],
               $a['created_at'], $a['created_by'], $a['purged_at'] ? "purged {$a['purged_at']} by {$a['purged_by']}" : 'not purged');
    }
    exit(0);
}

if ($mode === 'purge') {
    if (!ctype_digit($options['purge'])) {
        fwrite(STDERR, $usage);
        exit(2);
    }
    try {
        $archive = $log->purge((int)$options['purge'], $by);
    } catch (\DomainException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    $logger->log('events_purged', [
        'archive_id' => $archive['id'], 'before_ts' => $archive['before_ts'], 'max_event_id' => $archive['max_event_id'],
        'events' => $archive['events'], 'sha256' => $archive['sha256'], 'file' => $archive['file'], 'username' => $by,
    ]);
    echo "Archive #{$archive['id']} purged: {$archive['events']} event(s) before {$archive['before_ts']} deleted.\n";
    exit(0);
}

// Archive
try {
    $before = EventLog::filters(['until' => $options['before']])['until'] ?? null;
} catch (\InvalidArgumentException) {
    $before = null;
}
if ($before === null) {
    fwrite(STDERR, "Invalid date: {$options['before']}\n");
    exit(2);
}
$out = $options['out'];
$file = @fopen($out, 'x');
if ($file === false) {
    fwrite(STDERR, "Cannot create $out (it must not exist yet)\n");
    exit(2);
}
try {
    $archive = $log->startArchive($before, $by);
} catch (\DomainException $e) {
    fclose($file);
    unlink($out);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$result = $log->writeArchive($archive, fn(string $line) => fwrite($file, $line) === strlen($line));
// A full disk can surface only when the file is closed
$result['complete'] = fclose($file) && $result['complete'];
$log->finishArchive($archive, $result);

$logger->log('events_archived', [
    'archive_id' => $archive['id'], 'before_ts' => $before, 'max_event_id' => $archive['max_event_id'], 'file' => $out,
    'events' => $result['events'], 'sha256' => $result['sha256'], 'complete' => $result['complete'], 'username' => $by,
]);
if (!$result['complete']) {
    fwrite(STDERR, "Writing $out failed: the archive was not recorded and can't be purged.\n");
    exit(1);
}
echo "Archive #{$archive['id']}: {$result['events']} event(s) before $before written to $out\n";
echo "SHA-256 {$result['sha256']}\n";
echo "Keep the file, then purge with: php bin/archive-events.php --purge {$archive['id']}\n";
exit(0);
