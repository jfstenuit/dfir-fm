<?php
namespace Tests\Unit\Middleware;

use Middleware\SecurityMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityMiddlewareTest extends TestCase
{
    public static function throttleSettingsCases(): array
    {
        $defaults = ['per_ip' => 5, 'per_user' => 10, 'window' => 300];
        return [
            'defaults' => [[], $defaults],
            'configured' => [['max_login_attempts' => '3', 'max_login_attempts_per_user' => '4', 'login_lockout_duration' => '60'], ['per_ip' => 3, 'per_user' => 4, 'window' => 60]],
            'invalid values fall back' => [['max_login_attempts' => 'abc', 'max_login_attempts_per_user' => '0', 'login_lockout_duration' => '-5'], $defaults],
        ];
    }

    #[DataProvider('throttleSettingsCases')]
    public function testLoginThrottleSettings(array $config, array $expected): void
    {
        $this->assertSame($expected, SecurityMiddleware::loginThrottleSettings($config));
    }

    public function testThrottle(): void
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            $this->markTestSkipped('APCu not enabled for the CLI (run through `composer test`)');
        }
        $key = 'test_' . bin2hex(random_bytes(6));

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(SecurityMiddleware::throttle($key, 3, 60), "attempt $i allowed");
        }
        $this->assertFalse(SecurityMiddleware::throttle($key, 3, 60), '4th attempt blocked');

        SecurityMiddleware::clearThrottle($key);
        $this->assertTrue(SecurityMiddleware::throttle($key, 3, 60), 'allowed again after clear');
        SecurityMiddleware::clearThrottle($key);
    }
}
