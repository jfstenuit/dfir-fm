<?php
// src/Backends/MailEnginePHPMailer.php
namespace Backends;

class MailEnginePHPMailer implements MailEngineInterface {
    public function send($to, $subject, $message, $headers = []) {
        // Ensure headers include Content-Type for HTML emails
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'text/html; charset=UTF-8';
        }
        
        // Construct headers for the mail function
        $headersString = '';
        foreach ($headers as $key => $value) {
            $headersString .= "$key: $value\r\n";
        }

        // Use the PHP `mail()` function to send the email
        return mail($to, $subject, $message, $headersString);
    }
}
?>