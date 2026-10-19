<?php
namespace Middleware;

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

    public static function validateCsrfToken(): bool {
        $expected = Session::getCsrfToken();
        $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';

        // csrf_token[]=... would arrive as an array
        return is_string($received) && hash_equals($expected, $received);
    }

}
?>