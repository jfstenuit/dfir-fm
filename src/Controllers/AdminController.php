<?php
// src/Controllers/AdminController.php
namespace Controllers;

use Middleware\AccessMiddleware;
use Middleware\SecurityMiddleware;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\App;
use Backends\MailEngineFactory;
use Views\InvitationView;
use Views\AdminView;
use PDO;

class AdminController
{
    public static function handle($config, $db)
    {
        $action = trim($_POST['a'] ?? '');

        // Every action in this controller is admin-only
        if (!AccessMiddleware::isAdmin($db, Session::getUserId())) {
            App::getLogger()->log('admin_access_denied', ['requested_action' => $action]);
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied.']);
            } else {
                Response::triggerAccessDenied();
            }
            return;
        }

        if ($action === "invite") {
            self::inviteUser($config,$db);
        } elseif ($action === "listGroupPermissions") {
            self::listGroupPermissions($config, $db);
        } elseif ($action === "listGroups") {
            self::listGroups($config, $db);
        } elseif ($action === "createGroupIfNotExists") {
            self::createGroupIfNotExists($config, $db);
        } elseif ($action === "setAccessRight") {
            self::setAccessRight($config, $db);
        } elseif ($action === "removeAccessRight") {
            self::removeAccessRight($config, $db);
        } elseif ($action === "listUsers") {
            self::listUsers($config, $db);
        } elseif ($action === 'deleteUser') {
            self::deleteUser($config,$db);
        } elseif ($action === "addUserToGroup") {
            self::addUserToGroup($config, $db);
        } elseif ($action === "removeUserFromGroup") {
            self::removeUserFromGroup($config, $db);
        } elseif ($action === "lockUser") {
            self::lockUser($config, $db);
        } elseif ($action === "unlockUser") {
            self::unlockUser($config, $db);
        } elseif ($action === "resendInvite") {
            self::resendInvite($config, $db);
        } elseif ($action === "listGroupsWithDetails") {
            self::listGroupsWithDetails($config, $db);
        } elseif ($action === "deleteGroup") {
            self::deleteGroup($config, $db);
        } else {
            // Render the admin view
            $users=[]; $groups=[]; $accessRights=[];
            AdminView::render($users, $groups, $accessRights, SecurityMiddleware::proxyWarningActive());
        }
    }

    /**
     * Invite a contributor into a subfolder (D14): create the account if
     * needed, add it to the folder's default group unless unticked (D15,
     * D21), then deliver a link (deliverInvitation). Never sets or changes
     * a group's rights.
     */
    public static function inviteUser($config, $db) {
        header('Content-Type: application/json');

        $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        $cwd = trim(is_string($_POST['cwd'] ?? null) ? $_POST['cwd'] : '');
        $joinDefaultGroup = ($_POST['addToDefaultGroup'] ?? '') === 'true';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email address']);
            return;
        }
        if ($cwd === '' || $cwd === '/') {
            echo json_encode(['success' => false, 'message' => 'Invitations are made into a subfolder, not into the root: open the folder first.']);
            return;
        }
        if (!AccessMiddleware::getDirectoryInfo($db, $cwd)) {
            echo json_encode(['success' => false, 'message' => 'Invalid target directory']);
            return;
        }

        $db->exec('BEGIN IMMEDIATE');
        try {
            $invitee = AccessMiddleware::getUserInfo($db, $email, true);
            $group = null;
            if ($joinDefaultGroup) {
                $group = AccessMiddleware::defaultGroupFor($db, $cwd);
                AccessMiddleware::addUserToGroup($db, $invitee['id'], $group['id']);
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }

        if ($group !== null && $group['created']) {
            App::getLogger()->log('group_created', ['group' => $group['name'], 'default_group_of' => $cwd]);
        }

        $delivery = self::deliverInvitation($config, $db, $email, $cwd);
        App::getLogger()->log('user_invited', [
            'invited_user' => $email,
            'directory' => $cwd,
            'group' => $group['name'] ?? null,
            'delivery' => $delivery['delivery'],
            'invitation_sent' => $delivery['error'] === null,
        ]);
        $done = $group !== null ? "The account was created or found and added to the group \"{$group['name']}\"" : 'The account was created or found, without any group';
        self::answerDelivery($delivery, 'Invitation sent',
            "$done, but the invitation e-mail could not be sent (details in the application log, mail_send_failure). "
            . 'Check the mail configuration with bin/send-test-mail.php, then use "Resend invitation" in the admin page.');
    }

    /**
     * Issue a new token for the account and deliver the link to $directory:
     * by mail, or, with mail_engine=none, back to the administrator
     * (answerDelivery). The link is never logged.
     * @return array{delivery: 'mail'|'manual', link: ?string, error: ?string}
     */
    private static function deliverInvitation($config, PDO $db, string $email, string $directory): array
    {
        $manual = MailEngineFactory::isManual($config);
        $token = AccessMiddleware::generateTokenForUser($db, $email);
        if (!$token) {
            // The callers checked that the account exists
            throw new \RuntimeException("No account to issue an invitation token for: $email");
        }

        $baseUrl = Request::getBaseUrl();
        if ($manual) {
            return ['delivery' => 'manual', 'link' => InvitationView::link($email, $token, $directory, $baseUrl), 'error' => null];
        }

        $error = null;
        try {
            $mailEngine = MailEngineFactory::create($config);
            // As generateTokenForUser() sets it, give or take a second; shown to the minute
            $expiresAt = gmdate('Y-m-d H:i', time() + 3600);
            $body = InvitationView::render($email, $token, $directory, $baseUrl, $expiresAt);
            if (!$mailEngine->send($email, InvitationView::subject($directory, $baseUrl), $body)) {
                $error = $mailEngine->lastError() ?? 'unknown error';
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        if ($error !== null) {
            // Details (SMTP replies, configuration problems) go to the log only
            App::getLogger()->log('mail_send_failure', [
                'recipient' => $email,
                'mail_engine' => MailEngineFactory::engineName($config),
                'error' => $error,
            ]);
        }
        return ['delivery' => 'mail', 'link' => null, 'error' => $error];
    }

    /** The JSON answer for a delivery; a manual link is shown once and never cached */
    private static function answerDelivery(array $delivery, string $sent, string $mailFailed): void
    {
        if ($delivery['error'] !== null) {
            echo json_encode(['success' => false, 'message' => $mailFailed]);
        } elseif ($delivery['delivery'] === 'manual') {
            header('Cache-Control: no-store');
            echo json_encode(['success' => true, 'message' => 'Invitation link created', 'link' => $delivery['link'],
                              'expires_in' => 3600]);
        } else {
            echo json_encode(['success' => true, 'message' => $sent]);
        }
    }

    private static function listGroupPermissions($config, PDO $db)
    {
        header('Content-Type: application/json');
    
        $cwd = trim($_POST['cwd'] ?? '');
    
        if ($cwd === '') {
            echo json_encode([]);
            return;
        }
    
        try {
            // Step 1: Lookup directory ID
            $stmt = $db->prepare("SELECT id FROM directories WHERE path = :path");
            $stmt->execute([':path' => $cwd]);
            $directoryId = $stmt->fetchColumn();
    
            if (!$directoryId) {
                echo json_encode([]);
                return;
            }
    
            // Step 2: Get group permissions for this directory
            $stmt = $db->prepare("
                SELECT g.name AS group_name, 
                       ar.can_view, ar.can_write, ar.can_upload
                FROM access_rights ar
                JOIN groups g ON ar.group_id = g.id
                WHERE ar.directory_id = :directory_id
                ORDER BY g.name
            ");
            $stmt->execute([':directory_id' => $directoryId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
            // Step 3: Normalize output
            $output = array_map(function ($row) {
                return [
                    'group' => $row['group_name'],
                    'can_read' => (bool) $row['can_view'],
                    'can_write' => (bool) $row['can_write'],
                    'can_upload' => (bool) $row['can_upload']
                ];
            }, $results);
    
            echo json_encode($output);
    
        } catch (\PDOException $e) {
            error_log("Error listing group permissions: " . $e->getMessage());
            echo json_encode([]);
        }
    }
    
    private static function listGroups($config, PDO $db)
    {
        header('Content-Type: application/json');

        // Retrieve and sanitize the query input
        $query = trim($_POST['q'] ?? '');

        if ($query !== '' && mb_strlen($query) > 50) {
            echo json_encode([]); // prevent abuse or overlong inputs
            return;
        }

        try {
            if ($query === '') {
                // Return all group names (limit to 20 for safety)
                $stmt = $db->prepare("SELECT name FROM groups ORDER BY name LIMIT 20");
                $stmt->execute();
            } else {
                // Use LIKE safely in SQLite (case-insensitive by default)
                $stmt = $db->prepare("SELECT name FROM groups WHERE name LIKE :query ORDER BY name LIMIT 20");
                $stmt->execute([':query' => '%' . $query . '%']);
            }

            $groups = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode($groups);

        } catch (\PDOException $e) {
            error_log("Failed to fetch groups: " . $e->getMessage());
            echo json_encode([]);
        }
    }

    private static function createGroupIfNotExists($config, PDO $db)
    {
        header('Content-Type: application/json');

        $name = trim($_POST['name'] ?? '');
        $cwd = trim($_POST['cwd'] ?? '');

        // Basic input validation
        if ($name === '' || mb_strlen($name) > 100) {
            echo json_encode(['success' => false, 'message' => 'Invalid group name.']);
            return;
        }

        if ($cwd === '') {
            echo json_encode(['success' => false, 'message' => 'Missing directory context.']);
            return;
        }

        try {
            // Step 1: Create group if it doesn't exist
            $stmt = $db->prepare("INSERT OR IGNORE INTO groups (name) VALUES (:name)");
            $stmt->execute([':name' => $name]);

            // Step 2: Get group ID
            $stmt = $db->prepare("SELECT id FROM groups WHERE name = :name");
            $stmt->execute([':name' => $name]);
            $groupId = $stmt->fetchColumn();

            if (!$groupId) {
                echo json_encode(['success' => false, 'message' => 'Failed to find or create group.']);
                return;
            }

            // Step 3: Find directory ID from path
            $stmt = $db->prepare("SELECT id FROM directories WHERE path = :path");
            $stmt->execute([':path' => $cwd]);
            $directoryId = $stmt->fetchColumn();

            if (!$directoryId) {
                echo json_encode(['success' => false, 'message' => 'Directory not found.']);
                return;
            }

            // Step 4: Ensure access_rights record exists for this group + dir (with all FALSE)
            $stmt = $db->prepare("
                INSERT OR IGNORE INTO access_rights (group_id, directory_id, can_view, can_write, can_upload)
                VALUES (:group_id, :directory_id, 0, 0, 0)
            ");
            $stmt->execute([
                ':group_id' => $groupId,
                ':directory_id' => $directoryId
            ]);

            App::getLogger()->log('group_created', ['group'=>$name]);

            echo json_encode(['success' => true]);

        } catch (\PDOException $e) {
            error_log("Group creation error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
    }

    private static function setAccessRight($config, PDO $db)
    {
        header('Content-Type: application/json');
    
        $group = trim($_POST['group'] ?? '');
        $cwd = trim($_POST['cwd'] ?? '');
        $right = trim($_POST['right'] ?? '');
        $value = (int) ($_POST['value'] ?? 0);
    
        if ($group === '' || $cwd === '' || !in_array($right, ['read', 'write', 'upload'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid input.']);
            return;
        }
    
        // Map right to column
        $columnMap = [
            'read' => 'can_view',
            'write' => 'can_write',
            'upload' => 'can_upload'
        ];
        $column = $columnMap[$right];
    
        try {
            // Fetch IDs
            $stmt = $db->prepare("SELECT id FROM groups WHERE name = :name");
            $stmt->execute([':name' => $group]);
            $groupId = $stmt->fetchColumn();
    
            $stmt = $db->prepare("SELECT id FROM directories WHERE path = :path");
            $stmt->execute([':path' => $cwd]);
            $directoryId = $stmt->fetchColumn();
    
            if (!$groupId || !$directoryId) {
                echo json_encode(['success' => false, 'message' => 'Group or directory not found.']);
                return;
            }
    
            // Ensure row exists
            $stmt = $db->prepare("
                INSERT OR IGNORE INTO access_rights (group_id, directory_id, can_view, can_write, can_upload)
                VALUES (:group_id, :directory_id, 0, 0, 0)
            ");
            $stmt->execute([':group_id' => $groupId, ':directory_id' => $directoryId]);
    
            // Update specific column
            $sql = "UPDATE access_rights SET $column = :value WHERE group_id = :group_id AND directory_id = :directory_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':value' => $value,
                ':group_id' => $groupId,
                ':directory_id' => $directoryId
            ]);

            App::getLogger()->log('access_granted', ['group'=>$group, 'directory'=>$cwd, 'right'=>$column, 'value'=>$value]);

            echo json_encode(['success' => true]);
    
        } catch (\PDOException $e) {
            error_log("setAccessRight error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
    }

    private static function removeAccessRight($config, PDO $db)
    {
        header('Content-Type: application/json');
    
        $group = trim($_POST['group'] ?? '');
        $cwd = trim($_POST['cwd'] ?? '');
    
        if ($group === '' || $cwd === '') {
            echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
            return;
        }
    
        try {
            $stmt = $db->prepare("SELECT id FROM groups WHERE name = :name");
            $stmt->execute([':name' => $group]);
            $groupId = $stmt->fetchColumn();
    
            $stmt = $db->prepare("SELECT id FROM directories WHERE path = :path");
            $stmt->execute([':path' => $cwd]);
            $directoryId = $stmt->fetchColumn();
    
            if (!$groupId || !$directoryId) {
                echo json_encode(['success' => false, 'message' => 'Group or directory not found.']);
                return;
            }
    
            $stmt = $db->prepare("DELETE FROM access_rights WHERE group_id = :group_id AND directory_id = :directory_id");
            $stmt->execute([':group_id' => $groupId, ':directory_id' => $directoryId]);

            App::getLogger()->log('access_revoked', ['group'=>$group, 'directory'=>$cwd]);

            echo json_encode(['success' => true]);
    
        } catch (\PDOException $e) {
            error_log("removeAccessRight error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
    }

    private static function listUsers($config, PDO $db)
    {
        header('Content-Type: application/json');

        $stmt = $db->query("SELECT id, username, `password`, invitation_token, token_expiry, locked_at FROM users WHERE deleted_at IS NULL");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];

        foreach ($users as $user) {
            $identifier = $user['username'];

            // locked: by an administrator; active: has a password; invited:
            // no password yet, with a pending link or an expired one
            $pending = !empty($user['invitation_token']) && !empty($user['token_expiry']) && strtotime($user['token_expiry']) > time();
            if ($user['locked_at'] !== null) {
                $status = 'locked';
            } elseif (!empty($user['password']) && $user['password'] !== '!') {
                $status = 'active';
            } else {
                $status = 'invited';
            }

            // Get group memberships
            $stmtGroups = $db->prepare("
                SELECT g.name
                FROM groups g
                JOIN user_group ug ON g.id = ug.group_id
                WHERE ug.user_id = :user_id
            ");
            $stmtGroups->execute([':user_id' => $user['id']]);
            $groups = $stmtGroups->fetchAll(PDO::FETCH_COLUMN);

            $result[] = [
                'email' => $identifier,
                'status' => $status,
                'invitation_pending' => $pending,
                'groups' => $groups
            ];
        }

        echo json_encode($result);
    }

    private static function deleteUser($config, PDO $db)
    {
        header('Content-Type: application/json');

        $email = trim($_POST['email'] ?? '');
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Missing email.']);
            return;
        }

        // Lookup user by email (username is email unless it's 'admin')
        $stmt = $db->prepare("SELECT id, username, email FROM users WHERE username = :email AND deleted_at IS NULL");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            return;
        }

        if ((int)$user['id'] === Session::getUserId()) {
            echo json_encode(['success' => false, 'message' => 'You cannot delete your own account.']);
            return;
        }

        if (self::isLastActiveAdmin($db, (int)$user['id'])) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete the last active administrator.']);
            return;
        }

        // Soft deletion: the row stays, so that the files and folders of the
        // account keep their uploader. Credentials and memberships go, and
        // the address is replaced by a pseudonym derived from it.
        $pseudonym = AccessMiddleware::deletedPseudonym($user['email'] ?: $user['username'], (int)$user['id']);
        $db->beginTransaction();
        $stmt = $db->prepare("UPDATE users SET username = :pseudonym, email = :pseudonym, password = '!',
                                     invitation_token = NULL, token_expiry = NULL, session_version = session_version + 1,
                                     deleted_at = :now, deleted_by = :by
                              WHERE id = :id");
        $stmt->execute([':pseudonym' => $pseudonym, ':now' => gmdate('Y-m-d H:i:s'), ':by' => Session::getUserId(), ':id' => $user['id']]);
        $db->prepare("DELETE FROM user_group WHERE user_id = :id")->execute([':id' => $user['id']]);
        $db->commit();

        App::getLogger()->log('user_deleted', ['target_user_id' => (int)$user['id'], 'pseudonym' => $pseudonym]);
        echo json_encode(['success' => true]);
    }

    private static function addUserToGroup($config, PDO $db)
    {
        header('Content-Type: application/json');
    
        $email = trim($_POST['email'] ?? '');
        $groupName = trim($_POST['group'] ?? '');
    
        if (empty($email) || empty($groupName)) {
            echo json_encode(['success' => false, 'message' => 'Missing email or group.']);
            return;
        }
    
        // Lookup user by email or username
        $stmt = $db->prepare("SELECT id FROM users WHERE (email = :email OR username = :email) AND deleted_at IS NULL");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            return;
        }
    
        $userId = $user['id'];
    
        // Create group if it doesn't exist
        $stmt = $db->prepare("SELECT id FROM groups WHERE name = :name");
        $stmt->execute([':name' => $groupName]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$group) {
            $stmt = $db->prepare("INSERT INTO groups (name) VALUES (:name)");
            if (!$stmt->execute([':name' => $groupName])) {
                echo json_encode(['success' => false, 'message' => 'Failed to create group.']);
                return;
            }
    
            $groupId = $db->lastInsertId();
        } else {
            $groupId = $group['id'];
        }
    
        // Check for existing membership
        $stmt = $db->prepare("SELECT 1 FROM user_group WHERE user_id = :uid AND group_id = :gid");
        $stmt->execute([':uid' => $userId, ':gid' => $groupId]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'User is already in this group.']);
            return;
        }
    
        // Add membership
        $stmt = $db->prepare("INSERT INTO user_group (user_id, group_id) VALUES (:uid, :gid)");
        $success = $stmt->execute([':uid' => $userId, ':gid' => $groupId]);
    
        App::getLogger()->log('group_user_added', ['group'=>$groupName , 'target_user'=>$email]);

        echo json_encode(['success' => $success]);
    }

    private static function removeUserFromGroup($config, PDO $db)
    {
        header('Content-Type: application/json');
    
        $email = trim($_POST['email'] ?? '');
        $groupName = trim($_POST['group'] ?? '');
    
        if (empty($email) || empty($groupName)) {
            echo json_encode(['success' => false, 'message' => 'Missing email or group name.']);
            return;
        }
    
        // Lookup user
        $stmt = $db->prepare("SELECT id FROM users WHERE username = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            return;
        }
    
        // Lookup group
        $stmt = $db->prepare("SELECT id, is_admin FROM groups WHERE name = :name");
        $stmt->execute([':name' => $groupName]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$group) {
            echo json_encode(['success' => false, 'message' => 'Group not found.']);
            return;
        }

        if ($group['is_admin'] && self::isLastActiveAdmin($db, (int)$user['id'])) {
            echo json_encode(['success' => false, 'message' => 'Cannot remove the last active administrator.']);
            return;
        }

        // Remove user-group relationship
        $stmt = $db->prepare("DELETE FROM user_group WHERE user_id = :uid AND group_id = :gid");
        $success = $stmt->execute([
            ':uid' => $user['id'],
            ':gid' => $group['id']
        ]);
    
        if ($success) {
            App::getLogger()->log('group_user_removed', ['group'=>$groupName, 'target_user'=>$email]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to remove user from group.']);
        }
    }

    private static function lockUser($config, PDO $db)
    {
        header('Content-Type: application/json');

        $email = trim($_POST['email'] ?? '');

        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Missing email.']);
            return;
        }

        // Lookup user
        $stmt = $db->prepare("SELECT id, locked_at FROM users WHERE (email = :email OR username = :email) AND deleted_at IS NULL");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            return;
        }

        if ($user['locked_at'] !== null) {
            echo json_encode(['success' => false, 'message' => 'This account is already locked.']);
            return;
        }

        if ((int)$user['id'] === Session::getUserId()) {
            echo json_encode(['success' => false, 'message' => 'You cannot lock your own account.']);
            return;
        }

        if (self::isLastActiveAdmin($db, (int)$user['id'])) {
            echo json_encode(['success' => false, 'message' => 'Cannot lock the last active administrator.']);
            return;
        }

        // Credentials go (password and pending invitation) and open sessions
        // end; locked_at keeps the account from being re-invited until unlocked
        $stmt = $db->prepare("UPDATE users SET password = '!', invitation_token = NULL, token_expiry = NULL,
                                     session_version = session_version + 1, locked_at = :now, locked_by = :by
                              WHERE id = :id");
        $success = $stmt->execute([':now' => gmdate('Y-m-d H:i:s'), ':by' => Session::getUserId(), ':id' => $user['id']]);

        App::getLogger()->log('user_locked', ['locked_user'=>$email]);

        echo json_encode(['success' => $success]);
    }

    /**
     * Clear a lock. No credentials are issued: the account stays unusable
     * until a resend gives its owner a link to set a password.
     */
    private static function unlockUser($config, PDO $db)
    {
        header('Content-Type: application/json');
        $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');

        $stmt = $db->prepare("UPDATE users SET locked_at = NULL, locked_by = NULL
                              WHERE username = :u AND deleted_at IS NULL AND locked_at IS NOT NULL");
        $stmt->execute([':u' => $email]);
        if ($stmt->rowCount() !== 1) {
            echo json_encode(['success' => false, 'message' => 'No locked account with this address.']);
            return;
        }

        App::getLogger()->log('user_unlocked', ['target_user' => $email]);
        echo json_encode(['success' => true, 'message' => 'Account unlocked. Resend the invitation so that its owner can set a password.']);
    }

    /**
     * A new link to '/' for an existing account (DFM-08, D22); never touches
     * groups or rights. The link sets a new password (D24): this is also how
     * an administrator resets a forgotten one. The account's open sessions
     * end now. Refused for locked (unlock first) and deleted accounts.
     */
    private static function resendInvite($config, PDO $db)
    {
        header('Content-Type: application/json');
        $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');

        $stmt = $db->prepare("SELECT id, locked_at FROM users WHERE username = :u AND deleted_at IS NULL");
        $stmt->execute([':u' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            return;
        }
        if ($user['locked_at'] !== null) {
            echo json_encode(['success' => false, 'message' => 'This account is locked: unlock it first.']);
            return;
        }

        $db->prepare("UPDATE users SET session_version = session_version + 1 WHERE id = :id")->execute([':id' => $user['id']]);
        $delivery = self::deliverInvitation($config, $db, $email, '/');
        App::getLogger()->log('invitation_resent', [
            'target_user' => $email,
            'delivery' => $delivery['delivery'],
            'invitation_sent' => $delivery['error'] === null,
        ]);
        self::answerDelivery($delivery, 'Invitation resent',
            'The invitation e-mail could not be sent (details in the application log, mail_send_failure). The previous link '
            . 'no longer works and the open sessions of the account have ended. Check the mail configuration with '
            . 'bin/send-test-mail.php, then resend.');
    }

    private static function listGroupsWithDetails($config, PDO $db)
    {
        header('Content-Type: application/json');
    
        $stmt = $db->query("SELECT id, name FROM groups");
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
    
        foreach ($groups as $group) {
            // Get group members
            $stmtUsers = $db->prepare("
                SELECT u.username FROM users u
                JOIN user_group ug ON u.id = ug.user_id
                WHERE ug.group_id = :group_id
            ");
            $stmtUsers->execute([':group_id' => $group['id']]);
            $members = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);
    
            // Get directory access rights
            $stmtDirs = $db->prepare("
                SELECT d.path, ar.can_view, ar.can_write, ar.can_upload
                FROM access_rights ar
                JOIN directories d ON d.id = ar.directory_id
                WHERE ar.group_id = :group_id
            ");
            $stmtDirs->execute([':group_id' => $group['id']]);
            $dirs = $stmtDirs->fetchAll(PDO::FETCH_ASSOC);
    
            $result[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'members' => $members,
                'directories' => $dirs
            ];
        }
    
        echo json_encode($result);
    }

    private static function deleteGroup($config, PDO $db)
    {
        header('Content-Type: application/json');

        $groupName = trim($_POST['name'] ?? '');

        if (empty($groupName)) {
            echo json_encode(['success' => false, 'message' => 'Missing group name.']);
            return;
        }

        // Lookup group
        $stmt = $db->prepare("SELECT id, is_admin FROM groups WHERE name = :name");
        $stmt->execute([':name' => $groupName]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$group) {
            echo json_encode(['success' => false, 'message' => 'Group not found.']);
            return;
        }

        if ($group['is_admin']) {
            echo json_encode(['success' => false, 'message' => 'The administrator group cannot be deleted.']);
            return;
        }

        // Groups only grant access (D18): deleting one is final, and the
        // foreign keys remove its memberships and access rights with it
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM user_group WHERE group_id = :gid");
        $stmtCheck->execute([':gid' => $group['id']]);
        $members = (int)$stmtCheck->fetchColumn();

        $stmt = $db->prepare("DELETE FROM groups WHERE id = :id");
        $success = $stmt->execute([':id' => $group['id']]);

        App::getLogger()->log('group_deleted', ['group' => $groupName, 'members' => $members]);

        echo json_encode(['success' => $success]);
    }

    /**
     * True if $userId is an admin and no other active admin exists,
     * i.e. removing, locking or deleting them would leave nobody able to
     * administer the application.
     */
    private static function isLastActiveAdmin(PDO $db, int $userId): bool
    {
        return AccessMiddleware::isAdmin($db, $userId)
            && AccessMiddleware::countActiveAdmins($db, $userId) === 0;
    }

}
?>