<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

/** bin/check-integrity.php and bin/expire-uploads.php */
final class UploadCliTest extends FeatureTestCase
{
    public function testIntegrityCleanStorage(): void
    {
        $this->server->addFile('a.bin', 'alpha');
        mkdir($this->server->root . '/storage/files/.uploads');
        file_put_contents($this->server->root . '/storage/files/.uploads/0123456789abcdef0123456789abcdef', 'staged'); // not an orphan

        $result = $this->server->runBin('check-integrity.php');
        $this->assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('1 file(s) re-hashed, 0 problem(s).', $result['stdout']);
    }

    public function testIntegrityProblemsReported(): void
    {
        $files = $this->server->root . '/storage/files';
        $this->server->addFile('tampered.bin', 'original');
        file_put_contents("$files/tampered.bin", 'changed!');
        $this->server->addFile('missing.bin', 'gone');
        unlink("$files/missing.bin");
        file_put_contents("$files/orphan.bin", 'partial v1 upload');

        $result = $this->server->runBin('check-integrity.php');
        $this->assertSame(1, $result['exit']);
        $this->assertMatchesRegularExpression('#^HASH +/tampered\.bin \(file id 1\): stored ' . hash('sha256', 'changed!') . '#m', $result['stdout']);
        $this->assertMatchesRegularExpression('#^MISSING +/missing\.bin#m', $result['stdout']);
        $this->assertMatchesRegularExpression('#^ORPHAN +/orphan\.bin#m', $result['stdout']);
        $this->assertSame('changed!', file_get_contents("$files/tampered.bin"), 'read-only');
    }

    public function testExpireUploads(): void
    {
        $db = $this->server->db();
        $db->exec("INSERT INTO uploads (user_id, client_uuid, directory_id, name, size, chunk_size, chunk_count, staging_key, status, last_update)
                   VALUES (1, 'u1', 1, 'old.bin', 10, 4, 3, '0123456789abcdef0123456789abcdef', 'in_progress', datetime('now', '-2 days')),
                          (1, 'u2', 1, 'new.bin', 10, 4, 3, 'fedcba9876543210fedcba9876543210', 'in_progress', datetime('now'))");
        $staging = $this->server->root . '/storage/files/.uploads';
        mkdir($staging);
        touch("$staging/0123456789abcdef0123456789abcdef");
        touch("$staging/fedcba9876543210fedcba9876543210");

        $result = $this->server->runBin('expire-uploads.php');
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame("1 upload(s) expired.\n", $result['stdout']);
        $this->assertSame(['expired', 'in_progress'], $db->query("SELECT status FROM uploads ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(['fedcba9876543210fedcba9876543210'], array_map('basename', glob("$staging/*")));
        $this->assertCount(1, $this->server->logEntries('upload_expired'));
    }
}
