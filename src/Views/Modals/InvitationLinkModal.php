<?php

namespace Views\Modals;

class InvitationLinkModal {
    /** Admin page, mail_engine=none: the link of a resent invitation, shown once */
    public static function render() {
?>
<div class="modal fade" id="invitationLinkModal" tabindex="-1" role="dialog" aria-labelledby="invitationLinkModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="invitationLinkModalLabel">Invitation link</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>New invitation link for <strong id="invitationLinkUser"></strong>:</p>
                <div class="input-group mb-2">
                    <input type="text" class="form-control" id="invitationLink" readonly aria-label="Invitation link">
                    <button type="button" class="btn btn-outline-secondary" id="copyInvitationLink"><i class="bi bi-clipboard" aria-hidden="true"></i> Copy</button>
                </div>
                <div class="form-text">
                    Pass this link on through a trusted channel. It works once and expires in one hour; it is not shown again.
                </div>
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
