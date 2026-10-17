<?php
// Admin: file integrity checks, restore tests and the off-site backup location.
require_once '../config/auth.php';
requireAdmin();
require_once '../config/database.php';
require_once '../config/helpers.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$me = (int)$_SESSION['user_id'];

function lastRun($pdo, $kind) {
    $stmt = $pdo->prepare("SELECT r.*, u.Username AS TriggeredByName FROM integrity_runs r LEFT JOIN users u ON u.ID = r.TriggeredBy
                           WHERE r.Kind = ? ORDER BY r.ID DESC LIMIT 1");
    $stmt->execute([$kind]);
    $r = $stmt->fetch();
    if ($r) $r['Details'] = json_decode((string)$r['Details'], true) ?: [];
    return $r ?: null;
}

function isRunning($pdo, $kind) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM integrity_runs WHERE Kind = ? AND State = 'running' AND StartedAt > DATE_SUB(NOW(), INTERVAL 2 HOUR)");
    $stmt->execute([$kind]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    switch ($in['action'] ?? '') {
        case 'status':
            $offsite = getSetting($pdo, 'offsite_backup_dir', '');
            echo json_encode(['success' => true,
                'integrity' => lastRun($pdo, 'integrity'),
                'restore' => lastRun($pdo, 'restore'),
                'offsite' => ['path' => $offsite, 'reachable' => $offsite !== '' && is_dir($offsite) && is_writable($offsite)],
            ]);
            break;

        case 'runIntegrity':
            if (isRunning($pdo, 'integrity')) jsonFail(409, 'กำลังตรวจอยู่ กรุณารอให้เสร็จก่อน');
            spawnBackground('integrity_check.php', [$me]);
            echo json_encode(['success' => true]);
            break;

        case 'runRestoreTest':
            if (isRunning($pdo, 'restore')) jsonFail(409, 'กำลังทดลองกู้คืนอยู่ กรุณารอให้เสร็จก่อน');
            spawnBackground('restore_test.php', [$me]);
            echo json_encode(['success' => true]);
            break;

        case 'saveOffsite':
            // e.g. \\NAS01\edms-backup or E:\edms-backup — checked by writing a test file
            $path = rtrim(trim((string)($in['path'] ?? '')), '\\/');
            if ($path !== '') {
                if (!is_dir($path)) jsonFail(400, "ไม่พบโฟลเดอร์ $path (ตรวจว่าเชื่อมต่อ NAS/ฮาร์ดดิสก์ และ Apache มีสิทธิ์เข้าถึง)");
                $probe = $path . DIRECTORY_SEPARATOR . '.edms_write_test';
                if (@file_put_contents($probe, 'ok') === false) jsonFail(400, "เขียนไฟล์ลงใน $path ไม่ได้");
                @unlink($probe);
                $main = realpath(BACKUP_DIR);
                if ($main && realpath($path) && stripos(realpath($path), $main) === 0) jsonFail(400, 'ต้องเป็นคนละที่กับที่เก็บสำรองหลัก');
            }
            setSetting($pdo, 'offsite_backup_dir', $path);
            addAuditLog($pdo, $me, 'Update Settings', $path === '' ? 'ปิดการสำรองไปที่เก็บภายนอก' : "ตั้งที่เก็บสำรองภายนอก: $path");
            echo json_encode(['success' => true]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('integrity.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
