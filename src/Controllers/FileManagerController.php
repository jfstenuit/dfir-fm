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

        // Verify user has access to the current directory
        $AccessRights = AccessMiddleware::checkAccess($db, $currentPath, $userId);

        if (!$AccessRights['directory_id']) {
            Response::triggerNotFound();
            return;
        }
        if (! ( $AccessRights['can_read'] || $AccessRights['can_upload'])) {
            error_log("Access denied");
            Response::triggerAccessDenied();
            return;
        }

        $directoryId = $AccessRights['directory_id'];

        $items = AccessMiddleware::getDirectoryItems($db, $directoryId, $AccessRights);

        // Render the file manager view
        \Views\FileManagerView::render($items, $currentPath, $AccessRights);
    }
}
?>