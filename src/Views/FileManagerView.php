<?php
// src/Views/FileManagerView.php
namespace Views;

use Views\Modals\DeleteConfirmationModal;
use Views\Modals\NewFolderModal;
use Views\Modals\OperationResultModal;
use Views\Modals\UploadModal;
use Views\Modals\InviteModal;
use Views\Modals\AccessRightsModal;
use Views\Modals\ProfileModal;

class FileManagerView
{
    public static function render($items,$cwd,$userPermissions)
    {
        $e = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        ?>
<!DOCTYPE html>
<html lang="en">
<?php Layout::appHead('File Manager'); ?>
<body class="navbar-fixed">
  <div class="container-fluid">
  <nav class="navbar navbar-expand-lg navbar-light bg-white mb-4 main-nav fixed-top">
  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="navbarSupportedContent">
    <div class="col-sm-5">
      <a href="?p=/" title="Home"><i class="bi bi-house" aria-hidden="true"></i></a>
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
    <div class="col-sm-7">
      <ul class="navbar-nav justify-content-end ">
      <?php if ((isset($userPermissions['can_upload']) && $userPermissions['can_upload']) || (isset($userPermissions['is_admin']) && $userPermissions['is_admin'])) : ?>
        <li class="nav-item">
          <a title="Upload" class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#uploadModal">
              <i class="bi bi-cloud-upload" aria-hidden="true"></i> Upload
          </a>
        </li>
        <?php endif; ?>
        <?php if (isset($userPermissions['is_admin']) && $userPermissions['is_admin']) : ?>
        <li class="nav-item">
          <a title="New Folder" class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#newFolderModal">
              <i class="bi bi-folder-plus" aria-hidden="true"></i> New Folder
          </a>
        </li>
        <?php if ($cwd !== '/') : /* no invitation into the root (D14) */ ?>
        <li class="nav-item">
          <a title="Invite Contributor" class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#inviteModal">
              <i class="bi bi-person-plus" aria-hidden="true"></i> Invite
          </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a title="Access Rights" class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#accessRightsModal">
              <i class="bi bi-person-gear" aria-hidden="true"></i> Access Rights
          </a>
        </li>
        <li class="nav-item">
            <a title="Settings" class="nav-link" href="./admin"><i class="bi bi-gear" aria-hidden="true"></i> Settings</a>
        </li>
        <?php endif; ?>
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person" aria-hidden="true"></i> Account
            </a>
            <div class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="bi bi-key" aria-hidden="true"></i> Change Password
                </a>
                <a class="dropdown-item" href="logout">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Logout
                </a>
            </div>
        </li>
    </ul>
    </div>
  </div>
  </nav>
  <form action="" method="post" class="pt-3">
    <div class="table-responsive">
<?php $canDelete = !empty($userPermissions['can_write']) || !empty($userPermissions['is_admin']); ?>
      <table id="main-table" class="table table-bordered table-striped table-hover table-sm" data-can-delete="<?php echo $canDelete ? '1' : '0'; ?>">
        <thead class="table-light">
          <tr>
            <th class="col-name">Name</th>
            <th class="col-size text-end">Size</th>
            <th class="col-created">Created</th>
            <th class="col-by">Uploaded by / from</th>
            <th class="col-sha256 checksum" data-sortable="false">SHA256</th>
            <th class="col-actions text-center" data-sortable="false">Actions</th>
          </tr>
        </thead>
        <tbody>
<?php foreach ($items as $i) {
  $path = urlencode(rtrim($cwd, '/') . '/' . $i['name']);
  $link = $i['type'] === 'd' ? "?p=$path" : "dl?p=$path";
?>
        <tr>
          <td>
            <div class="filename">
              <a href="<?php echo $e($link); ?>"><?php if ($i['type'] === 'd') : ?><i class="bi bi-folder me-1" aria-hidden="true"></i><?php endif; ?><?php echo $e($i['name']); ?></a>
            </div>
          </td>
          <td class="text-end" data-order="<?php echo $e($i['size']); ?>"><?php echo $e($i['size']); ?></td>
          <td data-order="<?php echo $e($i['created_at']); ?>"><?php echo $e($i['created_at']); ?></td>
          <td>
            <?php echo $e($i['created_by']); ?>
            <br>
            <?php echo $e($i['created_from']); ?>
          </td>
          <td class="checksum" title="<?php echo $e($i['sha256']); ?>"><?php echo $e($i['sha256']); ?></td>
          <td class="inline-actions text-center" data-id="<?php echo $e($i['id']); ?>" data-type="<?php echo $e($i['type']); ?>" data-name="<?php echo $e($i['name']); ?>">
              <?php if ($canDelete) : ?>
              <a href="#" class="delete-action" title="Delete" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal">
                  <i class="bi bi-trash" aria-hidden="true"></i>
              </a>
              <?php endif; ?>
              <a title="Rename" href="#"><i class="bi bi-pencil-square" aria-hidden="true"></i></a>
          </td>
        </tr>
<?php } ?>
        </tbody>
      </table>
    </div>
  </form>
<?php
UploadModal::render($cwd);
NewFolderModal::render();
OperationResultModal::render();
DeleteConfirmationModal::render();
AccessRightsModal::render($cwd);
if ($cwd !== '/') {
    InviteModal::render($cwd);
}
ProfileModal::render($cwd);
?>
</body>
</html>
        <?php
    }
}
?>