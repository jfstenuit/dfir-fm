<?php
namespace Tests\Unit\Services\Oidc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Services\Oidc\OidcConfig;

final class OidcConfigTest extends TestCase
{
    public const COMPLETE = [
        'oidc_issuer' => 'https://idp.example.com/application/o/dfir-fm/',
        'oidc_client_id' => 'dfir-fm',
        'oidc_client_secret' => 'S3cr3t-value',
        'oidc_redirect_uri' => 'https://fm.example.com/login/oidc/callback',
        'oidc_email_domain' => 'Example.COM',
        'oidc_admin_claim' => 'groups',
        'oidc_admin_value' => 'dfir-fm-admins',
    ];

    public function testDisabledWithoutAnyRequiredKey(): void
    {
        foreach ([[], ['oidc_issuer' => '  ', 'oidc_display_name' => 'Corp SSO', 'oidc_remember_days' => 'abc']] as $config) {
            $oidc = OidcConfig::fromConfig($config);
            $this->assertSame(OidcConfig::DISABLED, $oidc->state());
            $this->assertFalse($oidc->isEnabled());
            $this->assertNull($oidc->problem(), 'optional keys alone are ignored');
            $this->assertFalse($oidc->inDomain('alice@example.com'));
        }
    }

    public function testEnabledWithDefaults(): void
    {
        $oidc = OidcConfig::fromConfig(self::COMPLETE);
        $this->assertSame(OidcConfig::ENABLED, $oidc->state());
        $this->assertTrue($oidc->isEnabled());
        $this->assertNull($oidc->problem());
        $this->assertSame('https://idp.example.com/application/o/dfir-fm/', $oidc->issuer());
        $this->assertSame('dfir-fm', $oidc->clientId());
        $this->assertSame('S3cr3t-value', $oidc->clientSecret());
        $this->assertSame('https://fm.example.com/login/oidc/callback', $oidc->redirectUri());
        $this->assertSame('example.com', $oidc->emailDomain(), 'lowercased');
        $this->assertSame('groups', $oidc->adminClaim());
        $this->assertSame('dfir-fm-admins', $oidc->adminValue());
        $this->assertSame('openid email profile', $oidc->scopes());
        $this->assertSame('email', $oidc->emailClaim());
        $this->assertTrue($oidc->requireEmailVerified());
        $this->assertFalse($oidc->requireMfa());
        $this->assertNull($oidc->maxAge());
        $this->assertSame('SSO', $oidc->displayName());
        $this->assertSame(365, $oidc->rememberDays());
    }

    public function testOptionalKeys(): void
    {
        $oidc = OidcConfig::fromConfig(self::COMPLETE + [
            'oidc_scopes' => "openid  email\tprofile groups",
            'oidc_email_claim' => 'upn',
            'oidc_require_email_verified' => 'false',
            'oidc_require_mfa' => 'yes',
            'oidc_max_age' => '3600',
            'oidc_display_name' => 'Corp SSO',
            'oidc_remember_days' => '30',
        ]);
        $this->assertNull($oidc->problem());
        $this->assertSame('openid email profile groups', $oidc->scopes());
        $this->assertSame('upn', $oidc->emailClaim());
        $this->assertFalse($oidc->requireEmailVerified());
        $this->assertTrue($oidc->requireMfa());
        $this->assertSame(3600, $oidc->maxAge());
        $this->assertSame('Corp SSO', $oidc->displayName());
        $this->assertSame(30, $oidc->rememberDays());
    }

    public function testPartialListsTheMissingKeys(): void
    {
        $config = self::COMPLETE;
        unset($config['oidc_client_secret']);
        $config['oidc_admin_value'] = ' ';

        $oidc = OidcConfig::fromConfig($config);
        $this->assertSame(OidcConfig::PARTIAL, $oidc->state());
        $this->assertFalse($oidc->isEnabled());
        $this->assertSame(['oidc_client_secret', 'oidc_admin_value'], $oidc->missingKeys());
        $this->assertStringContainsString('missing oidc_client_secret, oidc_admin_value', $oidc->problem());
        $this->assertFalse($oidc->inDomain('alice@example.com'));
    }

    public static function invalidCases(): array
    {
        return [
            'http issuer' => ['oidc_issuer', 'http://idp.example.com/', 'oidc_issuer must be an https:// URL'],
            'issuer with query' => ['oidc_issuer', 'https://idp.example.com/?tenant=x', 'oidc_issuer must be an https:// URL'],
            'issuer not a URL' => ['oidc_issuer', 'idp.example.com', 'oidc_issuer must be an https:// URL'],
            'issuer with credentials' => ['oidc_issuer', 'https://u:p@idp.example.com/', 'oidc_issuer must be an https:// URL'],
            'http redirect' => ['oidc_redirect_uri', 'http://fm.example.com/login/oidc/callback', 'oidc_redirect_uri must be an https:// URL'],
            'redirect with fragment' => ['oidc_redirect_uri', 'https://fm.example.com/cb#x', 'oidc_redirect_uri must be an https:// URL'],
            'domain with @' => ['oidc_email_domain', '@example.com', 'oidc_email_domain must be a bare domain'],
            'domain with scheme' => ['oidc_email_domain', 'https://example.com', 'oidc_email_domain must be a bare domain'],
            'single label' => ['oidc_email_domain', 'localhost', 'oidc_email_domain must be a bare domain'],
            'trailing dot' => ['oidc_email_domain', 'example.com.', 'oidc_email_domain must be a bare domain'],
            'IP address' => ['oidc_email_domain', '192.0.2.1', 'oidc_email_domain must be a bare domain'],
            'remember days zero' => ['oidc_remember_days', '0', 'oidc_remember_days must be a positive integer'],
            'remember days text' => ['oidc_remember_days', 'a year', 'oidc_remember_days must be a positive integer'],
            'max age negative' => ['oidc_max_age', '-60', 'oidc_max_age must be a positive integer'],
            'max age decimal' => ['oidc_max_age', '1.5', 'oidc_max_age must be a positive integer'],
            'boolean' => ['oidc_require_mfa', 'maybe', 'oidc_require_mfa must be true or false'],
            'scopes without openid' => ['oidc_scopes', 'email profile', 'oidc_scopes must include openid'],
        ];
    }

