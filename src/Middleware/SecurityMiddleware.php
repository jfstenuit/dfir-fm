<?php
namespace Middleware;

use Core\App;
use Core\Request;
use Core\Session;

class SecurityMiddleware
{
    /**
     * Rate-limit settings from the configuration, with safe defaults
     * for missing or invalid values.
     *
     * @return array{per_ip:int, per_user:int, window:int}
     */
    public static function loginThrottleSettings(array $config): array
    {
        $positive = function ($key, $default) use ($config) {
            $value = filter_var($config[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return $value === false ? $default : $value;
        };

        return [
            'per_ip' => $positive('max_login_attempts', 5),
            'per_user' => $positive('max_login_attempts_per_user', 10),
            'window' => $positive('login_lockout_duration', 300),
        ];
    }

    public static function throttle(string $key, int $maxAttempts = 5, int $ttl = 300): bool
    {
        if (!function_exists('apcu_fetch')) {
            throw new \Exception("APCu extension is required for rate limiting.");
        }

        $attempts = apcu_fetch($key) ?: 0;

        if ($attempts >= $maxAttempts) {
            return false;
        }

        apcu_inc($key, 1, $success, $ttl);
        return true;
    }

    public static function clearThrottle(string $key): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete($key);
        }
    }

    /** APCu key set when the proxy warning was last logged; it expires after PROXY_WARNING_TTL */
    public const PROXY_WARNING_KEY = 'config_warning_untrusted_proxy';
    public const PROXY_WARNING_TTL = 3600;

    /**
     * X-Forwarded-For arrives but `trusted_proxies` is empty: the site is
     * most likely behind a proxy that isn't configured, so every client
     * appears with the proxy's address (shared login rate limit, wrong
     * uploaded_from) and, without force_https, cookies lose Secure.
     * Logged as config_warning at most once per PROXY_WARNING_TTL.
     */
    public static function checkProxyConfiguration(): void
    {
        if (!isset($_SERVER['HTTP_X_FORWARDED_FOR']) || Request::hasTrustedProxies()) {
            return;
        }
        // apcu_add is atomic: only one request per period gets to log
        if (function_exists('apcu_add') && !apcu_add(self::PROXY_WARNING_KEY, time(), self::PROXY_WARNING_TTL)) {
            return;
        }
        App::getLogger()->log('config_warning', [
            'setting' => 'trusted_proxies',
            'message' => 'X-Forwarded-For received but trusted_proxies is empty: every client appears with the proxy address',
        ]);
    }

    /** Whether the admin page shows the proxy warning: seen now or within the last period */
    public static function proxyWarningActive(): bool
    {
        if (Request::hasTrustedProxies()) {
            return false;
        }
        return isset($_SERVER['HTTP_X_FORWARDED_FOR'])
            || (function_exists('apcu_exists') && apcu_exists(self::PROXY_WARNING_KEY));
    }

    public static function validateCsrfToken(): bool {
        $expected = Session::getCsrfToken();
        $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';

        // csrf_token[]=... would arrive as an array
        return is_string($received) && hash_equals($expected, $received);
    }

}
?>