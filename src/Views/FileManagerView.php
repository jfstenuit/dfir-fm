<?php
// src/Views/FileManagerView.php
namespace Views;

use Views\Modals\DeleteConfirmationModal;
use Views\Modals\NewFolderModal;
use Views\Modals\OperationResultModal;
use Views\Modals\UploadModal;
use Views\Modals\AccessRightsModal;
use Views\Modals\ProfileModal;

class FileManagerView
{
    public static function render($items,$cwd,$userPermissions)
    {
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>File Manager</title>
  <link rel="stylesheet" href="vendor/components/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="vendor/components/font-awesome/css/all.min.css">
  <!-- <link rel="stylesheet" href="vendor/datatables/datatables/media/css/jquery.dataTables.min.css"> -->
  <link rel="stylesheet" href="vendor/datatables/datatables/media/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" href="vendor/enyo/dropzone/dist/min/dropzone.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="vendor/components/jquery/jquery.min.js"></script>
  <script src="vendor/components/bootstrap/js/bootstrap.min.js"></script>
  <script src="vendor/datatables/datatables/media/js/jquery.dataTables.min.js"></script>
  <script src="vendor/datatables/datatables/media/js/dataTables.bootstrap4.min.js"></script>
  <script src="vendor/enyo/dropzone/dist/min/dropzone.min.js"></script>
  <script src="vendor/corejavascript/typeahead.js/dist/typeahead.bundle.min.js"></script>
  <script src="assets/js/site.js"></script>
</head>
<body class="navbar-fixed">
  <div class="container-fluid">
  <nav class="navbar navbar-expand-lg  navbar-light bg-white mb-4 main-nav fixed-top">
  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse">
    <div class="col-xs-6 col-sm-5">
      <a href='?p=/'><i class='fa fa-home' aria-hidden='true'></i></a>
<?php
      $parts = explode('/', trim($cwd, '/'));
      $currentPath = '';
      foreach ($parts as $part) {
        $currentPath .= '/' . $part;
?>
      <i class="bread-crumb"> / </i>
      <a href="?p=<?php echo urlencode($currentPath); ?>"><?php echo htmlspecialchars($part, ENT_QUOTES, 'UTF-8'); ?></a>
<?php } ?>
    </div>
    <div class="col-xs-6 col-sm-7">
      <ul class="navbar-nav justify-content-end ">
      <?php if ((isset($userPermissions['can_upload']) && $userPermissions['can_upload']) || (isset($userPermissions['is_admin']) && $userPermissions['is_admin'])) : ?>
        <li class="nav-item">
          <a title="Upload" class="nav-link" href="#" data-toggle="modal" data-target="#uploadModal">
              <i class="fa fa-cloud-upload" aria-hidden="true"></i> Upload
          </a>
        </li>
        <?php endif; ?>
        <?php if (isset($userPermissions['is_admin']) && $userPermissions['is_admin']) : ?>
        <li class="nav-item">
          <a title="New Folder" class="nav-link" href="#" data-toggle="modal" data-target="#newFolderModal">
              <i class="fa fa-folder" aria-hidden="true"></i> New Folder
          </a>
        </li>
        <li class="nav-item">
        <a title="Access Rights" class="nav-link" href="#" data-toggle="modal" data-target="#accessRightsModal">
            <i class="fa fa-users-cog" aria-hidden="true"></i> Access Rights
        </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a title="Settings" class="dropdown-item nav-link" href="./admin"><i class="fa fa-cog" aria-hidden="true"></i> Settings</a>
        </li>
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fa fa-user" aria-hidden="true"></i> Account
            </a>
            <div class="dropdown-menu dropdown-menu-right" aria-labelledby="userDropdown">
                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#profileModal">
                <i class="fa fa-key" aria-hidden="true"></i> Change Password
                </a>
                <a class="dropdown-item" href="logout">
                <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
                </a>
            </div>
        </li>
    </ul>
    </div>
  </div>
  </nav>
  <form action="" method="post" class="pt-3">
    <div class="table-responsive">
      <table id="main-table" class="table table-bordered table-hover table-sm bg-white">
        <thead class="thead-white">
          <tr>
            <th>Name</th>
            <th>Size</th>
            <th>Created</th>
            <th>Uploaded by / from</th>
            <th class="checksum">SHA256</th>
            <th>Actions</th>
          </tr>
        </thead>
<?php foreach ($items as $i) {
  $link = $i['type'] === 'd' ? '?p='.urlencode(rtrim($cwd, '/').'/'.$i['name'])
                             : 'dl?p='.urlencode(rtrim($cwd, '/').'/'.$i['name']);
?>
        <tr>
          <td>
            <div class="filename">
              <a href="<?php echo $link; ?>"><i class=""></i> <?php echo $i['name']; ?></a>
            </div>
          </td>
          <td data-order="<?php echo $i['size']; ?>">
            <?php echo $i['size']; ?>
          </td>
          <td data-order="<?php echo $i['created_at'] ?>"><?php echo $i['created_at'] ?></td>
          <td>
            <?php echo $i['created_by'] ?>
            <br>
            <?php echo $i['created_from'] ?>
          </td>
          <td class="checksum" title="<?php echo $i['sha256'] ?>">
            <?php echo $i['sha256'] ?>
          </td>
          <td class="inline-actions" data-id="<?php echo $i['id']; ?>" data-type="<?php echo $i['type']; ?>" data-name="<?php echo htmlspecialchars($i['name']); ?>">
              <?php if ((isset($userPermissions['can_write']) && $userPermissions['can_write']) || (isset($userPermissions['is_admin']) && $userPermissions['is_admin'])) : ?>
              <a href="#" class="delete-action" data-toggle="modal" data-target="#deleteConfirmModal">
                  <i class="fa fa-trash"></i>
              </a>
              <?php endif; ?>
              <a title="Rename" href="#"><i class="fa fa-pencil-square-o" aria-hidden="true"> </i></a>
          </td>
        </tr>
<?php } ?>
      </table>
    </div>
  </form>
<?php
UploadModal::render($cwd);
NewFolderModal::render();
OperationResultModal::render();
DeleteConfirmationModal::render();
AccessRightsModal::render($cwd);
ProfileModal::render($cwd);
?>
</body>
</html>
        <?php
    }
}
?>