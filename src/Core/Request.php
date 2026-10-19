<?php
// src/Core/Request.php
namespace Core;

class Request
{
    /**
     * Trusted proxy networks as [packed address, prefix length] pairs,
     * set from the `trusted_proxies` configuration key at bootstrap.
     */
    private static array $trustedProxies = [];

    /**
     * Set from the `force_https` configuration key: the site is only served
     * over HTTPS, even if PHP doesn't see it (TLS terminated by a proxy).
     */
    private static bool $forceHttps = false;

    /**
     * Parse the `force_https` setting (true/false, yes/no, on/off, 1/0;
     * empty means false).
     *
     * @throws \InvalidArgumentException on an unrecognised value
     */
    public static function configureForceHttps(?string $setting): void
    {
        $setting = trim($setting ?? '');
        if ($setting === '') {
            self::$forceHttps = false;
            return;
        }

        $value = filter_var($setting, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new \InvalidArgumentException("Invalid force_https value: '$setting' (expected true or false)");
        }
        self::$forceHttps = $value;
    }

    /**
     * Parse the `trusted_proxies` setting: a comma-separated list of IPv4/IPv6
     * addresses or CIDR ranges. Empty means no trusted proxy: the client IP is
     * REMOTE_ADDR, as set by the web server (directly, or by mod_remoteip).
     *
     * @throws \InvalidArgumentException on an invalid entry
     */
    public static function configureTrustedProxies(?string $setting): void
    {
        $networks = [];
        foreach (explode(',', $setting ?? '') as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $networks[] = self::parseNetwork($entry);
            }
        }
        self::$trustedProxies = $networks;
    }

    /**
     * IP address of the client, used for logging, rate limiting and forensic
     * records (e.g. uploaded_from).
     *
     * X-Forwarded-For is only read when the direct peer is a trusted proxy.
     * It is then walked from right to left (each proxy appends the address it
     * received the request from), skipping trusted proxies: the first
     * untrusted address is the client. Entries further left are supplied by
     * the client itself and are never trusted.
     */
    public static function getClientIp(): ?string
    {
        $peer = self::getPeerIp();
        if ($peer === null || !self::isTrustedProxy($peer)) {
            return $peer;
        }

        $hops = array_values(array_filter(
            array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')),
            fn($hop) => $hop !== ''
        ));

        $client = $peer;
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $hop = self::normalizeIp(self::stripPort($hops[$i]));
            if ($hop === null) {
                // Unparseable entry: we can't go further left. Keep the last
                // address added by a trusted hop (or the peer itself).
                return $client;
            }
            $client = $hop;
            if (!self::isTrustedProxy($hop)) {
                return $hop;
            }
        }

        // Every entry is a trusted proxy: use the leftmost one
        return $client;
    }

    /**
     * Address of the direct TCP peer (REMOTE_ADDR), normalised.
     * Equals the client IP unless a trusted proxy relayed the request.
     */
    public static function getPeerIp(): ?string
    {
        return self::normalizeIp($_SERVER['REMOTE_ADDR'] ?? null);
    }

    /**
     * Whether the request was relayed by a configured trusted proxy.
     */
    public static function isFromTrustedProxy(): bool
    {
        return self::isTrustedProxy(self::getPeerIp());
    }

    /**
     * Whether the client connection uses HTTPS: always when `force_https` is
     * set, otherwise as seen by PHP. X-Forwarded-Proto is only honoured when
     * the request comes from a trusted proxy.
     */
    public static function isHttps(): bool
    {
        if (self::$forceHttps) {
            return true;
        }
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? null) == 443) {
            return true;
        }
        return self::isFromTrustedProxy()
            && strtolower(trim($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function getBaseUrl()
    {
        $scheme = self::isHttps() ? 'https://' : 'http://';

        // Get the host
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        // Get the script's directory (to account for subdirectories)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = rtrim(dirname($scriptName), '/');

        // Construct and return the base URL
        return $scheme . $host . $scriptDir;
    }

    public static function isTrustedProxy(?string $ip): bool
    {
        $packed = self::pack($ip);
        if ($packed === null) {
            return false;
        }

        foreach (self::$trustedProxies as [$network, $bits]) {
            if (strlen($network) === strlen($packed) && self::prefixMatches($packed, $network, $bits)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Canonical text form of an IP address (IPv4-mapped IPv6 addresses are
     * converted to IPv4), or null if the input isn't a valid IP address.
     */
    public static function normalizeIp(?string $ip): ?string
    {
        $packed = self::pack($ip);
        return $packed === null ? null : inet_ntop($packed);
    }

    private static function parseNetwork(string $entry): array
    {
        $parts = explode('/', $entry, 2);
        $packed = self::pack($parts[0]);
        if ($packed === null) {
            throw new \InvalidArgumentException("Invalid trusted_proxies entry: '$entry'");
        }

        $writtenAsIpv6 = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (!isset($parts[1])) {
            return [$packed, strlen($packed) * 8];
        }
        $bits = ctype_digit($parts[1]) ? (int)$parts[1] : -1;
        if ($bits < 0 || $bits > ($writtenAsIpv6 ? 128 : 32)) {
            throw new \InvalidArgumentException("Invalid prefix length in trusted_proxies entry: '$entry'");
        }
        // An IPv4-mapped IPv6 network (::ffff:a.b.c.d/n) is stored as IPv4
        if (strlen($packed) === 4 && $writtenAsIpv6) {
            if ($bits < 96) {
                throw new \InvalidArgumentException("IPv4-mapped trusted_proxies entry needs a prefix of at least /96: '$entry'");
            }
            $bits -= 96;
        }
        return [$packed, $bits];
    }

    /**
     * inet_pton() with IPv4-mapped IPv6 addresses (::ffff:a.b.c.d) folded to
     * IPv4, so that one trusted_proxies entry matches both notations.
     */
    private static function pack(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }
        return $packed;
    }

    private static function prefixMatches(string $address, string $network, int $bits): bool
    {
        $fullBytes = intdiv($bits, 8);
        if (substr($address, 0, $fullBytes) !== substr($network, 0, $fullBytes)) {
            return false;
        }
        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($address[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }

    /**
     * Some proxies append "a.b.c.d:port" or "[v6]:port" to X-Forwarded-For.
     */
    private static function stripPort(string $hop): string
    {
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $hop, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $hop, $m)) {
            return $m[1];
        }
        return $hop;
    }
}
