<?php
namespace Middleware;

use Core\Database;
use PDO;

class AccessMiddleware
{
    /** Start of the username of a deleted account (see deletedPseudonym()) */
    public const DELETED_PREFIX = 'deleted:';

    public static function getUserInfo($db, $selector, $createIfMissing=false)
    {
        if (preg_match('/^\d+$/',$selector)) {
            $stmt = $db->prepare("SELECT * FROM users u WHERE u.id=:user_id");
            $stmt->bindParam(':user_id', $selector, PDO::PARAM_INT);
        } else {
            $stmt = $db->prepare("SELECT * FROM users u WHERE u.username=:user_name");
            $stmt->bindParam(':user_name', $selector, PDO::PARAM_STR);
        }
 
        $stmt->execute();

        $ret = $stmt->fetch(PDO::FETCH_ASSOC);

        // If user not found and $createIfMissing is true, create a new entry
        if (!$ret) {
            $stmt = $db->prepare("
                INSERT INTO users (username, password)
                VALUES (:email, '!')
            ");
            $stmt->bindParam(':email', $selector, PDO::PARAM_STR);
            $stmt->execute();
            $userId = $db->lastInsertId();

            return self::getUserInfo($db,$userId,false);
        }

        return $ret;
    }

    /**
     * SQL expression naming the user whose id is in $idColumn, the users
     * row being joined as `u`: "deleted user #<id>" for a deleted account,
     * "unknown user #<id>" when the row is missing (accounts removed before
     * deletion became soft), NULL when $idColumn is NULL
     */
    public static function userLabel(string $idColumn): string
    {
        return "CASE WHEN u.id IS NULL THEN 'unknown user #' || $idColumn"
             . " WHEN u.deleted_at IS NOT NULL THEN 'deleted user #' || u.id"
             . " ELSE u.username END";
    }

    /**
     * Username (and e-mail) of a deleted account: a hash of its address, so
     * that the account of a known address can still be found
     * (bin/find-deleted-user.php), and the account id, so that one address
     * can be deleted more than once
     */
    public static function deletedPseudonym(string $address, int $userId): string
    {
        return self::DELETED_PREFIX . self::addressHash($address) . ':' . $userId;
    }

    public static function addressHash(string $address): string
    {
        return hash('sha256', mb_strtolower(trim($address)));
    }

    public static function generateTokenForUser($db, $selector)
    {
        $userInfo = self::getUserInfo($db,$selector,true);
        if (!$userInfo) {
            return false;
        }
 
        $userId = $userInfo['id'];

        // Generate a unique token and expiry time
        $token = bin2hex(random_bytes(16));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Update token
        $stmt = $db->prepare("
            UPDATE users 
            SET invitation_token = :token, token_expiry = :expiry 
            WHERE id = :id
        ");
        $stmt->bindParam(':token', $token, PDO::PARAM_STR);
        $stmt->bindParam(':expiry', $expiry, PDO::PARAM_STR);
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $token;
    }

    /** Suffix of a folder's default group name: "<folder path> contributors" (D20) */
    public const DEFAULT_GROUP_SUFFIX = ' contributors';

    /**
     * The default group of a folder, created when missing (D15, D20, D21).
     *
     * Its name is "<folder path> contributors", or "… contributors (n)"; it
     * is the default group only while it has an access_rights row on that
     * folder. A group of that name with rights elsewhere (left by a rename or
     * by a deleted folder of the same path) is never reused: its members
     * would gain the folder. A new group gets contributor access only
     * (can_upload) and the next free name. The rights of an existing group
     * are never changed.
     *
     * Returns null for '/' (no invitation into the root, D14) or an unknown
     * folder; else ['id', 'name', 'created'].
     */
    public static function defaultGroupFor(PDO $db, string $directoryPath): ?array
    {
        if ($directoryPath === '/') {
            return null;
        }
        // BEGIN IMMEDIATE: two invitations at once can't both create a group
        $own = !$db->inTransaction();
        if ($own) {
            $db->exec('BEGIN IMMEDIATE');
        }
        try {
            $group = self::findOrCreateDefaultGroup($db, $directoryPath);
            if ($own) {
                $db->exec('COMMIT');
            }
            return $group;
        } catch (\Throwable $e) {
            if ($own) {
                $db->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    private static function findOrCreateDefaultGroup(PDO $db, string $directoryPath): ?array
    {
        $stmt = $db->prepare("SELECT id FROM directories WHERE path = :path");
        $stmt->execute([':path' => $directoryPath]);
        $directoryId = $stmt->fetchColumn();
        if ($directoryId === false) {
            return null;
        }

        // Names are compared in PHP: the path may hold LIKE wildcards (_ and %)
        $base = $directoryPath . self::DEFAULT_GROUP_SUFFIX;
        $stmt = $db->prepare("SELECT g.id, g.name FROM groups g JOIN access_rights ar ON ar.group_id = g.id
                              WHERE ar.directory_id = :dir ORDER BY g.id");
        $stmt->execute([':dir' => $directoryId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $group) {
            if ($group['name'] === $base || preg_match('/^' . preg_quote($base, '/') . ' \(\d+\)$/', $group['name'])) {
                return ['id' => (int)$group['id'], 'name' => $group['name'], 'created' => false];
            }
        }

        $taken = $db->prepare("SELECT 1 FROM groups WHERE name = :name");
        for ($n = 1, $name = $base; ; $name = $base . ' (' . ++$n . ')') {
            $taken->execute([':name' => $name]);
            if ($taken->fetchColumn() === false) {
                break;
            }
        }

        $stmt = $db->prepare("INSERT INTO groups (name, description) VALUES (:name, :description)");
        $stmt->execute([':name' => $name, ':description' => "Default group of $directoryPath (contributors)"]);
        $groupId = (int)$db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO access_rights (group_id, directory_id, can_view, can_write, can_upload)
                              VALUES (:group, :dir, 0, 0, 1)");
        $stmt->execute([':group' => $groupId, ':dir' => $directoryId]);

        return ['id' => $groupId, 'name' => $name, 'created' => true];
    }

    public static function addUserToGroup($db, $userId, $groupId) {
        $stmt = $db->prepare("
            INSERT OR IGNORE INTO user_group (group_id, user_id)
            VALUES (:group_id, :user_id)
        ");
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }
    
    /**
     * Whether the user belongs to a group flagged as admin.
     * Evaluated on every call (not cached in the session) so that
     * revoking admin rights takes effect immediately.
     */
    public static function isAdmin($db, $userId): bool
    {
        if (empty($userId)) {
            return false;
        }

        $stmt = $db->prepare("
            SELECT 1
            FROM user_group ug
            JOIN groups g ON g.id = ug.group_id
            WHERE ug.user_id = :user_id AND g.is_admin = 1
            LIMIT 1
        ");
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Number of users in admin groups whose account is not locked,
     * optionally excluding one user. Used to prevent removing the last
     * usable admin.
     */
    public static function countActiveAdmins($db, ?int $excludeUserId = null): int
    {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT u.id)
            FROM users u
            JOIN user_group ug ON ug.user_id = u.id
            JOIN groups g ON g.id = ug.group_id
            WHERE g.is_admin = 1 AND u.password <> '!' AND u.locked_at IS NULL AND u.deleted_at IS NULL AND u.id <> :exclude
        ");
        $stmt->bindValue(':exclude', $excludeUserId ?? 0, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    public static function checkAccess($db, $directoryPath, $userId)
    {
        // Step 1: Admins have full access to every directory
        if (self::isAdmin($db, $userId)) {
            // Admin has full access rights
            $stmt = $db->prepare("SELECT id FROM directories WHERE path = :directory_name");
            $stmt->bindParam(':directory_name', $directoryPath, PDO::PARAM_STR);
            $stmt->execute();
            $directoryId = $stmt->fetchColumn();

            return [
                'directory_id' => $directoryId,
                'is_admin' => true,
                'can_read' => true,
                'can_write' => true,
                'can_upload' => true
            ];
        }

        // Step 2: Aggregate access rights from all groups the user belongs to for the specified directory
        $stmt = $db->prepare("
            SELECT
                MAX(d.id) AS directory_id,
                MAX(ar.can_view) AS can_read,
                MAX(ar.can_write) AS can_write,
                MAX(ar.can_upload) AS can_upload
            FROM
                directories d
            LEFT JOIN
                access_rights ar ON ar.directory_id = d.id
            LEFT JOIN
                user_group ug ON ug.group_id = ar.group_id
            WHERE
                d.path = :directory_name
                AND ug.user_id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':directory_name', $directoryPath, PDO::PARAM_STR);
        $stmt->execute();

        $accessRights = $stmt->fetch(PDO::FETCH_ASSOC);

        // Step 3: Return the aggregated access rights
        return [
            'directory_id' => $accessRights['directory_id'],
            'is_admin' => false,
            'can_read' => (bool)$accessRights['can_read'],
            'can_write' => (bool)$accessRights['can_write'],
            'can_upload' => (bool)$accessRights['can_upload']
        ];
    }

    public static function getDirectoryInfo($db, $selector)
    {
        $query = "
            SELECT
                d.*,
                p.path AS parent_path,
                (SELECT COUNT(*) FROM directories WHERE parent_id = d.id) AS subdirectory_count,
                (SELECT COUNT(*) FROM files WHERE directory_id = d.id) AS file_count
            FROM directories d
            LEFT JOIN directories p ON d.parent_id = p.id
            WHERE
        ";
        if (preg_match('/^\d+$/',$selector)) {
            $stmt = $db->prepare($query."d.id=:directory_id");
            $stmt->bindParam(':directory_id', $selector, PDO::PARAM_INT);
        } elseif ($selector==='') {
            $stmt = $db->prepare($query."d.path='/'");
        } else {
            $stmt = $db->prepare($query."d.path=:directory_name");
            $stmt->bindParam(':directory_name', $selector, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function getFileInfo($db, $selector)
    {
        $query = "
            SELECT
                f.*, d.path AS directory_path
            FROM
                files f
            JOIN
                directories d ON f.directory_id = d.id
            WHERE
        ";
        if (preg_match('/^\d+$/',$selector)) {
            $stmt = $db->prepare($query."f.id=:file_id");
            $stmt->bindParam(':file_id', $selector, PDO::PARAM_INT);
        } else {
            $pathParts = pathinfo($selector);
            $directoryPath = $pathParts['dirname'];
            $fileName = $pathParts['basename'];
            $stmt = $db->prepare($query."d.path = :directory_path
                AND f.name = :file_name");
            $stmt->bindParam(':directory_path', $directoryPath, PDO::PARAM_STR);
            $stmt->bindParam(':file_name', $fileName, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function getAdminDirectoryItems($db, $userId, $directoryInfo)
    {
        $directories = [];
        $files = [];
    
        $cwd = rtrim($directoryInfo['path'], '/');
        $cwdPrefix = $cwd === '' ? '/' : $cwd . '/';

        $stmt = $db->prepare("
            SELECT
                'd' AS type,
                d.id,
                d.name,
                NULL AS size,
                NULL AS sha256,
                d.created_at,
                " . self::userLabel('d.created_by') . " AS created_by,
                d.created_from
            FROM directories d
            LEFT JOIN users u ON d.created_by = u.id
            WHERE d.parent_id = :parent_id
        ");
        $stmt->execute([':parent_id' => $directoryInfo['id']]);
        $directories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all files
        $stmt = $db->prepare("
            SELECT
                'f' AS type,
                f.id AS id,
                f.name AS name,
                f.size AS size,
                f.sha256 AS sha256,
                f.uploaded_at AS created_at,
                " . self::userLabel('f.uploaded_by') . " AS created_by,
                f.uploaded_from AS created_from
            FROM files f
            LEFT JOIN users u ON f.uploaded_by = u.id
            WHERE f.directory_id = :directory_id
        ");
        $stmt->execute([':directory_id' => $directoryInfo['id']]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_merge($directories, $files);
    }

    public static function getDirectoryItems($db, $userId, $directoryInfo)
    {
        $directories = [];
        $files = [];

        $cwd = rtrim($directoryInfo['path'], '/');
        $cwdPrefix = $cwd === '' ? '/' : $cwd . '/';

        // The stored path, not $cwd: at the root $cwd is '' and no directory has that path
        $accessRights = self::checkAccess($db, $directoryInfo['path'], $userId);

        if (isset($accessRights['is_admin']) && $accessRights['is_admin']) {
            return self::getAdminDirectoryItems($db, $userId, $directoryInfo);
        }

        // Step 1: Get accessible paths
        $stmt = $db->prepare("
            SELECT d.id, d.path
            FROM directories d
            JOIN access_rights ar ON ar.directory_id = d.id
            JOIN user_group ug ON ar.group_id = ug.group_id
            WHERE ug.user_id = :user_id
            AND (ar.can_view = 1 OR ar.can_upload = 1 OR ar.can_write = 1)
        ");
        $stmt->execute([':user_id' => $userId]);
        $accessiblePaths = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // id => path

        // Step 2: Extract immediate children of cwd
        $visibleSubpaths = [];
        foreach ($accessiblePaths as $id => $path) {
            if (strpos($path, $cwdPrefix) === 0) {
                $remaining = substr($path, strlen($cwdPrefix));
                if ($remaining !== '' && strpos($remaining, '/') === false) {
                    // It's a direct child
                    $visibleSubpaths[$id] = $cwdPrefix . $remaining;
                }
            }
        }

        if (!empty($visibleSubpaths)) {
            // Step 3: Fetch metadata for visible subdirectories
            $placeholders = implode(',', array_fill(0, count($visibleSubpaths), '?'));
            $stmt = $db->prepare("
                SELECT
                    'd' AS type,
                    d.id,
                    d.name,
                    NULL AS size,
                    NULL AS sha256,
                    d.created_at,
                    " . self::userLabel('d.created_by') . " AS created_by,
                    d.created_from
                FROM directories d
                LEFT JOIN users u ON d.created_by = u.id
                WHERE d.path IN ($placeholders)
            ");
            $stmt->execute(array_values($visibleSubpaths));
            $directories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fetch files
        if (!empty($accessRights['can_upload']) || !empty($accessRights['can_read'])) {
            if (!empty($accessRights['can_read'])) {
                // If user can read, show all files
                $stmt = $db->prepare("
                    SELECT
                        'f' AS type,
                        f.id AS id,
                        f.name AS name,
                        f.size AS size,
                        f.sha256 AS sha256,
                        f.uploaded_at AS created_at,
                        " . self::userLabel('f.uploaded_by') . " AS created_by,
                        f.uploaded_from AS created_from
                    FROM files f
                    LEFT JOIN users u ON f.uploaded_by = u.id
                    WHERE f.directory_id = :directory_id
                ");
                $stmt->execute([
                    ':directory_id' => $directoryInfo['id']
                ]);
            } else {
                // If user can only upload, show only own files
                $stmt = $db->prepare("
                    SELECT
                        'f' AS type,
                        f.id AS id,
                        f.name AS name,
                        f.size AS size,
                        f.sha256 AS sha256,
                        f.uploaded_at AS created_at,
                        " . self::userLabel('f.uploaded_by') . " AS created_by,
                        f.uploaded_from AS created_from
                    FROM files f
                    LEFT JOIN users u ON f.uploaded_by = u.id
                    WHERE f.directory_id = :directory_id
                      AND f.uploaded_by = :user_id
                ");
                $stmt->execute([
                    ':directory_id' => $directoryInfo['id'],
                    ':user_id' => $userId
                ]);
            }
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    
        // Merge directories and files
        return array_merge($directories, $files);
    }
    
    public static function getDirectoryById($db, $id)
    {
        $stmt = $db->prepare("
            SELECT
                d.*
            FROM
                directories d
            WHERE
                d.id = :directory_id
        ");
        $stmt->bindParam(':directory_id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

}
?>