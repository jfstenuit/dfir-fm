<?php
// src/Backends/MailEngineInterface.php
namespace Backends;

interface MailEngineInterface {
    /**
     * Send an HTML message; a plain-text alternative is generated from it.
     * Returns false on failure, with the reason in lastError().
     */
    public function send(string $to, string $subject, string $htmlBody): bool;

    /** Why the last send() failed, or null if it succeeded */
    public function lastError(): ?string;
}
?>