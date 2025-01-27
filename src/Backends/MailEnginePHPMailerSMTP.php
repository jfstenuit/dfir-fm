<?php
// src/Backends/MailEnginePHPMailerSMTP.php
namespace Backends;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailEnginePHPMailerSMTP implements MailEngineInterface {
    private $smtpHost;
    private $smtpUser;
    private $smtpPassword;
    private $smtpPort;

    public function __construct($smtpHost, $smtpUser, $smtpPassword, $smtpPort = 587) {
        $this->smtpHost = $smtpHost;
        $this->smtpUser = $smtpUser;
        $this->smtpPassword = $smtpPassword;
        $this->smtpPort = $smtpPort;
    }

    public function send($to, $subject, $message, $headers = []) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUser;
            $mail->Password = $this->smtpPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpPort;

            $mail->setFrom($headers['From'] ?? 'no-reply@yourdomain.com', 'Your App');
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;

            return $mail->send();
        } catch (Exception $e) {
            error_log('PHPMailer Error: ' . $mail->ErrorInfo);
            return false;
        }
    }
}
?>