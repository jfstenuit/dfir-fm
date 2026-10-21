<?php
// src/Views/SetPasswordView.php - The page of an invitation link: set a password
namespace Views;

use Core\PasswordPolicy;

class SetPasswordView
{
    /**
     * The form, or only $error when the link can't be used. The token travels
     * in hidden fields: set-password.js posts it, the GET never logs in.
     */
    public static function render(string $email, string $token, string $directory, ?string $error = null): void
    {
        $e = fn(string $value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<?php Layout::head('Set your password', [Layout::BOOTSTRAP_CSS, 'assets/css/login.css'], [], $error === null ? ['assets/js/set-password.js'] : []); ?>
<body class="login">
<div class="login-container">
<?php if ($error !== null): ?>
    <h2>Invitation link not valid</h2>
    <p><?= $e($error) ?></p>
<?php else: ?>
    <h2>Set your password</h2>
    <p class="small">Account: <strong><?= $e($email) ?></strong></p>
    <!-- The button starts disabled: set-password.js enables it once it handles the form,
         so the form is never submitted natively -->
    <form id="setPasswordForm" novalidate>
        <input type="text" name="username" value="<?= $e($email) ?>" autocomplete="username" readonly hidden>
        <input type="hidden" id="email" value="<?= $e($email) ?>">
        <input type="hidden" id="token" value="<?= $e($token) ?>">
        <input type="hidden" id="directory" value="<?= $e($directory) ?>">
        <div class="login-field">
            <input type="password" id="password" placeholder="New password" autocomplete="new-password"
                   minlength="<?= PasswordPolicy::MIN_LENGTH ?>" required>
        </div>
        <div class="login-field">
            <input type="password" id="confirmation" placeholder="Repeat the password" autocomplete="new-password" required>
        </div>
        <button type="submit" class="btn" id="setPasswordBtn" disabled>Set password and log in</button>
    </form>
    <p class="small">At least <?= PasswordPolicy::MIN_LENGTH ?> characters. This link can be used once, within one hour of
       the invitation; when it has expired, ask the person who invited you for a new one.</p>
<?php endif; ?>
    <div id="loginMessage"></div>
</div>
</body>
</html>
<?php
    }
}
