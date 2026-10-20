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
                <th style="width: 35%;">Group</th>
                <th style="width: 10%;">Read</th>
                <th style="width: 10%;">Write</th>
                <th style="width: 10%;">Upload</th>
                <th style="width: 10%;">Remove</th>
              </tr>
            </thead>
            <tbody id="groupAccessTableBody">
                <tr id="groupAccessRowTemplate" class="d-none">
                    <td class="group-name"></td>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input access-read">
                    </td>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input access-write">
                    </td>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input access-upload">
                    </td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-danger remove-group" title="Remove group access">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    </td>
                </tr>
              <!-- Rows will be injected by JavaScript -->
            </tbody>
          </table>
        </div>

        <!-- Add Group with Typeahead -->
        <form id="addGroupForm" class="d-flex flex-wrap gap-2 mb-2">
          <div>
            <label for="groupNameInput" class="visually-hidden">Group Name</label>
            <input type="text" class="form-control" id="groupNameInput" placeholder="Start typing group name..." autocomplete="off">
          </div>
          <input type="hidden" id="cwdInput" name="cwd" value="<?php echo rtrim($cwd, '/'); ?>">
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