<?php

namespace Views\Modals;

use Backends\MailEngineFactory;
use Core\App;
use Middleware\AccessMiddleware;

class InviteModal {
    /**
     * The invite dialog of a subfolder (D14, D21). With mail_engine=none the
     * link comes back in the answer and is shown here, once (data-manual).
     */
    public static function render($cwd) {
        $e = fn(string $value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $manual = MailEngineFactory::isManual(App::getConfig());
?>
<div class="modal fade" id="inviteModal" tabindex="-1" role="dialog" aria-labelledby="inviteModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="inviteModalLabel">Invite a contributor to <?= $e($cwd) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="inviteForm" novalidate data-manual="<?= $manual ? 'true' : 'false' ?>">
                    <div class="mb-3">
                        <label for="inviteEmail">Email Address</label>
                        <input type="email" class="form-control" id="inviteEmail" name="inviteEmail" placeholder="Enter email address" required>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="addToDefaultGroup" checked>
                        <label class="form-check-label" for="addToDefaultGroup">Add to the default group of this folder</label>
                        <div class="form-text">
                            The group <strong><?= $e($cwd . AccessMiddleware::DEFAULT_GROUP_SUFFIX) ?></strong>, created with upload access
                            only if it doesn't exist yet. Its rights are never changed by an invitation. Unticked: the account is
                            created without any access; grant it in "Access Rights".
                        </div>
                    </div>
                </form>
                <div id="inviteLinkResult" class="d-none">
                    <label for="inviteLink" class="form-label">Invitation link</label>
                    <div class="input-group mb-2">
                        <input type="text" class="form-control" id="inviteLink" readonly>
                        <button type="button" class="btn btn-outline-secondary" id="copyInviteLink"><i class="bi bi-clipboard" aria-hidden="true"></i> Copy</button>
                    </div>
                    <div class="form-text">
                        Pass this link on to the invitee through a trusted channel. It works once and expires in one hour;
                        it is not shown again (resend from the admin page for a new one).
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" form="inviteForm" class="btn btn-primary" id="sendInviteButton"><?= $manual ? 'Create invitation link' : 'Send invitation' ?></button>
            </div>
        </div>
    </div>
</div>
<?php
    }
}
?>
