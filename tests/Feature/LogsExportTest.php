<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\HttpClient;
use Tests\Support\HttpResponse;
use Tests\Support\TestServer;

/** Admin page, Logs tab: exportEvents (#23, Phase 15 step 3) */
final class LogsExportTest extends FeatureTestCase
{
    private HttpClient $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->adminClient();
        $this->token = (string)$this->admin->get('admin')->csrfToken();
    }

    /** As the Logs tab sends it: a form POST with the token as a field */
    private function export(string $format, array $filters = []): HttpResponse
    {
        return $this->admin->post('admin', ['a' => 'exportEvents', 'format' => $format, 'csrf_token' => $this->token] + $filters);
    }

    public function testJsonlIsTheLogFile(): void
    {
        $response = $this->export('jsonl');
        $this->assertSame(200, $response->status);
        $this->assertStringStartsWith('application/x-ndjson', (string)$response->header('Content-Type'));
        $this->assertMatchesRegularExpression('/attachment; filename="dfir-fm-events_start_to_\d{4}-\d{2}-\d{2}T\d{6}Z\.jsonl"/',
            (string)$response->header('Content-Disposition'));

        $logged = $this->server->logEntries('logs_exported');
        $this->assertCount(1, $logged);
        // Every line of the log file written before the export, in order
        $lines = file($this->server->root . '/app.log', FILE_IGNORE_NEW_LINES);
        $this->assertSame(implode("\n", array_slice($lines, 0, $logged[0]['events'])) . "\n", $response->body);
        $this->assertSame($logged[0]['events'], count($lines) - 1, 'all but logs_exported itself');

        $this->assertSame(hash('sha256', $response->body), $logged[0]['sha256']);
        $this->assertSame('jsonl', $logged[0]['format']);
        $this->assertTrue($logged[0]['complete']);
        $this->assertSame((int)$this->server->events()[count($lines) - 2]['id'], $logged[0]['last_event_id']);
        $this->assertSame([], $logged[0]['filters']);
    }

    public function testCsvRoundTrip(): void
    {
        $awkward = "comma, \"quotes\" and\nnew line\r\n=SUM(A1) \\\" backslash";
        $this->server->addEvent('2026-09-01T10:00:00+00:00', 'upload_completed', ['username' => 'ann,"x"@case.test',
            'ip' => '198.51.100.7', 'peer_ip' => '10.0.0.2', 'file' => $awkward]);
        $this->server->addEvent('2026-09-02T10:00:00+00:00', 'download_success', ['file' => 'other']);

        $response = $this->export('csv', ['text' => 'SUM(A1)']);
        $this->assertSame(200, $response->status);
        $this->assertStringStartsWith('text/csv', (string)$response->header('Content-Type'));

        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $response->body);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        $this->assertSame(['ts', 'action', 'username', 'ip', 'peer_ip', 'record'], $rows[0]);
        $this->assertCount(2, $rows, 'header and the one matching event');
        $this->assertSame(['2026-09-01T10:00:00+00:00', 'upload_completed', 'ann,"x"@case.test', '198.51.100.7', '10.0.0.2'], array_slice($rows[1], 0, 5));
        $this->assertSame($awkward, json_decode($rows[1][5], true)['file']);
        $this->assertSame(array_column($this->server->events(), 'record', 'action')['upload_completed'], $rows[1][5], 'the record as stored');

        $logged = $this->server->logEntries('logs_exported')[0];
        $this->assertSame(hash('sha256', $response->body), $logged['sha256']);
        $this->assertSame(['text' => 'SUM(A1)'], $logged['filters']);
        $this->assertSame(1, $logged['events']);
    }

    public function testFiltersAndFileName(): void
    {
        $this->server->addEvent('2026-09-01T10:00:00+00:00', 'fixture', ['n' => 1]);
        $this->server->addEvent('2026-09-02T10:00:00+00:00', 'fixture', ['n' => 2]);
        $this->server->addEvent('2026-09-03T10:00:00+00:00', 'fixture', ['n' => 3]);

        $response = $this->export('jsonl', ['action' => 'fixture', 'from' => '2026-09-02T00:00', 'until' => '2026-09-03T10:00:00Z']);
        $this->assertSame([2], array_map(fn($l) => json_decode($l, true)['n'], explode("\n", rtrim($response->body))));
        $this->assertStringContainsString('filename="dfir-fm-events_2026-09-02T000000Z_to_2026-09-03T100000Z.jsonl"',
            (string)$response->header('Content-Disposition'));
    }

    public function testEmptySelection(): void
    {
        $response = $this->export('csv', ['action' => 'no_such_action']);
        $this->assertSame("ts,action,username,ip,peer_ip,record\r\n", $response->body);
        $this->assertSame(0, $this->server->logEntries('logs_exported')[0]['events']);
    }

    public function testInvalidRequestsRefused(): void
    {
        foreach ([['format' => 'xml'], ['format' => ''], ['format' => 'csv', 'from' => 'soon'], ['format' => 'csv', 'ip[]' => 'x']] as $form) {
            $response = $this->admin->post('admin', ['a' => 'exportEvents', 'csrf_token' => $this->token] + $form);
            $this->assertSame(400, $response->status, json_encode($form));
        }
        $this->assertSame([], $this->server->logEntries('logs_exported'));

        $response = $this->admin->post('admin', ['a' => 'exportEvents', 'format' => 'csv']);
        $this->assertSame(403, $response->status, 'no CSRF token');

        $bob = $this->bobClient();
        $response = $bob->postWithCsrf('admin', ['a' => 'exportEvents', 'format' => 'jsonl']);
        $this->assertSame(403, $response->status);
        $this->assertStringNotContainsString('"action"', $response->body);
        $this->assertSame('exportEvents', $this->server->logEntries('admin_access_denied')[0]['requested_action']);
    }
}
