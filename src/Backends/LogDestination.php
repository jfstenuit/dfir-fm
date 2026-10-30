<?php
// src/Backends/LogDestination.php
namespace Backends;

/**
 * One place events are written to: the events database or the configured
 * log engine. LogEngineTee builds each record once and hands it to every
 * destination.
 */
interface LogDestination {
    /** Short name for the halt record and error messages: events, file, syslog, stderr */
    public function name(): string;

    /**
     * Write one record (LogEntry::build). Throws on any failure: a lost
     * event stops the application (D29).
     */
    public function write(array $entry): void;

    public function healthCheck(): bool;
}
?>
