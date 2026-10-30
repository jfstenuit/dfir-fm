<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;
use Tests\Support\TestServer;

/** Admin page, Logs tab: listEvents and listEventActions (#23, Phase 15 step 2) */
final class LogsTabTest extends FeatureTestCase
{
    private HttpClient $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->adminClient();
        $this->token = (string)$this->admin->get('admin')->csrfToken();
    }

    private function list(array $form = []): array
    {
        $response = $this->admin->post('admin', ['a' => 'listEvents'] + $form, ['headers' => ['X-CSRF-TOKEN' => $this->token]]);
        $this->assertSame(200, $response->status, $response->body);
        return $response->json();
    }

    /** @return list<string> actions of a page, newest first */
    private function actions(array $form = []): array
    {
        return array_column($this->list($form)['events'], 'action');
    }

    public function testNewestFirstWithTheRecordAsStored(): void
    {
        $stored = $this->server->events();
        $page = $this->list();
        $this->assertTrue($page['success']);
        $this->assertNull($page['next'], 'everything fits in one page');
        $this->assertSame(array_reverse(array_map('intval', array_column($stored, 'id'))), array_column($page['events'], 'id'),
            'newest first; its own logs_viewed comes after the query');

        $records = array_column($stored, 'record', 'id');
        foreach ($page['events'] as $event) {
            $this->assertSame($records[$event['id']], $event['record']);
        }
        $this->assertSame('login_password_success', $page['events'][0]['action']);
        $this->assertSame(TestServer::ADMIN, $page['events'][0]['username']);
    }

    /** Events logged between two pages neither shift nor repeat the next page */
    public function testPagingWithoutGapsOrRepeats(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->server->addEvent("2026-10-01T00:00:0$i+00:00", 'fixture', ['n' => $i]);
        }
        $all = array_reverse(array_map('intval', array_column($this->server->events(), 'id')));

        $seen = [];
        $page = $this->list(['limit' => '3']);
        while (true) {
            $seen = [...$seen, ...array_column($page['events'], 'id')];
            // Logged meanwhile: newer than everything shown
            $this->server->addEvent('2026-10-02T00:00:00+00:00', 'fixture', ['n' => 'late']);
            if ($page['next'] === null) {
                break;
            }
            $this->assertSame(end($seen), $page['next']);
            $page = $this->list(['limit' => '3', 'before' => (string)$page['next']]);
        }
        $this->assertSame($all, $seen, 'exactly the events present when the search started, each once, newest first');
    }

    public function testFilters(): void
    {
        $this->server->addEvent('2026-09-01T10:00:00+00:00', 'upload_completed', ['username' => 'ann@x', 'ip' => '203.0.113.1',
            'file' => 'disk_1%.E01', 'sha256' => str_repeat('AB', 32)]);
        $this->server->addEvent('2026-09-02T10:00:00+00:00', 'download_success', ['username' => 'ann@x', 'ip' => '198.51.100.7',
            'peer_ip' => '10.0.0.2', 'file' => 'memory.raw']);
        $this->server->addEvent('2026-09-03T10:00:00+00:00', 'download_success', ['username' => 'bo@x', 'ip' => '10.0.0.2', 'file' => 'disk_10.E01']);

        $this->assertSame(['download_success', 'download_success'], $this->actions(['action' => 'download_success']));
        $this->assertSame(['download_success', 'upload_completed'], $this->actions(['username' => 'ann@x']));
        $this->assertSame([], $this->actions(['username' => 'ann']), 'username is exact');
        $this->assertSame(['download_success', 'download_success'], $this->actions(['ip' => '10.0.0.2']), 'client or proxy');
        $this->assertSame(['upload_completed'], $this->actions(['text' => 'disk_1%']), '% and _ are literal');
        $this->assertSame(['upload_completed'], $this->actions(['text' => str_repeat('ab', 32)]), 'case-insensitive');
        $this->assertSame(['download_success'], $this->actions(['from' => '2026-09-02T10:00', 'until' => '2026-09-03T10:00']),
            'from inclusive, until exclusive, UTC');
        $this->assertSame(['download_success', 'upload_completed'],
            $this->actions(['from' => '2026-09-01T12:00:00+02:00', 'until' => '2026-09-03', 'username' => 'ann@x']), 'offsets converted to UTC');
    }

    public function testInvalidInputRefused(): void
    {
        foreach ([['from' => 'yesterday-ish'], ['action[]' => 'x'], ['before' => 'abc'], ['limit' => '-1']] as $form) {
            $response = $this->admin->post('admin', ['a' => 'listEvents'] + $form, ['headers' => ['X-CSRF-TOKEN' => $this->token]]);
            $this->assertSame(400, $response->status, json_encode($form));
            $this->assertFalse($response->json()['success']);
        }
    }

    public function testNewQueriesAreLoggedNotPages(): void
    {
        $this->server->addEvent('2026-09-01T10:00:00+00:00', 'fixture');
        $first = $this->list(['limit' => '1', 'action' => 'fixture']);
        $this->list(['limit' => '1', 'action' => 'fixture', 'before' => (string)$first['events'][0]['id']]);
        $this->list();

        $viewed = $this->server->logEntries('logs_viewed');
        $this->assertCount(2, $viewed);
        $this->assertSame(['action' => 'fixture'], $viewed[0]['filters']);
        $this->assertSame([], $viewed[1]['filters']);
        $this->assertSame(TestServer::ADMIN, $viewed[0]['username']);
        $this->assertStringContainsString('"filters":{}', $this->server->events()[array_key_last($this->server->events())]['record'],
            'no filters: an empty object, not a list');
    }

    public function testActionsList(): void
    {
        $this->server->addEvent('2026-09-01T10:00:00+00:00', 'zz_fixture');
        $response = $this->admin->post('admin', ['a' => 'listEventActions'], ['headers' => ['X-CSRF-TOKEN' => $this->token]]);
        $actions = $response->json()['actions'];
        $this->assertContains('cli_admin_created', $actions);
        $this->assertSame('zz_fixture', end($actions));
        $this->assertSame($actions, array_values(array_unique($actions)));
    }

    public function testTheTabIsRendered(): void
    {
        $page = $this->admin->get('admin');
        $this->assertSame(200, $page->status);
        foreach (['id="logsFilterForm"', 'id="logsTable"', 'id="logsMore"', 'id="eventRecordModal"'] as $marker) {
            $this->assertStringContainsString($marker, $page->body);
        }
    }

    public function testNonAdminRefused(): void
    {
        $bob = $this->bobClient();
        foreach (['listEvents', 'listEventActions'] as $action) {
            $response = $bob->postWithCsrf('admin', ['a' => $action]);
            $this->assertSame(403, $response->status);
            $this->assertArrayNotHasKey('events', $response->json() ?? []);
        }
        $this->assertSame(['listEvents', 'listEventActions'], array_column($this->server->logEntries('admin_access_denied'), 'requested_action'));
    }
}
