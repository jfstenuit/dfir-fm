<?php
// src/Backends/MailEngineFactory.php
namespace Backends;

use PHPMailer\PHPMailer\PHPMailer;

class MailEngineFactory {
    /** Accepted smtp_secure values, including the usual aliases */
    private const SMTP_SECURE = [
        'starttls' => MailEnginePHPMailerSMTP::SECURE_STARTTLS,
        'tls' => MailEnginePHPMailerSMTP::SECURE_STARTTLS,
        'smtps' => MailEnginePHPMailerSMTP::SECURE_SMTPS,
        'ssl' => MailEnginePHPMailerSMTP::SECURE_SMTPS,
        'none' => MailEnginePHPMailerSMTP::SECURE_NONE,
    ];

    /** @throws \InvalidArgumentException on missing or invalid settings */
    public static function create(array $config): MailEngineInterface {
        $engine = strtolower(trim($config['mail_engine'] ?? '')) ?: 'mail';

        $from = trim($config['default_mail_from'] ?? '');
        if (!PHPMailer::validateAddress($from)) {
            throw new \InvalidArgumentException('default_mail_from is missing or is not a valid e-mail address.');
        }
        $fromName = trim($config['default_mail_from_name'] ?? '');

        switch ($engine) {
            case 'mail':
                return new MailEnginePHPMailer($from, $fromName);

            case 'smtp':
                $host = trim($config['smtp_host'] ?? '');
                if ($host === '') {
                    throw new \InvalidArgumentException('smtp_host is required when mail_engine=smtp.');
                }

                $port = trim($config['smtp_port'] ?? '');
                $secureSetting = strtolower(trim($config['smtp_secure'] ?? ''));
                if ($secureSetting === '') {
                    // Port 465 only speaks implicit TLS; anything else gets STARTTLS
                    $secure = $port === '465' ? MailEnginePHPMailerSMTP::SECURE_SMTPS : MailEnginePHPMailerSMTP::SECURE_STARTTLS;
                } elseif (isset(self::SMTP_SECURE[$secureSetting])) {
                    $secure = self::SMTP_SECURE[$secureSetting];
                } else {
                    throw new \InvalidArgumentException("Invalid smtp_secure '$secureSetting': use starttls, smtps or none.");
                }

                if ($port === '') {
                    $port = match ($secure) {
                        MailEnginePHPMailerSMTP::SECURE_SMTPS => 465,
                        MailEnginePHPMailerSMTP::SECURE_NONE => 25,
                        default => 587,
                    };
                }
                $port = self::positiveInt($port, 'smtp_port', 65535);

                $user = trim($config['smtp_user'] ?? '');
                $password = $config['smtp_password'] ?? '';
                if ($user !== '' && $password === '') {
                    throw new \InvalidArgumentException('smtp_password is required when smtp_user is set.');
                }

                $timeout = self::positiveInt($config['smtp_timeout'] ?? '30', 'smtp_timeout', 3600);

                return new MailEnginePHPMailerSMTP($from, $fromName, $host, $port, $secure, $user, $password, $timeout);

            case 'sendgrid':
                $apiKey = trim($config['sendgrid_api_key'] ?? '');
                if ($apiKey === '') {
                    throw new \InvalidArgumentException('sendgrid_api_key is required when mail_engine=sendgrid.');
                }
                return new MailEngineSendGrid($apiKey, $from, $fromName);

            default:
                throw new \InvalidArgumentException("Invalid mail engine specified in configuration: '$engine'");
        }
    }

    private static function positiveInt(int|string $value, string $key, int $max): int
    {
        $int = filter_var(trim((string)$value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        if ($int === false) {
            throw new \InvalidArgumentException("Invalid $key '$value': expected an integer between 1 and $max.");
        }
        return $int;
    }
}
?>