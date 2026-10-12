<?php
/**
 * Scanner API Endpoint
 * --------------------
 * สั่งสแกนเอกสารจากเครื่อง Canon DR-G2110 ผ่าน NAPS2 Console CLI
 * งานสแกนเข้าคิวและทำงานเบื้องหลัง (tools/scan_worker.php) หน้าเว็บจึงไม่ค้างระหว่างสแกน
 *
 * Actions:
 *   - checkStatus     : เช็คสถานะ NAPS2 / scanner / คิว
 *   - listDevices     : แสดงรายชื่อเครื่องสแกนที่พบ (TWAIN, WIA, eSCL เครือข่าย)
 *   - start           : สร้างงานสแกนใหม่ในคิว
 *   - jobStatus       : สถานะงานสแกน (เมื่อเสร็จจะได้รายการไฟล์)
 *   - cancel          : ยกเลิกงานที่ยังรอคิว
 *   - getScanSettings : ค่าเริ่มต้นและตัวเลือก
 */

require_once '../config/auth.php';
requireLogin();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../config/helpers.php';
require_once '../config/storage.php';
require_once '../config/scanner.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';
$me = (int)$_SESSION['user_id'];

if ($action !== 'checkStatus' && $action !== 'getScanSettings' && !canEdit()) {
    jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
}

