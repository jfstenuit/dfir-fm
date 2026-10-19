<?php
// tests/Support/FakeSmtpServer.php
namespace Tests\Support;

/**
 * Runs fake-smtp.php on a free local port and reads back what it received.
 * No STARTTLS: engines under test use smtp_secure=none.
 */
final class FakeSmtpServer
{
    public readonly int $port;
    private readonly string $dir;
    private $process = null;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-smtp-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->port = TestServer::freePort();

        $this->process = proc_open([PHP_BINARY, __DIR__ . '/fake-smtp.php', (string)$this->port, $this->dir], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', "$this->dir/server.log", 'a'],
            2 => ['file', "$this->dir/server.log", 'a'],
        ], $pipes);

        TestServer::waitForPort($this->port, fn() => $this->stop());
    }

    /** @return list<array{helo:?string, auth:?string, from:string, rcpt:list<string>, data:string}> */
    public function messages(): array
    {
        $files = glob("$this->dir/*.json") ?: [];
        sort($files);
        return array_map(function ($f) {
            $message = json_decode(file_get_contents($f), true, flags: JSON_THROW_ON_ERROR);
            $message['data'] = base64_decode($message['data']);
            return $message;
        }, $files);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        foreach (glob("$this->dir/*") ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
