<?php
// index.php - Entry Point for the Application

// Define constants for the project paths
define('BASE_PATH', __DIR__);
define('SRC_PATH', BASE_PATH . '/src');
define('ENV_FILE', BASE_PATH . '/.env');

// Security headers, sent first so that every response carries them
// (redirects and error pages included)
// No inline scripts or styles anywhere (Bootstrap 5 sets styles through the CSSOM,
// which CSP allows); data: images are Bootstrap's own SVG icons in its CSS
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
// Pages and JSON answers hold case data and per-user state: never cached
// (downloads replace it with their own private, no-store)
header('Cache-Control: no-store');

// Autoload classes
require_once BASE_PATH . '/vendor/autoload.php';

use Core\Config;
use Core\Database;
use Core\Session;
use Core\App;
use Core\Response;
use Core\Request;
use Backends\LogEngineFactory;
use Backends\LogWriteException;
use Middleware\SecurityMiddleware;

// Load configuration
$config = Config::load(ENV_FILE);

// Client IP and HTTPS detection: refuse to run with an invalid configuration
// rather than silently trusting (or ignoring) the wrong values
try {
    Request::configureTrustedProxies($config['trusted_proxies'] ?? '');
    Request::configureForceHttps($config['force_https'] ?? '');
} catch (\InvalidArgumentException $e) {
    error_log('DFIR FM configuration error: ' . $e->getMessage());
    Response::triggerSystemError();
    return;
}

// Login rate limiting needs APCu: without it (or with apc.enabled=0, where
// every apcu_* call silently fails) the limits would quietly switch off (#14)
if (($apcuProblem = SecurityMiddleware::apcuProblem()) !== null) {
    error_log("DFIR FM configuration error: APCu unavailable, $apcuProblem");
    Response::triggerApcuUnavailable($apcuProblem);
    return;
}

// Initialize database
$db = Database::initialize(Config::databasePath($config, BASE_PATH));

// Initialize logger: the events database, plus the configured log engine
$logEngine = LogEngineFactory::create($config);
if (!$logEngine->healthCheck()) {
    Response::triggerSystemError();
    return;
}

// No logging, no service (D29): an event that can't be logged ends the
// request (the action it records may be done), and the application stays
// stopped until a probe, at most once per 30 s, writes again
set_exception_handler(function (\Throwable $e) {
    if (!headers_sent()) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
    if ($e instanceof LogWriteException) {
        if (!headers_sent()) {
            Response::triggerLoggingFailure();
        }
        return;
    }
    // Anything else as PHP reports an uncaught exception
    error_log('PHP Fatal error:  Uncaught ' . $e);
    if (!headers_sent()) {
        Response::triggerSystemError();
    }
});
if (!$logEngine->resume()) {
    Response::triggerLoggingFailure();
    return;
}

// Globally available variables through singleton pattern
App::setConfig($config);
App::setDb($db);
App::setLogger($logEngine);

// Start session management, in the application's own session directory
$sessionSavePath = Config::sessionSavePath($config, BASE_PATH);
if (!Session::configure($config, $sessionSavePath)) {
    error_log("DFIR FM configuration error: session directory not writable: $sessionSavePath");
    Response::triggerSystemError();
    return;
}
Session::start();

// Behind a proxy that nobody told us about: say so in the log (and on the admin page)
SecurityMiddleware::checkProxyConfiguration();

// Basic routing logic
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptName = dirname($_SERVER['SCRIPT_NAME']);

// Remove subdirectory from the request URI if applicable
if (strpos($requestUri, $scriptName) === 0) {
    $requestUri = substr($requestUri, strlen($scriptName));
}

// Normalize the request URI
$requestUri = '/' . trim($requestUri, '/');

// Authentication check. A session whose account was locked, deleted or
// had its password changed elsewhere ends at once, as does one past its
// idle timeout or absolute lifetime; both are answered as an expired
// session (the JS clients treat a redirect to login as one). A change of
// client IP is logged, not refused.
if ($requestUri !== '/login') {
    if (Session::isAuthenticated()) {
        if (($revoked = Session::revocationCause($db)) !== null) {
            App::getLogger()->log('session_revoked', ['cause' => $revoked]);
            Session::logout();
        } elseif (($expired = Session::expiryCause()) !== null) {
            App::getLogger()->log('session_expired', ['cause' => $expired]);
            Session::logout();
        } elseif (($previousIp = Session::clientIpChange()) !== null) {
            App::getLogger()->log('session_ip_changed', ['previous_ip' => $previousIp]);
        }
    }
    if (!Session::isAuthenticated()) {
        $currentUrl = $_SERVER['REQUEST_URI'];
        header('Location: login?redirect=' . urlencode($currentUrl));
        exit;
    }
}

// CSRF protection, including the login form (login CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SecurityMiddleware::validateCsrfToken()) {
        header('Content-Type: application/json');
        http_response_code(403);
        // code: lets the client tell an expired token from a refused right (also 403)
        echo json_encode(['success' => false, 'code' => 'csrf_invalid', 'message' => 'Invalid CSRF token']);
        exit;
    }
}

switch ($requestUri) {
    case '/':
        Controllers\FileManagerController::handle($config,$db);
        break;

    case '/dl':
        Controllers\DownloadController::handle($config,$db);
        break;

    case '/upload':
        Controllers\UploadController::handle($config,$db);
        break;
    
    case '/admin':
        Controllers\AdminController::handle($config,$db);
        break;

    case '/profile':
        Controllers\ProfileController::handle($config,$db);
        break;
    
    case '/login':
        Controllers\LoginController::handle($config,$db);
        break;

    case '/logout':
        Controllers\LogoutController::handle($config,$db);
        break;
    
    default:
        Response::triggerNotFound();
        break;
}
?>
