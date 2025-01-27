<?php
// src/Controllers/UploadController.php
namespace Controllers;

use Core\Session;
use Core\Database;
use Core\Request;
use Core\Response;
use Middleware\AccessMiddleware;
use PDO;

class UploadController
{

    public static function handle()
    {
        // Fetch user-specific data from the database (e.g., files)
        $db = Database::initialize(STORAGE_PATH . '/database/app.sqlite');
        $userId = $_SESSION['user_id'];

        // Get the requested path from the query parameter `p`
        $currentDirectory = isset($_POST['cwd']) ? trim($_POST['cwd']) ?? '/' : '/';
        $action = isset($_POST['a']) ? $_POST['a'] : null;

        // Verify user has access to the current directory
        $stmt = $db->prepare("
SELECT
    d.id AS directory_id,
    COALESCE(ar.group_id, 1) AS group_id -- Use admin group ID (1) if group_id is NULL
FROM
    directories d
LEFT JOIN
    access_rights ar ON ar.directory_id = d.id
LEFT JOIN
    user_group ug ON ug.group_id = ar.group_id
WHERE
    d.path = :directory_name
    AND (
        1 IN (SELECT group_id FROM user_group WHERE user_id = :user_id) -- Check if user is in admin group
        OR (
            ar.group_id IN (SELECT group_id FROM user_group WHERE user_id = :user_id)
            AND ( ar.can_write = TRUE OR ar.can_upload ) -- Non-admin group access
        )
    )");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':directory_name', $currentDirectory, PDO::PARAM_STR);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $hasPermission=false;
        if ($result) {
            $hasPermission=true;
        }

        if ($action === 'checkRights') {
            header('Content-Type: application/json');
            if ($hasPermission) {
                echo json_encode(['status' => 'success', 'message' => 'Authorized to upload in this directory', 'hasPermission' => true]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Not authorized or directory does not exist', 'hasPermission' => false]);
            }
            return;
        }
        
        $directoryId = $result['directory_id'];
        $groupId = $result['group_id'];

        $baseStorageDir = realpath(__DIR__ . '/../../storage/files');
        $requestedPath = ltrim($currentDirectory, '/');
        $realStorageDir = realpath($baseStorageDir . DIRECTORY_SEPARATOR . $requestedPath);

        if (!is_dir($realStorageDir)) {
            Response::triggerSystemError();
            return;
        }

        header('Content-Type: application/json');

        // Get the file details
        $file = $_FILES['file'];
        $chunkIndex = isset($_POST['dzuuid']) ? $_POST['dzchunkindex'] : null;
        $chunkCount = isset($_POST['dztotalchunkcount']) ? $_POST['dztotalchunkcount'] : null;
        $uuid = isset($_POST['dzuuid']) ? $_POST['dzuuid'] : uniqid();
        $originalFileName = $file['name'];
        $fileSize = isset($_POST['dztotalfilesize']) ? $_POST['dztotalfilesize'] : null;
        $chunkFile = $realStorageDir . DIRECTORY_SEPARATOR . '.' . $uuid . '-' . $chunkIndex;
        if (move_uploaded_file($file['tmp_name'], $chunkFile)) {
            // If all chunks are uploaded, merge them
            if ($chunkIndex == $chunkCount - 1) {
                $targetFile = $realStorageDir . DIRECTORY_SEPARATOR . $originalFileName;
                $hashContext = hash_init('sha256');
                $outFile = fopen($targetFile, 'wb');
                for ($i = 0; $i < $chunkCount; $i++) {
                    $chunkPath = $realStorageDir . DIRECTORY_SEPARATOR . '.' . $uuid . '-' . $i;
                    if (file_exists($chunkPath)) {
                        $inFile = fopen($chunkPath, 'rb');
                        while ($buffer = fread($inFile, 8192)) {
                            fwrite($outFile, $buffer);
                            hash_update($hashContext, $buffer);
                        }
                        fclose($inFile);
                        unlink($chunkPath);
                    } else {
                        fclose($outFile);
                        echo json_encode(['status' => 'error', 'message' => "Missing chunk: $i"]);
                        return;
                    }
                }
                fclose($outFile);
                $checksum = hash_final($hashContext);
                $fileSize = filesize($targetFile);
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $targetFile);
                finfo_close($finfo);
                $utcTimestamp = gmdate('Y-m-d H:i:s');
                $remoteIp = Request::getClientIp();

                $stmt = $db->prepare("
                INSERT INTO files
                    (directory_id, name, uploaded_at, uploaded_by, uploaded_from, size, sha256)
                VALUES
                    (:directory_id, :name, :uploaded_at, :uploaded_by, :uploaded_from, :size, :sha256)");
                $stmt->bindParam(':directory_id', $directoryId, PDO::PARAM_INT);
                $stmt->bindParam(':name', $originalFileName, PDO::PARAM_STR);
                $stmt->bindParam(':uploaded_at', $utcTimestamp, PDO::PARAM_STR);
                $stmt->bindParam(':uploaded_by', $userId, PDO::PARAM_INT);
                $stmt->bindParam(':uploaded_from', $remoteIp, PDO::PARAM_STR);
                $stmt->bindParam(':size', $fileSize, PDO::PARAM_INT);
                $stmt->bindParam(':sha256', $checksum, PDO::PARAM_STR);
                $stmt->execute();
                
                $fileId = $db->lastInsertId();
                $userInfo = AccessMiddleware::getUserInfo($db, $userId);
                echo json_encode(['status' => 'success', 'message' => 'File uploaded successfully.',
                    'file' => [ 'Name' => $originalFileName, 'Size' => $fileSize, 'SHA256' => $checksum,
                                'Created' => $utcTimestamp, 'Uploaded by / from' => $userInfo['email'].'<br>'.$remoteIp ]
                ]);
            } else {
                echo json_encode(['status' => 'progress', 'message' => 'Chunk uploaded.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to upload chunk.']);
        }
    }
}
?>