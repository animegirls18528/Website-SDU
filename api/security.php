<?php
// Account security: two-factor authentication, active logins (sessions), and tokens for scan agents.
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once '../config/totp.php';
require_once '../vendor/autoload.php';
header('Content-Type: application/json');

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$me = (int)$_SESSION['user_id'];

function userRow($pdo, $id) {
    $stmt = $pdo->prepare("SELECT ID, Username, Password, Role, TotpEnabled, TotpSecret, RecoveryCodes FROM users WHERE ID = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/** Short device description from a user-agent string. */
function deviceLabel($ua) {
    $os = preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/iPhone|iPad/i', $ua) ? 'iOS' : (preg_match('/Mac OS/i', $ua) ? 'macOS' : (preg_match('/Linux/i', $ua) ? 'Linux' : 'อุปกรณ์อื่น'))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'เบราว์เซอร์')));
    return "$br · $os";
}

try {
    switch ($in['action'] ?? '') {
        // ---------- two-factor ----------
        case 'status':
            $u = userRow($pdo, $me);
            echo json_encode(['success' => true, 'totpEnabled' => (bool)$u['TotpEnabled'],
                              'recoveryLeft' => count(json_decode((string)$u['RecoveryCodes'], true) ?: []),
                              'requireAdmin2fa' => getSetting($pdo, 'require_admin_2fa', '0') === '1']);
            break;

        case 'totpStart':
            $u = userRow($pdo, $me);
            if ($u['TotpEnabled']) jsonFail(400, 'เปิดใช้งานอยู่แล้ว');
            $secret = totpGenerateSecret();
            $_SESSION['totp_setup'] = ['secret' => $secret, 'time' => time()];
            $uri = totpUri($secret, $u['Username'], 'EDMS ' . getSetting($pdo, 'org_name', ''));
            $qr = (new QRCode(new QROptions(['outputType' => QRCode::OUTPUT_IMAGE_PNG, 'eccLevel' => QRCode::ECC_M, 'scale' => 6])))->render($uri);
            echo json_encode(['success' => true, 'secret' => trim(chunk_split($secret, 4, ' ')), 'qr' => $qr]);
            break;

        case 'totpConfirm':
            $setup = $_SESSION['totp_setup'] ?? null;
            if (!$setup || $setup['time'] < time() - 900) jsonFail(400, 'หมดเวลาตั้งค่า กรุณาเริ่มใหม่');
            if (!totpVerify($setup['secret'], $in['code'] ?? '')) jsonFail(400, 'รหัสไม่ถูกต้อง ตรวจว่าเวลาในโทรศัพท์ตรงกับเวลาจริง');
            [$plain, $hashes] = totpRecoveryCodes();
            $pdo->prepare("UPDATE users SET TotpSecret = ?, TotpEnabled = 1, RecoveryCodes = ? WHERE ID = ?")
                ->execute([$setup['secret'], json_encode($hashes), $me]);
            unset($_SESSION['totp_setup']);
            addAuditLog($pdo, $me, 'Security', 'เปิดการยืนยันตัวตนสองขั้น');
            echo json_encode(['success' => true, 'recoveryCodes' => $plain]);
            break;

        case 'totpDisable':
            $u = userRow($pdo, $me);
            if (!password_verify((string)($in['password'] ?? ''), $u['Password'])) jsonFail(400, 'รหัสผ่านไม่ถูกต้อง');
            if (isAdmin() && getSetting($pdo, 'require_admin_2fa', '0') === '1') jsonFail(400, 'ระบบกำหนดให้ผู้ดูแลระบบต้องใช้การยืนยันสองขั้น');
            $pdo->prepare("UPDATE users SET TotpSecret = NULL, TotpEnabled = 0, RecoveryCodes = NULL WHERE ID = ?")->execute([$me]);
            addAuditLog($pdo, $me, 'Security', 'ปิดการยืนยันตัวตนสองขั้น');
            echo json_encode(['success' => true]);
            break;

        case 'newRecoveryCodes':
            $u = userRow($pdo, $me);
            if (!$u['TotpEnabled']) jsonFail(400, 'ยังไม่ได้เปิดการยืนยันสองขั้น');
            if (!password_verify((string)($in['password'] ?? ''), $u['Password'])) jsonFail(400, 'รหัสผ่านไม่ถูกต้อง');
            [$plain, $hashes] = totpRecoveryCodes();
            $pdo->prepare("UPDATE users SET RecoveryCodes = ? WHERE ID = ?")->execute([json_encode($hashes), $me]);
            addAuditLog($pdo, $me, 'Security', 'สร้างรหัสกู้คืนชุดใหม่');
            echo json_encode(['success' => true, 'recoveryCodes' => $plain]);
            break;

        case 'resetUser2fa':
            // An admin helps a user who lost their phone
            if (!isAdmin()) jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
            $id = (int)($in['ID'] ?? 0);
            $pdo->prepare("UPDATE users SET TotpSecret = NULL, TotpEnabled = 0, RecoveryCodes = NULL WHERE ID = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM sessions WHERE UserID = ?")->execute([$id]);
            addAuditLog($pdo, $me, 'Security', "ล้างการยืนยันสองขั้นของผู้ใช้ #$id");
            echo json_encode(['success' => true]);
            break;

        // ---------- active logins ----------
        case 'sessions':
            // Admins may list everybody's; the handle is a hash, the real session ID never leaves the server
            $all = isAdmin() && !empty($in['all']);
            $sql = "SELECT SHA2(s.ID, 256) AS Handle, s.UserID, u.Username, s.IP, s.UserAgent, s.CreatedAt, s.UpdatedAt, (s.ID = ?) AS Current
                    FROM sessions s JOIN users u ON u.ID = s.UserID WHERE s.UpdatedAt > ?" . ($all ? '' : ' AND s.UserID = ?') . " ORDER BY s.UpdatedAt DESC";
            $params = [session_id(), time() - (int)ini_get('session.gc_maxlifetime')];
            if (!$all) $params[] = $me;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['Device'] = deviceLabel((string)$r['UserAgent']);
                $r['Current'] = (bool)$r['Current'];
                $r['LastSeen'] = date('Y-m-d H:i:s', (int)$r['UpdatedAt']);
                $r['Since'] = $r['CreatedAt'] ? date('Y-m-d H:i:s', (int)$r['CreatedAt']) : null;
                unset($r['UpdatedAt'], $r['CreatedAt']);
            }
            echo json_encode(['success' => true, 'sessions' => $rows]);
            break;

        case 'revokeSession':
            $handle = preg_match('/^[a-f0-9]{64}$/', $in['handle'] ?? '') ? $in['handle'] : '';
            $stmt = $pdo->prepare("SELECT ID, UserID FROM sessions WHERE SHA2(ID, 256) = ?");
            $stmt->execute([$handle]);
            $s = $stmt->fetch();
            if (!$s || ((int)$s['UserID'] !== $me && !isAdmin())) jsonFail(404, 'ไม่พบการเข้าสู่ระบบนี้');
            if ($s['ID'] === session_id()) jsonFail(400, 'ใช้ปุ่มออกจากระบบสำหรับอุปกรณ์นี้');
            $pdo->prepare("DELETE FROM sessions WHERE ID = ?")->execute([$s['ID']]);
            addAuditLog($pdo, $me, 'Security', 'สั่งออกจากระบบอุปกรณ์อื่น' . ((int)$s['UserID'] !== $me ? " ของผู้ใช้ #{$s['UserID']}" : ''));
            echo json_encode(['success' => true]);
            break;

        case 'revokeOthers':
            $stmt = $pdo->prepare("DELETE FROM sessions WHERE UserID = ? AND ID <> ?");
            $stmt->execute([$me, session_id()]);
            addAuditLog($pdo, $me, 'Security', 'ออกจากระบบอุปกรณ์อื่นทั้งหมด (' . $stmt->rowCount() . ')');
            echo json_encode(['success' => true, 'count' => $stmt->rowCount()]);
            break;

        // ---------- scan agent tokens ----------
        case 'tokens':
            $stmt = $pdo->prepare("SELECT ID, Name, CreatedAt, LastUsedAt, RevokedAt FROM api_tokens WHERE UserID = ? ORDER BY ID DESC");
            $stmt->execute([$me]);
            echo json_encode(['success' => true, 'tokens' => $stmt->fetchAll()]);
            break;

        case 'createToken':
            if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
            $name = mb_substr(trim((string)($in['Name'] ?? '')), 0, 100);
            if ($name === '') jsonFail(400, 'กรุณาตั้งชื่อ เช่น "เครื่องสแกนห้องการเงิน"');
            $token = 'edms_' . bin2hex(random_bytes(24));
            $pdo->prepare("INSERT INTO api_tokens (UserID, Name, TokenHash) VALUES (?, ?, ?)")->execute([$me, $name, hash('sha256', $token)]);
            addAuditLog($pdo, $me, 'Security', "สร้าง token เครื่องสแกน: $name");
            echo json_encode(['success' => true, 'token' => $token]);
            break;

        case 'revokeToken':
            $pdo->prepare("UPDATE api_tokens SET RevokedAt = NOW() WHERE ID = ? AND UserID = ? AND RevokedAt IS NULL")->execute([(int)($in['ID'] ?? 0), $me]);
            addAuditLog($pdo, $me, 'Security', 'ยกเลิก token เครื่องสแกน #' . (int)($in['ID'] ?? 0));
            echo json_encode(['success' => true]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('security.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
