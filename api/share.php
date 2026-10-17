<?php
// Admin side of reviewer links (e.g. for the State Audit Office): create, list, revoke, see access.
require_once '../config/auth.php';
requireAdmin();
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once '../config/storage.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$me = (int)$_SESSION['user_id'];

try {
    switch ($in['action'] ?? '') {
        case 'create':
            $recipient = mb_substr(trim((string)($in['Recipient'] ?? '')), 0, 255);
            $expires = (string)($in['ExpiresAt'] ?? '');
            $ids = array_values(array_unique(array_filter(array_map('strval', (array)($in['IDs'] ?? [])), 'strlen')));
            if ($recipient === '') jsonFail(400, 'กรุณาระบุผู้รับลิงก์');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) || strtotime($expires) < strtotime('today')) jsonFail(400, 'วันหมดอายุไม่ถูกต้อง');
            if (strtotime($expires) > strtotime('+180 days')) jsonFail(400, 'กำหนดวันหมดอายุได้ไม่เกิน 180 วัน');
            if (!$ids || count($ids) > 500) jsonFail(400, 'เลือกเอกสาร 1–500 ฉบับ');
            $stmt = $pdo->prepare("SELECT ID FROM documents WHERE ID IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND DeletedAt IS NULL");
            $stmt->execute($ids);
            $valid = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!$valid) jsonFail(400, 'ไม่พบเอกสารที่เลือก');

            $token = bin2hex(random_bytes(24));
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO share_links (TokenHash, Recipient, Note, AllowDownload, ExpiresAt, CreatedBy) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([hash('sha256', $token), $recipient, mb_substr(trim((string)($in['Note'] ?? '')), 0, 255) ?: null,
                           !empty($in['AllowDownload']) ? 1 : 0, $expires . ' 23:59:59', $me]);
            $linkId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO share_link_docs (LinkID, DocumentID) VALUES (?, ?)");
            foreach ($valid as $id) {
                $ins->execute([$linkId, $id]);
                addAuditLog($pdo, $me, 'Share Link', "สร้างลิงก์ผู้ตรวจ #$linkId ให้ $recipient (หมดอายุ $expires)", $id);
            }
            $pdo->commit();
            // The token itself is only shown now; the database keeps its hash
            echo json_encode(['success' => true, 'url' => publicBaseUrl($pdo) . '/share.php?t=' . $token, 'count' => count($valid), 'id' => $linkId]);
            break;

        case 'list':
            $rows = $pdo->query("SELECT s.ID, s.Recipient, s.Note, s.AllowDownload, s.ExpiresAt, s.RevokedAt, s.CreatedAt, s.LastAccessAt, s.AccessCount,
                                        u.Username AS CreatedByName, (SELECT COUNT(*) FROM share_link_docs x WHERE x.LinkID = s.ID) AS DocCount,
                                        (s.RevokedAt IS NULL AND s.ExpiresAt >= NOW()) AS Active
                                 FROM share_links s LEFT JOIN users u ON u.ID = s.CreatedBy ORDER BY s.ID DESC LIMIT 200")->fetchAll();
            foreach ($rows as &$r) $r['Active'] = (bool)$r['Active'];
            echo json_encode(['success' => true, 'links' => $rows]);
            break;

        case 'detail':
            $id = (int)($in['ID'] ?? 0);
            $stmt = $pdo->prepare("SELECT d.ID, d.Filename FROM share_link_docs x JOIN documents d ON d.ID = x.DocumentID WHERE x.LinkID = ? ORDER BY d.ID");
            $stmt->execute([$id]);
            $docs = $stmt->fetchAll();
            $stmt = $pdo->prepare("SELECT CreatedAt, Action, Details, IPAddress, DocumentID FROM audit_logs WHERE Action = 'Share Access' AND Details LIKE ? ORDER BY ID DESC LIMIT 200");
            $stmt->execute(["ลิงก์ #$id %"]);
            echo json_encode(['success' => true, 'documents' => $docs, 'access' => $stmt->fetchAll()]);
            break;

        case 'revoke':
            $id = (int)($in['ID'] ?? 0);
            $pdo->prepare("UPDATE share_links SET RevokedAt = NOW() WHERE ID = ? AND RevokedAt IS NULL")->execute([$id]);
            addAuditLog($pdo, $me, 'Share Link', "ยกเลิกลิงก์ผู้ตรวจ #$id");
            echo json_encode(['success' => true]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('share api: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
