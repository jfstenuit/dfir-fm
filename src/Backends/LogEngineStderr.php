<?php
// src/Backends/LogEngineStderr.php
namespace Backends;

class LogEngineStderr implements LogEngineInterface {
    public function log(string $action, array $context = []): void {
        $entry = array_merge([
            'ts' => gmdate('c'),
            'action' => $action
        ], $context);
        file_put_contents('php://stderr', json_encode($entry) . PHP_EOL);
    }

    public function healthCheck(): bool {
        // Stderr is always assumed available
        return true;
    }
}
?>