<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/**
 * bin/oidc-accounts.php and bin/create-admin.php with OIDC (#9, Phase 16
 * step 10). OIDC is configured with an IdP that is never called.
 */
final class OidcAccountsCliTest extends FeatureTestCase
{
    private const ISSUER = 'https://idp.example.invalid/';
    private const OIDC = [
        'oidc_issuer' => self::ISSUER,
        'oidc_client_id' => 'dfir-fm',
        'oidc_client_secret' => 'S3cr3t-value',
        'oidc_redirect_uri' => 'https://fm.example.com/login/oidc/callback',
        'oidc_email_domain' => 'oidc.test',
        'oidc_admin_claim' => 'groups',
        'oidc_admin_value' => 'dfir-fm-admins',
    ];
    private const CAROL = 'carol@oidc.test';

    protected function serverEnv(): array
    {
        return self::OIDC;
    }

    private function tool(string ...$args): array
    {
        return $this->server->runBin('oidc-accounts.php', ...$args);
    }

    private function addOidcAccount(string $email, ?string $subject, bool $admin = false, ?string $deletedAt = null): void
    {
        $db = $this->server->db();
        $db->prepare("INSERT INTO users (username, email, password, auth_source, oidc_issuer, oidc_subject, deleted_at)
                      VALUES (:u, :u, NULL, 'oidc', :iss, :sub, :del)")
            ->execute([':u' => $email, ':iss' => $subject !== null ? self::ISSUER : null, ':sub' => $subject, ':del' => $deletedAt]);
        if ($admin) {
            $db->exec("INSERT INTO user_group (user_id, group_id) SELECT u.id, g.id FROM users u, groups g WHERE u.username = '$email' AND g.is_admin = 1");
        }
    }

    private function user(string $email): array
    {
        $stmt = $this->server->db()->prepare('SELECT * FROM users WHERE username = :u');
        $stmt->execute([':u' => $email]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function testUsage(): void
    {
        foreach ([[], ['nope'], ['list', 'extra'], ['relink', self::CAROL], ['relink', self::CAROL, '--subject'],
                  ['relink', self::CAROL, '--subject', 's', '--force'], ['unlink'], ['unlink', self::CAROL, '--subject', 's']] as $args) {
            $result = $this->tool(...$args);
            $this->assertSame(2, $result['exit'], implode(' ', $args));
            $this->assertStringContainsString('Usage:', $result['stderr']);
        }
        $this->assertSame([], $this->server->logEntries('cli_oidc_account'), 'nothing done, nothing logged');
    }

    public function testList(): void
    {
        $this->assertSame("No OIDC account.\n", $this->tool('list')['stdout']);

        $this->addOidcAccount(self::CAROL, 'sub-carol', admin: true);
        $this->addOidcAccount('dave@oidc.test', null);
        $this->addOidcAccount('gone@oidc.test', 'sub-gone', deletedAt: '2026-01-01 00:00:00');
        $this->server->db()->exec("UPDATE users SET last_login_at = '2026-11-05 10:00:00' WHERE username = '" . self::CAROL . "'");

        $result = $this->tool('list');
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString(self::CAROL . "\n  issuer:     " . self::ISSUER . "\n  subject:    sub-carol\n  admin:      yes\n  last login: 2026-11-05 10:00:00 UTC\n", $result['stdout']);
        $this->assertStringContainsString("dave@oidc.test\n  issuer:     (not linked)\n  subject:    (not linked)\n  admin:      no\n  last login: never\n", $result['stdout']);
        $this->assertStringNotContainsString('gone@', $result['stdout'], 'deleted accounts left out');
        $this->assertStringNotContainsString(TestServer::BOB, $result['stdout'], 'password accounts left out');

        $entry = $this->server->logEntries('cli_oidc_account')[1];
        $this->assertSame(['list', 'ok', 2], [$entry['command'], $entry['result'], $entry['accounts']]);
        $this->assertStringStartsWith('cli:', $entry['username']);
    }

    /** A subject_conflict at sign-in: the IdP re-created the user, with a new sub */
    public function testRelink(): void
    {
        $this->addOidcAccount(self::CAROL, 'sub-old');
        $before = $this->user(self::CAROL);

        $result = $this->tool('relink', self::CAROL, '--subject', 'sub-new');
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('(was ' . self::ISSUER . ' sub-old)', $result['stdout']);
        $after = $this->user(self::CAROL);
        $this->assertSame([self::ISSUER, 'sub-new', 'oidc'], [$after['oidc_issuer'], $after['oidc_subject'], $after['auth_source']], '--issuer defaults to oidc_issuer');
        $this->assertSame($before['session_version'] + 1, $after['session_version'], 'sessions ended');

        $entry = $this->server->logEntries('cli_oidc_account')[0];
        $this->assertSame(['relink', 'ok', self::CAROL, self::ISSUER, 'sub-new', 'sub-old'],
            [$entry['command'], $entry['result'], $entry['target_user'], $entry['issuer'], $entry['subject'], $entry['previous_subject']]);

        // Another issuer (an IdP migration)
        $this->assertSame(0, $this->tool('relink', self::CAROL, '--issuer', 'https://new-idp.example/', '--subject', 'x1')['exit']);
        $this->assertSame('https://new-idp.example/', $this->user(self::CAROL)['oidc_issuer']);
    }

    public function testRelinkAnInDomainPasswordAccount(): void
    {
        $this->server->db()->exec("INSERT INTO users (username, password, invitation_token) VALUES ('frank@oidc.test', 'hash', 'tok')");
        $this->assertSame(0, $this->tool('relink', 'frank@oidc.test', '--subject', 'sub-frank')['exit']);
        $frank = $this->user('frank@oidc.test');
        $this->assertSame(['oidc', null, null, 'sub-frank'], [$frank['auth_source'], $frank['password'], $frank['invitation_token'], $frank['oidc_subject']]);
    }

    public function testRelinkRefused(): void
    {
        $this->addOidcAccount(self::CAROL, 'sub-carol');
        $this->addOidcAccount('dave@oidc.test', 'sub-dave');
        $this->addOidcAccount('gone@oidc.test', 'sub-gone', deletedAt: '2026-01-01 00:00:00');

        $cases = [
            "That identity belongs to 'dave@oidc.test': unlink it first." => ['relink', self::CAROL, '--subject', 'sub-dave'],
            'belongs to the deleted account #' => ['relink', self::CAROL, '--subject', 'sub-gone'],
            "No account 'nobody@oidc.test'." => ['relink', 'nobody@oidc.test', '--subject', 'x'],
            'is a password account outside the OIDC domain' => ['relink', TestServer::BOB, '--subject', 'x'],
            'already has that identity' => ['relink', self::CAROL, '--subject', 'sub-carol'],
        ];
        foreach ($cases as $message => $args) {
            $result = $this->tool(...$args);
            $this->assertSame(1, $result['exit'], implode(' ', $args));
            $this->assertStringContainsString($message, $result['stderr']);
        }
        $this->assertSame('sub-carol', $this->user(self::CAROL)['oidc_subject'], 'unchanged');
        $this->assertSame(array_fill(0, 5, 'refused'), array_column($this->server->logEntries('cli_oidc_account'), 'result'));
    }

    public function testUnlink(): void
    {
        $this->addOidcAccount(self::CAROL, 'sub-carol', admin: true);
        $before = $this->user(self::CAROL);
        $result = $this->tool('unlink', self::CAROL);
        $this->assertSame(0, $result['exit'], $result['stderr']);

        $after = $this->user(self::CAROL);
        $this->assertSame([null, null, 'oidc', null], [$after['oidc_issuer'], $after['oidc_subject'], $after['auth_source'], $after['password']],
            'still an OIDC account, without a password');
        $this->assertSame($before['session_version'] + 1, $after['session_version']);
        $this->assertTrue($this->server->isAdmin(self::CAROL), 'groups kept');
        $this->assertSame('sub-carol', $this->server->logEntries('cli_oidc_account')[0]['previous_subject']);

        $again = $this->tool('unlink', self::CAROL);
        $this->assertSame(1, $again['exit']);
        $this->assertStringContainsString('is not linked', $again['stderr']);
    }

    public function testCreateAdminRefusesTheDomain(): void
    {
        $result = $this->server->runCreateAdmin('Carol@OIDC.test');
        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('is in the OIDC domain oidc.test', $result['stderr']);
        $this->assertSame(0, (int)$this->server->db()->query("SELECT COUNT(*) FROM users WHERE username LIKE '%oidc.test'")->fetchColumn());

        // Without OIDC, any address
        $this->restartWith([]);
        $this->assertSame(0, $this->server->runCreateAdmin(self::CAROL)['exit']);
    }

    /** OIDC disabled after use, or the domain changed: break-glass must still give a working password */
    public function testCreateAdminConvertsAnOidcAccountOutsideTheDomain(): void
    {
        $this->addOidcAccount('olga@example.com', 'sub-olga');
        $result = $this->server->runCreateAdmin('olga@example.com');
        $this->assertSame(0, $result['exit'], $result['stderr']);
        preg_match('/^Password.*: (\S+)$/m', $result['stdout'], $m);

        $olga = $this->user('olga@example.com');
        $this->assertSame(['password', null, null], [$olga['auth_source'], $olga['oidc_issuer'], $olga['oidc_subject']]);
        $this->assertTrue($this->client()->login('olga@example.com', $m[1])->json()['success']);
        $this->assertTrue($this->server->logEntries('cli_admin_created')[1]['converted_from_oidc']);
    }
}
