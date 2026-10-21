<?php
// src/Controllers/RefreshController.php
namespace Controllers;

use Core\App;
use Core\Request;
use Backends\StorageEngineFactory;
use Middleware\AccessMiddleware;
use Services\RefreshException;
use Services\RefreshJobs;

/**
 * "Refresh from storage", POST / with field `a` (administrators only; the
 * logic is in Services\RefreshJobs):
 *   refresh        cwd: compare the folder with storage, start a job
 *   refreshStep    refresh_id: hash the next file (may take minutes)
 *   refreshStatus  refresh_id, after: state, progress and the results of
 *                  the files done after position `after`
 *   refreshCancel  refresh_id
 *
 * Responses are JSON: {"success":true,…}, or a 4xx/5xx status and
 * {"success":false,"code":…,"message":…}.
 */
class RefreshController
{
    public const ACTIONS = ['refresh', 'refreshStep', 'refreshStatus', 'refreshCancel'];

    public static function handle($config, $db, string $action)
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        $userId = (int)$_SESSION['user_id'];
        // A step can take minutes: status polls must not queue behind it on
        // PHP's session lock
        session_write_close();

        try {
            if (!AccessMiddleware::isAdmin($db, $userId)) {
                App::getLogger()->log('directory_refresh_denied', ['cause' => 'Not an administrator', 'action' => $action]);
                throw new RefreshException(403, 'Forbidden', 'Only administrators can refresh a folder from storage.');
            }

            $storage = StorageEngineFactory::create($config);
            if (!$storage->healthCheck()) {
                error_log("Failed to get a handle on a Storage Engine");
                throw new RefreshException(500, 'StorageError', 'The storage is unavailable.');
            }
            $jobs = new RefreshJobs($db, $storage, App::getLogger(), $config, Request::getClientIp());
            $jobId = $action === 'refresh' ? 0 : self::jobId($_POST['refresh_id'] ?? null);

            switch ($action) {
                case 'refresh':
                    self::respond(200, $jobs->start($userId, $_POST['cwd'] ?? null));
                    break;

                case 'refreshStep':
                    // Let the step finish (and record its result) even if the
                    // client or a proxy gives up on this request: the client
                    // then follows it with refreshStatus
                    ignore_user_abort(true);
                    set_time_limit(0);
                    self::respond(200, $jobs->step($userId, $jobId));
                    break;

                case 'refreshStatus':
                    $after = $_POST['after'] ?? '0';
                    self::respond(200, $jobs->status($userId, $jobId, is_string($after) && ctype_digit($after) ? (int)$after : 0));
                    break;

                case 'refreshCancel':
                    self::respond(200, $jobs->cancel($userId, $jobId));
                    break;
            }
        } catch (RefreshException $e) {
            self::respond($e->httpStatus, ['success' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()] + $e->details);
        } catch (\Throwable $e) {
            error_log('Refresh error: ' . $e);
            self::respond(500, ['success' => false, 'code' => 'InternalError', 'message' => 'Internal error.']);
        }
    }

    /** The job id of the step, status and cancel actions */
    private static function jobId(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^[1-9]\d{0,17}$/', $value)) {
            throw new RefreshException(400, 'InvalidArgument', 'Missing or invalid refresh_id.');
        }
        return (int)$value;
    }

    private static function respond(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode(($body['success'] ?? true) === false ? $body : ['success' => true] + $body);
    }
}
?>
