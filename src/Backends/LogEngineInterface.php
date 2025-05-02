<?php
// src/Backends/LogEngineInterface.php
namespace Backends;

interface LogEngineInterface {
    public function log(string $action, array $context = []): void;
    public function healthCheck(): bool;
}
?>