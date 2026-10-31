<?php
// tests/bootstrap.php

require dirname(__DIR__) . '/vendor/autoload.php';

// The application refuses to run without APCu (#14), and its tests need it
// too: the rate limit and the logging halt are tested against it. In the
// CLI it is off unless apc.enable_cli=1, which can't be set at runtime
// (php.ini or -d only); `composer test` passes it.
if (!function_exists('apcu_enabled') || !apcu_enabled()) {
    fwrite(STDERR, "APCu is not enabled for the CLI. Run the tests with `composer test`, or with\n"
        . "  php -d apc.enable_cli=1 vendor/bin/phpunit ...\n"
        . "(or set apc.enable_cli=1 in the CLI's php.ini for other test runners).\n");
    exit(1);
}

// Feature tests start the application on PHP's built-in server, in an
// isolated copy (see Tests\Support\TestServer). Nothing here touches the
// project's own .env, database or storage.
