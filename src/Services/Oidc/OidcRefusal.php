<?php

// src/Services/Oidc/OidcRefusal.php
namespace Services\Oidc;

/**
 * A sign-in refused for a reason logged as login_oidc_failure's cause
 * (signature, claims, nonce, unverified_email, foreign_domain, …). The
 * message is for the log, never for the user.
 */
final class OidcRefusal extends OidcException
{
    public function __construct(public readonly string $cause, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
