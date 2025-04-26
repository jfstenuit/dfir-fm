<?php

namespace Views\Modals;

class ProfileModal {
    public static function render($cwd) {
?>
<!-- Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" role="dialog" aria-labelledby="profileModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
      <div class="modal-content">
          <div class="modal-header">
              <h5 class="modal-title" id="profileModalLabel">Your Profile</h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                  <span aria-hidden="true">&times;</span>
              </button>
          </div>
          <div class="modal-body">
              <form id="profileForm">
                <input type="text" name="username" id="username" value="<!-- current username -->" autocomplete="username" readonly hidden>
                  <div class="form-group">
                      <label for="newPassword">New Password</label>
                      <input type="password" class="form-control" id="newPassword" name="newPassword" placeholder="Enter new password" autocomplete="new-password" required>
                  </div>
              </form>
          </div>
          <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary" id="updateProfileButton">Update</button>
          </div>
      </div>
  </div>
</div>
<?php
    }
}
?>