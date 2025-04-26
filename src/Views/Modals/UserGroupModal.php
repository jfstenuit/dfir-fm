<?php

namespace Views\Modals;

class UserGroupModal  {
    public static function render() {
?>
<!-- Add User to Group Modal -->
<div class="modal fade" id="addUserToGroupModal" tabindex="-1" role="dialog" aria-labelledby="addUserToGroupModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm" role="document">
    <div class="modal-content">
    
      <div class="modal-header">
        <h5 class="modal-title">Assign Group to User</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span>&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <form id="assignGroupForm">
          <div class="form-group">
            <label for="groupAssignInput" class="sr-only">Group</label>
            <input type="text" class="form-control" id="groupAssignInput" placeholder="Start typing group name..." autocomplete="off">
          </div>
          <input type="hidden" id="targetUserEmail" name="email" value="">
          <button type="submit" class="btn btn-primary btn-block">Add Group</button>
        </form>
        <small class="text-muted">If no match is found, the group will be created automatically.</small>
      </div>

    </div>
  </div>
</div>
<?php
    }
}
?>