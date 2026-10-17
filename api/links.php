<?php
// Related documents (ฎีกา ↔ ใบเสร็จ ↔ สัญญา ↔ ใบส่งของ ...). A link is shown from both sides.
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/helpers.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';
$me = (int)$_SESSION['user_id'];

const RELATION_TYPES = ['ฎีกา', 'ใบเสร็จรับเงิน', 'สัญญา', 'ใบส่งของ', 'ใบสั่งซื้อ', 'บันทึกขออนุมัติ', 'เอกสารแนบ', 'ฉบับแก้ไข', 'เกี่ยวข้อง'];

function linkDoc($pdo, $id) {
    $stmt = $pdo->prepare("SELECT ID, Filename, FolderId FROM documents WHERE ID = ? AND DeletedAt IS NULL");
    $stmt->execute([(string)$id]);
    $d = $stmt->fetch();
    if (!$d || !canAccessFolder($pdo, $d['FolderId'])) jsonFail(404, "ไม่พบเอกสาร $id หรือไม่มีสิทธิ์เข้าถึง");
    return $d;
}

try {
    switch ($action) {
        case 'types':
            echo json_encode(['success' => true, 'types' => RELATION_TYPES]);
            break;

        case 'list':
            $doc = linkDoc($pdo, $in['DocumentID'] ?? '');
            // The other side of each link; RelationType describes that other document
            $params = [$doc['ID'], $doc['ID'], $doc['ID']];
            $scope = folderScopeSql($pdo, $params);
            $stmt = $pdo->prepare("SELECT l.ID AS LinkID, l.RelationType, l.Note, l.CreatedAt, u.Username AS CreatedByName,
                                          d.ID, d.Filename, d.PaymentDate, (d.FileId IS NOT NULL AND d.FileId <> '') AS HasFiles
                                   FROM document_links l
                                   JOIN documents d ON d.ID = CASE WHEN l.DocA = ? THEN l.DocB ELSE l.DocA END
                                   LEFT JOIN users u ON u.ID = l.CreatedBy
                                   WHERE (l.DocA = ? OR l.DocB = ?) AND d.DeletedAt IS NULL AND $scope
                                   ORDER BY l.RelationType, d.ID");
            $stmt->execute($params);
            echo json_encode(['success' => true, 'links' => $stmt->fetchAll()]);
            break;

        case 'add':
            if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
            $a = linkDoc($pdo, $in['DocA'] ?? '');
            $b = linkDoc($pdo, $in['DocB'] ?? '');
            if ($a['ID'] === $b['ID']) jsonFail(400, 'เชื่อมเอกสารกับตัวเองไม่ได้');
            $type = in_array($in['RelationType'] ?? '', RELATION_TYPES, true) ? $in['RelationType'] : 'เกี่ยวข้อง';
            $stmt = $pdo->prepare("SELECT 1 FROM document_links WHERE (DocA = ? AND DocB = ?) OR (DocA = ? AND DocB = ?)");
            $stmt->execute([$a['ID'], $b['ID'], $b['ID'], $a['ID']]);
            if ($stmt->fetchColumn()) jsonFail(409, 'เอกสารทั้งสองเชื่อมกันอยู่แล้ว');
            $pdo->prepare("INSERT INTO document_links (DocA, DocB, RelationType, Note, CreatedBy) VALUES (?, ?, ?, ?, ?)")
                ->execute([$a['ID'], $b['ID'], $type, mb_substr(trim((string)($in['Note'] ?? '')), 0, 255) ?: null, $me]);
            addAuditLog($pdo, $me, 'Link Documents', "เชื่อม {$b['ID']} ($type)", $a['ID']);
            addAuditLog($pdo, $me, 'Link Documents', "เชื่อมกับ {$a['ID']}", $b['ID']);
            echo json_encode(['success' => true]);
            break;

        case 'remove':
            if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
            $stmt = $pdo->prepare("SELECT * FROM document_links WHERE ID = ?");
            $stmt->execute([(int)($in['LinkID'] ?? 0)]);
            $l = $stmt->fetch();
            if (!$l) jsonFail(404, 'ไม่พบการเชื่อมโยง');
            linkDoc($pdo, $l['DocA']);
            linkDoc($pdo, $l['DocB']);
            $pdo->prepare("DELETE FROM document_links WHERE ID = ?")->execute([$l['ID']]);
            addAuditLog($pdo, $me, 'Link Documents', "ยกเลิกการเชื่อมกับ {$l['DocB']}", $l['DocA']);
            echo json_encode(['success' => true]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('links.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
