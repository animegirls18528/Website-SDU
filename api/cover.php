<?php
/**
 * Cover-sheet batches: create the documents first (index data known), print one cover sheet per document,
 * put each sheet on top of its paper document and scan the whole stack in one go. The browser then finds the
 * cover QR codes, splits the stack and attaches each part to its document (see assets/modules/batch.js).
 */
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/helpers.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$me = (int)$_SESSION['user_id'];
if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');

try {
    switch ($in['action'] ?? '') {
        case 'create':
            $prefix = (string)($in['Prefix'] ?? '');
            $folderId = ($in['FolderId'] ?? '') !== '' ? (int)$in['FolderId'] : null;
            if ($prefix === '') jsonFail(400, 'กรุณาเลือกรหัสนำหน้า');
            if (!canAccessFolder($pdo, $folderId)) jsonFail(403, 'ไม่มีสิทธิ์จัดเก็บในแฟ้มนี้');
            if ($folderId !== null) {
                $chk = $pdo->prepare("SELECT 1 FROM folders WHERE ID = ?");
                $chk->execute([$folderId]);
                if (!$chk->fetchColumn()) jsonFail(400, 'ไม่พบแฟ้มที่เลือก');
            }
            // One title per line; or N copies of the same title
            $titles = array_values(array_filter(array_map(fn($t) => mb_substr(trim($t), 0, 255), (array)($in['Titles'] ?? [])), 'strlen'));
            if (!$titles) jsonFail(400, 'กรุณาระบุชื่อเอกสารอย่างน้อย 1 รายการ');
            if (count($titles) > 200) jsonFail(400, 'สร้างได้ครั้งละไม่เกิน 200 ใบ');
            $payment = (string)($in['PaymentDate'] ?? '');
            if ($payment !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $payment, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))) {
                jsonFail(400, 'วันที่จ่ายเงินไม่ถูกต้อง');
            }
            $expected = ($in['ExpectedPages'] ?? '') !== '' ? max(1, (int)$in['ExpectedPages']) : null;
            $location = ($in['LocationId'] ?? '') !== '' ? (int)$in['LocationId'] : null;
            $tags = mb_substr(trim((string)($in['Tags'] ?? '')), 0, 500) ?: null;

            $ids = [];
            $ins = $pdo->prepare("INSERT INTO documents (ID, Filename, FolderId, PaymentDate, ExpiryDate, Status, FileId, CustomData, Tags,
                                                         WorkflowStatus, CreatedBy, UpdatedAt, VerifyToken, CoverPrintedAt, ExpectedPages, LocationId)
                                  VALUES (?, ?, ?, ?, ?, 'Active', '', '{}', ?, 'Draft', ?, NOW(), ?, NOW(), ?, ?)");
            $pdo->beginTransaction();
            foreach ($titles as $title) {
                $id = reserveNextId($pdo, $prefix);
                $expiry = null;
                if ($payment !== '') {
                    [$y, $mo, $d] = array_map('intval', explode('-', $payment));
                    $y += retentionYearsFor($pdo, $id);
                    $expiry = sprintf('%04d-%02d-%02d', $y, $mo, min($d, (int)date('t', mktime(0, 0, 0, $mo, 1, $y))));
                }
                $ins->execute([$id, $title, $folderId, $payment ?: null, $expiry, $tags, $me, bin2hex(random_bytes(16)), $expected, $location]);
                $ids[] = $id;
            }
            require_once '../config/search.php';
            foreach ($ids as $id) updateSearchIndex($pdo, $id);
            $pdo->commit();
            addAuditLog($pdo, $me, 'Cover Sheets', 'สร้างใบปะหน้า ' . count($ids) . " ใบ ({$ids[0]} – " . end($ids) . ')');
            echo json_encode(['success' => true, 'ids' => $ids]);
            break;

        case 'markPrinted':
            $ids = array_slice(array_values(array_filter(array_map('strval', (array)($in['IDs'] ?? [])), 'strlen')), 0, 500);
            if (!$ids) jsonFail(400, 'กรุณาเลือกเอกสาร');
            $params = $ids;
            $scope = folderScopeSql($pdo, $params);
            $pdo->prepare("UPDATE documents d SET d.CoverPrintedAt = NOW() WHERE d.ID IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND $scope")
                ->execute($params);
            echo json_encode(['success' => true]);
            break;

        case 'resolve':
            // Codes found on cover sheets during batch scanning → what each document is
            $ids = array_slice(array_values(array_unique(array_filter(array_map('strval', (array)($in['IDs'] ?? [])), 'strlen'))), 0, 500);
            $out = [];
            if ($ids) {
                $stmt = $pdo->prepare("SELECT d.ID, d.Filename, d.FolderId, d.DeletedAt, d.ExpectedPages, (d.FileId IS NOT NULL AND d.FileId <> '') AS HasFiles
                                       FROM documents d WHERE d.ID IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
                $stmt->execute($ids);
                foreach ($stmt->fetchAll() as $d) {
                    if (!canAccessFolder($pdo, $d['FolderId'])) continue;
                    $out[$d['ID']] = ['ID' => $d['ID'], 'Filename' => $d['Filename'], 'Deleted' => (bool)$d['DeletedAt'],
                                      'HasFiles' => (bool)$d['HasFiles'], 'ExpectedPages' => $d['ExpectedPages'] ? (int)$d['ExpectedPages'] : null];
                }
            }
            echo json_encode(['success' => true, 'documents' => $out]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('cover.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
