<?php
// Shared session / authorization helpers. Include this instead of calling session_start() directly.

const SESSION_IDLE_TIMEOUT = 8 * 60 * 60; // 8 hours
const ROLES = ['admin', 'staff'];

function startSecureSession() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();

    if (isset($_SESSION['user_id'])) {
        $last = $_SESSION['last_activity'] ?? time();
        if (time() - $last > SESSION_IDLE_TIMEOUT) {
            $_SESSION = [];
            session_destroy();
            session_start();
        } else {
            $_SESSION['last_activity'] = time();
        }
    }
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function isAdmin() {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function jsonFail($httpCode, $message) {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message, 'message' => $message]);
    exit;
}

// For JSON API endpoints: require login, and a valid CSRF token on any state-changing request.
function requireLogin() {
    startSecureSession();
    if (!isset($_SESSION['user_id'])) {
        jsonFail(401, 'กรุณาเข้าสู่ระบบใหม่ (Unauthorized)');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
        if (!is_string($sent) || !hash_equals(csrfToken(), $sent)) {
            jsonFail(403, 'โทเค็นความปลอดภัยไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บ');
        }
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
    }
}

function loginUser($user) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['ID'];
    $_SESSION['username'] = $user['Username'];
    $_SESSION['role'] = $user['Role'];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['csrf_token']);
}

function validatePassword($password) {
    if (!is_string($password) || mb_strlen($password) < 8) {
        return 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    }
    return null;
}
