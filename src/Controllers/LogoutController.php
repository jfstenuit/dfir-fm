<?php
// src/Controllers/LogoutController.php
namespace Controllers;

use Core\Request;
use Core\Session;
use Core\App;

class LogoutController
{
    public static function handle($config, $db)
    {
        App::getLogger()->log('logout');

        Session::logout();

        $redirectUrl = Request::getBasePath() . '/';

        header("Location: $redirectUrl");
        exit;
    }
}
?>
