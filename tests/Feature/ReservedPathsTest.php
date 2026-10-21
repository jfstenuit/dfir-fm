<?php
namespace Tests\Feature;

use Tests\Support\FeatureTestCase;
use Tests\Support\TestServer;

/** The database configured inside the storage: its paths are reserved */
final class ReservedPathsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Relative to the application directory: storage/files/db/app.sqlite
        $this->restartWith(['database_path' => 'storage/files/db/app.sqlite']);
        $this->server->grantRootAccess(TestServer::BOB);
    }

    public function testUploadWithAReservedNameRefused(): void
    {
        $client = $this->bobClient();
        $client->defaultHeaders['X-CSRF-TOKEN'] = (string)$client->get('')->csrfToken();

        $probe = $client->post('upload', ['a' => 'checkRights', 'cwd' => '', 'name' => 'db', 'size' => '4']);
        $this->assertSame([400, 'InvalidName'], [$probe->status, $probe->json()['code']], $probe->body);
        $this->assertStringContainsString('reserved', $probe->json()['message']);

        $other = $client->post('upload', ['a' => 'checkRights', 'cwd' => '', 'name' => 'db.txt', 'size' => '4']);
        $this->assertSame(200, $other->status, 'a reserved directory has no companion files');
    }

    public function testFolderWithAReservedNameRefused(): void
    {
        $response = $this->bobClient()->postWithCsrf('', ['a' => 'createFolder', 'cwd' => '/', 'name' => 'db']);
        $this->assertFalse($response->json()['success']);
        $this->assertStringContainsString('reserved', $response->json()['message']);
        $this->assertSame('0', (string)$this->server->db()->query("SELECT COUNT(*) FROM directories WHERE name = 'db'")->fetchColumn());
    }

    public function testIntegrityCheckReportsButDoesNotCountThem(): void
    {
        $result = $this->server->runBin('check-integrity.php');
        $this->assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $this->assertMatchesRegularExpression('#^RESERVED +/db, /db/app\.sqlite: #m', $result['stdout']);
        $this->assertStringNotContainsString('ORPHAN', $result['stdout']);
    }
}
