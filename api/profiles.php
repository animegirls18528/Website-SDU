<?php
// Scan profiles: named sets of scanner options per document type (e.g. "ฎีกา = B&W 300 DPI duplex").
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/helpers.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';
$me = (int)$_SESSION['user_id'];

const PROFILE_OPTION_KEYS = ['dpi', 'colorMode', 'source', 'format', 'pageSize', 'deskew', 'skipBlank', 'ocr', 'splitPatchT', 'pdfa', 'jpegQuality'];

try {
    switch ($action) {
        case 'list':
            $rows = $pdo->query("SELECT p.*, f.FolderName FROM scan_profiles p LEFT JOIN folders f ON f.ID = p.FolderId ORDER BY p.SortOrder, p.Name")->fetchAll();
            foreach ($rows as &$r) $r['Options'] = json_decode($r['Options'], true) ?: [];
            echo json_encode(['success' => true, 'profiles' => $rows]);
            break;

        case 'save':
            if (!isAdmin()) jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
            $name = mb_substr(trim((string)($in['Name'] ?? '')), 0, 100);
            if ($name === '') jsonFail(400, 'กรุณาตั้งชื่อโปรไฟล์');
            $opts = array_intersect_key((array)($in['Options'] ?? []), array_flip(PROFILE_OPTION_KEYS));
            $vals = [$name, json_encode($opts), ($in['Prefix'] ?? '') ?: null, ($in['FolderId'] ?? '') !== '' ? (int)$in['FolderId'] : null,
                     ($in['ExpectedPages'] ?? '') !== '' ? max(1, (int)$in['ExpectedPages']) : null];
            if (!empty($in['ID'])) {
                $pdo->prepare("UPDATE scan_profiles SET Name=?, Options=?, Prefix=?, FolderId=?, ExpectedPages=? WHERE ID=?")->execute(array_merge($vals, [(int)$in['ID']]));
            } else {
                $pdo->prepare("INSERT INTO scan_profiles (Name, Options, Prefix, FolderId, ExpectedPages, CreatedBy) VALUES (?, ?, ?, ?, ?, ?)")->execute(array_merge($vals, [$me]));
            }
            addAuditLog($pdo, $me, 'Update Settings', "Saved scan profile: $name");
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            if (!isAdmin()) jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
            $pdo->prepare("DELETE FROM scan_profiles WHERE ID = ?")->execute([(int)($in['ID'] ?? 0)]);
            echo json_encode(['success' => true]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('profiles.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
