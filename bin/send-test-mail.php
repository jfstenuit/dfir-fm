<?php
// bin/send-test-mail.php - Send a test e-mail through the configured mail engine (CLI only)
//
// Usage: php bin/send-test-mail.php <recipient>
//
// Uses the mail settings from .env (mail_engine, smtp_*, sendgrid_api_key,
// default_mail_from*), exactly as invitations do. Run it as the web server
// user to also check its permissions (sendmail, .env readability).
// Exit code 0 when the engine accepted the message, 1 otherwise. With
// mail_engine=none nothing is sent: the script says so and exits 0.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
define('ENV_FILE', BASE_PATH . '/.env');

require_once BASE_PATH . '/vendor/autoload.php';

use Core\Config;
use Backends\LogEngineFactory;
use Backends\MailEngineFactory;
use PHPMailer\PHPMailer\PHPMailer;

if ($argc !== 2 || !PHPMailer::validateAddress($argv[1])) {
    fwrite(STDERR, "Usage: php bin/send-test-mail.php <recipient e-mail address>\n");
    exit(2);
}
$recipient = $argv[1];

$config = Config::load(ENV_FILE);
$logger = LogEngineFactory::create($config);
$engineName = MailEngineFactory::engineName($config);

if (MailEngineFactory::isManual($config)) {
    echo "Mail is disabled (mail_engine=none): nothing was sent.\n";
    echo "Invitation links are shown to the administrator, who passes them on through another channel.\n";
    exit(0);
}

$osUser = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? (string)posix_geteuid()) : get_current_user();
$host = gethostname() ?: 'unknown host';

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>DFIR-FM test e-mail</title></head><body>'
    . '<p>This is a test e-mail from DFIR-FM on <strong>' . htmlspecialchars($host) . '</strong>.</p>'
    . '<p>Mail engine: <code>' . htmlspecialchars($engineName) . '</code><br>Sent: ' . date('c') . '</p>'
    . '<p>If you can read this, invitations can be delivered.</p>'
    . '</body></html>';

try {
    $engine = MailEngineFactory::create($config);
    $sent = $engine->send($recipient, 'DFIR-FM test e-mail', $html);
    $error = $sent ? null : ($engine->lastError() ?? 'unknown error');
} catch (\Exception $e) {
    $error = $e->getMessage();
}

if ($error !== null) {
    $logger->log('mail_send_failure', [
        'recipient' => $recipient,
        'mail_engine' => $engineName,
        'error' => $error,
        'username' => 'cli:' . $osUser,
    ]);
    fwrite(STDERR, "Mail engine '$engineName' failed: $error\n");
    exit(1);
}

$logger->log('cli_test_mail_sent', [
    'recipient' => $recipient,
    'mail_engine' => $engineName,
    'username' => 'cli:' . $osUser,
]);
echo "Test e-mail accepted by mail engine '$engineName' for $recipient.\n";
echo "Check the recipient's mailbox (and spam folder): acceptance is not delivery.\n";
