<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/**
 * Invitations and the admin page with OIDC (#9, Phase 16 step 8): in-domain
 * invitations, resends, IdP-managed admin membership, the users list. None
 * of it calls the IdP, so OIDC is configured with one that doesn't exist.
 */
final class OidcAdminTest extends FeatureTestCase
{
    private const OIDC = [
        'oidc_issuer' => 'https://idp.example.invalid/',
        'oidc_client_id' => 'dfir-fm',
        'oidc_client_secret' => 'S3cr3t-value',
        'oidc_redirect_uri' => 'https://fm.example.com/login/oidc/callback',
        'oidc_email_domain' => 'oidc.test',
        'oidc_admin_claim' => 'groups',
        'oidc_admin_value' => 'dfir-fm-admins',
        'oidc_display_name' => 'Corp SSO',
        'mail_engine' => 'mail',
        'default_mail_from' => 'noreply@dfir.test',
    ];
    private const DAVE = 'dave@oidc.test';

    protected function serverEnv(): array
    {
        return self::OIDC;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->server->addDirectory('/case-a');
    }

    private function invite(string $email): array
    {
        return $this->adminClient()->postWithCsrf('admin', ['a' => 'invite', 'email' => $email, 'cwd' => '/case-a', 'addToDefaultGroup' => 'true'])->json();
    }

