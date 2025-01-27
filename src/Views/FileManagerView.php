<?php
// src/Views/FileManagerView.php
namespace Views;

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
          <a title="Invite" class="nav-link" href="#" data-toggle="modal" data-target="#inviteModal">
            <i class="fa fa-user-plus" aria-hidden="true"></i> Invite
          </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a title="Settings" class="dropdown-item nav-link" href="?p=&amp;settings=1"><i class="fa fa-cog" aria-hidden="true"></i> Settings</a>
        </li>
        <li class="nav-item">
            <a title="Logout" class="nav-link" href="logout">
                <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
            </a>
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
  <!-- Upload Modal -->
  <div class="modal fade" id="uploadModal" tabindex="-1" role="dialog" aria-labelledby="uploadModalLabel" aria-hidden="true">
      <div class="modal-dialog" role="document">
          <div class="modal-content">
              <div class="modal-header">
                  <h5 class="modal-title" id="uploadModalLabel">Upload Files</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                  </button>
              </div>
              <div class="modal-body">
                  <!-- Dropzone Form -->
                  <form action="upload" class="dropzone" id="fileUploader" enctype="multipart/form-data">
                    <input type="hidden" id="cwd" name="cwd" value="<?php echo rtrim($cwd, '/'); ?>">
                  </form>
              </div>
              <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
              </div>
          </div>
      </div>
  </div>

  <!-- New Folder Modal -->
  <div class="modal fade" id="newFolderModal" tabindex="-1" role="dialog" aria-labelledby="newFolderModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newFolderModalLabel">Create New Folder</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="newFolderForm">
                    <div class="form-group">
                        <label for="folderName">Folder Name</label>
                        <input type="text" class="form-control" id="folderName" name="folderName" placeholder="Enter folder name" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="createFolderButton">Create</button>
            </div>
        </div>
    </div>
</div>

<!-- Operation Result Modal -->
<div class="modal fade" id="operationResultModal" tabindex="-1" role="dialog" aria-labelledby="operationResultModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="operationResultModalLabel">Operation Result</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="operationResultMessage">
                <!-- Success or Failure message will be displayed here dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" role="dialog" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteConfirmModalLabel">Confirm Deletion</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete <span id="deleteItemName"></span>?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteButton">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Invite Modal -->
<div class="modal fade" id="inviteModal" tabindex="-1" role="dialog" aria-labelledby="inviteModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="inviteModalLabel">Invite User or Guest</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="inviteForm">
                    <!-- Email Address Input -->
                    <div class="form-group">
                        <label for="inviteEmail">Email Address</label>
                        <input type="email" class="form-control" id="inviteEmail" name="inviteEmail" placeholder="Enter email address" required>
                    </div>
                    
                    <!-- Access Rights Options -->
                    <div class="form-group">
                        <label>Access Rights</label>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="accessRead" name="accessRights[]" value="read">
                            <label class="form-check-label" for="accessRead">Read</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="accessWrite" name="accessRights[]" value="write">
                            <label class="form-check-label" for="accessWrite">Write</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="accessUpload" name="accessRights[]" value="upload" checked>
                            <label class="form-check-label" for="accessUpload">Upload</label>
                        </div>
                    </div>
                    
                    <!-- Send Invitation Checkbox -->
                    <div class="form-group form-check">
                        <input type="checkbox" class="form-check-input" id="sendLink" name="sendLink" checked>
                        <label class="form-check-label" for="sendLink">Send Invitation Link</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="sendInviteButton">Send Invite</button>
            </div>
        </div>
    </div>
</div>

  </div>
</body>
</html>
        <?php
    }
}
?>