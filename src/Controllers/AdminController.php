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
        } elseif ($action === "resendInvite") {
            self::resendInvite($config, $db);
        } elseif ($action === "listGroupsWithDetails") {
            self::listGroupsWithDetails($config, $db);
        } elseif ($action === "deleteGroup") {
            self::deleteGroup($config, $db);
        } else {
            // Render the admin view
            $users=[]; $groups=[]; $accessRights=[];
            AdminView::render($users, $groups, $accessRights);
        }
    }

    public static function inviteUser($config, $db) {
        header('Content-Type: application/json');

        $email = trim((string)($_POST['email'] ?? ''));
        $cwd = trim($_POST['cwd'] ?? '/');
        $userId = $_SESSION['user_id'];
        $sendLink = isset($_POST['sendLink']) && $_POST['sendLink'] === 'true';
        $accessRights = $_POST['accessRights'] ?? [];
    
        // Validate email syntax
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email address']);
            return;
        }
    
        // Validate cwd (should exist)
        $directoryInfo = AccessMiddleware::getDirectoryInfo($db, $cwd);
        if (!$directoryInfo) {
            echo json_encode(['success' => false, 'message' => 'Invalid target directory']);
            return;
        }
        $directoryId = $directoryInfo['id'];

        // Get User ID - create if user not found
        $invitedUserInfo = AccessMiddleware::getUserInfo($db, $email, true);
        if (!$invitedUserInfo) {
            echo json_encode(['success' => false, 'message' => 'Could not create user']);
            return;
        }
        $invitedUserId = $invitedUserInfo['id'];

        // Find or create a group for the user's domain
        $domain = substr(strrchr($email, "@"), 1);
        $groupName = $domain . ' users';

        $stmt = $db->prepare("INSERT OR IGNORE INTO groups (name) VALUES (:name)");
        $stmt->execute([':name' => $groupName]);

        $stmt = $db->prepare("SELECT id FROM groups WHERE name = :name");
        $stmt->execute([':name' => $groupName]);
        $groupID = $stmt->fetchColumn();

        if (!$groupID) {
            echo json_encode(['success' => false, 'message' => 'Could not find or create group']);
            return;
        }

        // Add user to that group
        $stmt = $db->prepare("
            INSERT OR IGNORE INTO user_group (user_id, group_id) VALUES (:user_id, :group_id)
        ");
        $stmt->execute([
            ':user_id' => $invitedUserId,
            ':group_id' => $groupID
        ]);

        // Grant access to group on directory
        $stmt = $db->prepare("
            INSERT OR IGNORE INTO access_rights (directory_id, group_id) VALUES (:dir_id, :group_id)
        ");
        $stmt->execute([
            ':dir_id' => $directoryId,
            ':group_id' => $groupID
        ]);
        $stmt = $db->prepare("
            UPDATE access_rights SET can_view=:read , can_write=:write , can_upload=:upload
            WHERE directory_id=:dir_id AND group_id=:group_id
        ");
        $stmt->execute([
            ':read' => in_array('read', $accessRights) ? 1 : 0,
            ':write' => in_array('write', $accessRights) ? 1 : 0,
            ':upload' => in_array('upload', $accessRights) ? 1 : 0,
            ':dir_id' => $directoryId,
            ':group_id' => $groupID
        ]);

        if ($sendLink) {
            // Generate invitation token
            $invitationToken = AccessMiddleware::generateTokenForUser($db, $email);
            if (!$invitationToken) {
                echo json_encode(['success' => false, 'message' => 'Could not generate invitation token']);
                return;
            }
    
            // Format E-mail
            $baseUrl = Request::getBaseUrl();
            $mailBody = InvitationView::render($email, $invitationToken, $cwd, $baseUrl);
    
            // Send email
            $mailError = null;
            try {
                $mailEngine = MailEngineFactory::create($config);
                if (!$mailEngine->send($email, 'You have been invited to contribute on ' . $baseUrl, $mailBody)) {
                    $mailError = $mailEngine->lastError() ?? 'unknown error';
                }
            } catch (\Exception $e) {
                $mailError = $e->getMessage();
            }

            App::getLogger()->log('user_invited', ['invited_user' => $email, 'invitation_sent' => $mailError === null]);

            if ($mailError !== null) {
                // Details (SMTP replies, configuration problems) go to the log only
                App::getLogger()->log('mail_send_failure', [
                    'recipient' => $email,
                    'mail_engine' => $config['mail_engine'] ?? 'mail',
                    'error' => $mailError,
                ]);
                echo json_encode(['success' => false, 'message' => 'Contributor added, but the invitation e-mail could not be sent. See the application log.']);
                return;
            }

            echo json_encode(['success' => true, 'message' => 'Invitation sent']);
            return;
        }

        App::getLogger()->log('user_invited', ['invited_user' => $email, 'invitation_sent' => false]);

        echo json_encode(['success' => true, 'message' => 'Contributor invited']);
        return;
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

        $stmt = $db->query("SELECT id, username, `password`, invitation_token, token_expiry FROM users WHERE deleted_at IS NULL");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];

        foreach ($users as $user) {
            $identifier = $user['username'];

            // Determine active vs locked
            $hasPassword = !empty($user['password']) && $user['password'] !== '!';
            $hasValidToken = false;

            if (!empty($user['invitation_token']) && !empty($user['token_expiry'])) {
                $expiry = strtotime($user['token_expiry']);
                $now = time();
                if ($expiry > $now) {
                    $hasValidToken = true;
                }
            }

            $status = ($hasPassword || $hasValidToken) ? 'active' : 'locked';

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
                                     invitation_token = NULL, token_expiry = NULL,
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
        $stmt = $db->prepare("SELECT id FROM users WHERE (email = :email OR username = :email) AND deleted_at IS NULL");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
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

        // Lock the user
        $stmt = $db->prepare("UPDATE users SET password = '!', token_expiry = 0 WHERE id = :id");
        $success = $stmt->execute([':id' => $user['id']]);

        App::getLogger()->log('user_locked', ['locked_user'=>$email]);

        echo json_encode(['success' => $success]);
    }

    private static function resendInvite($config, PDO $db)
    {
        $_POST['sendLink'] = 'true';
        self::inviteUser($config, $db);
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

        // Optional: Warn if it has members
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM user_group WHERE group_id = :gid");
        $stmtCheck->execute([':gid' => $group['id']]);
        $count = $stmtCheck->fetchColumn();

        if ($count > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete group with members. Remove users first.']);
            return;
        }

        // Proceed to delete (will cascade in access_rights, user_group)
        $stmt = $db->prepare("DELETE FROM groups WHERE id = :id");
        $success = $stmt->execute([':id' => $group['id']]);

        App::getLogger()->log('group_deleted', ['group'=>$groupName]);

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