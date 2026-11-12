<?php
/**
 * Runs one scan job in the background: php tools/scan_worker.php <jobId>
 * Jobs are serialized with a file lock, so a second job waits in the queue until the scanner is free.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/storage.php';
require_once __DIR__ . '/../config/scanner.php';
require_once __DIR__ . '/../config/scanner_detect.php';

$jobId = (int)($argv[1] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM scan_jobs WHERE ID = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch();
if (!$job || $job['Status'] !== 'queued') exit;

function finishJob($pdo, $jobId, $status, $message, $files = []) {
    $pdo->prepare("UPDATE scan_jobs SET Status = ?, Message = ?, Files = ?, FinishedAt = NOW() WHERE ID = ?")
        ->execute([$status, $message, json_encode(array_values($files)), $jobId]);
}

// One scan at a time: wait here while another job holds the scanner
$lock = fopen(uploadsDir() . DIRECTORY_SEPARATOR . '.scan.lock', 'c');
flock($lock, LOCK_EX);

// The job may have been cancelled while waiting for the scanner
$stmt = $pdo->prepare("UPDATE scan_jobs SET Status = 'running', StartedAt = NOW() WHERE ID = ? AND Status = 'queued'");
$stmt->execute([$jobId]);
if (!$stmt->rowCount()) {
    flock($lock, LOCK_UN);
    exit;
}
$o = json_decode($job['Options'], true);

if (!is_file(NAPS2_CONSOLE_PATH)) {
    finishJob($pdo, $jobId, 'failed', 'ไม่พบ NAPS2 Console กรุณาติดตั้ง NAPS2 จาก https://www.naps2.com/download');
    exit;
}

// Output name; a $(nnnn) counter is needed whenever NAPS2 may write more than one file
$multiFile = $o['splitPatchT'] || $o['format'] !== 'pdf' && $o['format'] !== 'tiff';
$base = newStoragePath('scan', $o['format']);
$outRel = $multiFile ? preg_replace('#\.(\w+)$#', '_$(nnnn).$1', $base) : $base;
$outAbs = absPath($outRel);

$cmd = [NAPS2_CONSOLE_PATH];
if ($o['driver'] === 'twain' && NAPS2_TWAIN_PROFILE !== '' && strcasecmp($o['device'], SCANNER_DEVICE_NAME) === 0) {
    array_push($cmd, '--profile', NAPS2_TWAIN_PROFILE);
} else {
    $cmd[] = '--noprofile';
}
$cmd = array_merge($cmd, [
    '-o', $outAbs,
    '--driver', $o['driver'],
    '--device', $o['device'],
    '--source', $o['source'],
    '--pagesize', $o['pageSize'],
    '--dpi', (string)$o['dpi'],
    '--bitdepth', $o['colorMode'],
    '--force',
]);
if ($o['deskew']) $cmd[] = '--deskew';
// NAPS2 Console has no --excludeblank option; blank-page removal requires a GUI profile.
if ($o['ocr'] && $o['format'] === 'pdf') { array_push($cmd, '--enableocr', '--ocrlang', SCAN_OCR_LANG); }
else $cmd[] = '--disableocr'; // Do not inherit OCR from a local profile.
if ($o['splitPatchT']) $cmd[] = '--splitpatcht';
if ($o['pdfa'] && $o['format'] === 'pdf') { array_push($cmd, '--pdfcompat', 'A2-b'); }
if (!empty($o['jpegQuality'])) { array_push($cmd, '--jpegquality', (string)$o['jpegQuality']); }

$outLog = tempnam(sys_get_temp_dir(), 'scan_o');
$errLog = tempnam(sys_get_temp_dir(), 'scan_e');
if ($outLog === false || $errLog === false) {
    if ($outLog !== false) @unlink($outLog);
    if ($errLog !== false) @unlink($errLog);
    finishJob($pdo, $jobId, 'failed', 'สร้างไฟล์ชั่วคราวสำหรับสแกนไม่ได้');
    exit;
}
// PHP on Windows cannot reliably make anonymous pipes nonblocking. Use files so
// a quiet driver cannot block fread() and prevent the timeout from being checked.
$process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $outLog, 'w'], 2 => ['file', $errLog, 'w']], $pipes);
if (!is_resource($process)) {
    @unlink($outLog);
    @unlink($errLog);
    finishJob($pdo, $jobId, 'failed', 'ไม่สามารถเรียก NAPS2 Console ได้ - กรุณาตรวจสอบ path ของ NAPS2');
    exit;
}
fclose($pipes[0]);

$stdout = $stderr = '';
$start = time();
$timedOut = false;
while (true) {
    $status = proc_get_status($process);
    if (!$status['running']) break;
    if (time() - $start > SCAN_TIMEOUT) {
        killProcessTree($process);
        $timedOut = true;
        break;
    }
    usleep(200000);
}
$exit = proc_close($process);
if (isset($status['exitcode']) && $status['exitcode'] !== -1) $exit = $status['exitcode'];
$stdout = (string)file_get_contents($outLog);
$stderr = (string)file_get_contents($errLog);
@unlink($outLog);
@unlink($errLog);

// Collect produced files
$dir = dirname($outAbs);
$stem = pathinfo($base, PATHINFO_FILENAME);
$files = [];
foreach (glob($dir . DIRECTORY_SEPARATOR . $stem . '*') ?: [] as $f) {
    $rel = dirname($base) . '/' . basename($f);
    if (normalizeFileRef($rel) && filesize($f) > 0) $files[] = $rel;
}
sort($files);

if ($timedOut) {
    foreach ($files as $f) @unlink(absPath($f));
    finishJob($pdo, $jobId, 'failed', 'หมดเวลาสแกน (' . SCAN_TIMEOUT . ' วินาที) - กรุณาตรวจสอบเครื่องสแกน');
} elseif ($files) {
    $size = array_sum(array_map(fn($f) => filesize(absPath($f)), $files));
    finishJob($pdo, $jobId, 'done', 'สแกนเอกสารสำเร็จ', $files);
    addAuditLog($pdo, $job['UserID'], 'Scan Complete', sprintf('Job #%d: %d file(s), %.1f KB', $jobId, count($files), $size / 1024));
} else {
    $err = trim($stderr ?: $stdout);
    if ($err === '') $err = "สแกนไม่สำเร็จ (Exit Code: $exit)";
    if (stripos($err, 'no device') !== false || stripos($err, 'not found') !== false) {
        $err = "ไม่พบเครื่องสแกน \"{$o['device']}\" - กรุณาตรวจสอบการเชื่อมต่อ USB และเปิดเครื่องสแกน";
    } elseif (stripos($err, 'paper') !== false || stripos($err, 'empty') !== false || stripos($err, 'no pages') !== false) {
        $err = 'ไม่มีกระดาษในถาดป้อนเอกสาร - กรุณาใส่กระดาษแล้วลองใหม่';
    } elseif (stripos($err, 'busy') !== false || stripos($err, 'locked') !== false) {
        $err = 'เครื่องสแกนกำลังถูกใช้งานโดยโปรแกรมอื่น - กรุณาปิดโปรแกรมนั้นแล้วลองใหม่';
    } elseif (stripos($err, 'jam') !== false) {
        $err = 'กระดาษติด - กรุณาเปิดเครื่องสแกนและนำกระดาษที่ติดออก';
    }
    error_log("Scan job $jobId failed (exit $exit): $stderr $stdout");
    finishJob($pdo, $jobId, 'failed', $err);
    addAuditLog($pdo, $job['UserID'], 'Scan Failed', "Job #$jobId: $err");
}

flock($lock, LOCK_UN);
fclose($lock);
