<?php
// src/Backends/MailEngineSendgrid.php
namespace Backends;

use SendGrid\Mail\Mail;

class MailEngineSendgrid implements MailEngineInterface {
    private $apiKey;

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function send($to, $subject, $message, $headers = []) {
        $email = new Mail();
        $email->setFrom($headers['From'] ?? 'no-reply@yourdomain.com', 'Your App');
        $email->setSubject($subject);
        $email->addTo($to);
        $email->addContent('text/html', $message);

        $sendgrid = new \SendGrid($this->apiKey);
        try {
            $response = $sendgrid->send($email);
            return $response->statusCode() < 400;
        } catch (Exception $e) {
            error_log('SendGrid Error: ' . $e->getMessage());
            return false;
        }
    }
}
?>