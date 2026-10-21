<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/** "Refresh from storage" over HTTP (the jobs themselves: RefreshJobsTest) */
final class RefreshTest extends FeatureTestCase
{
    public function testAdministratorImportsAFile(): void
    {
        $files = $this->server->root . '/storage/files';
        file_put_contents("$files/rsynced.E01", 'copied outside the application');
        mkdir("$files/case-042");
        $this->server->addFile('known.bin', 'known');

        $admin = $this->adminClient();
        $start = $admin->postWithCsrf('', ['a' => 'refresh', 'cwd' => '/']);
        $this->assertSame(200, $start->status, $start->body);
        $job = $start->json();
        $this->assertSame([true, 'in_progress', 2, ['case-042']], [$job['success'], $job['status'], $job['total'], $job['comparison']['new_folders']]);

        $results = [];
        do {
            $step = $admin->postWithCsrf('', ['a' => 'refreshStep', 'refresh_id' => (string)$job['refresh_id']]);
            $this->assertSame(200, $step->status, $step->body);
            $state = $step->json();
            if ($state['file']) {
                $results[$state['file']['name']] = $state['file']['status'];
            }
        } while ($state['status'] === 'in_progress');

        $this->assertSame(['rsynced.E01' => 'imported', 'known.bin' => 'verified'], $results);
        $this->assertSame('completed', $state['status']);
        $status = $admin->postWithCsrf('', ['a' => 'refreshStatus', 'refresh_id' => (string)$job['refresh_id'], 'after' => '1'])->json();
        $this->assertSame(['known.bin'], array_column($status['files'], 'name'));

        $row = $this->server->db()->query("SELECT origin, sha256, uploaded_by FROM files WHERE name = 'rsynced.E01'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['import', hash('sha256', 'copied outside the application')], [$row['origin'], $row['sha256']]);
        $this->assertSame('1', (string)$this->server->db()->query("SELECT COUNT(*) FROM directories WHERE path = '/case-042'")->fetchColumn());
        $this->assertCount(1, $this->server->logEntries('file_imported'));
        $this->assertCount(1, $this->server->logEntries('directory_refreshed'));

        // The listing carries the origin (the UI marks it in Step 3)
        $this->assertStringContainsString('rsynced.E01', $admin->get('')->body);
    }

    public function testNonAdministratorRefused(): void
    {
        $this->server->grantRootAccess(TestServer::BOB);
        file_put_contents($this->server->root . '/storage/files/rsynced.E01', 'x');
        $bob = $this->bobClient();

        foreach (['refresh' => ['cwd' => '/'], 'refreshStep' => ['refresh_id' => '1'], 'refreshCancel' => ['refresh_id' => '1']] as $action => $fields) {
            $response = $bob->postWithCsrf('', ['a' => $action] + $fields);
            $this->assertSame([403, 'Forbidden'], [$response->status, $response->json()['code']], $action);
        }
        $this->assertSame('0', (string)$this->server->db()->query("SELECT COUNT(*) FROM refresh_jobs")->fetchColumn());
        $this->assertSame('0', (string)$this->server->db()->query("SELECT COUNT(*) FROM files")->fetchColumn());
        $this->assertSame(['Not an administrator'], array_unique(array_column($this->server->logEntries('directory_refresh_denied'), 'cause')));
    }

    public function testInvalidArguments(): void
    {
        $admin = $this->adminClient();
        $cases = [
            [['a' => 'refreshStep'], 400, 'InvalidArgument'],
            [['a' => 'refreshStep', 'refresh_id' => '0'], 400, 'InvalidArgument'],
            [['a' => 'refreshStatus', 'refresh_id' => '42'], 404, 'NoSuchRefresh'],
            [['a' => 'refresh', 'cwd' => '/no-such-folder'], 404, 'NoSuchFolder'],
        ];
        foreach ($cases as [$fields, $status, $code]) {
            $response = $admin->postWithCsrf('', $fields);
            $this->assertSame([$status, $code], [$response->status, $response->json()['code'] ?? null], json_encode($fields));
        }
    }

    public function testCsrfRequired(): void
    {
        $response = $this->adminClient()->post('', ['a' => 'refresh', 'cwd' => '/']);
        $this->assertSame(403, $response->status);
        $this->assertSame('csrf_invalid', $response->json()['code']);
    }
}
