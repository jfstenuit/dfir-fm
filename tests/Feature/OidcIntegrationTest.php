<?php
namespace Tests\Feature;

use Controllers\OidcController;
use Tests\Support\HttpClient;
use Tests\Support\MockOidcTestCase;
use Tests\Support\TestServer;

/**
 * OIDC sign-in end to end (#9, Phase 16 step 11), against mock-oidc: the
 * login page, the IdP, accounts and rights, logout and the CLI together,
 * as a user and an operator go through them.
 */
final class OidcIntegrationTest extends MockOidcTestCase
{
    private const CAROL = 'carol@oidc.test';
    private const DAVE = 'dave@oidc.test';

    protected function serverEnv(): array
    {
        return parent::serverEnv() + ['mail_engine' => 'mail', 'default_mail_from' => 'noreply@dfir.test'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->server->addDirectory('/case-a');
        $this->server->addDirectory('/case-b');
    }

    private function userId(string $username): ?int
    {
        $stmt = $this->server->db()->prepare('SELECT id FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        return ($id = $stmt->fetchColumn()) === false ? null : (int)$id;
    }

    /** The login page as login.js goes through it: the e-mail, then the IdP */
    private function signInFromTheLoginPage(HttpClient $client, string $email, string $subject, array $claims, string $redirect = ''): \Tests\Support\HttpResponse
    {
        $this->assertSame(200, $client->get('login', ['query' => array_filter(['redirect' => $redirect])])->status);
        $type = $client->postWithCsrf('login', ['action' => 'checkUser', 'email' => $email], 'login')->json()['login_type'];
        $this->assertSame('oidc', $type);
        return $this->signIn($client, $subject, $claims, array_filter(['login_hint' => $email, 'redirect' => $redirect]));
    }

    public function testFirstSignInThenStraightToTheIdp(): void
    {
        $response = $this->signInFromTheLoginPage($this->client(), self::CAROL, 'sub-carol', self::claims(self::CAROL, admin: true));
        $this->assertSame('/', $response->header('location'));
        $remembered = rawurlencode(self::CAROL);
        $this->assertStringStartsWith(OidcController::EMAIL_COOKIE . "=$remembered;", (string)$response->setCookie(OidcController::EMAIL_COOKIE));

        // Another browser that has only the cookie: its next visit goes straight to the IdP
        $browser = $this->client();
        $browser->cookies[OidcController::EMAIL_COOKIE] = $remembered;
        $page = $browser->get('admin');
        $this->assertSame('/login?redirect=%2Fadmin', $page->header('location'));
        $login = $browser->get('login', ['query' => ['redirect' => '/admin']]);
        $this->assertSame("/login/oidc?login_hint=$remembered&redirect=%2Fadmin", $login->header('location'));
        $idp = $browser->get('login/oidc', ['query' => ['login_hint' => self::CAROL, 'redirect' => '/admin']])->header('location');
        $back = $this->complete($browser, (string)$idp, 'sub-carol', self::claims(self::CAROL, admin: true));
        $this->assertSame('/admin', $back->header('location'));
        $this->assertSame(200, $browser->get('admin')->status);
    }

    public function testInvitedNonAdminSeesOnlyTheirFolder(): void
    {
        $invite = $this->adminClient()->postWithCsrf('admin', ['a' => 'invite', 'email' => self::DAVE, 'cwd' => '/case-a', 'addToDefaultGroup' => 'true']);
        $this->assertTrue($invite->json()['success']);
        $invited = $this->userId(self::DAVE);

        $dave = $this->client();
        $this->signInFromTheLoginPage($dave, self::DAVE, 'sub-dave', self::claims(self::DAVE));
        $this->assertSame($invited, $this->userId(self::DAVE), 'the invited account, linked');
        $this->assertSame(200, $dave->get('', ['query' => ['p' => '/case-a']])->status);
        $this->assertSame(404, $dave->get('', ['query' => ['p' => '/case-b']])->status);
        $this->assertSame(403, $dave->get('admin')->status);
        $this->assertSame([], $this->server->logEntries('oidc_user_provisioned'), 'no second account');
    }

    public function testUninvitedNonAdminGetsAnAccountWithoutRights(): void
    {
        $erin = $this->client();
        $this->signInFromTheLoginPage($erin, 'erin@oidc.test', 'sub-erin', self::claims('erin@oidc.test'));
        $root = $erin->get('');
        $this->assertSame(200, $root->status);
        $this->assertStringNotContainsString('case-a', $root->body, 'an empty root');
        $this->assertSame(404, $erin->get('', ['query' => ['p' => '/case-a']])->status);
        $this->assertFalse($this->server->logEntries('oidc_user_provisioned')[0]['admin']);

        // Until an administrator invites them
        $this->adminClient()->postWithCsrf('admin', ['a' => 'invite', 'email' => 'erin@oidc.test', 'cwd' => '/case-a', 'addToDefaultGroup' => 'true']);
        $this->assertSame(200, $erin->get('', ['query' => ['p' => '/case-a']])->status);
    }

    public function testExistingPasswordAccountLinkedAtItsFirstSignIn(): void
    {
        $this->server->db()->prepare("INSERT INTO users (username, password) VALUES ('frank@oidc.test', :p)")
            ->execute([':p' => password_hash('franks-password', PASSWORD_DEFAULT)]);
        $this->server->grantAccess('frank@oidc.test', '/case-b', view: true);
        $id = $this->userId('frank@oidc.test');
        $this->assertFalse($this->client()->login('frank@oidc.test', 'franks-password')->json()['success'], 'refused before');

        $frank = $this->client();
        $this->signInFromTheLoginPage($frank, 'frank@oidc.test', 'sub-frank', self::claims('frank@oidc.test'));
        $this->assertSame($id, $this->userId('frank@oidc.test'));
        $this->assertSame(200, $frank->get('', ['query' => ['p' => '/case-b']])->status, 'rights kept');
        $this->assertSame(['password', true], [$this->server->logEntries('oidc_account_linked')[0]['previous_auth_source'],
                                                $this->server->logEntries('oidc_account_linked')[0]['had_password']]);
        $this->assertFalse($this->client()->login('frank@oidc.test', 'franks-password')->json()['success'], 'refused after');
    }

    public function testAdminRoleRemovedAtTheIdp(): void
    {
        $carol = $this->client();
        $this->signIn($carol, 'sub-carol', self::claims(self::CAROL, admin: true));
        $this->assertTrue($this->server->isAdmin(self::CAROL));
        $this->server->grantAccess(self::CAROL, '/case-a', view: true);

        // The IdP group membership is removed; the next sign-in follows it
        $carol = $this->client();
        $this->signIn($carol, 'sub-carol', self::claims(self::CAROL));
        $this->assertFalse($this->server->isAdmin(self::CAROL));
        $this->assertSame(403, $carol->get('admin')->status);
        $this->assertSame(200, $carol->get('', ['query' => ['p' => '/case-a']])->status, 'folder rights kept');
        $this->assertCount(1, $this->server->logEntries('oidc_admin_revoked'));

        // And restored with it
        $this->signIn($this->client(), 'sub-carol', self::claims(self::CAROL, admin: true));
        $this->assertTrue($this->server->isAdmin(self::CAROL));
        $this->assertCount(1, $this->server->logEntries('oidc_admin_restored'));
    }

    public function testLocalAdminWithTheIdpDown(): void
    {
        $this->idp->halt();
        $this->assertFailurePage($this->client()->get('login/oidc', ['query' => ['login_hint' => self::CAROL]]), 'provider', 502);
        $admin = $this->adminClient();
        $this->assertSame(200, $admin->get('admin')->status, 'the break-glass admin, by password');
    }

    public function testOidcAdminMembershipIsReadOnly(): void
    {
        $carol = $this->client();
        $this->signIn($carol, 'sub-carol', self::claims(self::CAROL, admin: true));
        // Even by the account itself, and with another admin left
        $response = $carol->postWithCsrf('admin', ['a' => 'removeUserFromGroup', 'email' => self::CAROL, 'group' => 'admin']);
        $this->assertSame(403, $response->status);
        $this->assertTrue($this->server->isAdmin(self::CAROL));

        $users = array_column($carol->postWithCsrf('admin', ['a' => 'listUsers'])->json(), null, 'email');
        $this->assertSame(['admin'], $users[self::CAROL]['managed_groups']);
        $this->assertSame([], $users[TestServer::ADMIN]['managed_groups']);
    }

    public function testLogoutWithoutAnImmediateSignInAgain(): void
    {
        $carol = $this->client();
        $this->signIn($carol, 'sub-carol', self::claims(self::CAROL));
        $idpLogout = (string)$carol->get('logout')->header('location');
        $this->assertSame('https://dfirfm.test/login?signed_out', $this->idp->endSession($idpLogout)['location']);

        $page = $carol->get('login?signed_out');
        $this->assertSame(200, $page->status, 'the signed-out page, although the address is remembered');
        $this->assertArrayHasKey(OidcController::EMAIL_COOKIE, $carol->cookies);
        $this->assertStringContainsString('<h2>Signed out</h2>', $page->body);
        $this->assertSame(302, $carol->get('')->status, 'signed out');
    }

    /** The IdP re-created the user: refused, until an operator relinks the account */
    public function testRelinkResolvesASubjectConflict(): void
    {
        $this->signIn($this->client(), 'sub-old', self::claims(self::CAROL));
        $id = $this->userId(self::CAROL);
        $this->assertFailurePage($this->signIn($this->client(), 'sub-new', self::claims(self::CAROL)), 'subject_conflict');
        $this->assertStringContainsString('subject sub-new', $this->server->logEntries('login_oidc_failure')[0]['detail']);

        $result = $this->server->runBin('oidc-accounts.php', 'relink', self::CAROL, '--subject', 'sub-new');
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $carol = $this->client();
        $this->assertSame(302, $this->signIn($carol, 'sub-new', self::claims(self::CAROL))->status);
        $this->assertSame($id, $this->userId(self::CAROL), 'the same account');
        $this->assertFailurePage($this->signIn($this->client(), 'sub-old', self::claims(self::CAROL)), 'subject_conflict');
    }
}
