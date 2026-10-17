<?php
/**
 * Destruction of records under the records-management regulation (ระเบียบงานสารบรรณ):
 * draft batch of expired documents → committee review (each item: destroy or keep N more years)
 * → approval → carried out (destroyed documents go to the trash, kept ones get a new expiry date).
 * Items keep a snapshot of each document so the register stays complete after purging.
 */
require_once '../config/auth.php';
requireAdmin();
require_once '../config/database.php';
require_once '../config/helpers.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$me = (int)$_SESSION['user_id'];
const BATCH_STATUS = ['draft' => 'ร่างบัญชี', 'review' => 'คณะกรรมการพิจารณา', 'approved' => 'อนุมัติแล้ว', 'done' => 'ดำเนินการทำลายแล้ว'];

function loadBatch($pdo, $id) {
    $stmt = $pdo->prepare("SELECT b.*, cu.Username AS CreatedByName, au.Username AS ApprovedByName FROM destruction_batches b
                           LEFT JOIN users cu ON cu.ID = b.CreatedBy LEFT JOIN users au ON au.ID = b.ApprovedBy WHERE b.ID = ?");
    $stmt->execute([(int)$id]);
    $b = $stmt->fetch();
    if (!$b) jsonFail(404, 'ไม่พบบัญชีขอทำลาย');
    $b['Committee'] = json_decode((string)$b['Committee'], true) ?: [];
    return $b;
}

function thaiFiscalYear($date = null) {
    $t = $date ? strtotime($date) : time();
    return (int)date('Y', $t) + 543 + ((int)date('n', $t) >= 10 ? 1 : 0);
}

try {
    switch ($in['action'] ?? '') {
        case 'list':
            $rows = $pdo->query("SELECT b.ID, b.Title, b.FiscalYear, b.Status, b.CreatedAt, b.ApprovedAt, b.DoneAt,
                                        (SELECT COUNT(*) FROM destruction_items i WHERE i.BatchID = b.ID) AS Items,
                                        (SELECT COUNT(*) FROM destruction_items i WHERE i.BatchID = b.ID AND i.Decision = 'destroy') AS ToDestroy
                                 FROM destruction_batches b ORDER BY b.ID DESC")->fetchAll();
            $candidates = (int)$pdo->query("SELECT COUNT(*) FROM documents d WHERE d.DeletedAt IS NULL AND d.ExpiryDate < CURDATE()
                AND NOT EXISTS (SELECT 1 FROM destruction_items i JOIN destruction_batches b ON b.ID = i.BatchID WHERE i.DocumentID = d.ID AND b.Status <> 'done')")->fetchColumn();
            echo json_encode(['success' => true, 'batches' => $rows, 'statusLabels' => BATCH_STATUS, 'candidates' => $candidates, 'currentFiscalYear' => thaiFiscalYear()]);
            break;

        case 'create':
            // Source: every expired document not already in an open batch, or those with a destruction request
            $source = ($in['source'] ?? 'expired') === 'requested' ? 'requested' : 'expired';
            $cond = $source === 'requested' ? 'd.DestructionRequestedAt IS NOT NULL' : 'd.ExpiryDate < CURDATE()';
            $stmt = $pdo->query("SELECT d.ID, d.Filename, d.PaymentDate, d.ExpiryDate, COALESCE(f.FolderName, '') AS FolderName
                                 FROM documents d LEFT JOIN folders f ON f.ID = d.FolderId
                                 WHERE d.DeletedAt IS NULL AND $cond
                                   AND NOT EXISTS (SELECT 1 FROM destruction_items i JOIN destruction_batches b ON b.ID = i.BatchID
                                                   WHERE i.DocumentID = d.ID AND b.Status <> 'done')
                                 ORDER BY d.ExpiryDate, d.ID LIMIT 5000");
            $docs = $stmt->fetchAll();
            if (!$docs) jsonFail(400, $source === 'requested' ? 'ไม่มีเอกสารที่มีคำขอทำลาย' : 'ไม่มีเอกสารที่ครบอายุและยังไม่อยู่ในบัญชีขอทำลาย');
            $fy = (int)($in['FiscalYear'] ?? 0) ?: thaiFiscalYear();
            $title = mb_substr(trim((string)($in['Title'] ?? '')), 0, 255) ?: "บัญชีหนังสือขอทำลาย ประจำปี $fy";
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO destruction_batches (Title, FiscalYear, CreatedBy) VALUES (?, ?, ?)")->execute([$title, $fy, $me]);
            $batchId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO destruction_items (BatchID, DocumentID, Title, DocDate, FolderName, ExpiryDate) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($docs as $d) $ins->execute([$batchId, $d['ID'], mb_substr($d['Filename'], 0, 255), $d['PaymentDate'], $d['FolderName'], $d['ExpiryDate']]);
            $pdo->commit();
            addAuditLog($pdo, $me, 'Destruction', "สร้าง$title (" . count($docs) . ' รายการ)');
            echo json_encode(['success' => true, 'id' => $batchId, 'count' => count($docs)]);
            break;

        case 'get':
            $b = loadBatch($pdo, $in['ID'] ?? 0);
            $stmt = $pdo->prepare("SELECT i.*, (d.ID IS NOT NULL AND d.DeletedAt IS NULL) AS StillActive FROM destruction_items i
                                   LEFT JOIN documents d ON d.ID = i.DocumentID WHERE i.BatchID = ? ORDER BY i.ExpiryDate, i.DocumentID");
            $stmt->execute([$b['ID']]);
            $b['Items'] = $stmt->fetchAll();
            $b['StatusLabel'] = BATCH_STATUS[$b['Status']] ?? $b['Status'];
            echo json_encode(['success' => true, 'batch' => $b]);
            break;

        case 'saveCommittee':
            $b = loadBatch($pdo, $in['ID'] ?? 0);
            if (in_array($b['Status'], ['approved', 'done'], true)) jsonFail(400, 'บัญชีนี้อนุมัติแล้ว แก้ไขคณะกรรมการไม่ได้');
            $members = [];
            foreach ((array)($in['Committee'] ?? []) as $m) {
                $name = mb_substr(trim((string)($m['name'] ?? '')), 0, 150);
                if ($name === '') continue;
                $members[] = ['name' => $name, 'position' => mb_substr(trim((string)($m['position'] ?? '')), 0, 150),
                              'role' => in_array($m['role'] ?? '', ['ประธานกรรมการ', 'กรรมการ', 'กรรมการและเลขานุการ'], true) ? $m['role'] : 'กรรมการ'];
            }
            $pdo->prepare("UPDATE destruction_batches SET Committee = ?, Note = ? WHERE ID = ?")
                ->execute([json_encode($members, JSON_UNESCAPED_UNICODE), mb_substr(trim((string)($in['Note'] ?? '')), 0, 2000) ?: null, $b['ID']]);
            echo json_encode(['success' => true]);
            break;

        case 'decide':
            // Committee decision for one or many items
            $b = loadBatch($pdo, $in['ID'] ?? 0);
            if (in_array($b['Status'], ['approved', 'done'], true)) jsonFail(400, 'บัญชีนี้อนุมัติแล้ว แก้ไขการพิจารณาไม่ได้');
            $decision = ($in['Decision'] ?? '') === 'keep' ? 'keep' : 'destroy';
            $years = $decision === 'keep' ? max(1, min(50, (int)($in['KeepYears'] ?? 1))) : null;
            $docIds = array_values(array_filter(array_map('strval', (array)($in['DocumentIDs'] ?? [])), 'strlen'));
            $up = $pdo->prepare("UPDATE destruction_items SET Decision = ?, KeepYears = ?, Note = ? WHERE BatchID = ? AND DocumentID = ?");
            $note = mb_substr(trim((string)($in['Note'] ?? '')), 0, 255) ?: null;
            foreach ($docIds as $id) $up->execute([$decision, $years, $note, $b['ID'], $id]);
            echo json_encode(['success' => true]);
            break;

        case 'setStatus':
            $b = loadBatch($pdo, $in['ID'] ?? 0);
            $to = $in['Status'] ?? '';
            $flow = ['draft' => ['review'], 'review' => ['draft', 'approved']];
            if (!in_array($to, $flow[$b['Status']] ?? [], true)) jsonFail(400, 'เปลี่ยนสถานะนี้ไม่ได้');
            if ($to === 'review' && count($b['Committee']) < 3) jsonFail(400, 'ต้องแต่งตั้งคณะกรรมการอย่างน้อย 3 คนก่อนส่งพิจารณา');
            $pdo->prepare("UPDATE destruction_batches SET Status = ?" . ($to === 'approved' ? ", ApprovedBy = ?, ApprovedAt = NOW()" : "") . " WHERE ID = ?")
                ->execute($to === 'approved' ? [$to, $me, $b['ID']] : [$to, $b['ID']]);
            addAuditLog($pdo, $me, 'Destruction', "{$b['Title']}: " . BATCH_STATUS[$b['Status']] . ' → ' . BATCH_STATUS[$to]);
            echo json_encode(['success' => true]);
            break;

        case 'execute':
            // Carry out an approved batch: destroy → trash (purged after the trash period), keep → new expiry date
            $b = loadBatch($pdo, $in['ID'] ?? 0);
            if ($b['Status'] !== 'approved') jsonFail(400, 'ต้องอนุมัติบัญชีก่อนดำเนินการ');
            $stmt = $pdo->prepare("SELECT * FROM destruction_items WHERE BatchID = ?");
            $stmt->execute([$b['ID']]);
            $destroyed = $kept = 0;
            $pdo->beginTransaction();
            $trash = $pdo->prepare("UPDATE documents SET DeletedAt = NOW(), DeletedBy = ?, DeleteReason = ? WHERE ID = ? AND DeletedAt IS NULL");
            $keep = $pdo->prepare("UPDATE documents SET ExpiryDate = DATE_ADD(GREATEST(COALESCE(ExpiryDate, CURDATE()), CURDATE()), INTERVAL ? YEAR),
                                   Status = 'Active', DestructionRequestedAt = NULL, DestructionRequestedBy = NULL, DestructionReason = NULL WHERE ID = ?");
            foreach ($stmt->fetchAll() as $i) {
                if ($i['Decision'] === 'destroy') {
                    $trash->execute([$me, "ทำลายตาม{$b['Title']} (บัญชี #{$b['ID']})", $i['DocumentID']]);
                    if ($trash->rowCount()) { $destroyed++; addAuditLog($pdo, $me, 'Destruction', "ทำลายตามบัญชี #{$b['ID']}", $i['DocumentID']); }
                } else {
                    $keep->execute([(int)$i['KeepYears'], $i['DocumentID']]);
                    $kept++;
                    addAuditLog($pdo, $me, 'Destruction', "คณะกรรมการให้เก็บต่อ {$i['KeepYears']} ปี (บัญชี #{$b['ID']})", $i['DocumentID']);
                }
            }
            $pdo->prepare("UPDATE destruction_batches SET Status = 'done', DoneAt = NOW() WHERE ID = ?")->execute([$b['ID']]);
            $pdo->commit();
            echo json_encode(['success' => true, 'destroyed' => $destroyed, 'kept' => $kept]);
            break;

        case 'delete':
            $b = loadBatch($pdo, $in['ID'] ?? 0);
            if ($b['Status'] !== 'draft') jsonFail(400, 'ลบได้เฉพาะบัญชีที่ยังเป็นร่าง');
            $pdo->prepare("DELETE FROM destruction_batches WHERE ID = ?")->execute([$b['ID']]);
            addAuditLog($pdo, $me, 'Destruction', "ลบร่าง{$b['Title']}");
            echo json_encode(['success' => true]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('destruction.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
