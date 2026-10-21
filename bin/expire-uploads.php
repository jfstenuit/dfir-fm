<?php
// bin/expire-uploads.php - Expire abandoned uploads (CLI only)
//
// Usage: php bin/expire-uploads.php
//
// Uploads without activity for upload_stale_after seconds (default 86400)
// are marked expired and their staging files deleted. The application does
// this itself whenever an upload starts; this script is for a cron job, so
// staging space is reclaimed even when nobody uploads. Refreshes from
// storage left unfinished for as long expire too. Run it as the web server
// user.

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
use Services\RefreshJobs;

try {
    $config = Config::load(ENV_FILE);
    $db = Database::initialize(Config::databasePath($config, BASE_PATH));
    $logger = LogEngineFactory::create($config);
    App::setConfig($config);
    App::setDb($db);
    App::setLogger($logger);

    $storage = StorageEngineFactory::create($config);
    $expired = (new ChunkedUpload($db, $storage, $logger, $config))->expireStale();
    $refreshes = (new RefreshJobs($db, $storage, $logger, $config))->expireStale();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "$expired upload(s) expired.\n";
if ($refreshes > 0) {
    echo "$refreshes refresh(es) from storage expired.\n";
}
