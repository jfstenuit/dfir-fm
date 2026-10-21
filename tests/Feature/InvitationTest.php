<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;
use Tests\Support\HttpResponse;
use Tests\Support\TestServer;

/**
 * Invitations (D14, D15, D21, D22; DFM-01): only into a subfolder, into the
 * folder's default group or into none, never changing a group's rights;
 * with mail_engine=none the link comes back to the administrator.
 */
final class InvitationTest extends FeatureTestCase
{
    private const CAROL = 'carol@example.org';
    private const DAVE = 'dave@example.org';

    protected function serverEnv(): array
    {
        return ['mail_engine' => 'mail', 'default_mail_from' => 'noreply@dfir.test'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->addFolders();
    }

    private function addFolders(): void
    {
        $this->server->db()->exec("INSERT INTO directories (name, path, parent_id) VALUES ('case-a', '/case-a', 1), ('case-b', '/case-b', 1)");
    }

    private function invite(string $email, string $cwd, bool $addToDefaultGroup = true, ?HttpClient $admin = null): HttpResponse
    {
        return ($admin ?? $this->adminClient())->postWithCsrf('admin', ['a' => 'invite', 'email' => $email, 'cwd' => $cwd,
                                                                        'addToDefaultGroup' => $addToDefaultGroup ? 'true' : 'false']);
    }

    /** @return list<string> */
    private function groupsOf(string $email): array
    {
        $stmt = $this->server->db()->prepare("SELECT g.name FROM groups g JOIN user_group ug ON ug.group_id = g.id
                                              JOIN users u ON u.id = ug.user_id WHERE u.username = :u ORDER BY g.name");
        $stmt->execute([':u' => $email]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return list<string> "path:view,write,upload" */
    private function rightsOf(string $group): array
    {
        $stmt = $this->server->db()->prepare("SELECT d.path || ':' || ar.can_view || ',' || ar.can_write || ',' || ar.can_upload
                                              FROM access_rights ar JOIN groups g ON g.id = ar.group_id
                                              JOIN directories d ON d.id = ar.directory_id WHERE g.name = :g ORDER BY d.path");
        $stmt->execute([':g' => $group]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function userExists(string $email): bool
    {
        $stmt = $this->server->db()->prepare("SELECT 1 FROM users WHERE username = :u");
        $stmt->execute([':u' => $email]);
        return $stmt->fetchColumn() !== false;
    }

    public function testRefusedAtTheRootAndForUnknownFolders(): void
    {
        $admin = $this->adminClient();
        foreach (['/', '', '/nowhere'] as $cwd) {
            $answer = $this->invite(self::CAROL, $cwd, true, $admin)->json();
            $this->assertFalse($answer['success'], "cwd '$cwd'");
        }
        $this->assertStringContainsString('not into the root', $this->invite(self::CAROL, '/', true, $admin)->json()['message']);
        $this->assertFalse($this->userExists(self::CAROL));
        $this->assertSame([], $this->server->sentMail());
    }

    public function testSameDomainInviteesOnlyGetTheirOwnFolder(): void
    {
        $admin = $this->adminClient();
        $this->assertTrue($this->invite(self::CAROL, '/case-a', true, $admin)->json()['success']);
        $this->assertTrue($this->invite(self::DAVE, '/case-b', true, $admin)->json()['success']);

        $this->assertSame(['/case-a contributors'], $this->groupsOf(self::CAROL));
        $this->assertSame(['/case-b contributors'], $this->groupsOf(self::DAVE));
        $this->assertSame(['/case-a:0,0,1'], $this->rightsOf('/case-a contributors'), 'contributor access only (D21)');
        $this->assertSame(['/case-b:0,0,1'], $this->rightsOf('/case-b contributors'));
        $this->assertSame(0, (int)$this->server->db()->query("SELECT COUNT(*) FROM groups WHERE name LIKE '% users'")->fetchColumn(),
            'no domain group (DFM-01)');

        $invited = $this->server->logEntries('user_invited');
        $this->assertSame(['/case-a', '/case-a contributors', 'mail'], [$invited[0]['directory'], $invited[0]['group'], $invited[0]['delivery']]);
        $created = $this->server->logEntries('group_created');
        $this->assertSame(['/case-a contributors', '/case-b contributors'], array_column($created, 'group'));
        $this->assertCount(2, $this->server->sentMail());
    }

    public function testSecondInvitationJoinsTheSameGroupAndKeepsItsRights(): void
    {
        $admin = $this->adminClient();
        $this->invite(self::CAROL, '/case-a', true, $admin);
        $this->server->db()->exec("UPDATE access_rights SET can_view = 1, can_write = 1
                                   WHERE group_id = (SELECT id FROM groups WHERE name = '/case-a contributors')");

        $this->assertTrue($this->invite(self::DAVE, '/case-a', true, $admin)->json()['success']);
        $this->assertSame(['/case-a contributors'], $this->groupsOf(self::DAVE));
        $this->assertSame(['/case-a:1,1,1'], $this->rightsOf('/case-a contributors'));
        $this->assertCount(1, $this->server->logEntries('group_created'));
    }

    public function testUntickedCreatesTheAccountWithoutAccess(): void
    {
        $this->assertTrue($this->invite(self::CAROL, '/case-a', false)->json()['success']);

        $this->assertTrue($this->userExists(self::CAROL));
        $this->assertSame([], $this->groupsOf(self::CAROL));
        $this->assertSame(0, (int)$this->server->db()->query("SELECT COUNT(*) FROM groups WHERE is_admin = 0")->fetchColumn());
        $this->assertNull($this->server->logEntries('user_invited')[0]['group']);
        $this->assertCount(1, $this->server->sentMail(), 'a link is always delivered');
    }

    public function testInvitingAnExistingUserLeavesTheirOtherGroups(): void
    {
        $admin = $this->adminClient();
        $this->invite(TestServer::BOB, '/case-a', true, $admin);
        $this->invite(TestServer::BOB, '/case-b', true, $admin);
        $this->assertSame(['/case-a contributors', '/case-b contributors'], $this->groupsOf(TestServer::BOB));
    }

    public function testManualDeliveryReturnsAWorkingLink(): void
    {
        $this->restartWith(['mail_engine' => 'none']);
        $this->addFolders();

        $response = $this->invite(self::CAROL, '/case-a');
        $answer = $response->json();
        $this->assertTrue($answer['success'], $response->body);
        $this->assertSame('no-store', $response->header('Cache-Control'));
        $this->assertSame(3600, $answer['expires_in']);
        $this->assertStringStartsWith($this->server->url . '/login?email=carol%40example.org&token=', $answer['link']);
        $this->assertSame([], $this->server->sentMail(), 'no mail attempted');
        $this->assertSame([], $this->server->logEntries('mail_send_failure'));
        $this->assertSame('manual', $this->server->logEntries('user_invited')[0]['delivery']);

        parse_str((string)parse_url($answer['link'], PHP_URL_QUERY), $query);
        $log = (string)file_get_contents($this->server->root . '/app.log');
        $this->assertStringNotContainsString($query['token'], $log, 'the link is never logged');

        // The link leads to the password form (Phase 10 Step 1), then into the folder
        $invitee = $this->client();
        $relative = substr($answer['link'], strlen($this->server->url) + 1);
        $this->assertSame(200, $invitee->get($relative)->status);
        $set = $invitee->postWithCsrf('login', ['action' => 'setPassword', 'email' => self::CAROL, 'token' => $query['token'],
                                                'directory' => $query['directory'], 'password' => 'a long password'], $relative);
        $this->assertSame('/?p=%2Fcase-a', $set->json()['redirect'], $set->body);
    }

    public function testInviteButtonOnlyInSubfolders(): void
    {
        $admin = $this->adminClient();
        $root = $admin->get('')->body;
        $this->assertStringNotContainsString('id="inviteModal"', $root);
        $this->assertStringNotContainsString('data-bs-target="#inviteModal"', $root);

        $folder = $admin->get('?p=%2Fcase-a')->body;
        $this->assertStringContainsString('data-bs-target="#inviteModal"', $folder);
        $this->assertStringContainsString('/case-a contributors', $folder, 'the dialog names the default group');
        $this->assertStringContainsString('Send invitation', $folder);
        $this->assertStringNotContainsString('accessRights[]', $folder, 'no rights checkboxes (D21)');

        $this->restartWith(['mail_engine' => 'none']);
        $this->addFolders();
        $this->assertStringContainsString('Create invitation link', $this->adminClient()->get('?p=%2Fcase-a')->body);
    }

    public function testAdminPageHasNoInviteForm(): void
    {
        $this->assertStringNotContainsString('inviteUserForm', $this->adminClient()->get('admin')->body);
    }

    public function testResendChangesNoGroupAndLinksToTheRoot(): void
    {
        $this->restartWith(['mail_engine' => 'none']);
        $this->addFolders();
        $admin = $this->adminClient();
        $this->invite(self::CAROL, '/case-a', true, $admin);

        $answer = $admin->postWithCsrf('admin', ['a' => 'resendInvite', 'email' => self::CAROL])->json();
        $this->assertTrue($answer['success']);
        $this->assertStringEndsWith('&directory=%2F', $answer['link']);
        $this->assertSame(['/case-a contributors'], $this->groupsOf(self::CAROL));
        $this->assertSame(['/case-a:0,0,1'], $this->rightsOf('/case-a contributors'));

        $this->assertFalse($admin->postWithCsrf('admin', ['a' => 'resendInvite', 'email' => 'nobody@example.org'])->json()['success']);
        $this->assertFalse($this->userExists('nobody@example.org'));
    }
}
