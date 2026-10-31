<?php
namespace Tests\Unit\Core;

use Core\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public static function timeoutSettingsCases(): array
    {
        $defaults = ['idle' => 3600, 'max' => 604800];
        return [
            'defaults' => [[], $defaults],
            'configured' => [['session_idle_timeout' => '900', 'session_max_lifetime' => '43200'], ['idle' => 900, 'max' => 43200]],
            'invalid values fall back' => [['session_idle_timeout' => 'abc', 'session_max_lifetime' => '0'], $defaults],
        ];
    }

    #[DataProvider('timeoutSettingsCases')]
    public function testTimeoutSettings(array $config, array $expected): void
    {
        $this->assertSame($expected, Session::timeoutSettings($config));
    }
}
