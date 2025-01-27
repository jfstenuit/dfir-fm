<?php
// src/Controllers/LogoutController.php
namespace Controllers;

use Core\Session;

class LogoutController
{
    public static function handle($config, $db)
    {
        Session::logout();

        // Construct the application root URL
        $appRoot = dirname($_SERVER['REQUEST_URI']);
        $redirectUrl = $appRoot . '/';

        header("Location: $redirectUrl");
        exit;
    }
}
?>