/** Mark jobs whose worker died as failed so the queue never sticks. */
function failStaleJobs($pdo) {
    $pdo->prepare("UPDATE scan_jobs SET Status = 'failed', Message = 'งานสแกนค้าง ระบบยกเลิกให้อัตโนมัติ', FinishedAt = NOW()
                   WHERE (Status = 'running' AND StartedAt < DATE_SUB(NOW(), INTERVAL ? SECOND))
                      OR (Status = 'queued' AND CreatedAt < DATE_SUB(NOW(), INTERVAL ? SECOND))")
        ->execute([SCAN_TIMEOUT + 120, SCAN_TIMEOUT * 3 + 300]);
}

function listDevices($driver) {
    $out = [];
    exec('"' . NAPS2_CONSOLE_PATH . '" --listdevices --driver ' . escapeshellarg($driver) . ' 2>&1', $out);
    return array_values(array_filter(array_map('trim', $out), 'strlen'));
}

switch ($action) {

    case 'checkStatus':
        failStaleJobs($pdo);
        $naps2Exists = file_exists(NAPS2_CONSOLE_PATH);
        $result = [
            'success' => true,
            'naps2Installed' => $naps2Exists,
            'scannerDevice' => SCANNER_DEVICE_NAME,
            'defaults' => json_decode(SCANNER_DEFAULTS, true),
            'queue' => (int)$pdo->query("SELECT COUNT(*) FROM scan_jobs WHERE Status IN ('queued','running')")->fetchColumn(),
        ];
        if ($naps2Exists) {
            $result['detectedDevices'] = listDevices('twain');
            $result['scannerReady'] = !empty($result['detectedDevices']);
        }
        echo json_encode($result);
        break;

    case 'listDevices':
        if (!file_exists(NAPS2_CONSOLE_PATH)) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบ NAPS2 Console กรุณาติดตั้งก่อน']);
            break;
        }
        $all = [];
        foreach (['twain', 'wia', 'escl'] as $driver) {
            foreach (listDevices($driver) as $name) $all[] = ['name' => $name, 'driver' => $driver];
        }
        echo json_encode(['success' => true, 'devices' => $all]);
        break;

    case 'start':
        if (!file_exists(NAPS2_CONSOLE_PATH)) {
            jsonFail(400, 'ไม่พบ NAPS2 Console กรุณาติดตั้ง NAPS2 จาก https://www.naps2.com/download');
        }
        failStaleJobs($pdo);
        $d = json_decode(SCANNER_DEFAULTS, true);
        $pick = fn($key, array $allowed) => in_array($input[$key] ?? null, $allowed, true) ? $input[$key] : $d[$key];
        $device = trim((string)($input['device'] ?? '')) ?: SCANNER_DEVICE_NAME;
        $options = [
            'driver'      => $pick('driver', ['twain', 'wia', 'escl']),
            'device'      => mb_substr($device, 0, 200),
            'dpi'         => in_array((int)($input['dpi'] ?? 0), [150, 200, 300, 400, 600], true) ? (int)$input['dpi'] : $d['dpi'],
            'colorMode'   => $pick('colorMode', ['color', 'gray', 'bw']),
            'source'      => $pick('source', ['feeder', 'glass', 'duplex']),
            'format'      => $pick('format', ['pdf', 'png', 'jpg', 'tiff']),
            'pageSize'    => $pick('pageSize', ['a4', 'letter', 'legal', 'a3']),
            'deskew'      => (bool)($input['deskew'] ?? $d['deskew']),
            'skipBlank'   => (bool)($input['skipBlank'] ?? $d['skipBlank']),
            'ocr'         => (bool)($input['ocr'] ?? $d['ocr']),
            'splitPatchT' => (bool)($input['splitPatchT'] ?? $d['splitPatchT']),
            'pdfa'        => (bool)($input['pdfa'] ?? $d['pdfa']),
        ];
        $pdo->prepare("INSERT INTO scan_jobs (UserID, Options) VALUES (?, ?)")->execute([$me, json_encode($options)]);
        $jobId = (int)$pdo->lastInsertId();
        $ahead = (int)$pdo->query("SELECT COUNT(*) FROM scan_jobs WHERE Status IN ('queued','running') AND ID < $jobId")->fetchColumn();

        addAuditLog($pdo, $me, "Scan Document", sprintf("Job #%d: %s, %d DPI, %s, %s%s%s", $jobId, $options['device'], $options['dpi'],
            $options['colorMode'], $options['source'], $options['ocr'] ? ', OCR' : '', $options['splitPatchT'] ? ', Patch-T split' : ''));
        spawnBackground('scan_worker.php', [$jobId]);
        echo json_encode(['success' => true, 'jobId' => $jobId, 'ahead' => $ahead]);
        break;

    case 'jobStatus':
        $stmt = $pdo->prepare("SELECT ID, UserID, Status, Options, Files, Message, CreatedAt, StartedAt, FinishedAt FROM scan_jobs WHERE ID = ?");
        $stmt->execute([(int)($input['jobId'] ?? 0)]);
        $job = $stmt->fetch();
        if (!$job || ((int)$job['UserID'] !== $me && !isAdmin())) jsonFail(404, 'ไม่พบงานสแกน');

        $out = ['success' => true, 'status' => $job['Status'], 'message' => $job['Message']];
        if ($job['Status'] === 'queued') {
            $out['ahead'] = (int)$pdo->query("SELECT COUNT(*) FROM scan_jobs WHERE Status IN ('queued','running') AND ID < {$job['ID']}")->fetchColumn();
        }
        if ($job['Status'] === 'done') {
            $opts = json_decode($job['Options'], true);
            $out['files'] = [];
            foreach (json_decode((string)$job['Files'], true) ?: [] as $i => $rel) {
                if (!is_file(absPath($rel))) continue;
                // Hand the scanned files to this user's session so they can be attached to a document
                if (!isStaged($rel)) {
                    stageFile($rel, 'scan');
                    $_SESSION['staged_names'][$rel] = 'scan_' . date('Ymd_His', strtotime($job['FinishedAt'])) . ($i ? "_$i" : '') . '.' . $opts['format'];
                }
                $out['files'][] = [
                    'fileId' => 'uploads/' . $rel,
                    'name' => $_SESSION['staged_names'][$rel],
                    'size' => filesize(absPath($rel)),
                    'format' => $opts['format'],
                    'dpi' => $opts['dpi'],
                    'colorMode' => $opts['colorMode'],
                ];
            }
            $out['split'] = (bool)$opts['splitPatchT'];
        }
        echo json_encode($out);
        break;

    case 'resume':
        // After a page reload: the job still running for this user, and scanned files not yet saved into a document
        failStaleJobs($pdo);
        $stmt = $pdo->prepare("SELECT ID FROM scan_jobs WHERE UserID = ? AND Status IN ('queued','running') ORDER BY ID DESC LIMIT 1");
        $stmt->execute([$me]);
        $active = $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT Files, Options, FinishedAt FROM scan_jobs WHERE UserID = ? AND Status = 'done'
                               AND FinishedAt > DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY ID");
        $stmt->execute([$me]);
        $attached = $pdo->prepare("SELECT 1 FROM document_files WHERE StoredPath = ? OR OriginalPath = ?");
        foreach ($stmt->fetchAll() as $job) {
            foreach (json_decode((string)$job['Files'], true) ?: [] as $i => $rel) {
                if (isStaged($rel) || !is_file(absPath($rel))) continue;
                $attached->execute([$rel, $rel]);
                if ($attached->fetchColumn()) continue;
                stageFile($rel, 'scan');
                $_SESSION['staged_names'][$rel] = 'scan_' . date('Ymd_His', strtotime($job['FinishedAt'])) . ($i ? "_$i" : '') . '.' . pathinfo($rel, PATHINFO_EXTENSION);
            }
        }
        echo json_encode(['success' => true, 'activeJobId' => $active ? (int)$active : null, 'files' => stagedFilesFor('scan')]);
        break;

    case 'cancel':
        $stmt = $pdo->prepare("UPDATE scan_jobs SET Status = 'failed', Message = 'ยกเลิกโดยผู้ใช้', FinishedAt = NOW()
                               WHERE ID = ? AND Status = 'queued' AND (UserID = ? OR ?)");
        $stmt->execute([(int)($input['jobId'] ?? 0), $me, isAdmin() ? 1 : 0]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => $stmt->rowCount() ? 'ยกเลิกแล้ว' : 'งานเริ่มสแกนไปแล้ว ยกเลิกไม่ได้']);
        break;

    case 'getScanSettings':
        echo json_encode([
            'success' => true,
            'defaults' => json_decode(SCANNER_DEFAULTS, true),
            'deviceName' => SCANNER_DEVICE_NAME,
        ]);
        break;

    default:
        jsonFail(400, 'Invalid action');
}
