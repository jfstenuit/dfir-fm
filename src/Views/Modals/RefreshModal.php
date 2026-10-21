<?php

namespace Views\Modals;

/**
 * "Refresh from storage" (administrators): compares the current folder with
 * its content in storage, then hashes its files one by one
 * (assets/js/filemanager.js, refresh section). Static backdrop, no Escape:
 * a stray click or key would hide a refresh still running.
 */
class RefreshModal {
    public static function render($cwd) {
        $e = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<div class="modal fade" id="refreshModal" tabindex="-1" role="dialog" aria-labelledby="refreshModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="refreshModalLabel">Refresh <?= $cwd === '/' ? 'the root folder' : $e($cwd) ?> from storage</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="refreshCloseX"></button>
            </div>
            <div class="modal-body">
                <div id="refreshIntro">
                    <p>Compares this folder with its content in storage (this folder only, not its subfolders):</p>
                    <ul>
                        <li>files placed in storage outside the application (copied with <code>rsync</code>, restored from a backup) are hashed and <strong>imported</strong>, recorded as yours, from your address;</li>
                        <li>new subfolders are recorded, without access rights of their own;</li>
                        <li>the files already recorded are hashed again and compared with their records. A difference is flagged and logged, the record is never changed;</li>
                        <li>recorded files that are no longer in storage are flagged as missing.</li>
                    </ul>
                    <p class="mb-0">Hashing reads every file in full: large files take as long as the storage needs to deliver them.</p>
                </div>

                <div id="refreshSummary" class="d-none">
                    <p id="refreshCounts" class="mb-2"></p>
                    <div id="refreshMissing" class="alert alert-warning py-2 d-none" role="alert"></div>
                    <div id="refreshSkipped" class="alert alert-secondary py-2 d-none" role="status"></div>
                </div>

                <div id="refreshProgress" class="d-none mb-3">
                    <div class="d-flex justify-content-between">
                        <span id="refreshCurrent" class="text-truncate me-2"></span>
                        <span id="refreshFileCount" class="text-nowrap"></span>
                    </div>
                    <div class="progress" role="progressbar" aria-label="Hashing progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <div class="progress-bar" id="refreshBar"></div>
                    </div>
                </div>

                <div id="refreshOutcome" class="d-none" role="status"></div>

                <table class="table table-sm d-none" id="refreshResults">
                    <thead class="table-light">
                        <tr>
                            <th>File</th>
                            <th class="text-end">Size</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="refreshCloseButton">Close</button>
                <button type="button" class="btn btn-outline-danger d-none" id="refreshCancelButton">Stop</button>
                <button type="button" class="btn btn-primary" id="refreshStartButton">Start</button>
            </div>
        </div>
    </div>
</div>
<?php
    }
}
?>
