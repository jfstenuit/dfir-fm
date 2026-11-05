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
        header('Retry-After: 30');
        self::triggerUnavailable('logging_failed', 'The application is stopped: an event could not be logged. It resumes on its own once logging works again; the operator must fix the log destination (see the web server error log).');
    }

    /** APCu is missing or disabled (#14): the application refuses to run */
    public static function triggerApcuUnavailable(string $problem)
    {
        self::triggerUnavailable('apcu_unavailable', "The application can't run: it needs APCu for login rate limiting, and $problem");
    }

    /**
     * The oidc_* settings are partial or invalid (#9): the application
     * refuses to run. The reason is in the PHP error log only.
     */
    public static function triggerOidcMisconfigured()
    {
        self::triggerUnavailable('oidc_misconfigured', "The application can't run: its OIDC sign-in configuration is incomplete or invalid. The operator must fix the oidc_* settings (see the web server error log).");
    }

    /** 503: JSON for API calls (POST), a page otherwise */
    private static function triggerUnavailable(string $code, string $message)
    {
        http_response_code(503);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'code' => $code, 'message' => $message]);
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