    private function user(string $email): array
    {
        $stmt = $this->server->db()->prepare('SELECT * FROM users WHERE username = :u');
        $stmt->execute([':u' => $email]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** An OIDC account (as a first sign-in leaves it), in the admin group if asked */
    private function addOidcAccount(string $email, bool $admin): void
    {
        $db = $this->server->db();
        $db->prepare("INSERT INTO users (username, email, password, auth_source, oidc_issuer, oidc_subject, last_login_at)
                      VALUES (:u, :u, NULL, 'oidc', 'https://idp.example.invalid/', :u, '2026-11-05 10:00:00')")->execute([':u' => $email]);
        if ($admin) {
            $db->exec("INSERT INTO user_group (user_id, group_id) SELECT u.id, g.id FROM users u, groups g WHERE u.username = '$email' AND g.is_admin = 1");
        }
    }

    private function lastMail(): string
    {
        $mails = $this->server->sentMail();
        $this->assertNotEmpty($mails);
        return end($mails)['message'];
    }

    public function testInDomainInvitation(): void
    {
        $answer = $this->invite(self::DAVE);
        $this->assertTrue($answer['success'], json_encode($answer));
        $this->assertArrayNotHasKey('link', $answer);

        $dave = $this->user(self::DAVE);
        $this->assertSame(['oidc', null, null, null], [$dave['auth_source'], $dave['password'], $dave['invitation_token'], $dave['token_expiry']]);
        $this->assertSame(self::DAVE, $dave['email']);

        $mail = $this->lastMail();
        $this->assertStringContainsString('<title>Invitation to contribute to /case-a on', $mail);
        $this->assertStringContainsString('Sign in with your <strong>Corp SSO</strong> account', $mail);
        $this->assertStringContainsString('/?p=%2Fcase-a', $mail);
        $this->assertStringNotContainsString('token=', $mail);

        $invited = $this->server->logEntries('user_invited');
        $this->assertSame([self::DAVE, 'mail', true, true], [$invited[0]['invited_user'], $invited[0]['delivery'], $invited[0]['invitation_sent'], $invited[0]['sso']]);

        // Out of the domain: the link, as before
        $this->invite('erin@example.com');
        $this->assertStringContainsString('token=', $this->lastMail());
        $this->assertSame('password', $this->user('erin@example.com')['auth_source']);
        $this->assertFalse($this->server->logEntries('user_invited')[1]['sso']);
    }

    public function testInDomainInvitationWithoutMail(): void
    {
        $this->restartWith(['mail_engine' => 'none'] + self::OIDC);
        $this->server->addDirectory('/case-a');
        $answer = $this->invite(self::DAVE);
        $this->assertTrue($answer['success']);
        $this->assertArrayNotHasKey('link', $answer, 'nothing to pass on');
        $this->assertStringContainsString('signs in through Corp SSO', $answer['message']);
        $this->assertSame('oidc', $this->user(self::DAVE)['auth_source']);
    }

    public function testResendToAnOidcAccount(): void
    {
        $this->addOidcAccount(self::DAVE, false);
        $before = $this->user(self::DAVE)['session_version'];
        $answer = $this->adminClient()->postWithCsrf('admin', ['a' => 'resendInvite', 'email' => self::DAVE])->json();
        $this->assertTrue($answer['success'], json_encode($answer));

        $mail = $this->lastMail();
        $this->assertStringContainsString('<title>Your DFIR FM account on', $mail);
        $this->assertStringContainsString('Corp SSO', $mail);
        $this->assertStringNotContainsString('token=', $mail);
        $dave = $this->user(self::DAVE);
        $this->assertNull($dave['invitation_token'], 'no password reset');
        $this->assertSame($before, $dave['session_version'], 'sessions kept');
        $this->assertTrue($this->server->logEntries('invitation_resent')[0]['sso']);

        // An in-domain password account not linked yet: the notice too
        $this->server->db()->exec("INSERT INTO users (username, password) VALUES ('frank@oidc.test', 'x')");
        $this->adminClient()->postWithCsrf('admin', ['a' => 'resendInvite', 'email' => 'frank@oidc.test']);
        $this->assertStringNotContainsString('token=', $this->lastMail());
        $this->assertNull($this->user('frank@oidc.test')['invitation_token']);
    }

    public function testAdminMembershipManagedByTheIdp(): void
    {
        $this->addOidcAccount(self::DAVE, true);
        $this->addOidcAccount('gina@oidc.test', false);
        $admin = $this->adminClient();

        $removed = $admin->postWithCsrf('admin', ['a' => 'removeUserFromGroup', 'email' => self::DAVE, 'group' => 'admin']);
        $this->assertSame(403, $removed->status);
        $this->assertStringContainsString('managed by Corp SSO', $removed->json()['message']);
        $added = $admin->postWithCsrf('admin', ['a' => 'addUserToGroup', 'email' => 'gina@oidc.test', 'group' => 'admin']);
        $this->assertSame(403, $added->status);
        $this->assertTrue($this->server->isAdmin(self::DAVE));
        $this->assertFalse($this->server->isAdmin('gina@oidc.test'));
        $this->assertSame(['oidc_managed', 'oidc_managed'], array_column($this->server->logEntries('group_change_refused'), 'cause'));

        // Folder groups: as for any account
        $this->assertTrue($admin->postWithCsrf('admin', ['a' => 'addUserToGroup', 'email' => 'gina@oidc.test', 'group' => 'case-a readers'])->json()['success']);
        $this->assertTrue($admin->postWithCsrf('admin', ['a' => 'removeUserFromGroup', 'email' => 'gina@oidc.test', 'group' => 'case-a readers'])->json()['success']);
        // Password accounts' admin membership: unchanged
        $this->assertTrue($admin->postWithCsrf('admin', ['a' => 'addUserToGroup', 'email' => TestServer::BOB, 'group' => 'admin'])->json()['success']);
    }

    public function testUsersList(): void
    {
        $this->addOidcAccount(self::DAVE, true);
        $this->server->db()->exec("INSERT INTO users (username, password, auth_source) VALUES ('hugo@oidc.test', NULL, 'oidc')");
        $this->bobClient();
        $users = array_column($this->adminClient()->postWithCsrf('admin', ['a' => 'listUsers'])->json(), null, 'email');

        $this->assertTrue($users[self::DAVE]['sso']);
        $this->assertSame(['admin'], $users[self::DAVE]['managed_groups']);
        $this->assertSame('active', $users[self::DAVE]['status']);
        $this->assertSame('2026-11-05 10:00:00', $users[self::DAVE]['last_login_at']);
        $this->assertSame('invited', $users['hugo@oidc.test']['status'], 'not signed in yet');

        $this->assertFalse($users[TestServer::BOB]['sso']);
        $this->assertSame([], $users[TestServer::BOB]['managed_groups']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string)$users[TestServer::BOB]['last_login_at'], 'password logins recorded too');
        $this->assertSame([], $users[TestServer::ADMIN]['managed_groups'], 'a local admin');
    }

    public function testAdminPage(): void
    {
        $page = $this->adminClient()->get('admin')->body;
        $this->assertStringContainsString('data-sso="Corp SSO"', $page);
        $this->assertStringContainsString('Last login', $page);
        $this->assertStringNotContainsString('breakGlassWarning', $page, 'admin@test.local is a local admin outside the domain');

        $this->restartWith([]);
        $page = $this->adminClient()->get('admin')->body;
        $this->assertStringNotContainsString('data-sso', $page, 'OIDC disabled');
        $this->assertStringNotContainsString('breakGlassWarning', $page);
    }
}
