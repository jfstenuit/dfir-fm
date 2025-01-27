<?php
// src/Controllers/AdminController.php
namespace Controllers;

use Middleware\AccessMiddleware;
use Core\Request;
use Backends\MailEngineFactory;
use Views\InvitationView;
use PDO;

class AdminController
{
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
    
    public static function deleteItem($db) {
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
    
        // Base storage directory
        $baseStorageDir = realpath(__DIR__ . '/../../storage/files');
        if ($baseStorageDir === false) {
            error_log("Base storage directory does not exist");
            echo json_encode(['success' => false, 'message' => 'Internal server error']);
            return;
        }

        if ($itemType === 'd') {
            // Get directory details, including parent directory's path
            $stmt = $db->prepare("
                SELECT d.path AS path, p.path AS parent_path
                FROM directories d
                LEFT JOIN directories p ON d.parent_id = p.id
                WHERE d.id = :id
            ");
            $stmt->bindParam(':id', $itemId, PDO::PARAM_INT);
            $stmt->execute();
            $directory = $stmt->fetch(PDO::FETCH_ASSOC);
    
            if (!$directory) {
                echo json_encode(['success' => false, 'message' => 'Directory not found or access denied']);
                return;
            }

            // Check user access to parent directory
            $accessRights = AccessMiddleware::checkAccess($db, $directory['parent_path'], $userId);
            if (!$accessRights['can_write']) {
                echo json_encode(['success' => false, 'message' => 'Access denied to directory']);
                return;
            }

            $dirPath = realpath($baseStorageDir . DIRECTORY_SEPARATOR . ltrim($directory['path'], '/'));
            if ($dirPath === false || strpos($dirPath, $baseStorageDir) !== 0) {
                // Directory missing from the filesystem, clean up the database record
                error_log("Directory missing from filesystem: " . $directory['path']);
                $stmt = $db->prepare("DELETE FROM directories WHERE id = :id");
                $stmt->bindParam(':id', $itemId, PDO::PARAM_INT);
                $stmt->execute();
        
                echo json_encode(['success' => true, 'message' => 'Directory deleted from database (missing from filesystem)']);
                return;
            }
    
            // Ensure directory is empty
            if (count(scandir($dirPath)) > 2) { // '.' and '..' are always present
                echo json_encode(['success' => false, 'message' => 'Directory is not empty']);
                return;
            }
    
            // Delete the directory
            if (!rmdir($dirPath)) {
                error_log("Failed to delete directory: $dirPath");
                echo json_encode(['success' => false, 'message' => 'Failed to delete directory']);
                return;
            }
    
            // Remove database entry
            $stmt = $db->prepare("DELETE FROM directories WHERE id = :id");
            $stmt->bindParam(':id', $itemId, PDO::PARAM_INT);
            $stmt->execute();
    
            echo json_encode(['success' => true, 'message' => 'Directory deleted successfully']);
        } elseif ($itemType === 'f') {
            // Get file details
            $stmt = $db->prepare("
                SELECT f.name, d.path AS directory_path
                FROM files f
                JOIN directories d ON f.directory_id = d.id
                WHERE f.id = :id
            ");
            $stmt->bindParam(':id', $itemId, PDO::PARAM_INT);
            $stmt->execute();
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
            if (!$file) {
                echo json_encode(['success' => false, 'message' => 'File not found or access denied']);
                return;
            }

            // Check user access to parent directory
            $accessRights = AccessMiddleware::checkAccess($db, $file['directory_path'], $userId);
            if (!$accessRights['can_write']) {
                echo json_encode(['success' => false, 'message' => 'Access denied to parent directory']);
                return;
            }
            
            $filePath = realpath($baseStorageDir . DIRECTORY_SEPARATOR . ltrim($file['directory_path'], '/') . DIRECTORY_SEPARATOR . $file['name']);
            if ($filePath === false || strpos($filePath, $baseStorageDir) !== 0) {
                // Directory missing from the filesystem, clean up the database record
                error_log("File missing from filesystem: " . $file['name']);
                $stmt = $db->prepare("DELETE FROM files WHERE id = :id");
                $stmt->bindParam(':id', $itemId, PDO::PARAM_INT);
                $stmt->execute();
        
                echo json_encode(['success' => true, 'message' => 'File deleted from database (missing from filesystem)']);
                return;
            }

            // Delete the file
            if (!unlink($filePath)) {
                error_log("Failed to delete file: $filePath");
                echo json_encode(['success' => false, 'message' => 'Failed to delete file']);
                return;
            }
    
            // Remove database entry
            $stmt = $db->prepare("DELETE FROM files WHERE id = :id");
            $stmt->bindParam(':id', $itemId, PDO::PARAM_INT);
            $stmt->execute();
    
            echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
        }
    }
        
    public static function createDirectory($db) {
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
        $result = AccessMiddleware::getDirectoryInfo($db, $cwd, $requestedDir);
        if ($result) {
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

    public static function handle($config, $db)
    {
        $action = trim($_POST['a']) ?? '';
        if ($action === "createFolder") {
            self::createDirectory($db);
        } elseif ($action === "deleteItem") {
            self::deleteItem($db);
        } elseif ($action === "invite") {
            self::inviteUser($config,$db);
        }

    }
}
?>