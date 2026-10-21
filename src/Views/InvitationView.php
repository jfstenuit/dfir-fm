<?php
// src/Views/InvitationView.php
namespace Views;

class InvitationView
{
    /** The invitation link: the password form of LoginController (single use, one hour) */
    public static function link(string $email, string $token, string $directory, string $baseUrl): string
    {
        return $baseUrl
            . '/login?email=' . urlencode($email)
            . '&token=' . urlencode($token)
            . '&directory=' . urlencode($directory);
    }

    /** The subject of an invitation ($directory a folder) or of a new link ('/', a resend) */
    public static function subject(string $directory, string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
        return $directory === '/'
            ? "Your new DFIR FM link for $host"
            : "Invitation to contribute to $directory on $host";
    }

    /**
     * The invitation e-mail (HTML; MailText derives the plain-text part).
     * $directory '/' is a resend: a new link, which also resets a password.
     * $expiresAt: when the link stops working (UTC, as shown).
     */
    public static function render($email, $token, $directory, $baseUrl, ?string $expiresAt = null)
    {
        $url = self::link($email, $token, $directory, $baseUrl);
        $e = fn(string $value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
        $resend = $directory === '/';
        $expiry = $expiresAt !== null ? "at $expiresAt UTC, one hour after it was sent" : 'one hour after it was sent';

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
        .email-footer { margin-top: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header"><?= $resend ? 'A new link for your DFIR FM account' : 'Invitation to contribute' ?></div>
        <div class="email-content">
            <p>Hello,</p>
<?php if ($resend): ?>
            <p>An administrator of the DFIR FM file manager on <strong><?= $e($host) ?></strong> sent you a new link for your account <strong><?= $e($email) ?></strong>.</p>
<?php else: ?>
            <p>You have been invited to contribute files to the folder <strong><?= $e($directory) ?></strong> of the DFIR FM file manager on <strong><?= $e($host) ?></strong>, with the account <strong><?= $e($email) ?></strong>.</p>
<?php endif; ?>
            <p>Open the link below and choose a password (at least 12 characters); you are then logged in<?= $resend ? '' : ' and taken to the folder' ?>.<?= $resend ? ' If you already had a password, the new one replaces it.' : '' ?></p>
            <p><a href="<?= $e($url) ?>">Set my password</a></p>
            <p>If the link above does not work, copy this address into your browser:</p>
            <p><code><?= $e($url) ?></code></p>
            <p>The link works once and expires <?= $e($expiry) ?>. When it has expired, ask the person who invited you for a new one.</p>
        </div>
        <div class="email-footer">
            <p>Do not forward this message: the link gives access to your account. If you did not expect it, ignore it: nothing happens unless the link is used.</p>
        </div>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
