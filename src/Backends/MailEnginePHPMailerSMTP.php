<?php
// src/Backends/MailEnginePHPMailerSMTP.php
namespace Backends;

use PHPMailer\PHPMailer\PHPMailer;

/** mail_engine=smtp: PHPMailer over SMTP */
class MailEnginePHPMailerSMTP extends MailEnginePHPMailer {
    public const SECURE_STARTTLS = 'starttls';
    public const SECURE_SMTPS = 'smtps';
    public const SECURE_NONE = 'none';

    /**
     * @param string $secure   starttls (required, not opportunistic), smtps (implicit TLS, port 465) or none
     * @param string $username empty: no SMTP authentication
     */
    public function __construct(
        string $fromAddress,
        string $fromName,
        private string $host,
        private int $port = 587,
        private string $secure = self::SECURE_STARTTLS,
        private string $username = '',
        private string $password = '',
        private int $timeout = 30
    ) {
        parent::__construct($fromAddress, $fromName);
    }

    protected function configureTransport(PHPMailer $mail): void
    {
        $mail->isSMTP();
        $mail->Host = $this->host;
        $mail->Port = $this->port;
        $mail->Timeout = $this->timeout;
        // Debug output would go to the HTTP response, and level 2+ shows the AUTH exchange
        $mail->SMTPDebug = 0;

        switch ($this->secure) {
            case self::SECURE_SMTPS:
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                break;
            case self::SECURE_NONE:
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
                break;
            default:
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->SMTPAuth = $this->username !== '';
        if ($mail->SMTPAuth) {
            $mail->Username = $this->username;
            $mail->Password = $this->password;
        }
    }
}
?>