    #[DataProvider('invalidCases')]
    public function testValidation(string $key, string $value, string $message): void
    {
        $oidc = OidcConfig::fromConfig([$key => $value] + self::COMPLETE);
        $this->assertSame(OidcConfig::ENABLED, $oidc->state());
        $this->assertFalse($oidc->isEnabled());
        $this->assertStringContainsString($message, $oidc->problem());
        $this->assertFalse($oidc->inDomain('alice@example.com'));
    }

    public function testCaFile(): void
    {
        $this->assertNull(OidcConfig::fromConfig(self::COMPLETE)->caFile(), 'the system store by default');

        $dir = sys_get_temp_dir() . '/dfirfm-ca-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test CA'], $key), null, $key, 1);
            openssl_x509_export($certificate, $pem);
            file_put_contents("$dir/ca.pem", "# comment\n$pem$pem");
            file_put_contents("$dir/empty.pem", '');
            file_put_contents("$dir/key.pem", openssl_pkey_get_details($key)['key']);
            file_put_contents("$dir/broken.pem", "$pem-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n");
            file_put_contents("$dir/unreadable.pem", $pem);
            chmod("$dir/unreadable.pem", 0);

            $oidc = OidcConfig::fromConfig(['oidc_ca_file' => "$dir/ca.pem"] + self::COMPLETE);
            $this->assertNull($oidc->problem());
            $this->assertSame("$dir/ca.pem", $oidc->caFile());

            $cases = [
                'relative.pem' => 'must be an absolute path',
                "$dir/missing.pem" => 'is not a readable file',
                $dir => 'is not a readable file',
                "$dir/empty.pem" => 'holds no PEM certificate',
                "$dir/key.pem" => 'holds no PEM certificate',
                "$dir/broken.pem" => 'holds a certificate that cannot be parsed',
            ];
            if (!is_readable("$dir/unreadable.pem")) { // as root, it is
                $cases["$dir/unreadable.pem"] = 'is not a readable file';
            }
            foreach ($cases as $path => $message) {
                $oidc = OidcConfig::fromConfig(['oidc_ca_file' => $path] + self::COMPLETE);
                $this->assertFalse($oidc->isEnabled(), $path);
                $this->assertStringContainsString("oidc_ca_file $message: '$path'", (string)$oidc->problem());
            }
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function testProblemNeverContainsTheSecret(): void
    {
        $oidc = OidcConfig::fromConfig(['oidc_issuer' => 'http://x', 'oidc_email_domain' => 'x'] + self::COMPLETE);
        $this->assertStringNotContainsString('S3cr3t-value', $oidc->problem());
    }

    public function testIdnDomainInPunycode(): void
    {
        $this->assertNull(OidcConfig::fromConfig(['oidc_email_domain' => 'xn--bcher-kva.xn--p1ai'] + self::COMPLETE)->problem());
    }

    public static function multiTenantCases(): array
    {
        return [
            'common v2' => ['https://login.microsoftonline.com/common/v2.0'],
            'organizations' => ['https://login.microsoftonline.com/organizations/v2.0'],
            'consumers' => ['https://login.microsoftonline.com/Consumers/v2.0'],
            'v1 common' => ['https://sts.windows.net/common/'],
            'tenant placeholder' => ['https://login.microsoftonline.com/{tenantid}/v2.0'],
        ];
    }

    #[DataProvider('multiTenantCases')]
    public function testMultiTenantIssuerRefused(string $issuer): void
    {
        $oidc = OidcConfig::fromConfig(['oidc_issuer' => $issuer] + self::COMPLETE);
        $this->assertFalse($oidc->isEnabled());
        $this->assertStringContainsString('multi-tenant', $oidc->problem());
    }

    public function testSingleTenantIssuersAccepted(): void
    {
        foreach (['https://login.microsoftonline.com/6f1c2a3b-0000-4000-8000-000000000001/v2.0',
                  'https://idp.example.com/common/', // only Microsoft's hosts are multi-tenant
                  'https://keycloak.example.com/realms/corp'] as $issuer) {
            $this->assertNull(OidcConfig::fromConfig(['oidc_issuer' => $issuer] + self::COMPLETE)->problem(), $issuer);
        }
    }

    public static function domainCases(): array
    {
        return [
            'exact' => ['alice@example.com', true],
            'case' => ['Alice@EXAMPLE.Com', true],
            'subdomain' => ['alice@mail.example.com', false],
            'parent of a suffix' => ['alice@notexample.com', false],
            'other domain' => ['alice@example.org', false],
            'several @, last decides' => ['"a@b"@example.com', true],
            'several @, other last' => ['alice@example.com@evil.test', false],
            'empty local part' => ['@example.com', false],
            'no @' => ['example.com', false],
            'empty' => ['', false],
            'trailing dot' => ['alice@example.com.', false],
            'trailing space' => ['alice@example.com ', false],
        ];
    }

    #[DataProvider('domainCases')]
    public function testInDomain(string $address, bool $expected): void
    {
        $this->assertSame($expected, OidcConfig::fromConfig(self::COMPLETE)->inDomain($address));
    }
}
