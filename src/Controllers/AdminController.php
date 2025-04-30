<?php
// src/Controllers/AdminController.php
namespace Controllers;

use Middleware\AccessMiddleware;
use Core\Request;
use Backends\MailEngineFactory;
use Backends\StorageEngineFactory;
use Views\InvitationView;
use Views\AdminView;
use PDO;

class AdminController
{
    public static function handle($config, $db)
    {
        $action = isset($_POST['a']) ? trim($_POST['a']) ?? '' : '';
        if ($action === "createFolder") {
            self::createDirectory($config,$db);
        } elseif ($action === "deleteItem") {
            self::deleteItem($config,$db);
        } elseif ($action === "invite") {
            self::inviteUser($config,$db);
        } elseif ($action === "updatePassword") {
            self::updatePassword($config,$db);
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
            self::deleteUser($db);
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
            \Views\AdminView::render($users, $groups, $accessRights);
        }
    }

    public static function inviteUser($config, $db) {
        header('Content-Type: application/json');

        $email = trim($_POST['email']) ?? '';
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
            $mailEngine = MailEngineFactory::create($config);
            $mailEngine->send($email, 'You have been invited to contribute on ' . $baseUrl, $mailBody);
        }
    
        echo json_encode(['success' => true, 'message' => 'Contributor invited']);
        return;
    }

    public static function updatePassword($config, $db) {
        header('Content-Type: application/json');
    }

    public static function deleteItem($config,$db) {
        $itemId = intval($_POST['id']) ?? 0;
        $itemType = trim($_POST['type']) ?? '';
        $userId = $_SESSION['user_id'];
    
        header('Content-Type: application/json');
    
        // Validate input
        if (!$itemId || !in_array($itemType, ['f', 'd'])) {
            error_log("Invalid item type or ID: $itemType, $itemId");
            echo json_encode(['success' => false, 'message' => 'Invalid request parameters']);
            return;
        }


        if ($itemType === 'd') {
            $directoryInfo = AccessMiddleware::getDirectoryInfo($db,$itemId);
            if (!$directoryInfo) {
                echo json_encode(['success' => false, 'message' => 'Directory not found or access denied']);
                return;
            }

            // Check user access to parent directory
            $accessRights = AccessMiddleware::checkAccess($db, $directoryInfo['parent_path'], $userId);
            if (!$accessRights['can_write']) {
                echo json_encode(['success' => false, 'message' => 'Access denied to directory']);
                return;
            }

            // We don't delete the empty directories, as we don't create them either
            // Directories are only created when needed by files and removed when latest
            // file in directory is removed ("pruning") - if storage engine supports dir hierarchy

            // Ensure directory is empty
            if (($directoryInfo['subdirectory_count']+$directoryInfo['file_count'])>0) {
                echo json_encode(['success' => false, 'message' => 'Directory is not empty']);
                return;
            }
    
            // Remove database entry
            $stmt = $db->prepare("DELETE FROM directories WHERE id = :id");
            $stmt->bindParam(':id', $itemId, PDO::PARAM_INT);
            $stmt->execute();
    
            echo json_encode(['success' => true, 'message' => 'Directory deleted successfully']);
        } elseif ($itemType === 'f') {
            $fileInfo = AccessMiddleware::getFileInfo($db,$itemId);;
            if (!$fileInfo) {
                echo json_encode(['success' => false, 'message' => 'File not found or access denied']);
                return;
            }

            // Check user access to parent directory
            $accessRights = AccessMiddleware::checkAccess($db, $fileInfo['directory_path'], $userId);
            if (!$accessRights['can_write']) {
                echo json_encode(['success' => false, 'message' => 'Access denied to parent directory']);
                return;
            }
            
            $storageEngine = StorageEngineFactory::create($config);
            if (!$storageEngine->healthCheck()) {
                Response::triggerSystemError();
                return;
            }

            // Delete the file
            $path = rtrim($fileInfo['directory_path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileInfo['name'];
            if (!$storageEngine->remove($path)) {
                error_log("Failed to delete file: $filePath");
                echo json_encode(['success' => false, 'message' => 'Failed to delete file from storage']);
                return;
            }
    
            // Remove database entry
            $stmt = $db->prepare("DELETE FROM files WHERE id = :id");
            $stmt->bindParam(':id', $itemId, PDO::PARAM_INT);
            $stmt->execute();
    
            echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
        }
    }
        
    public static function createDirectory($config,$db) {
        header('Content-Type: application/json');

        $cwd = trim($_POST['cwd']) ?? '';
        $cwd = $cwd === '' ? '/' : $cwd;
        $folderName = trim($_POST['name']) ?? ''; // Supposed to be a name, not a path
        $userId = $_SESSION['user_id'];
        $remoteIp = Request::getClientIp();

        if (empty($folderName)) {
            echo json_encode(['success' => false, 'message' => 'Missing folder name.']);
            return;
        }

        // Construct new Dir Path (cwd + folder name)
        $newDirPath = (rtrim($cwd, '/') === '' ? '' : rtrim($cwd, '/')) . '/' . ltrim($folderName, '/');

        // Ensure folder doesn't already exist
        $existing = $db->prepare("SELECT id FROM directories WHERE path = :path");
        $existing->execute([':path' => $newDirPath]);
        if ($existing->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Folder already exists.']);
            return;
        }

        // Validate name: must be a valid directory name
        if (
            !mb_check_encoding($folderName, 'UTF-8') ||
            preg_match('/[<>\/\\\\&]/u', $folderName) ||
            preg_match('/[\x00-\x1F\x7F]/', $folderName)
        ) {
            error_log("Invalid directory name: $folderName");
            echo json_encode(['success' => false, 'message' => 'Invalid directory name']);
            return;
        }

        // Check user access to cwd
        $accessRights = AccessMiddleware::checkAccess($db, $cwd, $userId);
        if (!$accessRights['can_write']) {
            echo json_encode(['success' => false, 'message' => 'Access denied to directory']);
            return;
        }

        // Get parent directory Id
        $stmt = $db->prepare("SELECT id FROM directories WHERE path = :cwd");
        $stmt->execute([':cwd' => $cwd]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        $parentDirectoryId = $parent ? (int)$parent['id'] : null;

        // Get Username
        $user = AccessMiddleware::getUserInfo($db, $userId);
        $userName = $user['email'] ?? 'unknown';

        // Create database entry
        $utcTimestamp = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare("
            INSERT INTO directories
                (name, path, parent_id, created_at, created_by, created_from)
            VALUES
                (:name, :path, :parent_id, :created_at, :created_by, :created_from)");
        $stmt->execute([
            ':name' => $folderName,
            ':path' => $newDirPath,
            ':parent_id' => $parentDirectoryId,
            ':created_at' => $utcTimestamp,
            ':created_by' => $userId,
            ':created_from' => $remoteIp
        ]);
        $folderId = $db->lastInsertId();

        // Directory created successfully
        echo json_encode([
            'success' => true,
            'message' => 'Directory created successfully',
            'folder' => [
                'id' => $folderId,
                'name' => $folderName,
                'created_at' => $createdAt,
                'created_by' => $user['username'] ?? $user['email'] ?? 'unknown',
                'created_from' => $remoteIp
            ]
        ]);
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
    
            echo json_encode(['success' => true]);
    
        } catch (\PDOException $e) {
            error_log("removeAccessRight error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
    }

    private static function listUsers($config, PDO $db)
    {
        header('Content-Type: application/json');

        $stmt = $db->query("SELECT id, username, `password`, invitation_token, token_expiry FROM users");
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
        $stmt = $db->prepare("SELECT id FROM users WHERE email = :email OR username = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            return;
        }

        if ($user['id']===1) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete the primary admin user.']);
            return;
        }

        $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
        $success = $stmt->execute([':id' => $user['id']]);

        if ($success) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete user.']);
        }
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
        $stmt = $db->prepare("SELECT id FROM users WHERE email = :email OR username = :email");
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
        $stmt = $db->prepare("SELECT id FROM users WHERE email = :email OR username = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            return;
        }
    
        // Lookup group
        $stmt = $db->prepare("SELECT id FROM groups WHERE name = :name");
        $stmt->execute([':name' => $groupName]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$group) {
            echo json_encode(['success' => false, 'message' => 'Group not found.']);
            return;
        }
    
        // Remove user-group relationship
        $stmt = $db->prepare("DELETE FROM user_group WHERE user_id = :uid AND group_id = :gid");
        $success = $stmt->execute([
            ':uid' => $user['id'],
            ':gid' => $group['id']
        ]);
    
        if ($success) {
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
        $stmt = $db->prepare("SELECT id FROM users WHERE email = :email OR username = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            return;
        }

        // Lock the user
        $stmt = $db->prepare("UPDATE users SET password = '!', token_expiry = 0 WHERE id = :id");
        $success = $stmt->execute([':id' => $user['id']]);

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
        $stmt = $db->prepare("SELECT id FROM groups WHERE name = :name");
        $stmt->execute([':name' => $groupName]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$group) {
            echo json_encode(['success' => false, 'message' => 'Group not found.']);
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

        echo json_encode(['success' => $success]);
    }

}
?>