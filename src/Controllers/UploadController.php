<?php
// src/Controllers/UploadController.php
namespace Controllers;

use Core\Request;
use Core\App;
use Backends\StorageEngineFactory;
use Services\ChunkedUpload;
use Services\UploadException;

/**
 * POST /upload: Dropzone's chunked upload protocol. The upload logic is in
 * Services\ChunkedUpload; this maps it to HTTP.
 *
 * Field `a` selects the action:
 *   checkRights  probe before transfer: cwd, name, size
 *   (none)       one chunk: file + Dropzone's dz* fields + cwd
 *   complete     verify and commit (dzuuid); 202 while another request verifies
 *   status       state and verification progress (dzuuid)
 *   abort        cancel (dzuuid)
 *
 * Responses are JSON. Errors have a 4xx/5xx status and
 * {"status":"error","code":…,"message":…}.
 */
class UploadController
{
    public static function handle($config, $db)
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        $userId = (int)$_SESSION['user_id'];
        // Verification can take minutes: status polls must not queue behind
        // it on PHP's session lock
        session_write_close();

        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new UploadException(405, 'MethodNotAllowed', 'Use POST.');
            }

            // Over post_max_size, PHP drops the whole body: $_POST and $_FILES are empty
            if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                throw new UploadException(413, 'EntityTooLarge', 'The request is larger than the server accepts (PHP post_max_size).');
            }

            $storage = StorageEngineFactory::create($config);
            if (!$storage->healthCheck()) {
                error_log("Failed to get a handle on a Storage Engine");
                throw new UploadException(500, 'StorageError', 'The storage is unavailable.');
            }
            $uploads = new ChunkedUpload($db, $storage, App::getLogger(), $config, Request::getClientIp());

            $action = $_POST['a'] ?? null;
            $uuid = (string)($_POST['dzuuid'] ?? '');
            switch ($action) {
                case 'checkRights':
                    $size = $_POST['size'] ?? null;
                    $uploads->probe($userId, $_POST['cwd'] ?? null,
                        is_string($_POST['name'] ?? null) ? $_POST['name'] : null,
                        is_string($size) && ctype_digit($size) ? (int)$size : null);
                    self::respond(200, ['status' => 'success', 'message' => 'Authorized to upload in this folder']);
                    break;

                case null:
                    self::respond(200, ['status' => 'progress'] + self::receiveChunk($uploads, $userId));
                    break;

                case 'complete':
                case 'status':
                    // Both may run (or restart) the verification: let it finish
                    // even if the client or a proxy gives up on this request
                    ignore_user_abort(true);
                    set_time_limit(0);
                    $state = $action === 'complete' ? $uploads->complete($userId, $uuid) : $uploads->status($userId, $uuid);
                    $code = $action === 'complete' && $state['status'] !== 'completed' ? 202 : 200;
                    self::respond($code, $state + ['message' => 'Upload ' . $state['status']]);
                    break;

                case 'abort':
                    self::respond(200, $uploads->abort($userId, $uuid));
                    break;

                default:
                    throw new UploadException(400, 'InvalidArgument', 'Unknown action.');
            }
        } catch (UploadException $e) {
            self::respond($e->httpStatus, ['status' => 'error', 'code' => $e->errorCode, 'message' => $e->getMessage()] + $e->details);
        } catch (\Throwable $e) {
            error_log('Upload error: ' . $e);
            self::respond(500, ['status' => 'error', 'code' => 'InternalError', 'message' => 'Internal error.']);
        }
    }

    private static function receiveChunk(ChunkedUpload $uploads, int $userId): array
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || !is_int($file['error'] ?? null)) {
            throw new UploadException(400, 'InvalidChunk', 'No file data in the request.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    new UploadException(413, 'EntityTooLarge', 'The chunk is larger than the server accepts (PHP upload_max_filesize).'),
                UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE =>
                    new UploadException(400, 'InvalidChunk', 'The chunk was not received completely.'),
                default =>
                    new UploadException(500, 'ServerError', 'The server could not receive the chunk (PHP upload error ' . $file['error'] . ').'),
            };
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new UploadException(400, 'InvalidChunk', 'No file data in the request.');
        }

        // Streamed from PHP's temporary file to the storage, never read into memory
        $stream = fopen($file['tmp_name'], 'rb');
        if (!$stream) {
            throw new UploadException(500, 'ServerError', 'The received chunk could not be read.');
        }
        try {
            $fields = $_POST;
            $fields['name'] = $file['name'];
            return $uploads->receiveChunk($userId, $fields, $stream, (int)filesize($file['tmp_name']));
        } finally {
            fclose($stream);
            @unlink($file['tmp_name']);
        }
    }

    private static function respond(int $code, array $body): void
    {
        http_response_code($code);
        echo json_encode($body);
    }
}
