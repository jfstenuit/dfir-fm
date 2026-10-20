<?php
// tests/Support/TestServer.php
namespace Tests\Support;

/**
 * Runs the application on PHP's built-in server, in an isolated temporary
 * copy: its own .env, SQLite database, storage directory and log file.
 *
 * index.php and bin/ are copied (they derive BASE_PATH from their own
 * location); assets/ and vendor/ are symlinked. The project's own .env,
 * database and storage are never touched.
 *
 * Fixtures created on start:
 *   - admin@test.local: administrator, random password in $adminPassword
 *     (created through bin/create-admin.php)
 *   - bob@test.local / bob-pass-123: regular user, no group
 *   - directory '/' (id 1)
 */
final class TestServer
{
    public const BOB = 'bob@test.local';
    public const BOB_PASSWORD = 'bob-pass-123';
    public const ADMIN = 'admin@test.local';

    public readonly string $root;
    public readonly string $url;
    public string $adminPassword = '';

    private $process = null;
    private int $port;
    private ?\PDO $pdo = null;

    /**
     * @param array<string,string> $env extra .env settings (override defaults)
     */
    public function __construct(array $env = [], bool $withFixtures = true)
    {
        $project = dirname(__DIR__, 2);
        $this->root = sys_get_temp_dir() . '/dfirfm-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/storage/files', 0777, true);
        mkdir($this->root . '/bin');
        mkdir($this->root . '/sendmail');

        copy("$project/index.php", "$this->root/index.php");
        foreach (glob("$project/bin/*.php") as $script) {
            copy($script, "$this->root/bin/" . basename($script));
        }
        symlink("$project/assets", "$this->root/assets");
        symlink("$project/vendor", "$this->root/vendor");

        $settings = array_merge([
            'storage_engine' => 'local',
            'base_storage_directory' => "$this->root/storage/files",
            'log_engine' => 'file',
            'log_file_path' => "$this->root/app.log",
        ], $env);
        $lines = array_map(fn($k, $v) => "$k=$v", array_keys($settings), $settings);
        file_put_contents("$this->root/.env", implode("\n", $lines) . "\n");
        touch("$this->root/app.log");

        if ($withFixtures) {
            $this->createFixtures();
        }

