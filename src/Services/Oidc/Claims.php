<?php

// src/Services/Oidc/Claims.php
namespace Services\Oidc;

/**
 * The claims of a validated ID token, and of the userinfo answer when the
 * admin claim isn't in the token (some IdPs leave groups out of it).
 */
final class Claims
{
    private ?array $userinfo = null;

    public function __construct(private array $claims)
    {
    }

    /**
     * Add the userinfo answer. Its sub must be the token's (OIDC Core
     * 5.3.4): otherwise it's another user's, and refused.
     * @throws OidcRefusal
     */
    public function withUserinfo(array $userinfo): self
    {
        if (($userinfo['sub'] ?? null) !== $this->subject()) {
            throw new OidcRefusal('claims', "The userinfo sub isn't the ID token's");
        }
        $copy = clone $this;
        $copy->userinfo = $userinfo;
        return $copy;
    }

    public function all(): array
    {
        return $this->claims;
    }

    public function subject(): string
    {
        return $this->claims['sub'];
    }

    public function issuer(): string
    {
        return $this->claims['iss'];
    }

    /**
     * The value at a dotted path (realm_access.roles) in the token, or null.
     * A claim whose name contains a dot (Auth0's namespaced
     * https://example.com/roles) is found by its full name first.
     */
    public function get(string $path): mixed
    {
        return self::at($this->claims, $path);
    }

    /** The address in oidc_email_claim: a non-empty string, or null */
    public function email(string $claim): ?string
    {
        $email = $this->get($claim);
        return is_string($email) && $email !== '' ? $email : null;
    }

    /** email_verified is true (some IdPs send the string "true") */
    public function emailVerified(): bool
    {
        return in_array($this->claims['email_verified'] ?? null, [true, 'true'], true);
    }

    /**
     * Entra ID's overage: too many groups for the token, which then names a
     * source (_claim_names) instead of listing them
     */
    public function hasOverage(string $path): bool
    {
        $names = $this->claims['_claim_names'] ?? null;
        return is_array($names) && (array_key_exists($path, $names) || array_key_exists(explode('.', $path)[0], $names));
    }

    /** Whether the admin claim is in the token; if not, userinfo is asked for it */
    public function hasInToken(string $path): bool
    {
        return $this->get($path) !== null;
    }

    /** The claim at $path from the token, or else from userinfo, or null */
    public function claim(string $path): mixed
    {
        return $this->get($path) ?? ($this->userinfo !== null ? self::at($this->userinfo, $path) : null);
    }

    /** Whether the claim (token, else userinfo) is $value, or a list containing it; compared exactly */
    public function contains(string $path, string $value): bool
    {
        $claim = $this->claim($path);
        return is_array($claim) ? array_is_list($claim) && in_array($value, $claim, true) : $claim === $value;
    }

    private static function at(array $claims, string $path): mixed
    {
        if (array_key_exists($path, $claims)) {
            return $claims[$path];
        }
        $value = $claims;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || array_is_list($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
