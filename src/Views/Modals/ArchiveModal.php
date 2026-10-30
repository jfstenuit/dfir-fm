<?php

namespace Views\Modals;

class ArchiveModal {
    /** Admin page, Logs tab: archive events to a file, then purge them (D28) */
    public static function render() {
?>
<div class="modal fade" id="archiveModal" tabindex="-1" role="dialog" aria-labelledby="archiveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="archiveModalLabel">Archive and purge events</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert d-none" id="archiveMessage" role="status"></div>

                <div id="archiveMain">
                    <p class="small text-muted mb-2">
                        Events are kept until they are archived and purged. Archiving downloads every event before a date as
                        a JSONL file; once the file is safe in cold storage, purging deletes exactly those events. Both steps
                        are logged.
                    </p>
                    <form id="archiveForm" class="row g-2 align-items-end mb-3">
                        <div class="col-sm-6 col-lg-4">
                            <label for="archiveBefore" class="form-label">Archive the events before (UTC)</label>
                            <input type="datetime-local" class="form-control form-control-sm" id="archiveBefore" name="before" step="1" required>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-archive" aria-hidden="true"></i> Archive…</button>
                        </div>
                    </form>

                    <div class="table-responsive position-relative">
                        <table id="archivesTable" class="table table-bordered table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Made (UTC)</th>
                                    <th>Events before</th>
                                    <th>Events</th>
                                    <th>SHA-256 of the file</th>
                                    <th>Purged</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Rows built by admin-logs.js -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="purgeConfirm" class="d-none">
                    <h6>Purge archive #<span id="purgeId"></span></h6>
                    <p>This deletes <strong id="purgeEvents"></strong> event(s) from before <strong id="purgeBefore"></strong> for good.
                       They will only exist in the archive file:</p>
                    <dl class="row small mb-3">
                        <dt class="col-sm-2">File</dt><dd class="col-sm-10"><code id="purgeFile"></code></dd>
                        <dt class="col-sm-2">SHA-256</dt><dd class="col-sm-10"><code id="purgeSha256" class="text-break"></code></dd>
                    </dl>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="purgeSaved">
                        <label class="form-check-label" for="purgeSaved">
                            I have saved this file in cold storage, and its SHA-256 is the one above.
                        </label>
                    </div>
                    <button type="button" class="btn btn-secondary" id="purgeCancel">Cancel</button>
                    <button type="button" class="btn btn-danger" id="purgeGo" disabled><i class="bi bi-trash" aria-hidden="true"></i> Purge</button>
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
