<?php
// tests/Support/fake-sendmail.php - stand-in for sendmail in the test servers
//
// Usage (as sendmail_path): php fake-sendmail.php <outdir> [sendmail arguments...]
// Stores each message as JSON: {argv, message (base64)}. Never delivers anything.

$outdir = $argv[1];
$message = stream_get_contents(STDIN);
file_put_contents(
    $outdir . '/' . sprintf('%.6f', microtime(true)) . '-' . getmypid() . '.json',
    json_encode(['argv' => array_slice($argv, 2), 'message' => base64_encode($message)])
);
exit(0);
