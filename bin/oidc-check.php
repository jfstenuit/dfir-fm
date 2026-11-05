<?php
// bin/oidc-check.php - Check the OIDC provider and DFIR FM's client registration (CLI only)
//
// Usage:
//   php bin/oidc-check.php
//
// Checks, without a browser: the oidc_* configuration, the IdP's discovery
// document and JWKS, that the IdP accepts the client id and the redirect
// URI (an authorization request), and the client secret (a random code is
// exchanged: the IdP must refuse the code, not the client). A sign-in with
// a real account is tested end to end, through the login page.
//
// Run it as the web server user. Each run is logged as cli_oidc_check.
// Exit status: 0 when every check passes, 1 when one fails, 2 on a usage
// or configuration error.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
define('ENV_FILE', BASE_PATH . '/.env');

require_once BASE_PATH . '/vendor/autoload.php';

use Core\Config;
use Backends\LogEngineFactory;
use Backends\LogWriteException;
use Services\Oidc\CurlHttpsClient;
use Services\Oidc\OidcConfig;
use Services\Oidc\ProviderCheck;

if ($argc !== 1) {
    fwrite(STDERR, "Usage: php bin/oidc-check.php\n");
    exit(2);
}

try {
    $config = Config::load(ENV_FILE);
    $logger = LogEngineFactory::create($config);
    // A failed write ends the script with exit code 1 (D29)
    LogWriteException::handleInCli();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}
$osUser = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? (string)posix_geteuid()) : get_current_user();
$logCheck = function (string $result, array $context = []) use ($logger, $osUser, $config) {
    $logger->log('cli_oidc_check', ['result' => $result,
        'issuer' => $config['oidc_issuer'] ?? null] + $context + ['username' => 'cli:' . $osUser]);
};

$oidc = OidcConfig::fromConfig($config);
if ($oidc->state() !== OidcConfig::ENABLED || $oidc->problem() !== null) {
    $reason = match ($oidc->state()) {
        OidcConfig::DISABLED => 'OIDC is not configured (no oidc_* key set)',
        default => $oidc->problem(),
    };
    fwrite(STDERR, "FAIL  Configuration: $reason\n");
    $logCheck('config_error', ['reason' => $reason]);
    exit(2);
}
printf("OK    Configuration: %s, client %s, domain %s\n", $oidc->issuer(), $oidc->clientId(), $oidc->emailDomain());

$check = new ProviderCheck($oidc, new CurlHttpsClient($oidc->caFile()));
$results = $check->run();
foreach ($results as [$status, $name, $detail]) {
    printf("%-5s %s: %s\n", $status, $name, $detail);
}
if (!$check->passed()) {
    $failed = array_values(array_map(fn($r) => $r[1], array_filter($results, fn($r) => $r[0] === ProviderCheck::FAIL)));
    echo "\nSome checks failed.\n";
    $logCheck('fail', ['failed' => $failed]);
    exit(1);
}
echo "\nAll checks passed.\n";
$logCheck('ok');
exit(0);
