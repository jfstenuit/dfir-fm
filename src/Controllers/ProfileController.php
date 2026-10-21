<?php
// src/Controllers/ProfileController.php
namespace Controllers;

use Core\App;
use Core\PasswordPolicy;
use Core\Session;
use Core\Request;
use Core\Response;
use PDO;

class ProfileController
{
    public static function handle($config, $db)
    {
        header('Content-Type: application/json');

        if (!Session::isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authentication required.']);
            return;
        }

        if (!isset($_POST['a'])) {
            echo json_encode(['success' => false, 'message' => 'Missing action.']);
            return;
        }

        $action = isset($_POST['a']) ? trim($_POST['a']) ?? '' : '';
        switch ($action) {
            case 'updatePassword':
                $userId=Session::getUserId();
                self::updatePassword($db, $userId, $_POST);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action.']);
                break;
        }
    }

    private static function updatePassword(PDO $db, $userId, array $request)
    {
        // No previous password asked (D24): the session is proof enough, and an
        // invitee who set theirs through an invitation link may not remember it
        $problem = PasswordPolicy::problem($request['password'] ?? null);
        if ($problem !== null) {
            echo json_encode(['success' => false, 'message' => $problem]);
            return;
        }

        $newPasswordHash = password_hash($request['password'], PASSWORD_DEFAULT);

        // Ends the account's other sessions; this one is kept
        $stmt = $db->prepare("UPDATE users SET password = :password, session_version = session_version + 1 WHERE id = :id");
        $success = $stmt->execute([
            ':password' => $newPasswordHash,
            ':id' => $userId
        ]);

        if ($success) {
            $stmt = $db->prepare("SELECT session_version FROM users WHERE id = :id");
            $stmt->execute([':id' => $userId]);
            Session::setVersion((int)$stmt->fetchColumn());
            App::getLogger()->log('password_changed', ['via' => 'profile']);

            echo json_encode(['success' => true, 'message' => 'Password updated.']);
        } else {
            error_log("Failed to update password for user ID $userId");
            echo json_encode(['success' => false, 'message' => 'Failed to update password.']);
        }
    }
}
?>