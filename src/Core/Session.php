<?php
// src/Core/Session.php
namespace Core;

class Session
{
    /** Defaults of session_idle_timeout (1 hour) and session_max_lifetime (1 week), in seconds */
    public const DEFAULT_IDLE_TIMEOUT = 3600;
    public const DEFAULT_MAX_LIFETIME = 604800;

    private static int $idleTimeout = self::DEFAULT_IDLE_TIMEOUT;
    private static int $maxLifetime = self::DEFAULT_MAX_LIFETIME;
    private static ?string $savePath = null;

    /**
     * Timeout settings from the configuration, with the defaults for
     * missing or invalid values.
     *
     * @return array{idle:int, max:int}
     */
    public static function timeoutSettings(array $config): array
    {
        $positive = function ($key, $default) use ($config) {
            $value = filter_var($config[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return $value === false ? $default : $value;
        };

        return [
            'idle' => $positive('session_idle_timeout', self::DEFAULT_IDLE_TIMEOUT),
            'max' => $positive('session_max_lifetime', self::DEFAULT_MAX_LIFETIME),
        ];
    }

    /**
     * Timeouts and session directory (Config::sessionSavePath()), before
     * start(). The directory is created if needed.
     *
     * @return bool false if the directory can't be created or written to
     */
    public static function configure(array $config, string $savePath): bool
    {
        if (!is_dir($savePath) && !@mkdir($savePath, 0700, true) && !is_dir($savePath)) {
            return false;
        }
        if (!is_writable($savePath)) {
            return false;
        }
        $settings = self::timeoutSettings($config);
        self::$idleTimeout = $settings['idle'];
        self::$maxLifetime = $settings['max'];
        self::$savePath = $savePath;
        return true;
    }

    public static function start()
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Reject session IDs the server didn't issue (session fixation)
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');
            if (self::$savePath !== null) {
                // Our own directory: PHP's garbage collection is the only
                // cleanup, and removes what the idle timeout has ended
                // (php.ini may disable it, as Debian does in favour of cron)
                ini_set('session.save_path', self::$savePath);
                ini_set('session.gc_maxlifetime', (string)self::$idleTimeout);
                ini_set('session.gc_probability', '1');
                ini_set('session.gc_divisor', '100');
            }
            // Caching headers are set by index.php (no-store on every
            // response, private, no-store on downloads), not by PHP's default
            // "nocache" limiter, which would overwrite them
            session_cache_limiter('');

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
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['client_ip'] = Request::getClientIp();
    }

    /**
     * Why the session's account may no longer use it, or null if it may:
     * 'missing' (no users row), 'deleted', 'locked', or 'revoked'
     * (users.session_version changed since login: password changed or
     * reset, invitation resent). Read on every request, so that revocation
     * is immediate.
     */
    public static function revocationCause(\PDO $db): ?string
    {
        $stmt = $db->prepare("SELECT deleted_at, locked_at, session_version FROM users WHERE id = :id");
        $stmt->execute([':id' => self::getUserId()]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            return 'missing';
        }
        if ($user['deleted_at'] !== null) {
            return 'deleted';
        }
        // Locking also changes the version; locked_at is checked on its own
        // so that the cause is precise and doesn't depend on it
        if ($user['locked_at'] !== null) {
            return 'locked';
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

    /**
     * A session opened through the IdP (#9). OIDC accounts have no
     * password, so they have no other way in.
     */
    public static function isOidc(): bool
    {
        return ($_SESSION['auth_method'] ?? null) === 'oidc';
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

    /**
     * Why the session has expired, or null if it hasn't: 'idle' (no request
     * for session_idle_timeout seconds) or 'max_lifetime' (logged in more
     * than session_max_lifetime seconds ago, active or not). A session
     * still valid records this request as its last activity.
     */
    public static function expiryCause(): ?string
    {
        $now = time();
        // Sessions opened before the absolute lifetime existed count from now
        $_SESSION['login_time'] ??= $now;

        if (isset($_SESSION['last_activity']) && $now - $_SESSION['last_activity'] > self::$idleTimeout) {
            return 'idle';
        }
        if ($now - $_SESSION['login_time'] > self::$maxLifetime) {
            return 'max_lifetime';
        }
        $_SESSION['last_activity'] = $now;
        return null;
    }

    /**
     * The session's previous client IP if this request comes from another
     * one, else null. The session goes on (mobile networks and proxies with
     * several egress addresses change it legitimately): the change is only
     * logged. Sessions opened before this check start tracking now.
     */
    public static function clientIpChange(): ?string
    {
        $ip = Request::getClientIp();
        $previous = $_SESSION['client_ip'] ?? $ip;
        $_SESSION['client_ip'] = $ip;
        return $previous !== $ip ? $previous : null;
    }
}
?>