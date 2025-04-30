<?php
namespace Middleware;

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
}
?>