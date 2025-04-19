<?php
// src/Views/AdminView.php
namespace Views;

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
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="vendor/components/jquery/jquery.min.js"></script>
  <script src="vendor/components/bootstrap/js/bootstrap.min.js"></script>
  <script src="assets/js/site.js"></script>
</head>
<body>
  <div class="container-fluid">
    <!-- Admin Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white mb-4">
      <a class="navbar-brand" href="#">Admin Panel</a>
      <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="adminNavbar">
        <ul class="navbar-nav ml-auto">
          <li class="nav-item">
            <a class="nav-link" href="."><i class="fa fa-folder"></i> File Manager</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="logout"><i class="fa fa-sign-out-alt"></i> Logout</a>
          </li>
        </ul>
      </div>
    </nav>

    <!-- Admin Tabs -->
    <ul class="nav nav-tabs" id="adminTabs" role="tablist">
      <li class="nav-item">
        <a class="nav-link active" id="users-tab" data-toggle="tab" href="#users" role="tab" aria-controls="users" aria-selected="true">Users</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" id="groups-tab" data-toggle="tab" href="#groups" role="tab" aria-controls="groups" aria-selected="false">Groups</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" id="access-rights-tab" data-toggle="tab" href="#access-rights" role="tab" aria-controls="access-rights" aria-selected="false">Access Rights</a>
      </li>
    </ul>

    <div class="tab-content" id="adminTabsContent">
      <!-- Users Management -->
      <div class="tab-pane fade show active" id="users" role="tabpanel" aria-labelledby="users-tab">
        <div class="table-responsive mt-4">
          <h4>Manage Users</h4>
          <table class="table table-bordered table-hover">
            <thead class="thead-light">
              <tr>
                <th>ID</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $user): ?>
                <tr>
                  <td><?php echo htmlspecialchars($user['id']); ?></td>
                  <td><?php echo htmlspecialchars($user['email']); ?></td>
                  <td><?php echo htmlspecialchars($user['role']); ?></td>
                  <td><?php echo $user['active'] ? 'Active' : 'Inactive'; ?></td>
                  <td>
                    <a href="#" class="btn btn-sm btn-warning"><i class="fa fa-edit"></i> Edit</a>
                    <a href="#" class="btn btn-sm btn-danger"><i class="fa fa-trash"></i> Delete</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Groups Management -->
      <div class="tab-pane fade" id="groups" role="tabpanel" aria-labelledby="groups-tab">
        <div class="table-responsive mt-4">
          <h4>Manage Groups</h4>
          <table class="table table-bordered table-hover">
            <thead class="thead-light">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Description</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($groups as $group): ?>
                <tr>
                  <td><?php echo htmlspecialchars($group['id']); ?></td>
                  <td><?php echo htmlspecialchars($group['name']); ?></td>
                  <td><?php echo htmlspecialchars($group['description']); ?></td>
                  <td>
                    <a href="#" class="btn btn-sm btn-warning"><i class="fa fa-edit"></i> Edit</a>
                    <a href="#" class="btn btn-sm btn-danger"><i class="fa fa-trash"></i> Delete</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Access Rights Management -->
      <div class="tab-pane fade" id="access-rights" role="tabpanel" aria-labelledby="access-rights-tab">
        <div class="table-responsive mt-4">
          <h4>Manage Access Rights</h4>
          <table class="table table-bordered table-hover">
            <thead class="thead-light">
              <tr>
                <th>Directory</th>
                <th>Group</th>
                <th>Can View</th>
                <th>Can Write</th>
                <th>Can Upload</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($accessRights as $access): ?>
                <tr>
                  <td><?php echo htmlspecialchars($access['directory']); ?></td>
                  <td><?php echo htmlspecialchars($access['group']); ?></td>
                  <td><?php echo $access['can_view'] ? 'Yes' : 'No'; ?></td>
                  <td><?php echo $access['can_write'] ? 'Yes' : 'No'; ?></td>
                  <td><?php echo $access['can_upload'] ? 'Yes' : 'No'; ?></td>
                  <td>
                    <a href="#" class="btn btn-sm btn-warning"><i class="fa fa-edit"></i> Edit</a>
                    <a href="#" class="btn btn-sm btn-danger"><i class="fa fa-trash"></i> Delete</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
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
