<?php
// src/Backends/LogEntry.php
namespace Backends;

use Core\Session;
use Core\Request;

/**
 * Builds the log record shared by all log engines.
 */
class LogEntry {
    public static function build(string $action, array $context = []): array {
        // ts and action are authoritative: context keys can't override them
        $entry = ['ts' => gmdate('c'), 'action' => $action] + $context;

        $entry['ip'] = $entry['ip'] ?? Request::getClientIp();
        // Record which proxy relayed the request, when a trusted proxy did
        if (!array_key_exists('peer_ip', $entry) && Request::isFromTrustedProxy()) {
            $entry['peer_ip'] = Request::getPeerIp();
        }
        $entry['username'] = $entry['username'] ?? (Session::getUser()['username'] ?? null);

        return $entry;
    }

    /**
     * The JSON line every JSON destination writes, and the events
     * database's record column. Invalid UTF-8 (a file name in another
     * encoding) is replaced with U+FFFD rather than losing the whole event.
     */
    public static function encode(array $entry): string {
        return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
?>
