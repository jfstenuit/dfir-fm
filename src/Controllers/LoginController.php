<?php
// src/Controllers/LoginController.php
namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Session;
use PDO;

class LoginController
{
    public static function handle($config, $db)
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Case A : login with a token
            $email = trim($_GET['email'] ?? '');
            $token = trim($_GET['token'] ?? '');
            $directory = trim($_GET['directory'] ?? '');

            if (!empty($email) && !empty($token) && !empty($directory)) {
                self::processTokenLogin($db, $email, $token, $directory);
                return;
            }

            // Case B : standard login form
            $redirect = isset($_GET['redirect']) ? filter_var($_GET['redirect'], FILTER_SANITIZE_URL) : '';
            error_log("Redirect: $redirect");
            require_once SRC_PATH . '/Views/LoginView.php';
            \Views\LoginView::render($redirect);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Case C : process submited login form
            self::processLogin($db);
        } else {
            http_response_code(405); // Method Not Allowed
            echo "Method Not Allowed";
        }
    }

    private static function processTokenLogin($db, $email, $token, $directory)
    {
        // Validate the token for the provided email
        $stmt = $db->prepare("
            SELECT u.id AS user_id, u.invitation_token, u.token_expiry
            FROM users u
            WHERE u.email = :email
        ");
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(400);
            echo "Invalid or expired token.";
            return;
        }

        // Check token validity and expiry
        if ($user['invitation_token'] !== $token || strtotime($user['token_expiry']) < time()) {
            http_response_code(400);
            echo "Invalid or expired token.";
            return;
        }

        // Check if the directory exists
        $stmt = $db->prepare("
            SELECT id
            FROM directories
            WHERE path = :directory_path
        ");
        $stmt->bindParam(':directory_path', $directory, PDO::PARAM_STR);
        $stmt->execute();
        $directoryInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$directoryInfo) {
            http_response_code(404);
            echo "Directory not found.";
            return;
        }

        // Authenticate the user
        Session::authenticate($user['user_id']);

        // Redirect to the directory
        $redirectPath = dirname($_SERVER['REQUEST_URI']) . '/?p=' . urlencode($directory);
        header("Location: " . $redirectPath);
        exit;
    }

    public static function sanitizeRedirect($redirect)
    {
        // Get the base path of the application (e.g., "/a/b")
        $basePath = dirname($_SERVER['REQUEST_URI']);

        // Define valid redirect paths relative to the base path
        $validPaths = ['logout', 'admin'];

        // Parse and sanitize the $redirect URL
        $parsedUrl = parse_url($redirect);
        if ($parsedUrl === false) {
            return $basePath . '/'; // Return root of the application on invalid URL
        }

        // Extract the path from the parsed URL
        $redirectPath = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
        if (!str_starts_with($redirectPath, $basePath)) {
            return $basePath . '/'; // Redirect to root if the path doesn't start with the base path
        }

        // Extract the relative path (part after the base path)
        $relativePath = substr($redirectPath, strlen($basePath) + 1); // Skip the trailing "/"

        // Validate the relative path against allowed values
        if (!in_array($relativePath, $validPaths, true)) {
            return $basePath . '/'; // Redirect to root if the relative path is invalid
        }

        // If valid, return the sanitized redirect URL
        return $redirectPath;
    }

    public static function processLogin($db)
    {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $redirect = isset($_POST['redirect']) ? filter_var($_POST['redirect'], FILTER_SANITIZE_URL) : null;


        if (empty($username) || empty($password)) {
            echo "Username and password are required.";
            return;
        }

        $stmt = $db->prepare("SELECT id, password FROM users WHERE username = :username");
        $stmt->bindParam(':username', $username, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            Session::authenticate($user['id']);

            $loginPath = dirname($_SERVER['REQUEST_URI']);

            header("Location: " . self::sanitizeRedirect($redirect));
            exit;
        } else {
            echo "Invalid username or password.";
        }
    }
}
?>
