<?php
// src/Core/Session.php
namespace Core;

class Session
{
    public static function start()
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Reject session IDs the server didn't issue (session fixation)
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');

            // SameSite=Lax rather than Strict: Strict drops the cookie on
            // navigations coming from another site, which breaks invitation
            // links opened from an e-mail and the future OIDC callback.
            // Cross-site POSTs are still blocked (Lax + CSRF tokens).
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => self::cookiePath(),
                'secure' => Request::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /**
     * Restrict the cookie to the application's base path, so other
     * applications on the same host don't receive it.
     */
    private static function cookiePath(): string
    {
        return Request::getBasePath() . '/';
    }

    public static function getCsrfToken(): string {
        self::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function isAuthenticated()
    {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    public static function authenticate($user)
    {
        // New session ID on privilege change (session fixation), and a new
        // CSRF token so a token issued before login isn't reused after it
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['session_version'] = (int)($user['session_version'] ?? 0);
        $_SESSION['last_activity'] = time();
    }

    /**
     * Why the session's account may no longer use it, or null if it may:
     * 'missing' (no users row), 'deleted', or 'revoked' (users.session_version
     * changed since login: locked, password changed or reset). Read on
     * every request, so that revocation is immediate.
     */
    public static function revocationCause(\PDO $db): ?string
    {
        $stmt = $db->prepare("SELECT deleted_at, session_version FROM users WHERE id = :id");
        $stmt->execute([':id' => self::getUserId()]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            return 'missing';
        }
        if ($user['deleted_at'] !== null) {
            return 'deleted';
        }
        // Sessions opened before v5 have no version: they match the default 0
        if ((int)$user['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) {
            return 'revoked';
        }
        return null;
    }

    /** After a change that ends the account's other sessions (see revocationCause) */
    public static function setVersion(int $version): void
    {
        $_SESSION['session_version'] = $version;
    }

    public static function getUserId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function getUser(): ?array
    {
        if (isset($_SESSION['user_id'], $_SESSION['username'])) {
            return [
                'user_id' => (int) $_SESSION['user_id'],
                'username' => $_SESSION['username']
            ];
        }
    
        return null;
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