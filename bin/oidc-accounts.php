<?php
// bin/oidc-accounts.php - Manage the IdP identities of OIDC accounts (CLI only, #9)
//
// Usage:
//   php bin/oidc-accounts.php list
//   php bin/oidc-accounts.php relink <username> --subject <sub> [--issuer <iss>]
//   php bin/oidc-accounts.php unlink <username>
//
// list:   the OIDC accounts, with their issuer, subject, admin status and
//         last login.
// relink: give an account another IdP identity (an IdP migration, a changed
//         sub, a subject_conflict at sign-in), without creating a duplicate
//         account. --issuer defaults to oidc_issuer. Refused when another
//         account holds that identity. Ends the account's open sessions.
// unlink: clear the account's identity; its next sign-in links it again by
//         address (D33). It stays an OIDC account: it has no password.
//         Ends the account's open sessions.
//
// Check what the IdP says before a relink: the identity decides who gets
// the account. Every run is logged as cli_oidc_account, with the OS user.
// Exit status: 0 done, 1 refused, 2 on a usage or configuration error.

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

const USAGE = "Usage:\n"
    . "  php bin/oidc-accounts.php list\n"
    . "  php bin/oidc-accounts.php relink <username> --subject <sub> [--issuer <iss>]\n"
    . "  php bin/oidc-accounts.php unlink <username>\n";

function usage(string $problem = ''): never
{
    fwrite(STDERR, ($problem !== '' ? "$problem\n" : '') . USAGE);
    exit(2);
}

// Parsed by hand: getopt() skips what it doesn't know
$command = $argv[1] ?? '';
$args = array_slice($argv, 2);
$options = [];
$positional = [];
for ($i = 0; $i < count($args); $i++) {
    if (in_array($args[$i], ['--subject', '--issuer'], true)) {
        if (!isset($args[$i + 1]) || $args[$i + 1] === '') {
            usage("{$args[$i]} needs a value.");
        }
        $options[substr($args[$i], 2)] = $args[++$i];
    } elseif (str_starts_with($args[$i], '-')) {
        usage("Unknown option: {$args[$i]}");
    } else {
        $positional[] = $args[$i];
    }
}
$expected = ['list' => [0, []], 'relink' => [1, ['subject', 'issuer']], 'unlink' => [1, []]];
if (!isset($expected[$command])) {
    usage();
}
[$count, $allowed] = $expected[$command];
if (count($positional) !== $count || array_diff(array_keys($options), $allowed) !== []) {
    usage();
}
if ($command === 'relink' && !isset($options['subject'])) {
    usage('relink needs --subject.');
}

try {
    $config = Config::load(ENV_FILE);
    $db = Database::initialize(Config::databasePath($config, BASE_PATH));
    $logger = LogEngineFactory::create($config);
    // A failed write ends the script with exit code 1 (D29)
    LogWriteException::handleInCli();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}
App::setConfig($config);
App::setDb($db);
App::setLogger($logger);
$oidc = OidcConfig::fromConfig($config);

$osUser = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? (string)posix_geteuid()) : get_current_user();
$log = function (array $context) use ($logger, $osUser, $command) {
    $logger->log('cli_oidc_account', ['command' => $command] + $context + ['username' => 'cli:' . $osUser]);
};
$refuse = function (string $message, array $context) use ($log): never {
    fwrite(STDERR, "$message\n");
    $log(['result' => 'refused', 'reason' => $message] + $context);
    exit(1);
};

