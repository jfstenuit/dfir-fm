<?php
namespace Tests\Unit\Middleware;

use Core\Database;
use Middleware\AccessMiddleware;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * A folder's default group (D15, D20, D21): "<path> contributors", the one
 * with rights on that folder, created with contributor access only.
 */
final class DefaultGroupTest extends TestCase
{
    private string $dir;
    private PDO $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-groups-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        $this->db = Database::initialize("$this->dir/app.sqlite");
        foreach (['/case-a', '/case-a/evidence', '/case-b', '/case-b/evidence', '/odd_%'] as $path) {
            $parent = $this->db->query("SELECT id FROM directories WHERE path = " . $this->db->quote(dirname($path)))->fetchColumn();
            $stmt = $this->db->prepare("INSERT INTO directories (name, path, parent_id) VALUES (:n, :p, :parent)");
            $stmt->execute([':n' => basename($path), ':p' => $path, ':parent' => $parent]);
        }
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, null);
        array_map('unlink', glob("$this->dir/*"));
        rmdir($this->dir);
    }

    private function dirId(string $path): int
    {
        return (int)$this->db->query("SELECT id FROM directories WHERE path = " . $this->db->quote($path))->fetchColumn();
    }

    /** @return list<array{path: string, can_view: int, can_write: int, can_upload: int}> */
    private function rightsOf(int $groupId): array
    {
        return $this->db->query("SELECT d.path, ar.can_view, ar.can_write, ar.can_upload FROM access_rights ar
                                 JOIN directories d ON d.id = ar.directory_id WHERE ar.group_id = $groupId")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function addGroup(string $name, string $path): int
    {
        $this->db->prepare("INSERT INTO groups (name) VALUES (:n)")->execute([':n' => $name]);
        $id = (int)$this->db->lastInsertId();
        $this->db->exec("INSERT INTO access_rights (group_id, directory_id, can_view) VALUES ($id, {$this->dirId($path)}, 1)");
        return $id;
    }

    public function testCreatedWithContributorAccessOnly(): void
    {
        $group = AccessMiddleware::defaultGroupFor($this->db, '/case-a');

        $this->assertTrue($group['created']);
        $this->assertSame('/case-a contributors', $group['name']);
        $this->assertSame([['path' => '/case-a', 'can_view' => 0, 'can_write' => 0, 'can_upload' => 1]], $this->rightsOf($group['id']));
        $this->assertFalse($this->db->inTransaction());
    }

    public function testReusedWithItsRightsUnchanged(): void
    {
        $first = AccessMiddleware::defaultGroupFor($this->db, '/case-a');
        // An administrator widens the group's rights; a later invitation keeps them
        $this->db->exec("UPDATE access_rights SET can_view = 1, can_write = 1 WHERE group_id = {$first['id']}");

        $second = AccessMiddleware::defaultGroupFor($this->db, '/case-a');
        $this->assertSame($first['id'], $second['id']);
        $this->assertFalse($second['created']);
        $this->assertSame([['path' => '/case-a', 'can_view' => 1, 'can_write' => 1, 'can_upload' => 1]], $this->rightsOf($first['id']));
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM groups")->fetchColumn() - 1, 'one group besides admin');
    }

    public function testGroupWithTheNameButRightsElsewhereIsNotReused(): void
    {
        // Left by a rename (D20) or by a deleted folder of the same path
        $stale = $this->addGroup('/case-a contributors', '/case-b');
        $this->addGroup('/case-a contributors (2)', '/case-b');

        $group = AccessMiddleware::defaultGroupFor($this->db, '/case-a');
        $this->assertNotSame($stale, $group['id']);
        $this->assertSame('/case-a contributors (3)', $group['name']);
        $this->assertSame([['path' => '/case-b', 'can_view' => 1, 'can_write' => 0, 'can_upload' => 0]], $this->rightsOf($stale),
            'the stale group gains nothing');

        $this->assertSame($group['id'], AccessMiddleware::defaultGroupFor($this->db, '/case-a')['id'], 'the numbered group is found again');
    }

    public function testOtherGroupsWithRightsOnTheFolderAreNotDefaultGroups(): void
    {
        $this->addGroup('Analysts', '/case-a');
        $this->addGroup('/case-a contributors extra', '/case-a');

        $this->assertSame('/case-a contributors', AccessMiddleware::defaultGroupFor($this->db, '/case-a')['name']);
    }

    public function testSameFolderNameUnderTwoParents(): void
    {
        $a = AccessMiddleware::defaultGroupFor($this->db, '/case-a/evidence');
        $b = AccessMiddleware::defaultGroupFor($this->db, '/case-b/evidence');

        $this->assertNotSame($a['id'], $b['id']);
        $this->assertSame('/case-a/evidence contributors', $a['name']);
        $this->assertSame('/case-b/evidence contributors', $b['name']);
    }

    public function testDeletedDefaultGroupIsCreatedAgain(): void
    {
        $first = AccessMiddleware::defaultGroupFor($this->db, '/case-a');
        $this->db->exec("DELETE FROM groups WHERE id = {$first['id']}");   // D18; rights go with it (cascade)

        $again = AccessMiddleware::defaultGroupFor($this->db, '/case-a');
        $this->assertTrue($again['created']);
        $this->assertSame('/case-a contributors', $again['name']);
    }

    public function testPathWithLikeWildcards(): void
    {
        $this->addGroup('/oddX% contributors', '/odd_%');   // would match LIKE '/odd_% contributors'
        $this->assertSame('/odd_% contributors', AccessMiddleware::defaultGroupFor($this->db, '/odd_%')['name']);
    }

    public function testRootAndUnknownFolders(): void
    {
        $this->assertNull(AccessMiddleware::defaultGroupFor($this->db, '/'));
        $this->assertNull(AccessMiddleware::defaultGroupFor($this->db, '/nowhere'));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM groups WHERE is_admin = 0")->fetchColumn());
    }

    public function testRunsInsideTheCallersTransaction(): void
    {
        $this->db->beginTransaction();
        $group = AccessMiddleware::defaultGroupFor($this->db, '/case-a');
        $this->assertTrue($this->db->inTransaction());
        $this->db->rollBack();
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM groups WHERE id = {$group['id']}")->fetchColumn());
    }
}
