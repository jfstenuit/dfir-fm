<?php

// src/Services/Oidc/HttpsClient.php
namespace Services\Oidc;

/**
 * Every call to the IdP goes through this: CurlHttpsClient in production,
 * a stub in the tests.
 */
interface HttpsClient
{
    /**
     * @param list<string> $headers extra request headers, e.g. "Authorization: Bearer …"
     * @throws OidcException on a transport failure (any HTTP status is returned)
     */
    public function get(string $url, array $headers = []): HttpsResponse;

    /**
     * POST an application/x-www-form-urlencoded body, with HTTP Basic
     * authentication when $basicAuth is [user, password]
     * @param array<string,string> $fields
     * @param array{0:string,1:string}|null $basicAuth
     * @throws OidcException on a transport failure
     */
    public function postForm(string $url, array $fields, ?array $basicAuth = null): HttpsResponse;
}
