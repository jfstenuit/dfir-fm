<?php
// src/Backends/LogHalt.php
namespace Backends;

/**
 * The halt flag (D29), in APCu: memory, so it still works when the disk
 * that failed is full. Shared by every request of the web server, keyed by
 * installation (the events database path), since one PHP pool may serve
 * several.
 *
 * The record: since (first failed write, UTC), failures (destination =>
 * last error) and recorded (destinations that already hold the
 * logging_failure event of this halt).
 */
class LogHalt {
    /** Seconds between two attempts to write to the destinations while halted */
    public const PROBE_INTERVAL = 30;

    private string $key;
    private string $probeKey;

    public function __construct(string $scope) {
        $id = substr(hash('sha256', $scope), 0, 16);
        $this->key = "log_halt:$id";
        $this->probeKey = "log_halt_probe:$id";
    }

    /** Without APCu (the CLI scripts) the halt can't outlast the run whose write failed */
    private static function available(): bool {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function current(): ?array {
        $halt = self::available() ? apcu_fetch($this->key) : false;
        return is_array($halt) ? $halt : null;
    }

    /** @param array<string,string> $failures */
    public function start(array $failures): array {
        $halt = $this->current() ?? ['since' => gmdate('c'), 'failures' => [], 'recorded' => []];
        $halt['failures'] = $failures + $halt['failures'];
        $this->update($halt);
        // The first probe waits a full interval
        $this->claimProbe();
        return $halt;
    }

    public function update(array $halt): void {
        if (self::available()) {
            apcu_store($this->key, $halt);
        }
    }

    /** apcu_add is atomic: one request per interval gets to probe */
    public function claimProbe(): bool {
        return self::available() && apcu_add($this->probeKey, time(), self::PROBE_INTERVAL);
    }

    public function clear(): void {
        if (self::available()) {
            apcu_delete($this->key);
            apcu_delete($this->probeKey);
        }
    }
}
?>
