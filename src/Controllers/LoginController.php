<?php
// src/Controllers/LoginController.php
namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Request;
use Core\Session;
use Core\App;
use Middleware\SecurityMiddleware;
use Views\LoginView;

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
            LoginView::render($redirect);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Case C : process submited login form
            $action = $_POST['action'] ?? null;
            if ($action === 'checkUser') {
                self::handleUserCheck($db);
            } else {
                self::processLogin($db);
            }
        } else {
            http_response_code(405); // Method Not Allowed
            echo "Method Not Allowed";
        }
    }

    private static function processTokenLogin($db, $email, $token, $directory)
    {
        // Validate the token for the provided email
        $stmt = $db->prepare("
            SELECT u.id AS user_id, u.username, u.invitation_token, u.token_expiry
            FROM users u
            WHERE u.username = :email
        ");
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            App::getLogger()->log('login_token_failure', ['cause'=>'Invalid token']);
            http_response_code(400);
            echo "Invalid or expired token.";
            return;
        }

        // Check token validity and expiry
        if ($user['invitation_token'] !== $token || strtotime($user['token_expiry']) < time()) {
            App::getLogger()->log('login_token_failure', ['cause'=>'Expired token']);
            http_response_code(400);
            echo "Invalid or expired token.";
            return;
        }

        App::getLogger()->log('login_token_success', [
            'username' => $email
        ]);

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
        Session::authenticate($user);

        // Redirect to the directory
        $redirectPath = dirname($_SERVER['REQUEST_URI']) . '/?p=' . urlencode($directory);
        header("Location: " . $redirectPath);
        exit;
    }

    private static function handleUserCheck($db)
    {
        header('Content-Type: application/json');
        $email = trim($_POST['email'] ?? '');
    
        // Always respond the same way to avoid user enumeration
        usleep(random_int(100000, 300000)); // Sleep 100–300ms
    
        $stmt = $db->prepare("SELECT id FROM users WHERE username = :email");
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
        // In the future, implement logic for known OIDC-based users
        echo json_encode(['login_type' => 'password']);
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
        header('Content-Type: application/json');
    
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $redirect = isset($_POST['redirect']) ? filter_var($_POST['redirect'], FILTER_SANITIZE_URL) : null;
    
        if (empty($username) || empty($password)) {
            App::getLogger()->log('login_password_failure', ['cause'=>'Empty username or password']);
            echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
            return;
        }
    
        $ip = Request::getClientIp();
        $key = 'login_attempts_' . md5($ip);

        if (!SecurityMiddleware::throttle($key)) {
            App::getLogger()->log('login_password_failure', ['cause'=>'Brute force attempt']);
            echo json_encode(['success' => false, 'message' => 'Too many login attempts. Try again later.']);
            return;
        }

        $stmt = $db->prepare("SELECT id as user_id, password, username FROM users WHERE username = :username");
        $stmt->bindParam(':username', $username, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if ($user && password_verify($password, $user['password'])) {
            SecurityMiddleware::clearThrottle($key);
            Session::authenticate($user);

            App::getLogger()->log('login_password_success', []);

            echo json_encode([
                'success' => true,
                'redirect' => self::sanitizeRedirect($redirect)
            ]);
            return;
        } else {
            App::getLogger()->log('login_password_failure', ['cause'=>'Invalid login or password']);
            echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
        }
    }
    
}
?>
