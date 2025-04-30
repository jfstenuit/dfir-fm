<?php
namespace Middleware;

use Core\Session;

class SecurityMiddleware
{
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

        return hash_equals($expected, $received);
    }

}
?>