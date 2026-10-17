<?php
/**
 * Fixity check: recompute the SHA-256 of every stored file (current and old versions, stamped copies and
 * originals) and compare with the value recorded when it was saved. Finds missing, damaged or altered files.
 *   php tools/integrity_check.php [userId]
 * Schedule monthly with Windows Task Scheduler.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/storage.php';
set_time_limit(0);

$userId = isset($argv[1]) && ctype_digit($argv[1]) ? (int)$argv[1] : null;
$pdo->prepare("INSERT INTO integrity_runs (Kind, State, StartedAt, TriggeredBy) VALUES ('integrity', 'running', NOW(), ?)")->execute([$userId]);
$runId = (int)$pdo->lastInsertId();

$checked = 0;
$problems = [];
try {
    $rows = $pdo->query("SELECT f.ID, f.DocumentID, f.StoredPath, f.OriginalPath, f.Sha256, f.OriginalSha256, f.IsCurrent
                         FROM document_files f JOIN documents d ON d.ID = f.DocumentID")->fetchAll();
    foreach ($rows as $f) {
        foreach ([[$f['StoredPath'], $f['Sha256'], 'ไฟล์ที่ประทับ QR'], [$f['OriginalPath'], $f['OriginalSha256'], 'ต้นฉบับ']] as [$rel, $expected, $label]) {
            if (!$rel || !$expected) continue;
            $checked++;
            $abs = absPath($rel);
            if (!is_file($abs)) {
                $problems[] = ['doc' => $f['DocumentID'], 'path' => $rel, 'what' => "$label หายไป", 'current' => (bool)$f['IsCurrent']];
            } elseif (!hash_equals($expected, hash_file('sha256', $abs))) {
                $problems[] = ['doc' => $f['DocumentID'], 'path' => $rel, 'what' => "$label ถูกแก้ไขหรือเสียหาย (hash ไม่ตรง)", 'current' => (bool)$f['IsCurrent']];
            }
        }
    }
    $state = $problems ? 'problems' : 'ok';
} catch (Throwable $e) {
    $state = 'failed';
    $problems[] = ['doc' => null, 'path' => null, 'what' => 'ตรวจไม่สำเร็จ: ' . $e->getMessage()];
}

$pdo->prepare("UPDATE integrity_runs SET State = ?, FinishedAt = NOW(), Checked = ?, Problems = ?, Details = ? WHERE ID = ?")
    ->execute([$state, $checked, count($problems), json_encode(array_slice($problems, 0, 1000), JSON_UNESCAPED_UNICODE), $runId]);
addAuditLog($pdo, $userId, 'Integrity Check', "ตรวจ $checked ไฟล์ พบปัญหา " . count($problems) . ' รายการ');
foreach (array_slice($problems, 0, 200) as $p) {
    if ($p['doc']) addAuditLog($pdo, $userId, 'Integrity Problem', "{$p['what']}: {$p['path']}", $p['doc']);
}
echo "checked $checked, problems " . count($problems) . " ($state)\n";
