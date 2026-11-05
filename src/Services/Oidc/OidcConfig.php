<?php

// src/Services/Oidc/OidcConfig.php
namespace Services\Oidc;

/**
 * The oidc_* settings (#9, D31). OIDC is enabled only when every required
 * key is set; with none of them it's disabled and nothing changes. With
 * some of them, or with an invalid value, the configuration is refused:
 * index.php answers 503 rather than run without the sign-in the operator
 * meant to configure.
 */
final class OidcConfig
{
    public const DISABLED = 'disabled';
    public const ENABLED = 'enabled';
    public const PARTIAL = 'partial';

    public const REQUIRED = [
        'oidc_issuer', 'oidc_client_id', 'oidc_client_secret', 'oidc_redirect_uri',
        'oidc_email_domain', 'oidc_admin_claim', 'oidc_admin_value',
    ];

    /** Entra's multi-tenant endpoints: any tenant's users could sign in */
    private const MULTI_TENANT_HOSTS = [
        'login.microsoftonline.com', 'login.microsoftonline.us', 'login.partner.microsoftonline.cn',
        'login.windows.net', 'sts.windows.net',
    ];
    private const MULTI_TENANT_SEGMENTS = ['common', 'organizations', 'consumers'];

    private string $state;
    /** @var list<string> */
    private array $missing = [];
    /** @var list<string> */
    private array $errors = [];
    /** @var array<string,string> */
    private array $values = [];

    private string $scopes = 'openid email profile';
    private string $emailClaim = 'email';
    private bool $requireEmailVerified = true;
    private bool $requireMfa = false;
    private ?int $maxAge = null;
    private string $displayName = 'SSO';
    private int $rememberDays = 365;

    private function __construct()
    {
    }

    public static function fromConfig(array $config): self
    {
        $self = new self();
        $value = fn(string $key): string => trim((string)($config[$key] ?? ''));

        foreach (self::REQUIRED as $key) {
            if ($value($key) === '') {
                $self->missing[] = $key;
            } else {
                $self->values[$key] = $value($key);
            }
        }
        if (count($self->missing) === count(self::REQUIRED)) {
            $self->state = self::DISABLED;
            $self->missing = [];
            return $self;
        }
        if ($self->missing !== []) {
            $self->state = self::PARTIAL;
            return $self;
        }
        $self->state = self::ENABLED;

        foreach (['oidc_issuer', 'oidc_redirect_uri'] as $key) {
            if (!self::isHttpsUrl($self->values[$key], $key === 'oidc_issuer')) {
                $self->errors[] = "$key must be an https:// URL" . ($key === 'oidc_issuer' ? ' without query or fragment' : '')
                    . ": '{$self->values[$key]}'";
            }
        }
        if (self::isMultiTenant($self->values['oidc_issuer'])) {
            $self->errors[] = "oidc_issuer is a multi-tenant endpoint, which any tenant's users could sign in through: "
                . "'{$self->values['oidc_issuer']}' (use the issuer of your own tenant)";
        }
        $domain = strtolower($self->values['oidc_email_domain']);
        if (!self::isDomain($domain)) {
            $self->errors[] = "oidc_email_domain must be a bare domain name, such as example.com: '{$self->values['oidc_email_domain']}'";
        }
        $self->values['oidc_email_domain'] = $domain;

        if ($value('oidc_scopes') !== '') {
            $self->scopes = preg_replace('/\s+/', ' ', $value('oidc_scopes'));
            if (!in_array('openid', explode(' ', $self->scopes), true)) {
                $self->errors[] = "oidc_scopes must include openid: '{$self->scopes}'";
            }
        }
        if ($value('oidc_email_claim') !== '') {
            $self->emailClaim = $value('oidc_email_claim');
        }
        if ($value('oidc_display_name') !== '') {
            $self->displayName = $value('oidc_display_name');
        }
        $self->requireEmailVerified = $self->boolean($value('oidc_require_email_verified'), 'oidc_require_email_verified', true);
        $self->requireMfa = $self->boolean($value('oidc_require_mfa'), 'oidc_require_mfa', false);
        $self->maxAge = $self->positiveInteger($value('oidc_max_age'), 'oidc_max_age', null);
        $self->rememberDays = $self->positiveInteger($value('oidc_remember_days'), 'oidc_remember_days', 365);

        return $self;
    }

