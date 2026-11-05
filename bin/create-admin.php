<?php
// bin/create-admin.php - Create an administrator account (CLI only)
//
// Usage: php bin/create-admin.php <username>
//
// Creates the user (or reuses an existing one), sets a random password,
// adds the user to the admin group and prints the password once.
// The administrator should change it from the profile menu after logging in.
//
// Also the break-glass tool: it works without the web interface and without
// any identity provider, so it can restore access when every admin is locked out.
// With OIDC enabled, the username must be outside oidc_email_domain: an
// in-domain address signs in through the IdP only (D35). An existing OIDC
// account it resets becomes a password account (its IdP identity is cleared).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
define('ENV_FILE', BASE_PATH . '/.env');

require_once BASE_PATH . '/vendor/autoload.php';

use Core\App;
use Core\Config;
use Core\Database;
use Backends\LogEngineFactory;
use Backends\LogWriteException;
use Middleware\AccessMiddleware;
use Services\Oidc\OidcConfig;

if ($argc !== 2 || $argv[1] === '' || str_starts_with($argv[1], '-')) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <username>\n");
    exit(2);
}
$username = trim($argv[1]);
if (str_starts_with($username, AccessMiddleware::DELETED_PREFIX)) {
    fwrite(STDERR, "'$username' is a deleted account; it cannot be reactivated. Create a new account with the person's address.\n");
    exit(2);
}

$config = Config::load(ENV_FILE);
$db = Database::initialize(Config::databasePath($config, BASE_PATH));
$logger = LogEngineFactory::create($config);
// A failed write ends the script with exit code 1 (D29)
LogWriteException::handleInCli();
App::setConfig($config);
App::setDb($db);
App::setLogger($logger);

$oidc = OidcConfig::fromConfig($config);
if ($oidc->inDomain($username)) {
    fwrite(STDERR, "'$username' is in the OIDC domain {$oidc->emailDomain()}: it signs in through the identity provider, never with a password,
"
        . "so it can't be a break-glass administrator. Use an address outside the domain. Its admin rights come from the IdP's admin claim.\n");
    exit(2);
}

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

$stmt = $db->prepare("SELECT id, auth_source FROM users WHERE username = :username");
$stmt->execute([':username' => $username]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);
$userId = $existing['id'] ?? false;
$fromOidc = ($existing['auth_source'] ?? null) === 'oidc';

if ($userId) {
    // A reset ends the account's open sessions, and lifts a lock (break-glass).
    // An OIDC account outside the domain (OIDC disabled, or the domain
    // changed) becomes a password account: the login refuses a password otherwise.
    $stmt = $db->prepare("UPDATE users SET password = :password, invitation_token = NULL, token_expiry = NULL, session_version = session_version + 1,
                                           locked_at = NULL, locked_by = NULL, auth_source = 'password', oidc_issuer = NULL, oidc_subject = NULL
                          WHERE id = :id");
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
    'converted_from_oidc' => $fromOidc,
    'username' => 'cli:' . $osUser,
]);

echo ($created ? "Created" : "Updated existing") . " administrator account '$username'.\n";
echo "Password (shown once, change it after first login): $password\n";
