<?php

namespace Views\Modals;

class NewFolderModal {
    public static function render() {
?>
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
<?php
    }
}
?>