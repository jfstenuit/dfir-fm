<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;
use Tests\Support\HttpResponse;
use Tests\Support\TestServer;

/** Archive, then purge (D28, Phase 15 step 4): admin actions and bin/archive-events.php */
final class LogsArchiveTest extends FeatureTestCase
{
    private HttpClient $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        // Old events to archive, then the admin's login (now)
        for ($day = 1; $day <= 5; $day++) {
            $this->server->addEvent("2026-09-0{$day}T10:00:00+00:00", 'fixture', ['day' => $day]);
        }
        $this->admin = $this->adminClient();
        $this->token = (string)$this->admin->get('admin')->csrfToken();
    }

    private function call(string $action, array $form = []): HttpResponse
    {
        return $this->admin->post('admin', ['a' => $action] + $form, ['headers' => ['X-CSRF-TOKEN' => $this->token]]);
    }

    /** As the Logs tab sends it: a form POST with the token as a field */
    private function archive(string $before): HttpResponse
    {
        return $this->admin->post('admin', ['a' => 'archiveEvents', 'before' => $before, 'csrf_token' => $this->token]);
    }

    private function archives(): array
    {
        return $this->call('listArchives')->json()['archives'];
    }

    /** @return list<string> the day of each fixture event still stored */
    private function daysLeft(): array
    {
        return array_values(array_map(fn($e) => json_decode($e['record'], true)['day'],
            array_filter($this->server->events(), fn($e) => $e['action'] === 'fixture')));
    }

    public function testArchiveThenPurge(): void
    {
        $preview = $this->call('archivePreview', ['before' => '2026-09-04'])->json();
        $this->assertSame(['success' => true, 'before_ts' => '2026-09-04T00:00:00+00:00', 'events' => 3], $preview);

        $response = $this->archive('2026-09-04');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('filename="dfir-fm-archive-1_before_2026-09-04T000000Z.jsonl"', (string)$response->header('Content-Disposition'));
        $fixtures = array_column(array_filter($this->server->events(), fn($e) => $e['action'] === 'fixture'), 'record');
        $this->assertSame(implode("\n", array_slice($fixtures, 0, 3)) . "\n", $response->body, 'the three oldest records, as stored');

        $archive = $this->archives()[0];
        $this->assertSame([1, '2026-09-04T00:00:00+00:00', 3, hash('sha256', $response->body), TestServer::ADMIN, null],
            [$archive['id'], $archive['before_ts'], $archive['events'], $archive['sha256'], $archive['created_by'], $archive['purged_at']]);
        $logged = $this->server->logEntries('events_archived')[0];
        $this->assertSame([1, 3, $archive['sha256'], true, $archive['file']],
            [$logged['archive_id'], $logged['events'], $logged['sha256'], $logged['complete'], $logged['file']]);

        $purge = $this->call('purgeArchive', ['id' => '1'])->json();
        $this->assertTrue($purge['success']);
        $this->assertSame(TestServer::ADMIN, $purge['archive']['purged_by']);
        $this->assertSame([4, 5], $this->daysLeft(), 'exactly the archived events are gone');
        $actions = array_column($this->server->events(), 'action');
        $this->assertContains('events_archived', $actions);
        $this->assertContains('events_purged', $actions);
        $this->assertContains('login_password_success', $actions);
        $purged = $this->server->logEntries('events_purged')[0];
        $this->assertSame([1, 3, $archive['sha256']], [$purged['archive_id'], $purged['events'], $purged['sha256']]);

        $again = $this->call('purgeArchive', ['id' => '1']);
        $this->assertSame(409, $again->status);
        $this->assertStringContainsString('already purged', $again->json()['message']);
    }

    public function testPurgeRefusedWhenTheEventsChanged(): void
    {
        $this->archive('2026-09-04');
        // One archived record altered, by hand in the database
        $db = new \PDO('sqlite:' . $this->server->eventsDatabasePath);
        $db->exec("UPDATE events SET record = replace(record, '\"day\":2', '\"day\":22') WHERE action = 'fixture'");

        $response = $this->call('purgeArchive', ['id' => '1']);
        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('changed since it was made (3 events now, 3 archived, different SHA-256)', $response->json()['message']);
        $this->assertCount(5, $this->daysLeft(), 'nothing purged');

        $db->exec("DELETE FROM events WHERE action = 'fixture' AND record LIKE '%\"day\":1%'");
        $this->assertStringContainsString('(2 events now, 3 archived', $this->call('purgeArchive', ['id' => '1'])->json()['message']);
        $this->assertSame([], $this->server->logEntries('events_purged'));
    }

    /** Two archives covering the same events: purging one makes the other refuse */
    public function testOverlappingArchives(): void
    {
        $this->archive('2026-09-03');
        $this->archive('2026-09-05');
        $this->assertTrue($this->call('purgeArchive', ['id' => '2'])->json()['success']);
        $this->assertSame(409, $this->call('purgeArchive', ['id' => '1'])->status);
        $this->assertSame([5], $this->daysLeft());
    }

    /** An event stored after the archive, with an older time (an import), is not part of it */
    public function testLaterEventsWithOlderTimesAreKept(): void
    {
        $this->archive('2026-09-04');
        $this->server->addEvent('2026-08-01T10:00:00+00:00', 'fixture', ['day' => 'imported']);
        $this->assertTrue($this->call('purgeArchive', ['id' => '1'])->json()['success']);
        $this->assertSame(['4', '5', 'imported'], array_map('strval', $this->daysLeft()));
    }

    public function testRefusals(): void
    {
        $this->assertSame(0, $this->call('archivePreview', ['before' => '2026-01-01'])->json()['events']);
        $response = $this->archive('2026-01-01');
        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('no event before', $response->json()['message']);
        $this->assertSame(400, $this->archive('')->status);
        $this->assertSame(400, $this->archive('someday')->status);
        $this->assertSame(400, $this->call('purgeArchive', ['id' => 'x'])->status);
        $this->assertSame(409, $this->call('purgeArchive', ['id' => '99'])->status);
        $this->assertSame([], $this->archives());
        $this->assertSame([], $this->server->logEntries('events_archived'));

        $bob = $this->bobClient();
        foreach (['listArchives', 'archivePreview', 'archiveEvents', 'purgeArchive'] as $action) {
            $this->assertSame(403, $bob->postWithCsrf('admin', ['a' => $action, 'before' => '2026-09-04', 'id' => '1'])->status, $action);
        }
        $this->assertCount(5, $this->daysLeft());
    }

    public function testCli(): void
    {
        $out = $this->server->root . '/archive.jsonl';
        $result = $this->server->runBin('archive-events.php', '--before', '2026-09-03', '--out', $out);
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $sha = hash_file('sha256', $out);
        $this->assertStringContainsString("SHA-256 $sha", $result['stdout']);
        $this->assertSame(2, count(file($out)));

        $this->assertSame(2, $this->server->runBin('archive-events.php', '--before', '2026-09-03', '--out', $out)['exit'], 'existing file refused');
        $this->assertSame(1, $this->server->runBin('archive-events.php', '--before', '2020-01-01', '--out', "$out.2")['exit'], 'no event');
        $this->assertFileDoesNotExist("$out.2");
        $this->assertSame(2, $this->server->runBin('archive-events.php', '--purge')['exit']);

        $list = $this->server->runBin('archive-events.php', '--list');
        $this->assertStringContainsString("#1  before 2026-09-03T00:00:00+00:00  2 event(s)  sha256 $sha", $list['stdout']);
        $this->assertStringContainsString('not purged', $list['stdout']);

        $purge = $this->server->runBin('archive-events.php', '--purge', '1');
        $this->assertSame(0, $purge['exit'], $purge['stderr']);
        $this->assertSame([3, 4, 5], $this->daysLeft());
        $this->assertSame(1, $this->server->runBin('archive-events.php', '--purge', '1')['exit'], 'already purged');

        $archived = $this->server->logEntries('events_archived')[0];
        $this->assertSame([$sha, 2, $out], [$archived['sha256'], $archived['events'], $archived['file']]);
        $this->assertStringStartsWith('cli:', $archived['username']);
        $this->assertStringStartsWith('cli:', $this->server->logEntries('events_purged')[0]['username']);
        $this->assertSame($archived['username'], $this->archives()[0]['purged_by']);
    }
}
