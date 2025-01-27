<?php
// src/Views/FileManagerView.php
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
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login">
    <div class="login-container">
        <h1>Login</h1>
        <form method="POST" class="login-form">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="btn">Login</button>
        </form>
        <form method="GET" action="/login/oidc" class="oidc-form">
            <button type="submit" class="btn oidc-btn">Login with OIDC</button>
        </form>
    </div>
</body>
</html>
<?php
    }
}
?>