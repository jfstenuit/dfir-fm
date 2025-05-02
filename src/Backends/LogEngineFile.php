<?php
// src/Backends/LogEngineFile.php
namespace Backends;

use Core\Session;
use Core\Request;

class LogEngineFile implements LogEngineInterface {
    private string $filePath;
    private string $format;

    public function __construct(string $filePath, string $format) {
        $this->filePath = $filePath;
        $this->format = $format;
    }

    public function log(string $action, array $context = []): void {
        $sessionUser = Session::getUser();
        $ip = Request::getClientIp();

        $entry = array_merge([
            'ts' => gmdate('c'),
            'action' => $action,
        ], $context);

        $entry['ip'] = $entry['ip'] ?? $ip;
        $entry['username'] = $entry['username'] ?? ($sessionUser['username'] ?? null);

        $line = $this->format === 'csv'
            ? $this->toCsv($entry)
            : json_encode($entry, JSON_UNESCAPED_SLASHES);

        file_put_contents($this->filePath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function toCsv(array $data): string {
        return '"' . implode('","', array_map('addslashes', $data)) . '"';
    }

    public function healthCheck(): bool {
        return is_writable(dirname($this->filePath)) || (!file_exists($this->filePath) && is_writable(dirname($this->filePath)));
    }
}
?>