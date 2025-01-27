<?php
// src/Controllers/DownloadController.php
namespace Controllers;

use Core\Session;
use Core\Database;
use Core\Request;
use Core\Response;
use Backends\StorageEngineFactory;
use Middleware\AccessMiddleware;
use PDO;

class DownloadController
{
    public static function handle($config,$db)
    {
        $userId = $_SESSION['user_id'];

        // Get the requested path from the query parameter `p`
        $requestedFile = trim($_GET['p']) ?? '';
        $pathParts = pathinfo($requestedFile);

        // Check that directory exists
        $directoryInfo = AccessMiddleware::getDirectoryInfo($db, $pathParts['dirname']);
        if (!$directoryInfo) {
            error_log("Directory \"$currentDirectory\" does not exist in DB");
            Response::triggerNotFound();
            return;
        }

        // Veryfy user has access
        $accessRights = AccessMiddleware::checkAccess($db, $pathParts['dirname'], $userId);
        if (! $accessRights['can_read']) {
            error_log("Directory does not exist or user doesn't have access");
            Response::triggerNotFound();
            return;
        }

        // Get file details
        $fileDetails = AccessMiddleware::getFileInfo($db, $requestedFile);
        if (!$fileDetails) {
            error_log("File does not exist in this directory");
            Response::triggerNotFound();
            return;
        }

        $storageEngine = StorageEngineFactory::create($config);
        if (!$storageEngine->healthCheck()) {
            Response::triggerSystemError();
            return;
        }

        if (!$storageEngine->exists($requestedFile)) {
            // File does not exist
            Response::triggerNotFound();
            exit;
        }

        // Fetch the file metadata
        $filesize = $storageEngine->getSize($requestedFile);
        $filename = $pathParts['basename'];

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
        $currentOffset = $start;

        while ($currentOffset <= $end) {
            // Calculate the number of bytes to read for the current chunk
            $bytesToRead = min($chunkSize, $end - $currentOffset + 1);
        
            // Read data from the storage engine
            $chunkData = $storageEngine->readAt($requestedFile, $currentOffset, $bytesToRead);
        
            // Check for read failure
            if ($chunkData === null) {
                Response::triggerSystemError();
                exit;
            }
        
            // Output the data to the client
            echo $chunkData;
            flush(); // Ensure immediate output to the client
        
            // Move to the next chunk
            $currentOffset += $bytesToRead;
        }

    }
}
?>