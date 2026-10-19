<?php
// src/Backends/MailEngineSendGrid.php
namespace Backends;

use SendGrid\Mail\Mail;

/** mail_engine=sendgrid: SendGrid Web API v3 */
class MailEngineSendGrid implements MailEngineInterface {
    private ?string $lastError = null;

    public function __construct(
        private string $apiKey,
        private string $fromAddress,
        private string $fromName = ''
    ) {
    }

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        $this->lastError = null;
        try {
            $response = (new \SendGrid($this->apiKey))->send($this->createMessage($to, $subject, $htmlBody));
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }

        if ($response->statusCode() >= 400) {
            // The body is SendGrid's JSON error list; it never contains the API key
            $this->lastError = 'SendGrid API returned HTTP ' . $response->statusCode() . ': ' . substr(trim($response->body()), 0, 500);
            return false;
        }
        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** The message, ready to send; separate from send() so tests can inspect it */
    public function createMessage(string $to, string $subject, string $htmlBody): Mail
    {
        $email = new Mail();
        $email->setFrom($this->fromAddress, $this->fromName !== '' ? $this->fromName : null);
        $email->setSubject($subject);
        $email->addTo($to);
        // SendGrid requires text/plain before text/html
        $email->addContent('text/plain', MailText::fromHtml($htmlBody));
        $email->addContent('text/html', $htmlBody);
        return $email;
    }
}
?>