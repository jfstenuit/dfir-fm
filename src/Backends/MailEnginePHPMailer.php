<?php
// src/Backends/MailEnginePHPMailer.php
namespace Backends;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * mail_engine=mail: PHP's mail() (the local sendmail binary) through PHPMailer,
 * which handles header encoding and refuses header injection.
 * MailEnginePHPMailerSMTP only changes the transport.
 */
class MailEnginePHPMailer implements MailEngineInterface {
    protected ?string $lastError = null;

    public function __construct(
        protected string $fromAddress,
        protected string $fromName = ''
    ) {
    }

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        $this->lastError = null;
        try {
            return $this->createMessage($to, $subject, $htmlBody)->send();
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** The message, ready to send; separate from send() so tests can inspect it */
    public function createMessage(string $to, string $subject, string $htmlBody): PHPMailer
    {
        $mail = new PHPMailer(true);
        $this->configureTransport($mail);

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($this->fromAddress, $this->fromName);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = MailText::fromHtml($htmlBody);

        return $mail;
    }

    protected function configureTransport(PHPMailer $mail): void
    {
        $mail->isMail();
    }
}
?>