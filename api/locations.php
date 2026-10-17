<?php
// Where the paper originals are kept (room / cabinet / shelf / box) and who has borrowed them.
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/helpers.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? ($_GET['action'] ?? '');
$me = (int)$_SESSION['user_id'];

function docForLoan($pdo, $id) {
    $stmt = $pdo->prepare("SELECT ID, Filename, FolderId, DeletedAt FROM documents WHERE ID = ?");
    $stmt->execute([(string)$id]);
    $d = $stmt->fetch();
    if (!$d || !canAccessFolder($pdo, $d['FolderId'])) jsonFail(404, 'ไม่พบเอกสาร หรือไม่มีสิทธิ์เข้าถึง');
    return $d;
}


try {
    switch ($action) {
        case 'list':
            $rows = $pdo->query("SELECT l.*, (SELECT COUNT(*) FROM documents d WHERE d.LocationId = l.ID AND d.DeletedAt IS NULL) AS DocCount
                                 FROM locations l ORDER BY l.Room, l.Cabinet, l.Shelf, l.Box")->fetchAll();
            foreach ($rows as &$r) $r['Label'] = locationLabel($r);
            echo json_encode(['success' => true, 'locations' => $rows]);
            break;

        case 'save':
            if (!isAdmin()) jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
            $f = fn($k) => mb_substr(trim((string)($in[$k] ?? '')), 0, 100) ?: null;
            if (!$f('Room')) jsonFail(400, 'กรุณาระบุห้อง/สถานที่');
            if (!empty($in['ID'])) {
                $pdo->prepare("UPDATE locations SET Room=?, Cabinet=?, Shelf=?, Box=?, Note=? WHERE ID=?")
                    ->execute([$f('Room'), $f('Cabinet'), $f('Shelf'), $f('Box'), $f('Note'), (int)$in['ID']]);
            } else {
                $pdo->prepare("INSERT INTO locations (Room, Cabinet, Shelf, Box, Note) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$f('Room'), $f('Cabinet'), $f('Shelf'), $f('Box'), $f('Note')]);
            }
            addAuditLog($pdo, $me, 'Update Settings', 'Saved storage location: ' . $f('Room'));
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            if (!isAdmin()) jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
            $id = (int)($in['ID'] ?? 0);
            $pdo->prepare("UPDATE documents SET LocationId = NULL WHERE LocationId = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM locations WHERE ID = ?")->execute([$id]);
            addAuditLog($pdo, $me, 'Update Settings', "Deleted storage location #$id");
            echo json_encode(['success' => true]);
            break;

        case 'setDocLocation':
            if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
            $doc = docForLoan($pdo, $in['DocumentID'] ?? '');
            $loc = ($in['LocationId'] ?? '') !== '' ? (int)$in['LocationId'] : null;
            if ($loc) {
                $stmt = $pdo->prepare("SELECT * FROM locations WHERE ID = ?");
                $stmt->execute([$loc]);
                if (!($l = $stmt->fetch())) jsonFail(400, 'ไม่พบที่เก็บนี้');
            }
            $note = mb_substr(trim((string)($in['LocationNote'] ?? '')), 0, 255) ?: null;
            $pdo->prepare("UPDATE documents SET LocationId = ?, LocationNote = ? WHERE ID = ?")->execute([$loc, $note, $doc['ID']]);
            addAuditLog($pdo, $me, 'Update Document', 'ที่เก็บตัวจริง: ' . ($loc ? locationLabel($l) : '-') . ($note ? " ($note)" : ''), $doc['ID']);
            echo json_encode(['success' => true]);
            break;

        case 'borrow':
            if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
            $doc = docForLoan($pdo, $in['DocumentID'] ?? '');
            $name = mb_substr(trim((string)($in['BorrowerName'] ?? '')), 0, 150);
            $due = (string)($in['DueDate'] ?? '');
            if ($name === '') jsonFail(400, 'กรุณาระบุชื่อผู้ยืม');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || strtotime($due) < strtotime('today')) jsonFail(400, 'วันกำหนดคืนไม่ถูกต้อง');
            $stmt = $pdo->prepare("SELECT BorrowerName FROM loans WHERE DocumentID = ? AND ReturnedAt IS NULL");
            $stmt->execute([$doc['ID']]);
            if ($who = $stmt->fetchColumn()) jsonFail(409, "เอกสารนี้ถูกยืมอยู่แล้วโดย $who");
            $pdo->prepare("INSERT INTO loans (DocumentID, BorrowerName, BorrowerUserID, Purpose, LoanedAt, DueDate, LoanedBy) VALUES (?, ?, ?, ?, NOW(), ?, ?)")
                ->execute([$doc['ID'], $name, ($in['BorrowerUserID'] ?? '') !== '' ? (int)$in['BorrowerUserID'] : null,
                           mb_substr(trim((string)($in['Purpose'] ?? '')), 0, 255) ?: null, $due, $me]);
            addAuditLog($pdo, $me, 'Loan', "ยืมตัวจริงโดย $name กำหนดคืน $due", $doc['ID']);
            echo json_encode(['success' => true]);
            break;

        case 'return':
            if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
            $stmt = $pdo->prepare("SELECT * FROM loans WHERE ID = ? AND ReturnedAt IS NULL");
            $stmt->execute([(int)($in['LoanID'] ?? 0)]);
            $loan = $stmt->fetch();
            if (!$loan) jsonFail(404, 'ไม่พบรายการยืม หรือคืนแล้ว');
            docForLoan($pdo, $loan['DocumentID']);
            $pdo->prepare("UPDATE loans SET ReturnedAt = NOW(), ReturnedBy = ?, Note = COALESCE(?, Note) WHERE ID = ?")
                ->execute([$me, mb_substr(trim((string)($in['Note'] ?? '')), 0, 255) ?: null, $loan['ID']]);
            addAuditLog($pdo, $me, 'Loan', "คืนตัวจริงจาก {$loan['BorrowerName']}", $loan['DocumentID']);
            echo json_encode(['success' => true]);
            break;

        case 'loans':
            // filter: open | overdue | all ; optional DocumentID
            $where = ['1=1'];
            $params = [];
            $filter = $in['filter'] ?? 'open';
            if ($filter === 'open') $where[] = 'l.ReturnedAt IS NULL';
            if ($filter === 'overdue') $where[] = 'l.ReturnedAt IS NULL AND l.DueDate < CURDATE()';
            if (!empty($in['DocumentID'])) { $where[] = 'l.DocumentID = ?'; $params[] = (string)$in['DocumentID']; }
            $where[] = folderScopeSql($pdo, $params);
            $stmt = $pdo->prepare("SELECT l.*, d.Filename, lu.Username AS LoanedByName, ru.Username AS ReturnedByName,
                                          (l.ReturnedAt IS NULL AND l.DueDate < CURDATE()) AS Overdue
                                   FROM loans l JOIN documents d ON d.ID = l.DocumentID
                                   LEFT JOIN users lu ON lu.ID = l.LoanedBy LEFT JOIN users ru ON ru.ID = l.ReturnedBy
                                   WHERE " . implode(' AND ', $where) . " ORDER BY l.ReturnedAt IS NULL DESC, l.DueDate LIMIT 500");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) $r['Overdue'] = (bool)$r['Overdue'];
            echo json_encode(['success' => true, 'loans' => $rows]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('locations.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
