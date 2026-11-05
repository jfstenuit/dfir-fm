<?php

// src/Services/Oidc/CurlHttpsClient.php
namespace Services\Oidc;

/**
 * HTTPS only, with peer and host verification, short timeouts, no
 * redirects followed and a cap on the answer's size: an unreachable or
 * misbehaving IdP must not hold PHP workers or fill memory.
 *
 * With a CA file (oidc_ca_file), it is the only trust store: the system
 * CAs, file and directory, are not used.
 */
final class CurlHttpsClient implements HttpsClient
{
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT = 10;
    public const MAX_BODY = 1024 * 1024;

    /** A CA directory that can't exist (/dev/null is a file): libcurl's default one is not read */
    private const NO_CA_PATH = '/dev/null/no-ca-path';

    public function __construct(private ?string $caFile = null)
    {
    }

    public function get(string $url, array $headers = []): HttpsResponse
    {
        return $this->request($url, [CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)]);
    }

    public function postForm(string $url, array $fields, ?array $basicAuth = null): HttpsResponse
    {
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC1738),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ];
        if ($basicAuth !== null) {
            // RFC 6749 2.3.1: client id and secret are form-urlencoded before Basic encoding
            $options[CURLOPT_HTTPHEADER][] = 'Authorization: Basic '
                . base64_encode(urlencode($basicAuth[0]) . ':' . urlencode($basicAuth[1]));
        }
        return $this->request($url, $options);
    }

    private function request(string $url, array $options): HttpsResponse
    {
        if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new OidcException("Refusing a non-HTTPS IdP URL: $url");
        }

        $body = '';
        $tooLarge = false;
        $location = null;
        $curl = curl_init($url);
        curl_setopt_array($curl, $options + [
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        curl_setopt_array($curl, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => 'DFIR-FM',
        ]);
        if ($this->caFile !== null) {
            curl_setopt_array($curl, [CURLOPT_CAINFO => $this->caFile, CURLOPT_CAPATH => self::NO_CA_PATH]);
        }
        curl_setopt_array($curl, [
            CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, strlen('Location:')));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY) {
                    $tooLarge = true;
                    return 0; // aborts the transfer
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if ($ok === false) {
            throw new OidcException($tooLarge
                ? 'IdP answer over ' . self::MAX_BODY . " bytes: $url"
                : "IdP request failed: $url: $error");
        }
        return new HttpsResponse($status, $body, $location);
    }
}
