<?php
// src/Controllers/FileManagerController.php
namespace Controllers;

use Core\Session;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\App;
use Middleware\AccessMiddleware;
use Backends\StorageEngineFactory;
use Views\FileManagerView;

use PDO;

class FileManagerController
{
    public static function handle($config,$db)
    {
        // File operations available to non-admin users; each one checks
        // the caller's rights on the target directory itself.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = trim($_POST['a'] ?? '');
            if ($action === 'createFolder') {
                self::createDirectory($config, $db);
            } elseif ($action === 'deleteItem') {
                self::deleteItem($config, $db);
            } else {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unknown action.']);
            }
            return;
        }

        $userId = $_SESSION['user_id'];

        // Get the requested path from the query parameter `p`
        $currentPath = isset($_GET['p']) ? trim($_GET['p']) : '';
        $currentPath = $currentPath === '' ? '/' : $currentPath;

        // Check that the directory exist
        $directoryInfo = AccessMiddleware::getDirectoryInfo($db, $currentPath);
        if (!$directoryInfo) {
            App::getLogger()->log('filemanager_failure', ['cause'=>'Tried to access invalid directory','directory'=>$currentPath]);
            Response::triggerNotFound();
            return;
        }

        // Access rights for the directory and directory items are
        // handled in getDirectoryItems
        $items = AccessMiddleware::getDirectoryItems($db, $userId, $directoryInfo);

        $accessRights = AccessMiddleware::checkAccess($db, $currentPath, $userId);
        // Render the file manager view
        FileManagerView::render($items, $currentPath, $accessRights);
    }

    public static function deleteItem($config,$db) {
        $itemId = intval($_POST['id'] ?? 0);
        $itemType = trim($_POST['type'] ?? '');
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

            // The foreign keys delete the folder's upload records: refuse
            // while one is active, its staging file would be left behind
            $active = $db->prepare("SELECT COUNT(*) FROM uploads WHERE directory_id = :id AND status IN ('in_progress', 'verifying')");
            $active->execute([':id' => $itemId]);
            if ($active->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'An upload into this directory is in progress']);
                return;
            }

            // Remove database entry (its access rights and upload records go with it)
            $stmt = $db->prepare("DELETE FROM directories WHERE id = :id");
            $stmt->bindParam(':id', $itemId, PDO::PARAM_INT);
            $stmt->execute();

            App::getLogger()->log('directory_deleted', ['directory'=>$directoryInfo['path']]);

            echo json_encode(['success' => true, 'message' => 'Directory deleted successfully']);
        } elseif ($itemType === 'f') {
            $fileInfo = AccessMiddleware::getFileInfo($db,$itemId);
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
                error_log("Failed to delete file: $path");
                echo json_encode(['success' => false, 'message' => 'Failed to delete file from storage']);
                return;
            }

            // Remove database entry
            $stmt = $db->prepare("DELETE FROM files WHERE id = :id");
            $stmt->bindParam(':id', $itemId, PDO::PARAM_INT);
            $stmt->execute();

            App::getLogger()->log('file_deleted', ['file'=>$path]);

            echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
        }
    }

    public static function createDirectory($config,$db) {
        header('Content-Type: application/json');

        $cwd = trim($_POST['cwd'] ?? '');
        $cwd = $cwd === '' ? '/' : $cwd;
        $folderName = trim($_POST['name'] ?? ''); // Supposed to be a name, not a path
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

        // Validate name: must be a valid directory name. No leading dot:
        // '.', '..', and the storage's staging directory '.uploads'
        if (
            !mb_check_encoding($folderName, 'UTF-8') ||
            preg_match('/[<>\/\\\\&]/u', $folderName) ||
            preg_match('/[\x00-\x1F\x7F]/', $folderName) ||
            str_starts_with($folderName, '.')
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

        // Files and folders share the storage namespace
        $file = $db->prepare("SELECT 1 FROM files WHERE directory_id = :dir AND name = :name");
        $file->execute([':dir' => $parentDirectoryId, ':name' => $folderName]);
        if ($file->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'A file with this name already exists.']);
            return;
        }

        // Get Username
        $user = AccessMiddleware::getUserInfo($db, $userId);

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

        App::getLogger()->log('directory_created', ['directory'=>$newDirPath]);

        // Directory created successfully
        echo json_encode([
            'success' => true,
            'message' => 'Directory created successfully',
            'folder' => [
                'id' => $folderId,
                'name' => $folderName,
                'created_at' => $utcTimestamp,
                'created_by' => $user['username'] ?? $user['email'] ?? 'unknown',
                'created_from' => $remoteIp
            ]
        ]);
    }
}
?>