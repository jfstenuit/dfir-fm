<?php
// src/Core/Response.php
namespace Core;

class Response
{
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