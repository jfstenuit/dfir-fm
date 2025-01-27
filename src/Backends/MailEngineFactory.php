<?php
// src/Backends/MailEngineFactory.php
namespace Backends;

class MailEngineFactory {
    public static function create($config) {
        $engine = $config['mail_engine'] ?? 'mail';
        $settings = $config['mail_settings'] ?? [];

        switch ($engine) {
            case 'mail':
                return new MailEnginePHPMailer();
            case 'smtp':
                // Validate SMTP settings
                if (empty($settings['smtp_host']) || empty($settings['smtp_user']) || empty($settings['smtp_password']) || empty($settings['smtp_port'])) {
                    throw new \Exception('SMTP settings are incomplete. Please check smtp_host, smtp_user, smtp_password, and smtp_port.');
                }
                
                return new MailEnginePHPMailerSMTP(
                    $settings['smtp_host'],
                    $settings['smtp_user'],
                    $settings['smtp_password'],
                    $settings['smtp_port']
                );
            case 'sendgrid':
                // Validate SendGrid settings
                if (empty($settings['sendgrid_api_key'])) {
                    throw new \Exception('SendGrid API key is missing. Please provide sendgrid_api_key in your configuration.');
                }
                
                return new MailEngineSendGrid($settings['sendgrid_api_key']);
            default:
                throw new \Exception('Invalid mail engine specified in configuration');
        }
    }
}
?>