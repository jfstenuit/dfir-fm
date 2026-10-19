<?php
namespace Tests\Unit\Core;

use Core\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private array $savedServer;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        Request::configureTrustedProxies('');
        Request::configureForceHttps('');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        Request::configureTrustedProxies('');
        Request::configureForceHttps('');
    }

    private function request(?string $remote, ?string $xff = null, ?string $proto = null): void
    {
        $_SERVER = [];
        if ($remote !== null) $_SERVER['REMOTE_ADDR'] = $remote;
        if ($xff !== null) $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
        if ($proto !== null) $_SERVER['HTTP_X_FORWARDED_PROTO'] = $proto;
    }

    // --- No trusted proxies: forwarding headers are always ignored ---

    public function testForgedForwardedForIgnoredWithoutTrustedProxies(): void
    {
        $this->request('203.0.113.7', '1.2.3.4');
        $_SERVER['HTTP_CLIENT_IP'] = '9.9.9.9';
        $this->assertSame('203.0.113.7', Request::getClientIp());
    }

    public function testForwardedProtoIgnoredWithoutTrustedProxies(): void
    {
        $this->request('203.0.113.7', null, 'https');
        $this->assertFalse(Request::isHttps());
    }

    public function testIpv4MappedPeerIsNormalised(): void
    {
        $this->request('::ffff:198.51.100.1');
        $this->assertSame('198.51.100.1', Request::getClientIp());
    }

    public function testInvalidOrMissingRemoteAddr(): void
    {
        $this->request('garbage');
        $this->assertNull(Request::getClientIp());
        $this->request(null);
        $this->assertNull(Request::getClientIp());
    }

    // --- Trusted proxies: X-Forwarded-For walked right to left ---

    public static function trustedProxyCases(): array
    {
        $local = '127.0.0.1, ::1';
        $chain = '10.0.0.0/8, 172.16.0.0/12, 2001:db8:ffff::/48';
        return [
            'tunnel: client from XFF' => [$local, '127.0.0.1', '198.51.100.20', '198.51.100.20'],
            'tunnel: forged left entry ignored' => [$local, '127.0.0.1', '6.6.6.6, 198.51.100.20', '198.51.100.20'],
            'tunnel: IPv6' => [$local, '::1', '2001:db8::5', '2001:db8::5'],
            'untrusted peer: XFF ignored' => [$local, '203.0.113.7', '1.2.3.4', '203.0.113.7'],
            'trusted peer, no XFF' => [$local, '127.0.0.1', null, '127.0.0.1'],
            'all hops trusted: leftmost' => [$local, '127.0.0.1', '127.0.0.1', '127.0.0.1'],
            'garbage rightmost: peer' => [$local, '127.0.0.1', 'not-an-ip', '127.0.0.1'],
            'garbage left of client: irrelevant' => [$local, '127.0.0.1', 'not-an-ip, 198.51.100.20', '198.51.100.20'],
            'IPv4 with port' => [$local, '127.0.0.1', '198.51.100.20:51234', '198.51.100.20'],
            'bracketed IPv6 with port' => [$local, '127.0.0.1', '[2001:db8::5]:443', '2001:db8::5'],
            'chain: skip two trusted hops' => [$chain, '10.1.2.3', '198.51.100.20, 172.20.0.5, 10.9.9.9', '198.51.100.20'],
            'chain: first untrusted from the right' => [$chain, '10.1.2.3', '198.51.100.20, 8.8.8.8, 10.9.9.9', '8.8.8.8'],
            'chain: outside /12' => [$chain, '172.32.0.1', '198.51.100.20', '172.32.0.1'],
            'chain: inside /12' => [$chain, '172.31.255.255', '198.51.100.20', '198.51.100.20'],
            'chain: IPv6 CIDR' => [$chain, '2001:db8:ffff:1::1', '2001:db8:1::1', '2001:db8:1::1'],
            'chain: IPv4-mapped peer' => [$chain, '::ffff:10.0.0.1', '198.51.100.20', '198.51.100.20'],
            'mapped CIDR /104' => ['::ffff:10.0.0.0/104', '10.200.0.1', '198.51.100.20', '198.51.100.20'],
            '/25 inside' => ['192.168.1.128/25', '192.168.1.200', '198.51.100.20', '198.51.100.20'],
            '/25 outside' => ['192.168.1.128/25', '192.168.1.100', '198.51.100.20', '192.168.1.100'],
        ];
    }

    #[DataProvider('trustedProxyCases')]
    public function testClientIpResolution(string $trusted, string $remote, ?string $xff, string $expected): void
    {
        Request::configureTrustedProxies($trusted);
        $this->request($remote, $xff);
        $this->assertSame($expected, Request::getClientIp());
    }

    public function testForwardedProtoHonouredFromTrustedProxy(): void
    {
        Request::configureTrustedProxies('127.0.0.1');
        $this->request('127.0.0.1', '198.51.100.20', 'HTTPS');
        $this->assertTrue(Request::isHttps());
        $this->assertTrue(Request::isFromTrustedProxy());
    }

    public static function invalidTrustedProxies(): array
    {
        return [['10.0.0.0/33'], ['example.com'], ['10.0.0.0/abc'], ['::ffff:10.0.0.0/80'], ['2001:db8::/129'], ['10.0.0.1/-1']];
    }

    #[DataProvider('invalidTrustedProxies')]
    public function testInvalidTrustedProxiesRefused(string $entry): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Request::configureTrustedProxies("127.0.0.1, $entry");
    }

    // --- force_https ---

    public static function forceHttpsValues(): array
    {
        return [['true', true], ['yes', true], ['on', true], ['1', true], ['false', false], ['no', false], ['0', false], ['', false]];
    }

    #[DataProvider('forceHttpsValues')]
    public function testForceHttps(string $value, bool $expected): void
    {
        $this->request('203.0.113.7');
        Request::configureForceHttps($value);
        $this->assertSame($expected, Request::isHttps());
    }

    public function testForceHttpsBaseUrl(): void
    {
        $this->request('203.0.113.7');
        $_SERVER['HTTP_HOST'] = 'labs.example';
        $_SERVER['SCRIPT_NAME'] = '/dfir-fm/index.php';
        Request::configureForceHttps('true');
        $this->assertSame('https://labs.example/dfir-fm', Request::getBaseUrl());
    }

    public function testInvalidForceHttpsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Request::configureForceHttps('maybe');
    }
}