        $this->port = self::freePort();
        $this->url = "http://127.0.0.1:$this->port";
    }

    public function start(): self
    {
        $command = [
            PHP_BINARY, ...$this->phpOptions(), '-d', 'display_errors=0', '-d', 'log_errors=1',
            '-S', "127.0.0.1:$this->port", '-t', $this->root, __DIR__ . '/router.php',
        ];
        $this->process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', "$this->root/server.log", 'a'],
            2 => ['file', "$this->root/server.log", 'a'],
        ], $pipes);

        self::waitForPort($this->port, fn() => $this->stop());
        return $this;
    }

    /**
     * PHP options for the server and the CLI scripts. mail() goes to
     * fake-sendmail.php, so mail_engine=mail never delivers real mail.
     */
    private function phpOptions(): array
    {
        $sendmail = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fake-sendmail.php')
            . ' ' . escapeshellarg("$this->root/sendmail");
        return ['-d', 'apc.enable_cli=1', '-d', "sendmail_path=$sendmail"];
    }

    /** Wait until something listens on a local port; on timeout, clean up and fail */
    public static function waitForPort(int $port, callable $onTimeout): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
        $onTimeout();
        throw new \RuntimeException("Nothing started listening on port $port");
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        $this->pdo = null;
        self::removeTree($this->root);
    }

    public function __destruct()
    {
        if (is_dir($this->root)) {
            $this->stop();
        }
    }

    public function client(): HttpClient
    {
        return new HttpClient($this->url);
    }

    /** Direct access to the test instance's database, for fixtures */
    public function db(): \PDO
    {
        return $this->pdo ??= new \PDO("sqlite:$this->root/storage/database/app.sqlite", null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** Put a file in storage and register it in directory '/' */
    public function addFile(string $name, string $content): int
    {
        file_put_contents("$this->root/storage/files/$name", $content);
        $stmt = $this->db()->prepare("INSERT INTO files (directory_id, name, size, sha256, uploaded_by) VALUES (1, :name, :size, :sha, 1)");
        $stmt->execute([':name' => $name, ':size' => strlen($content), ':sha' => hash('sha256', $content)]);
        return (int)$this->db()->lastInsertId();
    }

    /** Give a user read/write/upload access to '/' through a new group */
    public function grantRootAccess(string $username): void
    {
        $db = $this->db();
        $db->exec("INSERT OR IGNORE INTO groups (name) VALUES ('writers')");
        $db->prepare("INSERT OR IGNORE INTO user_group (user_id, group_id)
                      SELECT u.id, g.id FROM users u, groups g WHERE u.username = :u AND g.name = 'writers'")
           ->execute([':u' => $username]);
        $db->exec("INSERT OR IGNORE INTO access_rights (group_id, directory_id, can_view, can_write, can_upload)
                   SELECT id, 1, 1, 1, 1 FROM groups WHERE name = 'writers'");
    }

    /** Admin status of a user, read straight from the test instance's database */
    public function isAdmin(string $username): bool
    {
        $stmt = $this->db()->prepare('SELECT id FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        return \Middleware\AccessMiddleware::isAdmin($this->db(), $stmt->fetchColumn() ?: null);
    }

    /** Run bin/create-admin.php in the test instance */
    public function runCreateAdmin(string ...$args): array
    {
        return $this->runBin('create-admin.php', ...$args);
    }

    /**
     * Run a bin/ script in the test instance
     * @return array{exit:int, stdout:string, stderr:string}
     */
    public function runBin(string $script, string ...$args): array
    {
        $process = proc_open([PHP_BINARY, ...$this->phpOptions(), "$this->root/bin/$script", ...$args], [
            1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Messages handed to sendmail (mail_engine=mail) by the server or the CLI scripts
     * @return list<array{argv:list<string>, message:string}>
     */
    public function sentMail(): array
    {
        $files = glob("$this->root/sendmail/*.json") ?: [];
        sort($files);
        return array_map(function ($f) {
            $sent = json_decode(file_get_contents($f), true, flags: JSON_THROW_ON_ERROR);
            $sent['message'] = base64_decode($sent['message']);
            return $sent;
        }, $files);
    }

    /** @return list<array> application log entries (log_engine=file, jsonl) */
    public function appLog(): array
    {
        $lines = file("$this->root/app.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_map(fn($line) => json_decode($line, true), $lines);
    }

    /** @return list<array> application log entries for one action */
    public function logEntries(string $action): array
    {
        return array_values(array_filter($this->appLog(), fn($e) => ($e['action'] ?? null) === $action));
    }

    public function serverLog(): string
    {
        return (string)@file_get_contents("$this->root/server.log");
    }

    /** PHP warnings, notices, deprecations and fatal errors logged by the server */
    public function phpErrors(): array
    {
        preg_match_all('/PHP (?:Warning|Notice|Deprecated|Fatal error|Parse error).*$/m', $this->serverLog(), $m);
        return $m[0];
    }

    private function createFixtures(): void
    {
        $result = $this->runCreateAdmin(self::ADMIN);
        if ($result['exit'] !== 0 || !preg_match('/^Password.*: (\S+)$/m', $result['stdout'], $m)) {
            throw new \RuntimeException("create-admin failed: {$result['stdout']} {$result['stderr']}");
        }
        $this->adminPassword = $m[1];

        $db = $this->db();
        $db->prepare("INSERT INTO users (username, password) VALUES (:u, :p)")
           ->execute([':u' => self::BOB, ':p' => password_hash(self::BOB_PASSWORD, PASSWORD_DEFAULT)]);
    }

    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        return $port;
    }

    /** Recursive delete that removes symlinks without following them */
    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree("$path/$entry");
            }
        }
        @rmdir($path);
    }
}
