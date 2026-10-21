<?php
// bin/report-domain-groups.php - List the "<domain> users" groups of older invitations (CLI only)
//
// Usage: php bin/report-domain-groups.php
//
// Invitations used to add every invitee to a group named after their e-mail
// domain ("example.org users"), with rights on each folder they were invited
// into: invitees of one domain could see each other's folders (DFM-01).
// Invitations now use a default group per folder ("<path> contributors").
// This report lists the remaining domain groups, their members and the
// folders they have rights on, for the operator to review and split them
// (admin page: Groups tab, and the file manager's Access Rights dialog).
// Nothing is changed.
//
// Read-only. Exit status: 0 (also when there is nothing to report), 2 on error.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
define('ENV_FILE', BASE_PATH . '/.env');

require_once BASE_PATH . '/vendor/autoload.php';

use Core\Config;

if ($argc !== 1) {
    fwrite(STDERR, "Usage: php bin/report-domain-groups.php\n");
    exit(2);
}

try {
    $config = Config::load(ENV_FILE);
    $db = new PDO('sqlite:' . Config::databasePath($config, BASE_PATH), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    ]);
    $groups = $db->query("SELECT id, name FROM groups WHERE is_admin = 0 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    // "<domain> users", as inviteUser named them: a host name with at least one dot
    $groups = array_values(array_filter($groups, fn($g) => preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+ users$/i', $g['name'])));

    $members = $db->prepare("SELECT u.username FROM users u JOIN user_group ug ON ug.user_id = u.id
                             WHERE ug.group_id = :g AND u.deleted_at IS NULL ORDER BY u.username");
    $rights = $db->prepare("SELECT d.path, ar.can_view, ar.can_write, ar.can_upload FROM access_rights ar
                            JOIN directories d ON d.id = ar.directory_id WHERE ar.group_id = :g ORDER BY d.path");
    $report = [];
    foreach ($groups as $group) {
        $members->execute([':g' => $group['id']]);
        $rights->execute([':g' => $group['id']]);
        $report[] = ['name' => $group['name'], 'members' => $members->fetchAll(PDO::FETCH_COLUMN), 'rights' => $rights->fetchAll(PDO::FETCH_ASSOC)];
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}

if (!$report) {
    echo "No domain groups (\"<domain> users\"): nothing to review.\n";
    exit(0);
}

foreach ($report as $group) {
    echo "Group \"{$group['name']}\"\n";
    echo '  members: ' . ($group['members'] ? implode(', ', $group['members']) : 'none') . "\n";
    if (!$group['rights']) {
        echo "  rights: none\n";
    }
    foreach ($group['rights'] as $r) {
        $flags = implode('', [$r['can_view'] ? 'view ' : '', $r['can_write'] ? 'write ' : '', $r['can_upload'] ? 'upload ' : '']);
        echo "  rights on {$r['path']}: " . (trim($flags) ?: 'none') . "\n";
    }
    $folders = count($group['rights']);
    if ($folders > 1 && count($group['members']) > 1) {
        echo "  -> review: $folders folders shared by " . count($group['members']) . " members; members may see folders they were not invited into\n";
    }
    echo "\n";
}
echo count($report) . " domain group(s). Nothing was changed.\n";
exit(0);
