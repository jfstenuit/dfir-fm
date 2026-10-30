<?php
// src/Views/AdminView.php
namespace Views;

use Views\Modals\DeleteConfirmationModal;
use Views\Modals\OperationResultModal;
use Views\Modals\ProfileModal;
use Views\Modals\UserGroupModal;
use Views\Modals\InvitationLinkModal;
use Views\Modals\EventRecordModal;

class AdminView
{
    public static function render($users, $groups, $accessRights, bool $proxyWarning = false)
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
<?php if ($proxyWarning): ?>
      <div id="proxyWarning" class="alert alert-warning" role="alert">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <strong>Proxy not configured.</strong>
        Requests arrive with an <code>X-Forwarded-For</code> header, but <code>trusted_proxies</code> is empty:
        every client appears with the proxy's address (the login rate limit is shared by everyone, and uploads
        record the proxy as their origin). Set <code>trusted_proxies</code> to the proxy's address in
        <code>.env</code>, and <code>force_https=true</code> if TLS ends at the proxy.
      </div>
<?php endif; ?>
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
        <!-- Events, read from the events database (admin-logs.js) -->
        <form id="logsFilterForm" class="row g-2 align-items-end mb-3">
          <div class="col-sm-6 col-lg-2">
            <label for="logsFrom" class="form-label">From (UTC)</label>
            <input type="datetime-local" class="form-control form-control-sm" id="logsFrom" name="from" step="1">
          </div>
          <div class="col-sm-6 col-lg-2">
            <label for="logsUntil" class="form-label">Before (UTC)</label>
            <input type="datetime-local" class="form-control form-control-sm" id="logsUntil" name="until" step="1">
          </div>
          <div class="col-sm-6 col-lg-2">
            <label for="logsAction" class="form-label">Action</label>
            <select class="form-select form-select-sm" id="logsAction" name="action">
              <option value="">All actions</option>
            </select>
          </div>
          <div class="col-sm-6 col-lg-2">
            <label for="logsUsername" class="form-label">User</label>
            <input type="text" class="form-control form-control-sm" id="logsUsername" name="username" placeholder="exact username">
          </div>
          <div class="col-sm-6 col-lg-1">
            <label for="logsIp" class="form-label">IP</label>
            <input type="text" class="form-control form-control-sm" id="logsIp" name="ip" placeholder="client or proxy">
          </div>
          <div class="col-sm-6 col-lg">
            <label for="logsText" class="form-label">Text</label>
            <input type="search" class="form-control form-control-sm" id="logsText" name="text" placeholder="file name, SHA-256…">
          </div>
          <div class="col-auto d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary text-nowrap" title="Search"><i class="bi bi-search" aria-hidden="true"></i> Search</button>
            <button type="reset" class="btn btn-sm btn-outline-secondary" title="Clear the filters" aria-label="Clear the filters"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
          </div>
        </form>
        <!-- position-relative: keeps the visually-hidden header label inside the scroll box -->
        <div class="table-responsive position-relative">
          <table id="logsTable" class="table table-bordered table-striped table-hover table-sm">
            <thead class="table-light">
              <tr>
                <th>Time (UTC)</th>
                <th>Action</th>
                <th>User</th>
                <th>IP</th>
                <th>Details</th>
                <th><span class="visually-hidden">Record</span></th>
              </tr>
            </thead>
            <tbody>
              <!-- Rows built by admin-logs.js -->
            </tbody>
          </table>
        </div>
        <div class="d-flex align-items-center gap-3">
          <button type="button" class="btn btn-sm btn-outline-primary d-none" id="logsMore">Load more</button>
          <span class="text-muted small" id="logsCount" aria-live="polite"></span>
        </div>
      </div>
    </div>
  </div>
<?php
OperationResultModal::render();
DeleteConfirmationModal::render();
UserGroupModal::render();
InvitationLinkModal::render();
EventRecordModal::render();
ProfileModal::render('');
?>
</body>
</html>
        <?php
    }
}
?>
