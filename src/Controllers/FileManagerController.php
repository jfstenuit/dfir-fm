<?php
// src/Controllers/FileManagerController.php
namespace Controllers;

use Core\Session;
use Core\Database;
use Core\Request;
use Core\Response;
use Middleware\AccessMiddleware;
use PDO;

class FileManagerController
{
    public static function handle($config,$db)
    {
        $userId = $_SESSION['user_id'];

        // Get the requested path from the query parameter `p`
        $currentPath = isset($_GET['p']) ? trim($_GET['p']) : '';
        $currentPath = $currentPath === '' ? '/' : $currentPath;

        // Check that the directory exist
        $directoryInfo = AccessMiddleware::getDirectoryInfo($db, $currentPath);
        if (!$directoryInfo) {
            error_log("Directory \"$currentPath\" does not exist in DB");
            Response::triggerNotFound();
            return;
        }
        
        // Verify user has access to the current directory
        $accessRights = AccessMiddleware::checkAccess($db, $currentPath, $userId);
        if (! ( $accessRights['can_read'] || $accessRights['can_upload'])) {
            error_log("Access denied");
            Response::triggerAccessDenied();
            return;
        }

        $directoryId = $accessRights['directory_id'];

        $items = AccessMiddleware::getDirectoryItems($db, $directoryId, $accessRights);

        // Render the file manager view
        \Views\FileManagerView::render($items, $currentPath, $accessRights);
    }
}
?>