<?php
// src/Views/SsoNoticeView.php
namespace Views;

/**
 * The invitation e-mail of an address of oidc_email_domain (#9): no link
 * to set a password, as the account signs in through the IdP. It holds
 * nothing secret: the application's URL only.
 */
class SsoNoticeView
{
    /** The subject of an invitation ($directory a folder) or of a resend ('/') */
    public static function subject(string $directory, string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
        return $directory === '/'
            ? "Your DFIR FM account on $host"
            : "Invitation to contribute to $directory on $host";
    }

    /** The e-mail (HTML; MailText derives the plain-text part) */
    public static function render(string $email, string $directory, string $baseUrl, string $ssoName): string
    {
        $e = fn(string $value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
        $url = $directory === '/' ? "$baseUrl/" : "$baseUrl/?p=" . rawurlencode($directory);

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e(self::subject($directory, $baseUrl)) ?></title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; color: #333; margin: 0; padding: 0; }
        .email-container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; padding: 20px; }
        .email-header { font-size: 20px; font-weight: bold; margin-bottom: 16px; }
        .email-content { line-height: 1.6; }
        .email-content a { color: #0b5ed7; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header"><?= $directory === '/' ? 'Your DFIR FM account' : 'Invitation to contribute' ?></div>
        <div class="email-content">
            <p>Hello,</p>
<?php if ($directory === '/'): ?>
            <p>You have an account on the DFIR FM file manager on <strong><?= $e($host) ?></strong>: <strong><?= $e($email) ?></strong>.</p>
<?php else: ?>
            <p>You have been invited to contribute files to the folder <strong><?= $e($directory) ?></strong> of the DFIR FM file manager on <strong><?= $e($host) ?></strong>, with the account <strong><?= $e($email) ?></strong>.</p>
<?php endif; ?>
            <p>Sign in with your <strong><?= $e($ssoName) ?></strong> account: there is no separate password for DFIR FM.</p>
            <p><a href="<?= $e($url) ?>"><?= $e($url) ?></a></p>
        </div>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
