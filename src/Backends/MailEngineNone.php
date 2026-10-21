<?php
// src/Backends/MailEngineNone.php
namespace Backends;

/**
 * mail_engine=none: the host sends no mail (air-gapped deployment, no relay).
 * Invitation links are shown to the administrator, who passes them on through
 * another channel; callers check MailEngineFactory::isManual() and never send.
 * send() refuses, so a caller that forgets the check fails visibly.
 */
class MailEngineNone implements MailEngineInterface {
    public const ERROR = 'Mail is disabled (mail_engine=none): invitation links are delivered manually.';

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        return false;
    }

    public function lastError(): ?string
    {
        return self::ERROR;
    }
}
?>
