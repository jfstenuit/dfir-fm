<?php
// bin/expire-uploads.php - Expire abandoned uploads (CLI only)
//
// Usage: php bin/expire-uploads.php
//
// Uploads without activity for upload_stale_after seconds (default 86400)
// are marked expired and their staging files deleted. The application does
// this itself whenever an upload starts; this script is for a cron job, so
// staging space is reclaimed even when nobody uploads. Run it as the web
// server user.

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
use Backends\StorageEngineFactory;
use Services\ChunkedUpload;

try {
    $config = Config::load(ENV_FILE);
    $db = Database::initialize(Config::databasePath($config, BASE_PATH));
    $logger = LogEngineFactory::create($config);
    App::setConfig($config);
    App::setDb($db);
    App::setLogger($logger);

    $expired = (new ChunkedUpload($db, StorageEngineFactory::create($config), $logger, $config))->expireStale();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "$expired upload(s) expired.\n";
