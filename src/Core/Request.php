<?php
// src/Core/Request.php
namespace Core;

class Request
{
    public static function getClientIp()
    {
        $headers = [
            'HTTP_X_FORWARDED_FOR', // Used by reverse proxies to forward the original IP
            'HTTP_CLIENT_IP',       // Rare, but sometimes used to pass the client IP
            'REMOTE_ADDR'           // The standard remote IP address
        ];
    
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                // If multiple IPs are listed (comma-separated), take the first one
                $ipList = explode(',', $_SERVER[$header]);
                $clientIp = trim($ipList[0]);
                if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                    return $clientIp; // Return valid public IP
                }
            }
        }
    
        return null; // If no valid IP found
    }

    public static function getBaseUrl()
    {
        // Detect if the request is over HTTPS or if X-Forwarded-Proto indicates HTTPS
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || $_SERVER['SERVER_PORT'] == 443 
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

        $scheme = $isHttps ? 'https://' : 'http://';

        // Get the host
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        // Get the script's directory (to account for subdirectories)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = rtrim(dirname($scriptName), '/');

        // Construct and return the base URL
        return $scheme . $host . $scriptDir;
    }

}