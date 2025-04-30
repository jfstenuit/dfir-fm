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
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="vendor/components/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="vendor/components/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <script src="vendor/components/jquery/jquery.min.js"></script>
    <script src="vendor/components/bootstrap/js/bootstrap.min.js"></script>
    <script src="assets/js/login.js"></script>
</head>
<body class="login">
<div class="login-container">
    <h2>Please authenticate</h2>

    <!-- Step 1: Email input -->
    <div id="step-login">
        <div class="form-group">
            <input type="text" id="username" name="username" placeholder="Enter your email" autocomplete="username" required>
        </div>
        <button class="btn" id="nextBtn">Next</button>
    </div>

    <!-- Step 2: Password input -->
    <div id="step-password" class="d-none">
        <div class="form-group">
            <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
        </div>
        <button class="btn" id="loginBtn">Login</button>
    </div>

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