if ($command === 'list') {
    $accounts = $db->query("SELECT u.id, u.username, u.oidc_issuer, u.oidc_subject, u.last_login_at, u.locked_at,
                                   EXISTS (SELECT 1 FROM user_group ug JOIN groups g ON g.id = ug.group_id
                                           WHERE ug.user_id = u.id AND g.is_admin = 1) AS admin
                            FROM users u WHERE u.auth_source = 'oidc' AND u.deleted_at IS NULL ORDER BY u.username")
        ->fetchAll(PDO::FETCH_ASSOC);
    if ($accounts === []) {
        echo "No OIDC account.\n";
    }
    foreach ($accounts as $a) {
        printf("%s\n  issuer:     %s\n  subject:    %s\n  admin:      %s\n  last login: %s%s\n",
            $a['username'], $a['oidc_issuer'] ?? '(not linked)', $a['oidc_subject'] ?? '(not linked)',
            $a['admin'] ? 'yes' : 'no', $a['last_login_at'] !== null ? "{$a['last_login_at']} UTC" : 'never',
            $a['locked_at'] !== null ? "\n  locked:     {$a['locked_at']}" : '');
    }
    $log(['result' => 'ok', 'accounts' => count($accounts)]);
    exit(0);
}

$username = $positional[0];
$stmt = $db->prepare("SELECT * FROM users WHERE username = :u AND deleted_at IS NULL");
$stmt->execute([':u' => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$target = ['target_user' => $username];
if (!$user) {
    $refuse("No account '$username'.", $target);
}
$previous = ['previous_issuer' => $user['oidc_issuer'], 'previous_subject' => $user['oidc_subject']];

if ($command === 'unlink') {
    if ($user['oidc_subject'] === null) {
        $refuse("'$username' is not linked to an IdP identity.", $target);
    }
    // Stays auth_source='oidc' (no password): the next sign-in links it again by address
    $db->prepare("UPDATE users SET oidc_issuer = NULL, oidc_subject = NULL, session_version = session_version + 1 WHERE id = :id")
        ->execute([':id' => $user['id']]);
    $log(['result' => 'ok'] + $target + $previous);
    echo "Unlinked '$username' from {$user['oidc_issuer']} {$user['oidc_subject']}.\n"
        . "Its next sign-in through the IdP links it again by address; its open sessions have ended.\n";
    exit(0);
}

// relink
$issuer = $options['issuer'] ?? ($oidc->isEnabled() ? $oidc->issuer() : null);
if ($issuer === null) {
    usage('OIDC is not configured: give --issuer.');
}
$identity = ['issuer' => $issuer, 'subject' => $options['subject']];
if ($user['auth_source'] !== 'oidc' && !$oidc->inDomain($username)) {
    $refuse("'$username' is a password account outside the OIDC domain: it can't be linked to the IdP.", $target + $identity);
}
$stmt = $db->prepare("SELECT id, username, deleted_at FROM users WHERE oidc_issuer = :iss AND oidc_subject = :sub AND id <> :id");
$stmt->execute([':iss' => $issuer, ':sub' => $options['subject'], ':id' => $user['id']]);
if ($holder = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // A deleted account keeps its identity: that person stays refused (it isn't a new account)
    $refuse($holder['deleted_at'] !== null
        ? "That identity belongs to the deleted account #{$holder['id']}: a deleted account's identity can't be reused."
        : "That identity belongs to '{$holder['username']}': unlink it first.", $target + $identity);
}
if ($user['oidc_issuer'] === $issuer && $user['oidc_subject'] === $options['subject']) {
    $refuse("'$username' already has that identity.", $target + $identity);
}

// As a first sign-in links it (D33): no password, no pending invitation, sessions end
$db->prepare("UPDATE users SET oidc_issuer = :iss, oidc_subject = :sub, auth_source = 'oidc', password = NULL,
                               invitation_token = NULL, token_expiry = NULL, session_version = session_version + 1
              WHERE id = :id")
    ->execute([':iss' => $issuer, ':sub' => $options['subject'], ':id' => $user['id']]);
$log(['result' => 'ok'] + $target + $identity + $previous);
echo "Relinked '$username' to $issuer {$options['subject']}"
    . ($user['oidc_subject'] !== null ? " (was {$user['oidc_issuer']} {$user['oidc_subject']})" : '') . ".\n"
    . "Its open sessions have ended.\n";
exit(0);
