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

// Autoload classes
require_once BASE_PATH . '/vendor/autoload.php';

use Core\Config;
use Core\Database;
use Core\Session;
use Core\App;
use Core\Response;
use Core\Request;
use Backends\LogEngineFactory;
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

// Initialize database
$db = Database::initialize(Config::databasePath($config, BASE_PATH));

// Initialize logger
$logEngine = LogEngineFactory::create($config);
if (!$logEngine->healthCheck()) {
    Response::triggerSystemError();
    return;
}

// Globally available variables through singleton pattern
App::setConfig($config);
App::setDb($db);
App::setLogger($logEngine);

// Start session management
Session::start();

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
// had its password changed elsewhere ends at once, answered as an expired
// session (the JS clients treat a redirect to login as one).
if ($requestUri !== '/login') {
    $revoked = Session::isAuthenticated() ? Session::revocationCause($db) : null;
    if ($revoked !== null) {
        App::getLogger()->log('session_revoked', ['cause' => $revoked]);
        Session::logout();
    }
    if ($revoked !== null || !Session::isAuthenticated() || !Session::isStillValid()) {
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
