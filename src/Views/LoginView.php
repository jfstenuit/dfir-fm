<?php
// src/Views/LoginView.php
namespace Views;

class LoginView
{
    public static function render($redirect)
    {
?>
<!DOCTYPE html>
<html lang="en">
<?php Layout::head('Login', [Layout::BOOTSTRAP_CSS, 'assets/css/login.css'], [], ['assets/js/login.js']); ?>
<body class="login">
<div class="login-container">
    <h2>Please authenticate</h2>

    <!-- Step 1: Email input (forms: Enter submits; handled by login.js, never sent as such) -->
    <form id="step-login" novalidate>
        <div class="login-field">
            <input type="text" id="username" name="username" placeholder="Enter your email" autocomplete="username" required>
        </div>
        <button type="submit" class="btn" id="nextBtn">Next</button>
    </form>

    <!-- Step 2: Password input -->
    <form id="step-password" class="d-none" novalidate>
        <div class="login-field">
            <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn" id="loginBtn">Login</button>
    </form>

    <!-- Step 3: Optional OIDC -->
    <div id="step-oidc" class="d-none">
        <button class="btn" id="oidcBtn">Login with SSO</button>
    </div>

    <!-- Redirect info -->
    <input type="hidden" id="redirect" value="<?php echo htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); ?>">
    <div id="loginMessage"></div>
</div>

</body>
</html>
<?php
    }
}
