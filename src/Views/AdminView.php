<?php
// src/Views/AdminView.php
namespace Views;

use Views\Modals\DeleteConfirmationModal;
use Views\Modals\OperationResultModal;
use Views\Modals\ProfileModal;
use Views\Modals\UserGroupModal;

class AdminView
{
    public static function render($users, $groups, $accessRights)
    {
        ?>
<!DOCTYPE html>
<html lang="en">
<?php Layout::appHead('Admin Panel'); ?>
<body class="navbar-fixed">
  <div class="container-fluid">
    <nav class="navbar navbar-expand-lg navbar-light bg-white mb-4 main-nav fixed-top">
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
              data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
              aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="navbarSupportedContent">
        <!-- Left side: Cog icon + nav pills for admin sections -->
        <div class="col-sm-5 d-flex align-items-center">
          <i class="bi bi-gear me-2" aria-hidden="true"></i>
          <ul class="nav nav-pills" role="tablist">
            <li class="nav-item" role="presentation">
              <a class="nav-link active" href="#" role="tab" data-bs-toggle="tab" data-bs-target="#usersTab">Users</a>
            </li>
            <li class="nav-item" role="presentation">
              <a class="nav-link" href="#" role="tab" data-bs-toggle="tab" data-bs-target="#groupsTab">Groups</a>
            </li>
            <li class="nav-item" role="presentation">
              <a class="nav-link" href="#" role="tab" data-bs-toggle="tab" data-bs-target="#logsTab">Logs</a>
            </li>
          </ul>
        </div>

        <!-- Right side: Return to File Manager + Account Menu -->
        <div class="col-sm-7">
          <ul class="navbar-nav justify-content-end">
            <li class="nav-item">
              <a title="Back to File Manager" class="nav-link" href="./">
                <i class="bi bi-folder2-open" aria-hidden="true"></i> File Manager
              </a>
            </li>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                 data-bs-toggle="dropdown" aria-expanded="false">
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
    <div class="tab-content pt-5">
      <div class="tab-pane fade show active" id="usersTab">
        <!-- Users Table -->
        <table id="usersTable" class="table table-bordered table-striped table-hover table-sm">
          <thead class="table-light">
            <tr>
              <th>User</th>
              <th>Groups</th>
              <th class="status">Status</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <!-- Rows built by admin-users.js -->
          </tbody>
        </table>
      </div>

      <div class="tab-pane fade" id="groupsTab">
        <!-- Groups -->
        <table id="groupsTable" class="table table-bordered table-striped table-hover table-sm">
          <thead class="table-light">
            <tr>
              <th>Group</th>
              <th>Members</th>
              <th>Accessed Directories</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <!-- Rows built by admin-groups.js -->
          </tbody>
        </table>
      </div>

      <div class="tab-pane fade" id="logsTab">
        <!-- Logs (not implemented yet) -->
        <table id="logsTable" class="table table-bordered table-striped table-hover table-sm">
          <thead class="table-light">
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
ProfileModal::render('');
?>
</body>
</html>
        <?php
    }
}
?>
