<?php
// src/Backends/LogEngineSyslog.php
namespace Backends;

class LogEngineSyslog implements LogEngineInterface {
    private string $tag;

    public function __construct(string $tag = 'dfir-fm') {
        openlog($tag, LOG_PID, LOG_USER);
    }

    public function log(string $action, array $context = []): void {
        $entry = array_merge([
            'ts' => gmdate('c'),
            'action' => $action
        ], $context);
        syslog(LOG_INFO, json_encode($entry, JSON_UNESCAPED_SLASHES));
    }

    public function __destruct() {
        closelog();
    }

    public function healthCheck(): bool {
        // Syslog is always assumed available if openlog() succeeded
        return true;
    }
}
?>