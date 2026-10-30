<?php
// src/Backends/LogEngineSyslog.php
namespace Backends;

class LogEngineSyslog implements LogDestination {
    public function __construct(string $tag = 'dfir-fm') {
        openlog($tag, LOG_PID, LOG_USER);
    }

    public function name(): string {
        return 'syslog';
    }

    /** Only a local failure can be seen: what syslog does with the message next is outside the application */
    public function write(array $entry): void {
        if (!syslog(LOG_INFO, LogEntry::encode($entry))) {
            throw new \RuntimeException('syslog() failed');
        }
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
