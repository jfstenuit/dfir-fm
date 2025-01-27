<?php
// src/Core/Session.php
namespace Core;

class Session
{
    public static function start()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function isAuthenticated()
    {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    public static function authenticate($userId)
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['last_activity'] = time();
    }

    public static function logout()
    {
        // Start the session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_unset();
        session_destroy();

        // Remove the session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), // Name of the session cookie (e.g., PHPSESSID)
                '',             // Clear the cookie value
                time() - 42000, // Set expiration time in the past
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
    }

    public static function isStillValid($timeout = 3600)
    {
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            self::logout();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }
}
?>