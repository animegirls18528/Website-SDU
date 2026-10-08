<?php
require_once '../config/auth.php';
startSecureSession();
require_once '../config/database.php';
require_once '../config/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? '';
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT ID, Username, Password, Role, failed_attempts, locked_until FROM users WHERE Username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            echo json_encode(['success' => false, 'message' => 'บัญชีของคุณถูกล็อกชั่วคราวเนื่องจากใส่รหัสผ่านผิดหลายครั้ง กรุณาลองใหม่ในอีก 15 นาที']);
            exit;
        }

        if (password_verify($password, $user['Password'])) {
            $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE ID = ?")->execute([$user['ID']]);
            loginUser($user);
            addAuditLog($pdo, $user['ID'], 'Login', 'เข้าสู่ระบบ');
            echo json_encode(['success' => true]);
        } else {
            $attempts = $user['failed_attempts'] + 1;
            $locked = NULL;
            $msg = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
            
            if ($attempts >= 5) {
                $locked = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                $msg = 'ใส่รหัสผ่านผิดครบ 5 ครั้ง บัญชีถูกล็อก 15 นาที';
            }
            
            $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE ID = ?")->execute([$attempts, $locked, $user['ID']]);
            echo json_encode(['success' => false, 'message' => $msg]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง']);
    }
} catch (Exception $e) {
    error_log('Login error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดของระบบ']);
}
?>
