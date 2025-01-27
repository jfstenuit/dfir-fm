<?php
// src/Controllers/DownloadController.php
namespace Controllers;

use Core\Session;
use Core\Database;
use Core\Request;
use Core\Response;
use Middleware\AccessMiddleware;
use PDO;

class DownloadController
{
    public static function handle($config,$db)
    {
        // Fetch user-specific data from the database (e.g., files)
        $db = Database::initialize(STORAGE_PATH . '/database/app.sqlite');
        $userId = $_SESSION['user_id'];

        // Get the requested path from the query parameter `p`
        $requestedFile = trim($_GET['p']) ?? '';
        $pathParts = pathinfo($requestedFile);

        $access = AccessMiddleware::checkAccess($db, $pathParts['dirname'], $userId);
        if (!$access) {
            error_log("Directory does not exist or user doesn't have access");
            Response::triggerNotFound();
            return;
        }

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
            AND ar.can_view = TRUE -- Non-admin group access
        )
    )");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':directory_name', $pathParts['dirname'], PDO::PARAM_STR);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            error_log("Directory does not exist or user doesn't have access");
            Response::triggerNotFound();
            return;
        }

        $directoryId = $result['directory_id'];
        $groupId = $result['group_id'];

        // Get file details
        $stmt = $db->prepare("
SELECT
    f.*
FROM
    files f
WHERE
    f.directory_id = :directory_id
    AND f.name = :file_name");
        $stmt->bindParam(':directory_id', $directoryId, PDO::PARAM_INT);
        $stmt->bindParam(':file_name', $pathParts['basename'], PDO::PARAM_STR);
        $stmt->execute();
        $fileDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fileDetails) {
            error_log("File does not exist in this directory");
            Response::triggerNotFound();
            return;
        }

        $baseStorageDir = realpath(__DIR__ . '/../../storage/files');
        $requestedPath = ltrim($requestedFile, '/');
        $realFilePath = realpath($baseStorageDir . DIRECTORY_SEPARATOR . $requestedPath);
        // Check if the resolved path is within the base directory
        if ($realFilePath === false || strpos($realFilePath, $baseStorageDir) !== 0) {
            // Invalid path or directory traversal attempt
            error_log("Invalid path or directory traversal attempt");
            Response::triggerNotFound();
            return;
        }
        if (!file_exists($realFilePath)) {
            // File does not exist
            Response::triggerNotFound();
            exit;
        }

        // Fetch the file metadata
        $filesize = filesize($realFilePath);
        $filename = basename($realFilePath);

        // Set headers for file download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        // Handle partial content requests (e.g., for resuming downloads)
        $start = 0;
        $end = $filesize - 1;
        if (isset($_SERVER['HTTP_RANGE'])) {
            $range = $_SERVER['HTTP_RANGE'];
            if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
                $start = intval($matches[1]);
                if (!empty($matches[2])) {
                    $end = intval($matches[2]);
                }
            }

            // Validate range
            if ($start > $end || $start >= $filesize || $end >= $filesize) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header('Content-Range: bytes */' . $filesize);
                return;
            }

            // Inform the client about the partial content
            header('HTTP/1.1 206 Partial Content');
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $filesize);
        }

        // Set the content length for the requested range
        $contentLength = ($end - $start) + 1;
        header('Content-Length: ' . $contentLength);

        // Serve the file in chunks
        $chunkSize = 8192; // 8 KB per chunk
        $handle = fopen($realFilePath, 'rb');
        if ($handle === false) {
            Response::triggerSystemError();
            exit;
        }

        // Seek to the start of the requested range
        fseek($handle, $start);

        // Stream the file
        while (!feof($handle) && ($pos = ftell($handle)) <= $end) {
            $bytesToRead = min($chunkSize, $end - $pos + 1);
            echo fread($handle, $bytesToRead);
            flush(); // Ensure immediate output to the client
        }

        // Close the file handle
        fclose($handle);
    }
}
?>