<?php
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/helpers.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? ($_GET['action'] ?? '');
$me = (int)$_SESSION['user_id'];

// Any logged-in user may change their own password; everything else is admin-only.
if ($action !== 'changeOwnPassword' && !isAdmin()) {
    jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
}

function adminCount($pdo) {
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE Role = 'admin'")->fetchColumn();
}

function findUser($pdo, $id) {
    $stmt = $pdo->prepare("SELECT ID, Username, Role FROM users WHERE ID = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) jsonFail(404, 'ไม่พบผู้ใช้งาน');
    return $user;
}

try {
    switch ($action) {
        case 'list':
            $users = $pdo->query("SELECT ID, Username, Role, locked_until, CreatedAt FROM users ORDER BY ID")->fetchAll();
            foreach ($users as &$u) {
                $u['ID'] = (int)$u['ID'];
                $u['Locked'] = $u['locked_until'] && strtotime($u['locked_until']) > time();
                unset($u['locked_until']);
            }
            echo json_encode(['success' => true, 'users' => $users, 'me' => $me]);
            break;

        case 'create':
            $username = trim($input['Username'] ?? '');
            $password = $input['Password'] ?? '';
            $role = $input['Role'] ?? 'staff';
            if (!preg_match('/^[A-Za-z0-9._@-]{3,50}$/', $username)) {
                jsonFail(400, 'ชื่อผู้ใช้ต้องยาว 3-50 ตัว ใช้ได้เฉพาะ a-z, 0-9 และ . _ @ -');
            }
            if ($err = validatePassword($password)) jsonFail(400, $err);
            if (!in_array($role, ROLES, true)) jsonFail(400, 'สิทธิ์ไม่ถูกต้อง');

            $stmt = $pdo->prepare("SELECT 1 FROM users WHERE Username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) jsonFail(409, 'ชื่อผู้ใช้นี้มีอยู่แล้ว');

            $stmt = $pdo->prepare("INSERT INTO users (Username, Password, Role) VALUES (?, ?, ?)");
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
            addAuditLog($pdo, $me, 'Create User', "Created user: $username ($role)");
            echo json_encode(['success' => true]);
            break;

        case 'updateRole':
            $user = findUser($pdo, (int)($input['ID'] ?? 0));
            $role = $input['Role'] ?? '';
            if (!in_array($role, ROLES, true)) jsonFail(400, 'สิทธิ์ไม่ถูกต้อง');
            if ($user['Role'] === 'admin' && $role !== 'admin' && adminCount($pdo) <= 1) {
                jsonFail(400, 'ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน');
            }
            $pdo->prepare("UPDATE users SET Role = ? WHERE ID = ?")->execute([$role, $user['ID']]);
            if ((int)$user['ID'] === $me) $_SESSION['role'] = $role;
            addAuditLog($pdo, $me, 'Update User', "Changed role of {$user['Username']} to $role");
            echo json_encode(['success' => true]);
            break;

        case 'resetPassword':
            $user = findUser($pdo, (int)($input['ID'] ?? 0));
            $password = $input['Password'] ?? '';
            if ($err = validatePassword($password)) jsonFail(400, $err);
            $pdo->prepare("UPDATE users SET Password = ?, failed_attempts = 0, locked_until = NULL WHERE ID = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['ID']]);
            addAuditLog($pdo, $me, 'Reset Password', "Reset password for {$user['Username']}");
            echo json_encode(['success' => true]);
            break;

        case 'unlock':
            $user = findUser($pdo, (int)($input['ID'] ?? 0));
            $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE ID = ?")->execute([$user['ID']]);
            addAuditLog($pdo, $me, 'Unlock User', "Unlocked {$user['Username']}");
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $user = findUser($pdo, (int)($input['ID'] ?? 0));
            if ((int)$user['ID'] === $me) jsonFail(400, 'ไม่สามารถลบบัญชีของตัวเองได้');
            if ($user['Role'] === 'admin' && adminCount($pdo) <= 1) jsonFail(400, 'ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน');
            $pdo->prepare("DELETE FROM users WHERE ID = ?")->execute([$user['ID']]);
            addAuditLog($pdo, $me, 'Delete User', "Deleted user: {$user['Username']}");
            echo json_encode(['success' => true]);
            break;

        case 'changeOwnPassword':
            $stmt = $pdo->prepare("SELECT Password FROM users WHERE ID = ?");
            $stmt->execute([$me]);
            $hash = $stmt->fetchColumn();
            if (!$hash || !password_verify($input['CurrentPassword'] ?? '', $hash)) {
                jsonFail(400, 'รหัสผ่านปัจจุบันไม่ถูกต้อง');
            }
            $password = $input['NewPassword'] ?? '';
            if ($err = validatePassword($password)) jsonFail(400, $err);
            $pdo->prepare("UPDATE users SET Password = ? WHERE ID = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $me]);
            addAuditLog($pdo, $me, 'Change Password', 'Changed own password');
            echo json_encode(['success' => true]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('users.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
