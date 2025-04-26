<?php
// src/Views/AdminView.php
namespace Views;

use Views\Modals\DeleteConfirmationModal;
use Views\Modals\OperationResultModal;
use Views\Modals\UserGroupModal;

class AdminView
{
    public static function render($users, $groups, $accessRights)
    {
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Panel</title>
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
  <script src="vendor/corejavascript/typeahead.js/dist/typeahead.bundle.min.js"></script>
  <script src="assets/js/site.js"></script>
</head>
<body class="navbar-fixed">
  <div class="container-fluid">
    <nav class="navbar navbar-expand-lg navbar-light bg-white mb-4 main-nav fixed-top">
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
              data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
              aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse">
        <!-- Left side: Cog icon + nav pills for admin sections -->
        <div class="col-xs-6 col-sm-5 d-flex align-items-center">
          <i class="fa fa-cog mr-2" aria-hidden="true"></i>
          <ul class="nav nav-pills">
            <li class="nav-item">
              <a class="nav-link active" href="#" data-toggle="tab" data-target="#usersTab">Users</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="#" data-toggle="tab" data-target="#groupsTab">Groups</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="#" data-toggle="tab" data-target="#logsTab">Logs</a>
            </li>
          </ul>
        </div>

        <!-- Right side: Return to File Manager + Account Menu -->
        <div class="col-xs-6 col-sm-7">
          <ul class="navbar-nav justify-content-end">
            <li class="nav-item">
              <a title="Back to File Manager" class="nav-link" href="./">
                <i class="fa fa-folder-open" aria-hidden="true"></i> File Manager
              </a>
            </li>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                 data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
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
    <div class="tab-content pt-5">
      <div class="tab-pane fade show active" id="usersTab">
        <!-- Invite User Form -->
        <form id="inviteUserForm" class="form-inline mb-3">
          <div class="form-group mr-2">
            <label for="inviteEmailInput" class="sr-only">Email</label>
            <input type="email" class="form-control" id="inviteEmailInput" placeholder="Enter user email" required>
          </div>
          <button type="submit" class="btn btn-primary">Invite User</button>
        </form>
        <!-- Users Table -->
        <table id="usersTable" class="table table-bordered table-hover table-sm bg-white">
          <thead class="thead-light">
            <tr>
              <th>User</th>
              <th>Groups</th>
              <th class="status">Status</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <!-- Template -->
            <tr id="userRowTemplate" class="d-none">
              <td class="user-email"></td>
              <td class="user-groups"></td>
              <td class="status text-center"></td>
              <td class="actions text-center">
                <button class="btn btn-sm btn-link resend-invite" title="Re-send invitation email">
                  <i class="fa fa-envelope-open-text"></i>
                </button>
                <button class="btn btn-sm btn-link lock-user" title="Lock this user">
                  <i class="fa fa-lock"></i>
                </button>
                <button class="btn btn-sm btn-link text-danger delete-user" title="Delete user">
                  <i class="fa fa-trash"></i>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="tab-pane fade" id="groupsTab">
        <!-- Groups DataTable -->
        <table id="groupsTable" class="table table-bordered table-hover table-sm bg-white">
          <thead class="thead-light">
            <tr>
              <th>Group</th>
              <th>Members</th>
              <th>Accessed Directories</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <!-- Template -->
            <tr id="groupRowTemplate" class="d-none">
              <td class="group-name"></td>
              <td class="group-members"></td>
              <td class="group-directories"></td>
              <td class="group-actions text-center">
                <button class="btn btn-sm btn-danger delete-group" data-group="">
                  <i class="fa fa-trash"></i>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="tab-pane fade" id="logsTab">
        <!-- Logs DataTable -->
        <table id="logsTable" class="table table-bordered table-hover table-sm bg-white">
          <thead class="thead-light">
            <tr>
              <th>Timestamp</th>
              <th>User</th>
              <th>Action</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            <!-- populated via JS -->
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php
OperationResultModal::render();
DeleteConfirmationModal::render();
UserGroupModal::render();
?>
</body>
</html>
        <?php
    }
}
?>
