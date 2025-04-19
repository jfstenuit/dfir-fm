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
        } else {
            // Render the admin view
            $users=[]; $groups=[]; $accessRights=[];
            \Views\AdminView::render($users, $groups, $accessRights);
        }
    }

    public static function inviteUser($config, $db) {
        $email = trim($_POST['email']) ?? '';
        $directory = trim($_POST['cwd']) ?? '';
        $userId = $_SESSION['user_id'];
        $accessRights = $_POST['accessRights'] ?? [];
        $sendLink = isset($_POST['sendLink']) && $_POST['sendLink'] === 'true';
    
        header('Content-Type: application/json');
    
        // Check access rights to the directory
        $AccessRights = AccessMiddleware::checkAccess($db, $directory, $userId);
        if (!$AccessRights['is_admin']) {
            echo json_encode(['success' => false, 'message' => 'Access denied to directory']);
            return;
        }
    
        // Validate email syntax
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email address']);
            return;
        }
    
        // Get User ID - create if user not found
        $invitedUserInfo = AccessMiddleware::getUserInfo($db, $email, true);
        if (!$invitedUserInfo) {
            echo json_encode(['success' => false, 'message' => 'Could not create user']);
            return;
        }
    
        // Get group ID based on access rights - create if not found
        $groupId = AccessMiddleware::getContributorGroupId($db, $directory, $accessRights);
        if (!$groupId) {
            echo json_encode(['success' => false, 'message' => 'Could not get contributor group']);
            return;
        }
    
        // Add User to Group
        AccessMiddleware::addUserToGroup($db, $invitedUserInfo['id'], $groupId);
    
        if ($sendLink) {
            // Generate invitation token
            $invitationToken = AccessMiddleware::generateTokenForUser($db, $email);
            if (!$invitationToken) {
                echo json_encode(['success' => false, 'message' => 'Could not generate invitation token']);
                return;
            }
    
            // Format E-mail
            $baseUrl = Request::getBaseUrl();
            $mailBody = InvitationView::render($email, $invitationToken, $directory, $baseUrl);
    
            // Send email
            $mailEngine = MailEngineFactory::create($config);
            $mailEngine->send($email, 'Invitation to contribute on ' . $baseUrl, $mailBody);
        }
    
        echo json_encode(['success' => true, 'message' => 'Contributor invited']);
        return;
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

            // We don't handle the case of directory missing from filesystem
            // The abstracted filesystem logic is responsible for pruning empty directories
    
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
            if (!$storageEngine->remove($fileInfo['directory_path'] . DIRECTORY_SEPARATOR . $fileInfo['name'])) {
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
        $cwd = trim($_POST['cwd']) ?? '';
        $cwd = $cwd === '' ? '/' : $cwd;
        $requestedDir = trim($_POST['name']) ?? '';
        $userId = $_SESSION['user_id'];

        header('Content-Type: application/json');

        // Validate Current Working Directory
        $baseStorageDir = realpath(__DIR__ . '/../../storage/files');
        $realCwdPath = realpath($baseStorageDir . DIRECTORY_SEPARATOR . ltrim($cwd, '/'));

        // Check if the resolved path is within the base directory
        if ($realCwdPath === false || strpos($realCwdPath, $baseStorageDir) !== 0) {
            // Invalid path or directory traversal attempt
            error_log("Invalid cwd: potential directory traversal detected");
            echo json_encode(['success' => false, 'message' => 'Invalid current working directory']);
            return;
        }

        // Validate name: must be a valid directory name
        /*
        if (!preg_match('/^[^<>:"/\\\\|?*\x00-\x1F]+$/', $requestedDir)) {
            error_log("Invalid directory name: $requestedDir");
            echo json_encode(['success' => false, 'message' => 'Invalid directory name']);
            return;
        }
        */

        // Check user access to cwd
        $accessRights = AccessMiddleware::checkAccess($db, $cwd, $userId);
        if (!$accessRights['can_write']) {
            echo json_encode(['success' => false, 'message' => 'Access denied to directory']);
            return;
        }

        $directoryId = $result['directory_id'];
        $groupId = $result['group_id'];

        // Check if directory already exist
        $directoryInfo = AccessMiddleware::getDirectoryInfo($db, $cwd . DIRECTORY_SEPARATOR . $requestedDir);
        if ($directoryInfo) {
            // Directory already exist
            echo json_encode(['success' => false, 'message' => 'Directory already exists']);
            return;
        }

        // Attempt to create the directory
        $newDirPath = $cwd . DIRECTORY_SEPARATOR . $requestedDir;
        $realDirPath = $realCwdPath . DIRECTORY_SEPARATOR . $requestedDir;
        if (!mkdir($realDirPath, 0755)) {
            error_log("Failed to create directory: $realDirPath");
            echo json_encode(['success' => false, 'message' => 'Failed to create directory']);
            return;
        }

        // Create database entry
        $utcTimestamp = gmdate('Y-m-d H:i:s');
        $remoteIp = Request::getClientIp();
        $stmt = $db->prepare("
        INSERT INTO directories
            (name, path, parent_id, created_at, created_by, created_from)
        VALUES
            (:name, :path, :parent_id, :created_at, :created_by, :created_from)");
        $stmt->bindParam(':name', $requestedDir, PDO::PARAM_STR);
        $stmt->bindParam(':path', $newDirPath, PDO::PARAM_STR);
        $stmt->bindParam(':parent_id', $directoryId, PDO::PARAM_INT);
        $stmt->bindParam(':created_at', $utcTimestamp, PDO::PARAM_STR);
        $stmt->bindParam(':created_by', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':created_from', $remoteIp, PDO::PARAM_STR);
        $stmt->execute();

        // Directory created successfully
        echo json_encode(['success' => true, 'message' => 'Directory created successfully']);
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
        
}
?>