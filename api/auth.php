<?php
// Login: password, then (when enabled) a 6-digit authenticator code or a one-time recovery code.
require_once '../config/auth.php';
startSecureSession();
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once '../config/totp.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    // ---- step 2: second factor ----
    if (($input['action'] ?? '') === 'verify2fa') {
        $pending = $_SESSION['pending_2fa'] ?? null;
        if (!$pending || $pending['time'] < time() - 300) {
            unset($_SESSION['pending_2fa']);
            echo json_encode(['success' => false, 'restart' => true, 'message' => 'หมดเวลายืนยัน กรุณาเข้าสู่ระบบใหม่']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT ID, Username, Role, TotpSecret, RecoveryCodes FROM users WHERE ID = ?");
        $stmt->execute([$pending['id']]);
        $user = $stmt->fetch();
        $code = trim((string)($input['code'] ?? ''));
        $ok = $user && totpVerify($user['TotpSecret'], $code);
        $usedRecovery = false;
        if ($user && !$ok && preg_match('/^[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}$/', $code)) {
            // One-time recovery code: remove it once used
            $hashes = json_decode((string)$user['RecoveryCodes'], true) ?: [];
            foreach ($hashes as $i => $hash) {
                if (password_verify(strtoupper($code), $hash)) {
                    unset($hashes[$i]);
                    $pdo->prepare("UPDATE users SET RecoveryCodes = ? WHERE ID = ?")->execute([json_encode(array_values($hashes)), $user['ID']]);
                    $ok = $usedRecovery = true;
                    break;
                }
            }
        }
        if (!$ok) {
            $_SESSION['pending_2fa']['tries'] = ($pending['tries'] ?? 0) + 1;
            if ($_SESSION['pending_2fa']['tries'] >= 5) {
                unset($_SESSION['pending_2fa']);
                addAuditLog($pdo, $pending['id'], 'Login Failed', 'ใส่รหัสยืนยันสองขั้นผิด 5 ครั้ง');
                echo json_encode(['success' => false, 'restart' => true, 'message' => 'ใส่รหัสผิดหลายครั้ง กรุณาเข้าสู่ระบบใหม่']);
                exit;
            }
            echo json_encode(['success' => false, 'message' => 'รหัสยืนยันไม่ถูกต้อง']);
            exit;
        }
        unset($_SESSION['pending_2fa']);
        loginUser($user);
        addAuditLog($pdo, $user['ID'], 'Login', $usedRecovery ? 'เข้าสู่ระบบด้วยรหัสกู้คืน' : 'เข้าสู่ระบบ (ยืนยันสองขั้น)');
        echo json_encode(['success' => true]);
        exit;
    }

    // ---- step 1: username + password ----
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT ID, Username, Password, Role, failed_attempts, locked_until, TotpEnabled FROM users WHERE Username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            echo json_encode(['success' => false, 'message' => 'บัญชีของคุณถูกล็อกชั่วคราวเนื่องจากใส่รหัสผ่านผิดหลายครั้ง กรุณาลองใหม่ในอีก 15 นาที']);
            exit;
        }

        if (password_verify($password, $user['Password'])) {
            $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE ID = ?")->execute([$user['ID']]);
            if ((int)$user['TotpEnabled'] === 1) {
                session_regenerate_id(true);
                $_SESSION['pending_2fa'] = ['id' => (int)$user['ID'], 'time' => time(), 'tries' => 0];
                echo json_encode(['success' => false, 'need2fa' => true, 'message' => '']);
                exit;
            }
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
