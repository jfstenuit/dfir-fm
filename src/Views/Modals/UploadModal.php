<?php

namespace Views\Modals;

class UploadModal {
    public static function render($cwd) {
?>
<div class="modal fade" id="uploadModal" tabindex="-1" role="dialog" aria-labelledby="uploadModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadModalLabel">Upload Files</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Dropzone Form -->
                <form id="fileUploader" action="upload" method="post" enctype="multipart/form-data">
                    <input type="hidden" id="cwd" name="cwd" value="<?php echo htmlspecialchars(rtrim($cwd, '/'), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="dz-message">Drop files here or click to upload.</div>
                </form>
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