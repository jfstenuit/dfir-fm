<?php
// tests/Support/fake-smtp.php - minimal SMTP sink for the mail tests
//
// Usage: php fake-smtp.php <port> <outdir>
// Speaks just enough ESMTP for PHPMailer (EHLO, AUTH PLAIN, MAIL, RCPT, DATA,
// RSET, NOOP, QUIT), without STARTTLS. Stores each message as JSON:
// {helo, auth (decoded), from, rcpt, data (base64)}. Serves one connection at a time, forever.

[$port, $outdir] = [$argv[1], $argv[2]];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "fake-smtp: $errstr\n");
    exit(1);
}

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    $reply = fn(string $line) => fwrite($conn, "$line\r\n");
    $session = ['helo' => null, 'auth' => null];
    $message = ['from' => null, 'rcpt' => []];
    $data = null;
    $authPending = false;

    $reply('220 fake-smtp ESMTP');
    while (($line = fgets($conn)) !== false) {
        if ($data !== null) {
            if (rtrim($line, "\r\n") === '.') {
                file_put_contents(
                    $outdir . '/' . sprintf('%.6f', microtime(true)) . '.json',
                    json_encode($session + $message + ['data' => base64_encode($data)])
                );
                $message = ['from' => null, 'rcpt' => []];
                $data = null;
                $reply('250 OK queued');
            } else {
                $data .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            }
            continue;
        }

        $line = rtrim($line, "\r\n");
        if ($authPending) {
            $authPending = false;
            $session['auth'] = base64_decode($line);
            $reply('235 Authentication successful');
            continue;
        }

        $verb = strtoupper(strtok($line, ' ') ?: '');
        $arg = trim(substr($line, strlen($verb)));
        switch ($verb) {
            case 'EHLO':
                $session['helo'] = $arg;
                $reply('250-fake-smtp');
                $reply('250-AUTH PLAIN');
                $reply('250 8BITMIME');
                break;
            case 'HELO':
                $session['helo'] = $arg;
                $reply('250 fake-smtp');
                break;
            case 'AUTH':
                if (strtoupper($arg) === 'PLAIN') {
                    $authPending = true;
                    $reply('334 ');
                } elseif (stripos($arg, 'PLAIN ') === 0) {
                    $session['auth'] = base64_decode(substr($arg, 6));
                    $reply('235 Authentication successful');
                } else {
                    $reply('504 Unrecognized authentication type');
                }
                break;
            case 'MAIL':
                $message['from'] = $arg;
                $reply('250 OK');
                break;
            case 'RCPT':
                $message['rcpt'][] = $arg;
                $reply('250 OK');
                break;
            case 'DATA':
                $data = '';
                $reply('354 End data with <CR><LF>.<CR><LF>');
                break;
            case 'RSET':
                $message = ['from' => null, 'rcpt' => []];
                $reply('250 OK');
                break;
            case 'NOOP':
                $reply('250 OK');
                break;
            case 'QUIT':
                $reply('221 Bye');
                break 2;
            default:
                $reply('502 Command not implemented');
        }
    }
    fclose($conn);
}
