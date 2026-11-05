<?php
// src/Views/OidcFailureView.php
namespace Views;

use Core\Request;

/**
 * "Sign-in was not permitted" (#9): generic, whatever the cause, with the
 * correlation ID of the log entry. Its two ways on are links: it never
 * redirects by itself, so a failing IdP can't loop.
 */
class OidcFailureView
{
    public static function render(string $correlationId, string $displayName): void
    {
        $e = fn(string $value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        // Served from /login/oidc/callback: paths from the base path, not relative
        $base = Request::getBasePath();
?>
<!DOCTYPE html>
<html lang="en">
<?php Layout::head('Sign-in not permitted', ["$base/" . Layout::BOOTSTRAP_CSS, "$base/assets/css/login.css"]); ?>
<body class="login">
<div class="login-container">
    <h2>Sign-in was not permitted</h2>
    <p>The sign-in through <?= $e($displayName) ?> did not succeed. If this persists, contact your administrator with this reference:</p>
    <p><code id="correlationId"><?= $e($correlationId) ?></code></p>
    <a class="btn" id="oidcRetry" href="<?= $e("$base/login/oidc") ?>">Sign in with <?= $e($displayName) ?></a>
    <p class="mt-3"><a id="otherEmail" href="<?= $e("$base/login?other") ?>">Use a different e-mail</a></p>
</div>
</body>
</html>
<?php
    }
}
