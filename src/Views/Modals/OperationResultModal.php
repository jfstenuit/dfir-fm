<?php

namespace Views\Modals;

class OperationResultModal {
    public static function render() {
?>
<div class="modal fade" id="operationResultModal" tabindex="-1" role="dialog" aria-labelledby="operationResultModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="operationResultModalLabel">Operation Result</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="operationResultMessage">
                <!-- Success or Failure message will be displayed here dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php
    }
}
?>