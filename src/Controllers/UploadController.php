<?php
// src/Controllers/UploadController.php
namespace Controllers;

use Core\Session;
use Core\Database;
use Core\Request;
use Core\Response;
use Backends\StorageEngineFactory;
use Middleware\AccessMiddleware;
use PDO;

class UploadController
{

    public static function handle($config,$db)
    {
        header('Content-Type: application/json');

        $userId = $_SESSION['user_id'];

        // Get the requested path from the query parameter `p`
        $currentDirectory = !empty($_POST['cwd']) ? trim($_POST['cwd']) : '/';
        $action = isset($_POST['a']) ? $_POST['a'] : null;

        // Check that the current directory exist
        $directoryInfo = AccessMiddleware::getDirectoryInfo($db, $currentDirectory);
        if (!$directoryInfo) {
            error_log("Directory \"$currentDirectory\" does not exist in DB");
            echo json_encode(['status' => 'error', 'message' => 'Directory does not exist', 'hasPermission' => false]);
            return;
        }

        // Verify user has access to the current directory
        $accessRights = AccessMiddleware::checkAccess($db, $currentDirectory, $userId);
        if (! ( $accessRights['can_write'] || $accessRights['can_upload'])) {
            // TODO: logging "Access denied"
            error_log('Access denied for upload : '.print_r($accessRights,true));
            echo json_encode(['status' => 'error', 'message' => 'Not authorized', 'hasPermission' => false]);
            return;
        }
        
        if ($action === 'checkRights') {
            echo json_encode(['status' => 'success', 'message' => 'Authorized to upload in this directory', 'hasPermission' => true]);
            return;
        }

        $directoryId = $directoryInfo['id'];

        $storageEngine = StorageEngineFactory::create($config);
        if (!$storageEngine->healthCheck()) {
            Response::triggerSystemError();
            return;
        }

        // Get the file details
        $file = $_FILES['file'];
        $chunkIndex = isset($_POST['dzchunkindex']) ? (int)$_POST['dzchunkindex'] : null;
        $chunkCount = isset($_POST['dztotalchunkcount']) ? (int)$_POST['dztotalchunkcount'] : null;
        $uuid = isset($_POST['dzuuid']) ? $_POST['dzuuid'] : uniqid();
        $originalFileName = $file['name'];
        $fileSize = isset($_POST['dztotalfilesize']) ? (int)$_POST['dztotalfilesize'] : null;

        // Validate fields
        if (!$uuid || $chunkIndex === null || $chunkCount === null || !$fileSize) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid upload parameters.']);
            return;
        }

        // Handle upload state from DB
        $stmt = $db->prepare("SELECT * FROM uploads WHERE uuid = :uuid");
        $stmt->bindParam(':uuid', $uuid, PDO::PARAM_STR);
        $stmt->execute();
        $upload = $stmt->fetch(PDO::FETCH_ASSOC);


        $hashContext = null;
        if (!$upload) {
            // This is a new upload - create entry in DB, create storage
            $storagePath = $currentDirectory . DIRECTORY_SEPARATOR . $originalFileName;
            $stmt = $db->prepare("
                INSERT INTO uploads (uuid, file_name, file_size, total_chunks, last_chunk_index, hash_state, storage_path, status, user_id)
                VALUES (:uuid, :file_name, :file_size, :total_chunks, 0, '', :storage_path, 'in_progress', :user_id)
            ");
            $stmt->bindParam(':uuid', $uuid, PDO::PARAM_STR);
            $stmt->bindParam(':file_name', $originalFileName, PDO::PARAM_STR);
            $stmt->bindParam(':file_size', $fileSize, PDO::PARAM_INT);
            $stmt->bindParam(':total_chunks', $chunkCount, PDO::PARAM_INT);
            $stmt->bindParam(':storage_path', $storagePath, PDO::PARAM_STR);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $upload = [
                'uuid' => $uuid,
                'last_chunk_index' => -1,
                'storage_path' => $storagePath
            ];
            $hashContext = hash_init('sha256');
            $storageEngine->createElement($storagePath);
        } else {
            // Check if the chunk index is out of sequence
            if ($chunkIndex !== (int)$upload['last_chunk_index'] + 1) {
                echo json_encode(['status' => 'error', 'message' => 'Chunk out of sequence.']);
                return;
            }
            $hashContext = unserialize( $upload['hash_state'] );
        }

        // Append the chunk to the target file
        $chunkData = file_get_contents($file['tmp_name']);
        $storageEngine->writeAppend($upload['storage_path'], $chunkData);

        // Free the temporary file to release resources (maybe not necessary, but better safe than sorry)
        if (is_file($file['tmp_name'])) {
            unlink($file['tmp_name']);
        }

        // Update the hash state
        hash_update($hashContext, $chunkData);
        $hashState = serialize( $hashContext );

        // Update the database with the latest chunk and hash state
        $stmt = $db->prepare("
            UPDATE uploads
            SET last_chunk_index = :last_chunk_index, hash_state = :hash_state, last_update = CURRENT_TIMESTAMP
            WHERE uuid = :uuid
        ");
        $stmt->bindParam(':last_chunk_index', $chunkIndex, PDO::PARAM_INT);
        $stmt->bindParam(':hash_state', $hashState, PDO::PARAM_STR);
        $stmt->bindParam(':uuid', $uuid, PDO::PARAM_STR);
        $stmt->execute();

        // If all chunks are uploaded, create entry
        if ($chunkIndex == $chunkCount - 1) {
            $finalFileSize  = $storageEngine->getSize($storagePath);
            $checksum = hash_final($hashContext);
            // $finfo = finfo_open(FILEINFO_MIME_TYPE);
            // $mimeType = finfo_file($finfo, $targetFile);
            // finfo_close($finfo);
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

            // Mark the upload as completed
            $stmt = $db->prepare("
                UPDATE uploads
                SET status = 'completed', last_update = CURRENT_TIMESTAMP
                WHERE uuid = :uuid
            ");
            $stmt->bindParam(':uuid', $uuid, PDO::PARAM_STR);
            $stmt->execute();

            echo json_encode(['status' => 'success', 'message' => 'File uploaded successfully.',
                'file' => [ 'Name' => $originalFileName, 'Size' => $fileSize, 'SHA256' => $checksum,
                            'Created' => $utcTimestamp, 'Uploaded by / from' => $userInfo['email'].'<br>'.$remoteIp ]
            ]);
        } else {
            // If we are just in progress
            echo json_encode(['status' => 'progress', 'message' => 'Chunk uploaded.']);
        }
    }
}
?>