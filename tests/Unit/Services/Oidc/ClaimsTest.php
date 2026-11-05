<?php
namespace Tests\Unit\Services\Oidc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Services\Oidc\Claims;
use Services\Oidc\OidcRefusal;

final class ClaimsTest extends TestCase
{
    private const BASE = ['iss' => 'https://idp.example.com/', 'sub' => 'u1', 'aud' => 'dfir-fm', 'iat' => 1, 'exp' => 2];

    public static function paths(): array
    {
        return [
            'top level' => [['groups' => ['a']], 'groups', ['a']],
            'nested' => [['realm_access' => ['roles' => ['admin']]], 'realm_access.roles', ['admin']],
            'deeper' => [['resource_access' => ['dfir-fm' => ['roles' => ['x']]]], 'resource_access.dfir-fm.roles', ['x']],
            'name with dots first' => [['https://example.com/roles' => ['r'], 'https://example' => ['com/roles' => 'no']], 'https://example.com/roles', ['r']],
            'missing' => [[], 'groups', null],
            'missing below' => [['realm_access' => []], 'realm_access.roles', null],
            'through a scalar' => [['realm_access' => 'x'], 'realm_access.roles', null],
            'through a list' => [['realm_access' => [['roles' => 'x']]], 'realm_access.0.roles', null],
            'explicit null' => [['groups' => null], 'groups', null],
        ];
    }

    #[DataProvider('paths')]
    public function testGet(array $claims, string $path, mixed $expected): void
    {
        $this->assertSame($expected, (new Claims($claims + self::BASE))->get($path));
    }

    public static function containsCases(): array
    {
        return [
            'in a list' => [['groups' => ['staff', 'dfir-fm-admins']], 'groups', true],
            'not in the list' => [['groups' => ['staff']], 'groups', false],
            'a string equal' => [['groups' => 'dfir-fm-admins'], 'groups', true],
            'a string different' => [['groups' => 'staff'], 'groups', false],
            'case differs' => [['groups' => ['DFIR-FM-Admins']], 'groups', false],
            'substring' => [['groups' => ['dfir-fm-admins-old']], 'groups', false],
            'string containing it' => [['groups' => 'dfir-fm-admins staff'], 'groups', false],
            'an object, not a list' => [['groups' => ['dfir-fm-admins' => true]], 'groups', false],
            'a number' => [['groups' => [1]], 'groups', false],
            'missing' => [[], 'groups', false],
            'nested' => [['realm_access' => ['roles' => ['dfir-fm-admins']]], 'realm_access.roles', true],
        ];
    }

    #[DataProvider('containsCases')]
    public function testContains(array $claims, string $path, bool $expected): void
    {
        $this->assertSame($expected, (new Claims($claims + self::BASE))->contains($path, 'dfir-fm-admins'));
    }

    public function testEmail(): void
    {
        $this->assertSame('alice@example.com', (new Claims(['email' => 'alice@example.com'] + self::BASE))->email('email'));
        $this->assertSame('alice@example.com', (new Claims(['upn' => 'alice@example.com'] + self::BASE))->email('upn'));
        $this->assertNull((new Claims(self::BASE))->email('email'));
        $this->assertNull((new Claims(['email' => ''] + self::BASE))->email('email'));
        $this->assertNull((new Claims(['email' => ['alice@example.com']] + self::BASE))->email('email'));
    }

    public function testEmailVerified(): void
    {
        foreach ([[true, true], ['true', true], [false, false], ['false', false], [1, false], [null, false]] as [$value, $expected]) {
            $this->assertSame($expected, (new Claims(['email_verified' => $value] + self::BASE))->emailVerified(), var_export($value, true));
        }
        $this->assertFalse((new Claims(self::BASE))->emailVerified());
    }

    public function testOverage(): void
    {
        $overage = new Claims(['_claim_names' => ['groups' => 'src1'], '_claim_sources' => ['src1' => ['endpoint' => 'https://graph']]] + self::BASE);
        $this->assertTrue($overage->hasOverage('groups'));
        $this->assertFalse($overage->hasOverage('roles'));
        $this->assertTrue((new Claims(['_claim_names' => ['realm_access' => 'src1']] + self::BASE))->hasOverage('realm_access.roles'));
        $this->assertFalse((new Claims(['groups' => ['a']] + self::BASE))->hasOverage('groups'));
    }

    public function testUserinfoFallback(): void
    {
        $claims = new Claims(self::BASE);
        $this->assertFalse($claims->hasInToken('groups'));
        $this->assertFalse($claims->contains('groups', 'dfir-fm-admins'));

        $withUserinfo = $claims->withUserinfo(['sub' => 'u1', 'groups' => ['dfir-fm-admins']]);
        $this->assertTrue($withUserinfo->contains('groups', 'dfir-fm-admins'));
        $this->assertSame(['dfir-fm-admins'], $withUserinfo->claim('groups'));
        $this->assertNull($withUserinfo->get('groups'), 'get() reads the token only');
        $this->assertFalse($claims->contains('groups', 'dfir-fm-admins'), 'the original is unchanged');
    }

    public function testTokenWinsOverUserinfo(): void
    {
        $claims = (new Claims(['groups' => ['staff']] + self::BASE))->withUserinfo(['sub' => 'u1', 'groups' => ['dfir-fm-admins']]);
        $this->assertFalse($claims->contains('groups', 'dfir-fm-admins'));
    }

    public function testUserinfoOfAnotherSubjectRefused(): void
    {
        foreach ([['sub' => 'u2', 'groups' => ['dfir-fm-admins']], ['groups' => ['dfir-fm-admins']]] as $userinfo) {
            try {
                (new Claims(self::BASE))->withUserinfo($userinfo);
                $this->fail('accepted userinfo with sub ' . var_export($userinfo['sub'] ?? null, true));
            } catch (OidcRefusal $e) {
                $this->assertSame('claims', $e->cause);
            }
        }
    }

    /** What Authentik sends with its default scope mappings (openid email profile) */
    public function testAuthentikDefaultClaimSet(): void
    {
        $claims = new Claims([
            'iss' => 'https://idp.example.com/application/o/dfir-fm/', 'sub' => hash('sha256', 'u1'), 'aud' => 'client-id',
            'exp' => 1793877690, 'iat' => 1793877390, 'auth_time' => 1793877170,
            'acr' => 'goauthentik.io/providers/oauth2/default', 'amr' => ['pwd'], 'nonce' => 'n1', 'sid' => 's1', 'jti' => 'j1',
            'email' => 'alice@example.com', 'email_verified' => false,
            'name' => 'Alice Example', 'given_name' => 'Alice Example', 'preferred_username' => 'alice@example.com', 'nickname' => 'alice@example.com',
            'groups' => ['dfir-iris-users', 'dfir-fm users', 'dfir-fm-admins'],
        ]);
        $this->assertSame('alice@example.com', $claims->email('email'));
        $this->assertFalse($claims->emailVerified());
        $this->assertTrue($claims->hasInToken('groups'));
        $this->assertFalse($claims->hasOverage('groups'));
        $this->assertTrue($claims->contains('groups', 'dfir-fm-admins'));
        $this->assertFalse($claims->contains('groups', 'dfir-fm'), 'names compared whole');
        $this->assertFalse($claims->contains('groups', 'admin'));
    }
}
