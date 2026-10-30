<?php
// src/Backends/LogEngineFile.php
namespace Backends;

class LogEngineFile implements LogDestination {
    private string $filePath;
    private string $format;

    public function __construct(string $filePath, string $format) {
        $this->filePath = $filePath;
        $this->format = $format;
    }

    public function name(): string {
        return 'file';
    }

    public function write(array $entry): void {
        $line = $this->format === 'csv'
            ? $this->toCsv($entry)
            : LogEntry::encode($entry);

        $written = @file_put_contents($this->filePath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written !== strlen($line . PHP_EOL)) {
            throw new \RuntimeException("Cannot write to $this->filePath: " . (error_get_last()['message'] ?? 'short write'));
        }
    }

    private function toCsv(array $data): string {
        // Nested values as JSON, null as an empty field
        $fields = array_map(fn($value) => match (true) {
            $value === null => '',
            is_scalar($value) => (string)$value,
            default => LogEntry::encode($value),
        }, $data);
        return '"' . implode('","', array_map('addslashes', $fields)) . '"';
    }

    public function healthCheck(): bool {
        return is_writable(dirname($this->filePath)) || (!file_exists($this->filePath) && is_writable(dirname($this->filePath)));
    }
}
?>
