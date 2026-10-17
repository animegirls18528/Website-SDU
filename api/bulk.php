<?php
// Act on many documents at once: move folder, add tags, workflow, storage location, trash / restore.
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once '../config/search.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$op = $in['op'] ?? '';
$me = (int)$_SESSION['user_id'];
$ids = array_values(array_unique(array_filter(array_map('strval', (array)($in['IDs'] ?? [])), 'strlen')));
if (!$ids) jsonFail(400, 'กรุณาเลือกเอกสาร');
if (count($ids) > 500) jsonFail(400, 'เลือกได้ครั้งละไม่เกิน 500 เอกสาร');

$needsAdmin = ['approve', 'trash', 'restore'];
if (in_array($op, $needsAdmin, true) && !isAdmin()) jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');

// Only documents this user may access
$in_ = implode(',', array_fill(0, count($ids), '?'));
$params = $ids;
$scope = folderScopeSql($pdo, $params);
$stmt = $pdo->prepare("SELECT d.ID, d.Tags, d.WorkflowStatus, d.DeletedAt FROM documents d WHERE d.ID IN ($in_) AND $scope");
$stmt->execute($params);
$docs = $stmt->fetchAll();

$done = 0;
$skipped = count($ids) - count($docs);
try {
    $pdo->beginTransaction();
    switch ($op) {
        case 'move':
            $folderId = ($in['FolderId'] ?? '') !== '' ? (int)$in['FolderId'] : null;
            if ($folderId !== null) {
                $chk = $pdo->prepare("SELECT FolderName FROM folders WHERE ID = ?");
                $chk->execute([$folderId]);
                if (($name = $chk->fetchColumn()) === false) jsonFail(400, 'ไม่พบแฟ้มปลายทาง');
                if (!canAccessFolder($pdo, $folderId)) jsonFail(403, 'ไม่มีสิทธิ์จัดเก็บในแฟ้มนี้');
            }
            $up = $pdo->prepare("UPDATE documents SET FolderId = ?, UpdatedAt = NOW(), RowVersion = RowVersion + 1 WHERE ID = ? AND DeletedAt IS NULL");
            foreach ($docs as $d) {
                $up->execute([$folderId, $d['ID']]);
                if ($up->rowCount()) { $done++; addAuditLog($pdo, $me, 'Update Document', 'ย้ายแฟ้มแบบกลุ่ม → ' . ($name ?? 'ไม่มีแฟ้ม'), $d['ID']); }
            }
            break;

        case 'addTags':
            $add = array_filter(array_map(fn($t) => mb_substr(trim($t), 0, 40), preg_split('/[,\n]+/u', (string)($in['Tags'] ?? ''))), 'strlen');
            if (!$add) jsonFail(400, 'กรุณาระบุแท็ก');
            $up = $pdo->prepare("UPDATE documents SET Tags = ?, UpdatedAt = NOW(), RowVersion = RowVersion + 1 WHERE ID = ?");
            foreach ($docs as $d) {
                if ($d['DeletedAt']) { $skipped++; continue; }
                $tags = array_values(array_unique(array_merge(array_filter(array_map('trim', explode(',', (string)$d['Tags'])), 'strlen'), $add)));
                $up->execute([mb_substr(implode(', ', $tags), 0, 500), $d['ID']]);
                updateSearchIndex($pdo, $d['ID']);
                $done++;
            }
            addAuditLog($pdo, $me, 'Update Document', "เพิ่มแท็ก " . implode(', ', $add) . " ให้ $done เอกสาร");
            break;

        case 'submit':   // Draft → PendingReview (staff)
        case 'approve':  // PendingReview → Approved (admin)
            [$from, $to, $label] = $op === 'submit' ? ['Draft', 'PendingReview', 'ส่งตรวจสอบ'] : ['PendingReview', 'Approved', 'อนุมัติ'];
            $up = $pdo->prepare("UPDATE documents SET WorkflowStatus = ?, UpdatedAt = NOW(), RowVersion = RowVersion + 1 WHERE ID = ? AND WorkflowStatus = ? AND DeletedAt IS NULL");
            foreach ($docs as $d) {
                $up->execute([$to, $d['ID'], $from]);
                if ($up->rowCount()) { $done++; addAuditLog($pdo, $me, 'Workflow', "$label (แบบกลุ่ม)", $d['ID']); } else { $skipped++; }
            }
            break;

        case 'setLocation':
            $loc = ($in['LocationId'] ?? '') !== '' ? (int)$in['LocationId'] : null;
            $up = $pdo->prepare("UPDATE documents SET LocationId = ? WHERE ID = ?");
            foreach ($docs as $d) { $up->execute([$loc, $d['ID']]); $done++; }
            addAuditLog($pdo, $me, 'Update Document', "กำหนดที่เก็บตัวจริงแบบกลุ่ม $done เอกสาร");
            break;

        case 'trash':
            $reason = mb_substr(trim((string)($in['Reason'] ?? '')), 0, 255) ?: 'ลบแบบกลุ่ม';
            $up = $pdo->prepare("UPDATE documents SET DeletedAt = NOW(), DeletedBy = ?, DeleteReason = ? WHERE ID = ? AND DeletedAt IS NULL");
            foreach ($docs as $d) {
                $up->execute([$me, $reason, $d['ID']]);
                if ($up->rowCount()) { $done++; addAuditLog($pdo, $me, 'Delete Document', "Moved to trash (bulk): $reason", $d['ID']); }
            }
            break;

        case 'restore':
            $up = $pdo->prepare("UPDATE documents SET DeletedAt = NULL, DeletedBy = NULL, DeleteReason = NULL WHERE ID = ? AND DeletedAt IS NOT NULL");
            foreach ($docs as $d) {
                $up->execute([$d['ID']]);
                if ($up->rowCount()) { $done++; addAuditLog($pdo, $me, 'Restore Document', 'Restored (bulk)', $d['ID']); }
            }
            break;

        default:
            $pdo->rollBack();
            jsonFail(400, 'Invalid operation');
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'done' => $done, 'skipped' => $skipped]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('bulk.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
