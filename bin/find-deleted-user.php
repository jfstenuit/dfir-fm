<?php
// bin/find-deleted-user.php - Find the deleted accounts of an e-mail address (CLI only)
//
// Usage: php bin/find-deleted-user.php <email>
//
// A deleted account keeps its row, but its address is replaced by a
// pseudonym: "deleted:<sha256 of the lowercased address>:<id>". This script
// computes the hash of the given address and lists the matching accounts,
// with their id: files.uploaded_by, directories.created_by and the log
// entries of the account can then be searched by id.
//
// Read-only. Every lookup is logged (cli_deleted_user_lookup) with the hash,
// not the address. Exit status: 0 if an account was found, 1 if not, 2 on error.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
define('ENV_FILE', BASE_PATH . '/.env');

require_once BASE_PATH . '/vendor/autoload.php';

use Core\Config;
use Backends\LogEngineFactory;
use Middleware\AccessMiddleware;

if ($argc !== 2 || trim($argv[1]) === '' || str_starts_with($argv[1], '-')) {
    fwrite(STDERR, "Usage: php bin/find-deleted-user.php <email>\n");
    exit(2);
}
$hash = AccessMiddleware::addressHash($argv[1]);

try {
    $config = Config::load(ENV_FILE);
    $db = new PDO('sqlite:' . Config::databasePath($config, BASE_PATH), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    ]);
    // LIKE would treat '_' in the prefix as a wildcard: compare the prefix exactly
    $prefix = AccessMiddleware::DELETED_PREFIX . $hash . ':';
    $stmt = $db->prepare("SELECT u.id, u.deleted_at, a.username AS deleted_by,
                                 (SELECT COUNT(*) FROM files WHERE uploaded_by = u.id) AS files
                          FROM users u LEFT JOIN users a ON a.id = u.deleted_by
                          WHERE substr(u.username, 1, :length) = :prefix
                          ORDER BY u.id");
    $stmt->execute([':length' => strlen($prefix), ':prefix' => $prefix]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $logger = LogEngineFactory::create($config);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}

$osUser = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? (string)posix_geteuid()) : get_current_user();
$logger->log('cli_deleted_user_lookup', [
    'address_hash' => $hash,
    'accounts' => array_map(fn($a) => (int)$a['id'], $accounts),
    'username' => 'cli:' . $osUser,
]);

if (!$accounts) {
    echo "No deleted account for this address (hash $hash).\n";
    exit(1);
}
foreach ($accounts as $a) {
    echo "user id {$a['id']}: deleted {$a['deleted_at']} UTC by " . ($a['deleted_by'] ?? 'unknown') . ", {$a['files']} file(s) uploaded\n";
}
exit(0);