    public function state(): string
    {
        return $this->state;
    }

    /** OIDC is configured and valid: in-domain logins go through the IdP */
    public function isEnabled(): bool
    {
        return $this->state === self::ENABLED && $this->errors === [];
    }

    /** @return list<string> the required keys not set, for a partial configuration */
    public function missingKeys(): array
    {
        return $this->missing;
    }

    /** @return list<string> the invalid values, for an otherwise complete configuration */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Why the application must not run: a partial or invalid configuration.
     * Null when OIDC is disabled or valid. Never contains the client secret.
     */
    public function problem(): ?string
    {
        if ($this->state === self::PARTIAL) {
            return 'partially configured, missing ' . implode(', ', $this->missing)
                . ' (set every required oidc_* key, or none)';
        }
        return $this->errors === [] ? null : implode('; ', $this->errors);
    }

    public function issuer(): string
    {
        return $this->values['oidc_issuer'];
    }

    public function clientId(): string
    {
        return $this->values['oidc_client_id'];
    }

    public function clientSecret(): string
    {
        return $this->values['oidc_client_secret'];
    }

    public function redirectUri(): string
    {
        return $this->values['oidc_redirect_uri'];
    }

    /** Lowercased */
    public function emailDomain(): string
    {
        return $this->values['oidc_email_domain'];
    }

    public function adminClaim(): string
    {
        return $this->values['oidc_admin_claim'];
    }

    public function adminValue(): string
    {
        return $this->values['oidc_admin_value'];
    }

    public function scopes(): string
    {
        return $this->scopes;
    }

    public function emailClaim(): string
    {
        return $this->emailClaim;
    }

    public function requireEmailVerified(): bool
    {
        return $this->requireEmailVerified;
    }

    public function requireMfa(): bool
    {
        return $this->requireMfa;
    }

    /** Seconds; null: no max_age is sent */
    public function maxAge(): ?int
    {
        return $this->maxAge;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function rememberDays(): int
    {
        return $this->rememberDays;
    }

    /**
     * Whether an address belongs to oidc_email_domain: an exact,
     * case-insensitive match on the part after the last @, with a non-empty
     * part before it. Subdomains don't match. False while OIDC is disabled.
     */
    public function inDomain(string $address): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $at = strrpos($address, '@');
        if ($at === false || $at === 0) {
            return false;
        }
        return strtolower(substr($address, $at + 1)) === $this->emailDomain();
    }

    private static function isHttpsUrl(string $url, bool $issuer): bool
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['fragment'])) {
            return false;
        }
        return !$issuer || !isset($parts['query']);
    }

    private static function isMultiTenant(string $issuer): bool
    {
        if (str_contains($issuer, '{')) {
            return true; // {tenantid}, the placeholder of the multi-tenant discovery documents
        }
        $host = strtolower((string)parse_url($issuer, PHP_URL_HOST));
        $segment = strtolower(explode('/', trim((string)parse_url($issuer, PHP_URL_PATH), '/'))[0]);
        return in_array($host, self::MULTI_TENANT_HOSTS, true) && in_array($segment, self::MULTI_TENANT_SEGMENTS, true);
    }

    /** At least two labels of letters, digits and inner hyphens (punycode for IDNs) */
    private static function isDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain) === 1
            && !ctype_digit(substr($domain, strrpos($domain, '.') + 1));
    }

    private function boolean(string $setting, string $key, bool $default): bool
    {
        if ($setting === '') {
            return $default;
        }
        $value = filter_var($setting, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            $this->errors[] = "$key must be true or false: '$setting'";
            return $default;
        }
        return $value;
    }

    private function positiveInteger(string $setting, string $key, ?int $default): ?int
    {
        if ($setting === '') {
            return $default;
        }
        if (!ctype_digit($setting) || (int)$setting < 1 || strlen($setting) > 9) {
            $this->errors[] = "$key must be a positive integer: '$setting'";
            return $default;
        }
        return (int)$setting;
    }
}
