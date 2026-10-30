<?php
// src/Backends/LogEngineStderr.php
namespace Backends;

class LogEngineStderr implements LogDestination {
    public function name(): string {
        return 'stderr';
    }

    public function write(array $entry): void {
        $line = LogEntry::encode($entry) . PHP_EOL;
        if (@file_put_contents('php://stderr', $line) !== strlen($line)) {
            throw new \RuntimeException('Cannot write to stderr: ' . (error_get_last()['message'] ?? 'short write'));
        }
    }

    public function healthCheck(): bool {
        // Stderr is always assumed available
        return true;
    }
}
?>
