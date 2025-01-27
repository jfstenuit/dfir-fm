<?php
namespace Middleware;

use Core\Database;
use PDO;

class AccessMiddleware
{
    public static function getUserInfo($db, $selector, $createIfMissing=false)
    {
        if (preg_match('/^\d+$/',$selector)) {
            $stmt = $db->prepare("SELECT * FROM users u WHERE u.id=:user_id");
            $stmt->bindParam(':user_id', $selector, PDO::PARAM_INT);
        } elseif (filter_var($selector, FILTER_VALIDATE_EMAIL)) {
            $stmt = $db->prepare("SELECT * FROM users u WHERE u.email=:user_email");
            $stmt->bindParam(':user_email', $selector, PDO::PARAM_STR);
        } else {
            $stmt = $db->prepare("SELECT * FROM users u WHERE u.username=:user_name");
            $stmt->bindParam(':user_name', $selector, PDO::PARAM_STR);
        }
 
        $stmt->execute();

        $ret = $stmt->fetch(PDO::FETCH_ASSOC);

        // If user not found and $createIfMissing is true, create a new entry
        if (!$ret) {
            $stmt = $db->prepare("
                INSERT INTO users (username, password, email)
                VALUES (:email, '!', :email)
            ");
            $stmt->bindParam(':email', $selector, PDO::PARAM_STR);
            $stmt->execute();
            $userId = $db->lastInsertId();

            return self::getUserInfo($db,$userId,false);
        }

        return $ret;
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

    public static function getContributorGroupId($db, $directoryPath, $accessRights) {
        // Step 1: Get directory ID
        $stmt = $db->prepare("SELECT d.id
                              FROM directories d
                              WHERE d.path = :directory_path");
        $stmt->bindParam(':directory_path', $directoryPath, PDO::PARAM_STR);
        $stmt->execute();
        $directoryInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$directoryInfo) return false;  // Directory does not exist
        $directoryId = $directoryInfo['id'];

        // Step 2: Check if a contributor group exists for the directory with matching access rights
        $queryConditions = [];
        if (in_array('read', $accessRights)) {
            $queryConditions[] = 'ar.can_view = TRUE';
        }
        if (in_array('write', $accessRights)) {
            $queryConditions[] = 'ar.can_write = TRUE';
        }
        if (in_array('upload', $accessRights)) {
            $queryConditions[] = 'ar.can_upload = TRUE';
        }

        $queryConditionString = implode(' AND ', $queryConditions);
        $stmt = $db->prepare("SELECT g.id AS group_id
                              FROM groups g
                              JOIN access_rights ar ON g.id = ar.group_id
                              WHERE ar.directory_id = :directory_id
                                AND ($queryConditionString)");
        $stmt->bindParam(':directory_path', $directoryId, PDO::PARAM_INT);
        $stmt->execute();
        $groupInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($groupInfo) {
            // Contributor group already exists, return its ID
            return $groupInfo['group_id'];
        }

        // Step 3: Create a new contributor group
        $rightsAbbreviation = '';
        $rightsAbbreviation .= in_array('read', $accessRights) ? 'R' : '';
        $rightsAbbreviation .= in_array('write', $accessRights) ? 'W' : '';
        $rightsAbbreviation .= in_array('upload', $accessRights) ? 'U' : '';

        $groupName = "$rightsAbbreviation Contributors for $directoryPath";
        $groupDescription = "$rightsAbbreviation Contributor group for $directoryPath with selected rights";

        $stmt = $db->prepare("INSERT INTO groups (name, description)
                              VALUES (:name, :description)");
        $stmt->bindParam(':name', $groupName, PDO::PARAM_STR);
        $stmt->bindParam(':description', $groupDescription, PDO::PARAM_STR);
        $stmt->execute();
        $groupId = $db->lastInsertId();

        // Step 4: Link the group to the directory with specified permissions
        $canView = in_array('read', $accessRights) ? 1 : 0;
        $canWrite = in_array('write', $accessRights) ? 1 : 0;
        $canUpload = in_array('upload', $accessRights) ? 1 : 0;

        $stmt = $db->prepare("INSERT INTO access_rights (group_id, directory_id, can_view, can_write, can_upload)
                              VALUES (:group_id, :directory_id, :can_view, :can_write, :can_upload)");
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindParam(':directory_id', $directoryId, PDO::PARAM_INT);
        $stmt->bindParam(':can_view', $canView, PDO::PARAM_INT);
        $stmt->bindParam(':can_write', $canWrite, PDO::PARAM_INT);
        $stmt->bindParam(':can_upload', $canUpload, PDO::PARAM_INT);
        $stmt->execute();

        // Return the newly created group's ID
        return $groupId;
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
    
    public static function checkAccess($db, $directoryPath, $userId)
    {
        // Step 1: Check if the user is a member of the admin group (group_id = 1)
        $stmt = $db->prepare("SELECT 1 FROM user_group WHERE user_id = :user_id AND group_id = 1");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $isAdmin = $stmt->fetchColumn();

        if ($isAdmin) {
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

    public static function getDirectoryInfo($db, $directoryPath, $directoryName)
    {
        $fullPath = $directoryPath . DIRECTORY_SEPARATOR . $directoryName;
        $stmt = $db->prepare("
            SELECT
                d.*
            FROM
                directories d
            WHERE
                d.path = :directory_name
        ");
        $stmt->bindParam(':directory_name', $fullPath, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function getDirectoryItems($db, $directoryId, $accessRights)
    {
        $directories = [];
        $files = [];

        // Fetch directories if user has view access
        if (!empty($accessRights['can_read'])) {
            $stmt = $db->prepare("
                SELECT
                    'd' AS type,
                    d.id AS id,
                    d.name AS name,
                    NULL AS size,
                    NULL AS sha256,
                    d.created_at AS created_at,
                    u.email AS created_by,
                    d.created_from AS created_from
                FROM
                    directories d
                LEFT JOIN
                    users u ON d.created_by = u.id
                WHERE
                    d.parent_id = :directory_id
            ");
            $stmt->bindParam(':directory_id', $directoryId, PDO::PARAM_INT);
            $stmt->execute();
            $directories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fetch files if user has upload or view access
        if (!empty($accessRights['can_upload']) || !empty($accessRights['can_read'])) {
            $stmt = $db->prepare("
                SELECT
                    'f' AS type,
                    f.id AS id,
                    f.name AS name,
                    f.size AS size,
                    f.sha256 AS sha256,
                    f.uploaded_at AS created_at,
                    u.email AS created_by,
                    f.uploaded_from AS created_from
                FROM
                    files f
                JOIN
                    users u ON f.uploaded_by = u.id
                WHERE
                    f.directory_id = :directory_id
            ");
            $stmt->bindParam(':directory_id', $directoryId, PDO::PARAM_INT);
            $stmt->execute();
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