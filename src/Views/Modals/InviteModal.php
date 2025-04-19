<?php

namespace Views\Modals;

class InviteModal {
    public static function render($cwd) {
?>
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
<?php
    }
}
?>