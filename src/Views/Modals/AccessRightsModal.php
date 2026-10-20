<?php

namespace Views\Modals;

class AccessRightsModal {
    public static function render($cwd) {
?>
<!-- Access Rights Modal -->
<div class="modal fade" id="accessRightsModal" tabindex="-1" role="dialog" aria-labelledby="accessRightsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="accessRightsModalLabel">Manage Access Rights</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">

        <!-- Access Rights Table -->
        <div class="table-responsive mb-4">
          <table class="table table-bordered table-striped" id="groupAccessTable">
            <thead class="table-light">
              <tr>
                <th>Group</th>
                <th>Read</th>
                <th>Write</th>
                <th>Upload</th>
                <th>Remove</th>
              </tr>
            </thead>
            <tbody id="groupAccessTableBody">
              <!-- Rows built by filemanager.js -->
            </tbody>
          </table>
        </div>

        <!-- Add a group (suggestions: datalist from filemanager.js) -->
        <form id="addGroupForm" class="d-flex flex-wrap gap-2 mb-2" novalidate>
          <div>
            <label for="groupNameInput" class="visually-hidden">Group Name</label>
            <input type="text" class="form-control" id="groupNameInput" placeholder="Start typing group name..." autocomplete="off">
          </div>
          <button type="submit" class="btn btn-primary">Add Group</button>
        </form>

        <small class="text-muted">If no match is found, the group will be created automatically.</small>

      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>
<?php
    }
}
?>