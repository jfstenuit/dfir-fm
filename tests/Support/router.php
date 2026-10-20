<?php
// tests/Support/router.php
// Router for PHP's built-in server, mirroring .htaccess: existing static
// files under assets/ and the three front-end packages' build folders are
// served directly, the rest of assets/ and vendor/ is a 404, everything else
// goes through index.php.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(assets/|vendor/twbs/bootstrap/dist/|vendor/twbs/bootstrap-icons/font/|vendor/enyo/dropzone/dist/).+\.(css|js|mjs|map|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|ico)$#', $path)
    && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
    return false;
}
// Anything else under assets/ or vendor/ is a 404, as with .htaccess
if (preg_match('#^/(assets|vendor)(/|$)#', $path)) {
    http_response_code(404);
    echo "Not Found\n";
    return true;
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
