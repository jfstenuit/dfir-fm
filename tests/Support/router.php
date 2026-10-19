<?php
// tests/Support/router.php
// Router for PHP's built-in server, mirroring .htaccess: existing static
// files under assets/ and vendor/ are served directly, everything else goes
// through index.php.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(assets|vendor)/.+\.(css|js|map|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|ico)$#', $path)
    && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
    return false;
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
