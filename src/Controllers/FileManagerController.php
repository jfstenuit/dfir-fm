<?php
// src/Controllers/FileManagerController.php
namespace Controllers;

use Core\Session;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\App;
use Middleware\AccessMiddleware;
use Views\FileManagerView;

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
}
?>