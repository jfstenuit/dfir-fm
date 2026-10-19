<?php
// src/Controllers/DownloadController.php
namespace Controllers;

use Core\Session;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\App;
use Backends\StorageEngineFactory;
use Middleware\AccessMiddleware;
use PDO;

class DownloadController
{
    public static function handle($config,$db)
    {
        $userId = $_SESSION['user_id'];

        // Get the requested path from the query parameter `p`
        $requestedFile = is_string($_GET['p'] ?? null) ? trim($_GET['p']) : '';
        if ($requestedFile === '') {
            App::getLogger()->log('download_failure', ['cause'=>'No file requested']);
            Response::triggerNotFound();
            return;
        }
        $pathParts = pathinfo($requestedFile);

        // Check that directory exists
        $directoryInfo = AccessMiddleware::getDirectoryInfo($db, $pathParts['dirname']);
        if (!$directoryInfo) {
            App::getLogger()->log('download_failure', ['cause'=>'Invalid directory requested']);
            Response::triggerNotFound();
            return;
        }

        // Veryfy user has access
        $accessRights = AccessMiddleware::checkAccess($db, $pathParts['dirname'], $userId);
        if (! $accessRights['can_read']) {
            App::getLogger()->log('download_failure', ['cause'=>'Access denied']);
            Response::triggerNotFound();
            return;
        }

        // Get file details
        $fileDetails = AccessMiddleware::getFileInfo($db, $requestedFile);
        if (!$fileDetails) {
            App::getLogger()->log('download_failure', ['cause'=>'File not found in DB']);
            Response::triggerNotFound();
            return;
        }

        $storageEngine = StorageEngineFactory::create($config);
        if (!$storageEngine->healthCheck()) {
            error_log("Failed to get a handle on a Storage Engine");
            Response::triggerSystemError();
            return;
        }

        if (!$storageEngine->exists($requestedFile)) {
            error_log("Failed to get a file from back-end storage");
            Response::triggerSystemError();
            exit;
        }


        // Fetch the file metadata
        $filesize = $storageEngine->getSize($requestedFile);
        $filename = $pathParts['basename'];
        
        // Set headers for file download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: ' . Response::contentDisposition($filename));
        // Evidence must never be stored by shared caches (proxies, CDN)
        header('Cache-Control: private, no-store');
        header('Expires: 0');
        header('Accept-Ranges: bytes');

        // Handle partial content requests (e.g., for resuming downloads)
        $start = 0;
        $end = $filesize - 1;
        $range = self::parseRange($_SERVER['HTTP_RANGE'] ?? null, $filesize);
        if ($range === false) {
            App::getLogger()->log('download_failure', ['cause'=>'Range not satisfiable', 'file'=>$filename, 'range'=>$_SERVER['HTTP_RANGE']]);
            http_response_code(416);
            header('Content-Range: bytes */' . $filesize);
            return;
        }
        if ($range !== null) {
            [$start, $end] = $range;
            App::getLogger()->log('download_partial', ['file'=>$filename, 'directory'=>$pathParts['dirname'], 'range'=>$_SERVER['HTTP_RANGE']]);

            // Inform the client about the partial content
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $filesize);
        } else {
            App::getLogger()->log('download_success', ['file'=>$filename, 'directory'=>$pathParts['dirname']]);
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

    /**
     * Parse a Range header (RFC 9110, single byte range only).
     *
     * @return array{0:int,1:int}|null|false [start, end] (inclusive) for a
     *   satisfiable range; null to ignore the header and send the whole file
     *   (absent, malformed or multi-range); false if unsatisfiable (416).
     */
    public static function parseRange(?string $header, int $size): array|null|false
    {
        if ($header === null || !preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $m)) {
            return null;
        }
        [, $first, $last] = $m;

        if ($first === '' && $last === '') {
            return null;
        }

        if ($first === '') {
            // Suffix range "bytes=-N": the last N bytes
            $suffix = (int)$last;
            if ($suffix === 0 || $size === 0) {
                return false;
            }
            return [max(0, $size - $suffix), $size - 1];
        }

        $start = (int)$first;
        if ($start >= $size) {
            return false;
        }
        // An end beyond the file is clamped, not an error
        $end = $last === '' ? $size - 1 : min((int)$last, $size - 1);
        if ($end < $start) {
            return null;
        }
        return [$start, $end];
    }
}
?>