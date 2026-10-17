<?php
/**
 * Prove the latest backup can actually be restored, without touching the live system:
 *  1. read the whole database dump (gzip must be intact)
 *  2. import it into a throw-away database and count the rows
 *  3. open uploads.zip, check every entry, extract a sample and compare its SHA-256 with the records
 *  4. drop the throw-away database
 *   php tools/restore_test.php [userId]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
set_time_limit(0);

$userId = isset($argv[1]) && ctype_digit($argv[1]) ? (int)$argv[1] : null;
$pdo->prepare("INSERT INTO integrity_runs (Kind, State, StartedAt, TriggeredBy) VALUES ('restore', 'running', NOW(), ?)")->execute([$userId]);
$runId = (int)$pdo->lastInsertId();
$steps = [];
$ok = true;
$step = function ($name, $pass, $detail = '') use (&$steps, &$ok) {
    $steps[] = ['step' => $name, 'ok' => $pass, 'detail' => $detail];
    if (!$pass) $ok = false;
};
$testDb = 'edms_restore_test';

try {
    // Newest finished backup set
    $sets = glob(BACKUP_DIR . DIRECTORY_SEPARATOR . 'edms_*', GLOB_ONLYDIR) ?: [];
    rsort($sets);
    $set = null;
    foreach ($sets as $dir) {
        $m = json_decode((string)@file_get_contents("$dir/manifest.json"), true);
        if (($m['state'] ?? '') === 'done') { $set = $dir; break; }
    }
    if (!$set) throw new RuntimeException('ไม่พบชุดสำรองข้อมูลที่สมบูรณ์ — กรุณาสำรองข้อมูลก่อน');
    $step('เลือกชุดสำรองล่าสุด', true, basename($set));

    // 1. the dump decompresses completely
    $gz = gzopen("$set/database.sql.gz", 'rb');
    $bytes = 0;
    while (!gzeof($gz)) {
        $chunk = gzread($gz, 1 << 20);
        if ($chunk === false) throw new RuntimeException('อ่านไฟล์ฐานข้อมูลที่บีบอัดไม่ได้');
        $bytes += strlen($chunk);
    }
    gzclose($gz);
    $step('ไฟล์ฐานข้อมูลไม่เสียหาย', $bytes > 0, number_format($bytes) . ' ไบต์');

    // 2. import into a throw-away database
    $pdo->exec("DROP DATABASE IF EXISTS `$testDb`");
    $pdo->exec("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $cmd = [MYSQL_PATH, '--host=' . $host, '--user=' . $username, '--default-character-set=utf8mb4', $testDb];
    if ($password !== '') array_splice($cmd, 3, 0, '--password=' . $password);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $gz = gzopen("$set/database.sql.gz", 'rb');
    while (!gzeof($gz)) fwrite($pipes[0], gzread($gz, 1 << 20));
    gzclose($gz);
    fclose($pipes[0]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    $step('นำเข้าฐานข้อมูลทดลองสำเร็จ', $code === 0, $code === 0 ? '' : mb_substr($err, 0, 300));

    if ($code === 0) {
        $counts = [];
        foreach (['documents', 'document_files', 'users', 'folders', 'audit_logs'] as $t) {
            $restored = (int)$pdo->query("SELECT COUNT(*) FROM `$testDb`.`$t`")->fetchColumn();
            $live = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $counts[] = "$t: $restored (ปัจจุบัน $live)";
        }
        $step('ข้อมูลในชุดสำรองอ่านได้', true, implode(' · ', $counts));
    }

    // 3. the file archive
    $zip = new ZipArchive();
    $res = $zip->open("$set/uploads.zip", ZipArchive::CHECKCONS);
    if ($res !== true) {
        $step('ไฟล์เอกสารในชุดสำรองไม่เสียหาย', false, "เปิด uploads.zip ไม่ได้ (รหัส $res)");
    } else {
        $n = $zip->numFiles;
        $sample = [];
        $hashStmt = $pdo->prepare("SELECT Sha256 FROM document_files WHERE StoredPath = ? UNION SELECT OriginalSha256 FROM document_files WHERE OriginalPath = ?");
        $checked = $bad = 0;
        for ($i = 0; $i < $n && $checked < 30; $i += max(1, intdiv($n, 30))) {
            $name = $zip->getNameIndex($i);
            $hashStmt->execute([$name, $name]);
            $expected = $hashStmt->fetchColumn();
            if (!$expected) continue;
            $checked++;
            if (!hash_equals($expected, hash('sha256', (string)$zip->getFromIndex($i)))) { $bad++; $sample[] = $name; }
        }
        $zip->close();
        $step('ไฟล์เอกสารในชุดสำรองไม่เสียหาย', $bad === 0, "$n ไฟล์ · สุ่มตรวจ hash $checked ไฟล์" . ($bad ? " · ไม่ตรง $bad: " . implode(', ', $sample) : ' ตรงทั้งหมด'));
    }
} catch (Throwable $e) {
    $step('ทดลองกู้คืน', false, $e->getMessage());
} finally {
    try { $pdo->exec("DROP DATABASE IF EXISTS `$testDb`"); } catch (Throwable $e) {}
}

$problems = count(array_filter($steps, fn($s) => !$s['ok']));
$pdo->prepare("UPDATE integrity_runs SET State = ?, FinishedAt = NOW(), Checked = ?, Problems = ?, Details = ? WHERE ID = ?")
    ->execute([$ok ? 'ok' : 'failed', count($steps), $problems, json_encode($steps, JSON_UNESCAPED_UNICODE), $runId]);
addAuditLog($pdo, $userId, 'Restore Test', $ok ? 'ทดลองกู้คืนสำเร็จ' : 'ทดลองกู้คืนไม่ผ่าน');
echo ($ok ? 'OK' : 'FAILED') . "\n" . json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
