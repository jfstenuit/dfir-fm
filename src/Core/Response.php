<?php
// src/Core/Response.php
namespace Core;

class Response
{
    /**
     * Content-Disposition header value for a download (RFC 6266): an ASCII
     * fallback `filename` for old clients plus the exact UTF-8 name in
     * `filename*`. Quotes, backslashes and control characters can't break
     * out of the header; `;` is also replaced in the fallback for parsers
     * that split parameters without honouring quotes.
     */
    public static function contentDisposition(string $filename, string $type = 'attachment'): string
    {
        $fallback = preg_replace('/[^\x20-\x7e]|["\\\\%;]/', '_', $filename);
        if ($fallback === '' || $fallback === null) {
            $fallback = 'download';
        }

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $fallback, rawurlencode($filename));
    }

    public static function triggerNotFound()
    {
        http_response_code(404);
        echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">
<html><head>
<title>404 Not Found</title>
</head><body>
<h1>Not Found</h1>
<p>The requested URL was not found on this server.</p>
</body></html>";
    }

    public static function triggerAccessDenied()
    {
        http_response_code(403);
        echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">
<html><head>
<title>403 Unauthorized</title>
</head><body>
<h1>Unauthorized</h1>
<p>You don't have access to this ressource.</p>
</body></html>";
    }

    /**
     * The application is stopped because an event could not be logged
     * (D29). API calls (POST) get JSON, pages a 503 page.
     */
    public static function triggerLoggingFailure()
    {
        http_response_code(503);
        header('Retry-After: 30');
        $message = 'The application is stopped: an event could not be logged. It resumes on its own once logging works again; the operator must fix the log destination (see the web server error log).';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'code' => 'logging_failed', 'message' => $message]);
            return;
        }
        echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">
<html><head>
<title>503 Service Unavailable</title>
</head><body>
<h1>Service Unavailable</h1>
<p>" . htmlspecialchars($message) . "</p>
</body></html>";
    }

    public static function triggerSystemError()
    {
        http_response_code(500);
        echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">
<html><head>
<title>500 Internal Server Error</title>
</head><body>
<h1>Internal Server Error</h1>
<p>An unexpected error occured.</p>
</body></html>";
    }

}