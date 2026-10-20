<?php
namespace Tests\Unit\Controllers;

use Controllers\LoginController;
use Core\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Base path and post-login redirect, at the web root and in a subdirectory.
 * At the web root, dirname(REQUEST_URI) used to give '/', so redirects became
 * '//' (protocol-relative) and the browser failed to navigate after login.
 */
final class LoginRedirectTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private static function installedAt(string $scriptName, string $requestUri): void
    {
        $_SERVER['SCRIPT_NAME'] = $scriptName;
        $_SERVER['REQUEST_URI'] = $requestUri;
    }

    public static function basePaths(): array
    {
        return [
            'web root' => ['/index.php', ''],
            'subdirectory' => ['/dfir-fm/index.php', '/dfir-fm'],
            'nested' => ['/a/b/index.php', '/a/b'],
            'no directory in SCRIPT_NAME' => ['index.php', ''],
        ];
    }

    #[DataProvider('basePaths')]
    public function testBasePath(string $scriptName, string $expected): void
    {
        self::installedAt($scriptName, '/whatever?x=/y/z');
        $this->assertSame($expected, Request::getBasePath());
    }

    public static function redirects(): array
    {
        return [
            // [SCRIPT_NAME, REQUEST_URI, redirect, expected]
            'root: default' => ['/index.php', '/login', '', '/'],
            'root: admin' => ['/index.php', '/login', '/admin', '/admin'],
            'root: unknown page' => ['/index.php', '/login', '/elsewhere', '/'],
            'root: other host' => ['/index.php', '/login', 'https://evil.example/admin', '/admin'],
            'root: protocol-relative' => ['/index.php', '/login', '//evil.example/admin', '/admin'],
            'subdir: default' => ['/dfir-fm/index.php', '/dfir-fm/login', '', '/dfir-fm/'],
            'subdir: admin' => ['/dfir-fm/index.php', '/dfir-fm/login', '/dfir-fm/admin', '/dfir-fm/admin'],
            'subdir: outside base' => ['/dfir-fm/index.php', '/dfir-fm/login', '/admin', '/dfir-fm/'],
            'subdir: prefix lookalike' => ['/dfir-fm/index.php', '/dfir-fm/login', '/dfir-fmX/admin', '/dfir-fm/'],
            'subdir: slash in query' => ['/dfir-fm/index.php', '/dfir-fm/login?redirect=/a/b', '', '/dfir-fm/'],
        ];
    }

    #[DataProvider('redirects')]
    public function testSanitizeRedirect(string $scriptName, string $requestUri, string $redirect, string $expected): void
    {
        self::installedAt($scriptName, $requestUri);
        $result = LoginController::sanitizeRedirect($redirect);
        $this->assertSame($expected, $result);
        $this->assertStringStartsNotWith('//', $result);
    }
}
