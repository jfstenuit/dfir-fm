<?php
// src/Views/LoginView.php
namespace Views;

class LoginView
{
    /**
     * The e-mail step, then the password or (with OIDC, an in-domain
     * address) the redirect to the IdP
     * @param ?string $ssoName oidc_display_name, null while OIDC is disabled
     */
    public static function render($redirect, ?string $ssoName = null)
    {
        $e = fn(string $value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<?php Layout::head('Login', [Layout::BOOTSTRAP_CSS, 'assets/css/login.css'], [], ['assets/js/login.js']); ?>
<body class="login">
<div class="login-container">
    <h2>Please authenticate</h2>

    <!-- Step 1: Email input (forms: Enter submits; handled by login.js, never sent as such).
         The buttons start disabled: login.js enables them once it handles the forms,
         so an early click or Enter can't submit them natively (email in the URL). -->
    <form id="step-login" novalidate>
        <div class="login-field">
            <input type="text" id="username" name="username" placeholder="Enter your email" autocomplete="username" required>
        </div>
        <button type="submit" class="btn" id="nextBtn" disabled>Next</button>
    </form>

    <!-- Step 2: Password input -->
    <form id="step-password" class="d-none" novalidate>
        <div class="login-field">
            <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn" id="loginBtn" disabled>Login</button>
    </form>

<?php if ($ssoName !== null): ?>
    <!-- Step 2, an in-domain address: on to the IdP (login.js) -->
    <div id="step-oidc" class="d-none">
        <p>Redirecting to <?= $e($ssoName) ?>…</p>
    </div>
<?php endif; ?>

    <!-- Redirect info -->
    <input type="hidden" id="redirect" value="<?php echo $e($redirect); ?>">
    <div id="loginMessage"></div>
</div>

</body>
</html>
<?php
    }

    /**
     * After a logout (?signed_out) or a failed OIDC sign-in: no automatic
     * redirect to the IdP, the user chooses
     */
    public static function renderChoice(string $redirect, string $ssoName, string $signInUrl, bool $signedOut): void
    {
        $e = fn(string $value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $other = 'login?' . http_build_query(['other' => ''] + ($redirect !== '' ? ['redirect' => $redirect] : []));
?>
<!DOCTYPE html>
<html lang="en">
<?php Layout::head($signedOut ? 'Signed out' : 'Login', [Layout::BOOTSTRAP_CSS, 'assets/css/login.css']); ?>
<body class="login">
<div class="login-container">
    <h2><?= $signedOut ? 'Signed out' : 'Please authenticate' ?></h2>
    <a class="btn" id="oidcBtn" href="<?= $e($signInUrl) ?>">Sign in with <?= $e($ssoName) ?></a>
    <p class="mt-3"><a id="otherEmail" href="<?= $e($other) ?>">Use a different e-mail</a></p>
</div>
</body>
</html>
<?php
    }
}
