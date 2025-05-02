<?php
// index.php - Entry Point for the Application

// Define constants for the project paths
define('BASE_PATH', __DIR__);
define('SRC_PATH', BASE_PATH . '/src');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('ENV_FILE', BASE_PATH . '/.env');

// Autoload classes
require_once BASE_PATH . '/vendor/autoload.php';

use Core\Config;
use Core\Database;
use Core\Session;
use Core\App;
use Core\Response;
use Backends\LogEngineFactory;
use Middleware\SecurityMiddleware;

// Load configuration
$config = Config::load(ENV_FILE);

// Initialize database
$db = Database::initialize(STORAGE_PATH . '/database/app.sqlite');

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

// Authentication check
if ($requestUri !== '/login') {
    if (!Session::isAuthenticated() || !Session::isStillValid()) {
        $currentUrl = $_SERVER['REQUEST_URI'];
        header('Location: login?redirect=' . urlencode($currentUrl));
        exit;
    }
}

// CSP
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self';");

// CSRF protection
$csrfExemptRoutes = ['/login'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($requestUri, $csrfExemptRoutes)) {
    if (!SecurityMiddleware::validateCsrfToken()) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
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
        Middleware\NotFoundMiddleware::triggerNotFound();
        break;
}
?>
