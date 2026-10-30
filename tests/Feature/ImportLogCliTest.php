<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

/** bin/import-log.php (#23, Phase 15 step 5) */
final class ImportLogCliTest extends FeatureTestCase
{
    public function testUpgradedInstallation(): void
    {
        // The log of the version before: two rotated files and the current one,
        // which goes on after the events database started
        $root = $this->server->root;
        $old = fn(int $day, string $action) => json_encode(['ts' => "2026-09-0{$day}T10:00:00+00:00", 'action' => $action,
                                                            'ip' => '203.0.113.5', 'username' => 'ann@case.test']);
        file_put_contents("$root/old.log.2.gz", gzencode($old(1, 'login_password_success') . "\n"));
        file_put_contents("$root/old.log.1", $old(2, 'upload_completed') . "\n{broken\n");
        $current = "$root/app.log";
        file_put_contents($current, $old(3, 'download_success') . "\n" . file_get_contents($current));

        $result = $this->server->runBin('import-log.php', $current, "$root/old.log.1", "$root/old.log.2.gz");
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString("$root/old.log.1: 1 imported, 0 already stored, 0 newer than the events database, 0 in a purged period, 1 malformed line(s)", $result['stdout']);
        $this->assertMatchesRegularExpression('#app\.log: 1 imported, 0 already stored, [1-9]\d* newer than the events database#', $result['stdout'],
            'the lines written live are in the store already');

        $imported = array_values(array_filter($this->server->events(), fn($e) => str_starts_with($e['ts'], '2026-09-0')));
        $this->assertSame(['login_password_success', 'upload_completed', 'download_success'], array_column($imported, 'action'), 'oldest file first');
        $this->assertSame('ann@case.test', $imported[0]['username']);

        $logged = $this->server->logEntries('events_imported');
        $this->assertCount(3, $logged);
        $this->assertSame([hash_file('sha256', "$root/old.log.2.gz"), 1], [$logged[0]['sha256'], $logged[0]['imported']]);
        $this->assertStringStartsWith('cli:', $logged[0]['username']);

        $again = $this->server->runBin('import-log.php', "$root/old.log.1");
        $this->assertStringContainsString('0 imported, 1 already stored', $again['stdout']);
    }

    public function testRefusals(): void
    {
        $this->assertSame(2, $this->server->runBin('import-log.php')['exit']);
        $this->assertSame(2, $this->server->runBin('import-log.php', '--all')['exit']);

        file_put_contents($this->server->root . '/old.csv', "\"2026-09-01T10:00:00+00:00\",\"login_password_success\"\n");
        file_put_contents($this->server->root . '/old.log', json_encode(['ts' => '2026-09-01T10:00:00+00:00', 'action' => 'x']) . "\n");
        $result = $this->server->runBin('import-log.php', $this->server->root . '/old.log', $this->server->root . '/old.csv');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString("is a CSV log", $result['stderr']);
        $this->assertStringContainsString('Nothing was imported.', $result['stderr']);
        $this->assertSame([], $this->server->logEntries('events_imported'));
        $this->assertSame(1, $this->server->runBin('import-log.php', $this->server->root . '/missing.log')['exit']);
    }
}
