<?php

namespace Views\Modals;

class EventRecordModal {
    /** Admin page, Logs tab: one event's full record, as stored */
    public static function render() {
?>
<div class="modal fade" id="eventRecordModal" tabindex="-1" role="dialog" aria-labelledby="eventRecordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="eventRecordModalLabel">Event</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <pre class="event-record" id="eventRecord"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary me-auto" id="copyEventRecord"><i class="bi bi-clipboard" aria-hidden="true"></i> Copy</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php
    }
}
?>
