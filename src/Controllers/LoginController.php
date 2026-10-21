<?php
// src/Controllers/LoginController.php
namespace Controllers;

use Core\Config;
use Core\Database;
use Core\PasswordPolicy;
use Core\Request;
use Core\Session;
use Core\App;
use Middleware\SecurityMiddleware;
use Views\LoginView;
use Views\SetPasswordView;

use PDO;

class LoginController
{
    public static function handle($config, $db)
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Case A : invitation link. Only shows the password form: mail
            // scanners that follow links neither log in nor use up the token.
            if (isset($_GET['email']) || isset($_GET['token'])) {
                self::showInvitation($db);
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
            } elseif ($action === 'setPassword') {
                self::processSetPassword($db);
            } else {
                self::processLogin($db);
            }
        } else {
            http_response_code(405); // Method Not Allowed
            echo "Method Not Allowed";
        }
    }

    private const INVALID_LINK = 'This invitation link is not valid: it was already used, has expired (links are valid for one hour), or was replaced by a newer one. Ask the person who invited you for a new invitation.';

    /** A query parameter or form field as a string ('' when missing or an array) */
    private static function field(array $source, string $name): string
    {
        return is_string($source[$name] ?? null) ? $source[$name] : '';
    }

    /**
     * The account an invitation token belongs to, or the cause of refusal:
     * 'unknown' (no active account), 'used' (no pending token: used, or none
     * issued), 'wrong' (another token), 'expired'. The cause is logged only:
     * the invitee always gets the same message.
     * @return array{0: ?array, 1: ?string}
     */
    private static function checkInvitation(PDO $db, string $email, string $token): array
    {
        $stmt = $db->prepare("SELECT id AS user_id, username, invitation_token, token_expiry, session_version
                              FROM users WHERE username = :email AND deleted_at IS NULL AND locked_at IS NULL");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return [null, 'unknown'];
        }
        if ($user['invitation_token'] === null || $user['invitation_token'] === '') {
            return [null, 'used'];
        }
        if (!hash_equals($user['invitation_token'], $token)) {
            return [null, 'wrong'];
        }
        if ($user['token_expiry'] === null || strtotime($user['token_expiry']) < time()) {
            return [null, 'expired'];
        }
        return [$user, null];
    }

    /** Headers of the invitation page and answers: the URL holds the token */
    private static function invitationHeaders(): void
    {
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
    }

    private static function showInvitation(PDO $db): void
    {
        self::invitationHeaders();
        $email = trim(self::field($_GET, 'email'));
        $token = trim(self::field($_GET, 'token'));
        $directory = trim(self::field($_GET, 'directory')) ?: '/';

        [$user, $cause] = self::checkInvitation($db, $email, $token);
        if ($user === null) {
            App::getLogger()->log('login_token_failure', ['cause' => $cause, 'target_user' => $email, 'step' => 'link']);
            http_response_code(400);
            SetPasswordView::render($email, '', $directory, self::INVALID_LINK);
            return;
        }
        SetPasswordView::render($email, $token, $directory);
    }

    /**
     * The invitation form: checks the token again, sets the password and
     * uses up the token in one statement (a second POST with the same token
     * fails), ends the account's other sessions, then logs in.
     */
    private static function processSetPassword(PDO $db): void
    {
        self::invitationHeaders();
        header('Content-Type: application/json');
        $email = trim(self::field($_POST, 'email'));
        $token = trim(self::field($_POST, 'token'));
        $directory = trim(self::field($_POST, 'directory')) ?: '/';
        $password = $_POST['password'] ?? null;   // never trimmed

        [$user, $cause] = self::checkInvitation($db, $email, $token);
        if ($user === null) {
            App::getLogger()->log('login_token_failure', ['cause' => $cause, 'target_user' => $email, 'step' => 'password']);
            echo json_encode(['success' => false, 'message' => self::INVALID_LINK]);
            return;
        }

        $problem = PasswordPolicy::problem($password);
        if ($problem !== null) {
            echo json_encode(['success' => false, 'message' => $problem]);
            return;
        }

        $stmt = $db->prepare("UPDATE users SET password = :password, invitation_token = NULL, token_expiry = NULL,
                                               session_version = session_version + 1
                              WHERE id = :id AND invitation_token = :token AND deleted_at IS NULL AND locked_at IS NULL");
        $stmt->execute([':password' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['user_id'], ':token' => $user['invitation_token']]);
        if ($stmt->rowCount() !== 1) {
            // Another request used the token between the check and the update
            App::getLogger()->log('login_token_failure', ['cause' => 'used', 'target_user' => $email, 'step' => 'password']);
            echo json_encode(['success' => false, 'message' => self::INVALID_LINK]);
            return;
        }
        $user['session_version'] = (int)$user['session_version'] + 1;

        Session::authenticate($user);
        App::getLogger()->log('password_changed', ['via' => 'invitation']);
        App::getLogger()->log('login_token_success', []);

        // The invitation folder if it still exists, else the root
        $stmt = $db->prepare("SELECT 1 FROM directories WHERE path = :path");
        $stmt->execute([':path' => $directory]);
        $target = $stmt->fetchColumn() ? $directory : '/';
        echo json_encode(['success' => true, 'redirect' => Request::getBasePath() . '/?p=' . urlencode($target)]);
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
        // Base path of the application: "" at the web root, "/a/b" in a subdirectory
        $basePath = Request::getBasePath();

        // Define valid redirect paths relative to the base path
        $validPaths = ['logout', 'admin'];

        // Parse and sanitize the $redirect URL
        $parsedUrl = parse_url($redirect);
        if ($parsedUrl === false) {
            return $basePath . '/'; // Return root of the application on invalid URL
        }

        // Extract the path from the parsed URL
        $redirectPath = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
        if (!str_starts_with($redirectPath, $basePath . '/')) {
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
        // Never trimmed (PasswordPolicy): a space is a character like any other
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $redirect = isset($_POST['redirect']) ? filter_var($_POST['redirect'], FILTER_SANITIZE_URL) : null;
    
        if ($username === '' || $password === '') {
            App::getLogger()->log('login_password_failure', ['cause'=>'Empty username or password']);
            echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
            return;
        }
    
        // Two independent limits: per client IP (one source guessing many
        // accounts) and per username (a distributed attack on one account).
        $limits = SecurityMiddleware::loginThrottleSettings(App::getConfig());
        $ipKey = 'login_attempts_ip_' . hash('sha256', (string)Request::getClientIp());
        $userKey = 'login_attempts_user_' . hash('sha256', mb_strtolower($username));

        // A source that is already blocked doesn't count against the username,
        // so it can't keep locking out the account owner
        $ipAllowed = SecurityMiddleware::throttle($ipKey, $limits['per_ip'], $limits['window']);
        $userAllowed = $ipAllowed && SecurityMiddleware::throttle($userKey, $limits['per_user'], $limits['window']);
        if (!$ipAllowed || !$userAllowed) {
            App::getLogger()->log('login_password_failure', [
                'cause' => 'Brute force attempt',
                'limit' => $ipAllowed ? 'username' : 'ip',
                'target_user' => $username,
            ]);
            echo json_encode(['success' => false, 'message' => 'Too many login attempts. Try again later.']);
            return;
        }

        $stmt = $db->prepare("SELECT id as user_id, password, username, session_version FROM users WHERE username = :username AND deleted_at IS NULL AND locked_at IS NULL");
        $stmt->bindParam(':username', $username, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if ($user && password_verify($password, $user['password'])) {
            // Only the username counter is reset. Resetting the IP counter
            // would let an attacker with one valid account clear it between
            // guesses on other accounts.
            SecurityMiddleware::clearThrottle($userKey);
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
