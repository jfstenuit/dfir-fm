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
}
?>
