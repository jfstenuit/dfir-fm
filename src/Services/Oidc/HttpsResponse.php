<?php

// src/Services/Oidc/HttpsResponse.php
namespace Services\Oidc;

final class HttpsResponse
{
    /** @param ?string $location the Location header (redirects are never followed) */
    public function __construct(public readonly int $status, public readonly string $body, public readonly ?string $location = null)
    {
    }

    /** The body as a JSON object, or null if it isn't one */
    public function json(): ?array
    {
        $data = json_decode($this->body, true);
        return is_array($data) && !array_is_list($data) ? $data : null;
    }
}
