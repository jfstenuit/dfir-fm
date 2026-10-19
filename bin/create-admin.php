<?php
// bin/create-admin.php - Create an administrator account (CLI only)
//
// Usage: php bin/create-admin.php <username>
//
// Creates the user (or reuses an existing one), sets a random password,
// adds the user to the admin group and prints the password once.
// The administrator should change it from the profile menu after logging in.
//
// Temporary bootstrap tool: replaced by bin/admin-recovery when admin
// login moves to OIDC (PLAN.md, Phase 2b).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
define('ENV_FILE', BASE_PATH . '/.env');

require_once BASE_PATH . '/vendor/autoload.php';

use Core\App;
use Core\Config;
use Core\Database;
use Backends\LogEngineFactory;

if ($argc !== 2 || $argv[1] === '' || str_starts_with($argv[1], '-')) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <username>\n");
    exit(2);
}
$username = trim($argv[1]);

$config = Config::load(ENV_FILE);
$db = Database::initialize(STORAGE_PATH . '/database/app.sqlite');
$logger = LogEngineFactory::create($config);
App::setConfig($config);
App::setDb($db);
App::setLogger($logger);

$osUser = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? (string)posix_geteuid()) : get_current_user();

$adminGroupId = $db->query("SELECT id FROM groups WHERE is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$adminGroupId) {
    fwrite(STDERR, "No administrator group found in the database.\n");
    exit(1);
}

// 24 random bytes, base64url-encoded: 32 characters
$password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$hash = password_hash($password, PASSWORD_DEFAULT);

$db->beginTransaction();

$stmt = $db->prepare("SELECT id FROM users WHERE username = :username");
$stmt->execute([':username' => $username]);
$userId = $stmt->fetchColumn();

if ($userId) {
    $stmt = $db->prepare("UPDATE users SET password = :password, invitation_token = NULL, token_expiry = NULL WHERE id = :id");
    $stmt->execute([':password' => $hash, ':id' => $userId]);
    $created = false;
} else {
    $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (:username, :password)");
    $stmt->execute([':username' => $username, ':password' => $hash]);
    $userId = $db->lastInsertId();
    $created = true;
}

$stmt = $db->prepare("INSERT OR IGNORE INTO user_group (user_id, group_id) VALUES (:user_id, :group_id)");
$stmt->execute([':user_id' => $userId, ':group_id' => $adminGroupId]);

$db->commit();

$logger->log('cli_admin_created', [
    'target_user' => $username,
    'new_account' => $created,
    'username' => 'cli:' . $osUser,
]);

echo ($created ? "Created" : "Updated existing") . " administrator account '$username'.\n";
echo "Password (shown once, change it after first login): $password\n";
