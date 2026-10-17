<?php
// The user's inbox of files sent by scan agents: list, take into the Scan page, or discard.
require_once '../config/auth.php';
requireEditor();
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once '../config/storage.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$me = (int)$_SESSION['user_id'];

function inboxRow($pdo, $id, $me) {
    $stmt = $pdo->prepare("SELECT * FROM inbox_files WHERE ID = ? AND UserID = ?");
    $stmt->execute([(int)$id, $me]);
    $r = $stmt->fetch();
    if (!$r) jsonFail(404, 'ไม่พบไฟล์ในกล่องรับไฟล์');
    return $r;
}

try {
    switch ($in['action'] ?? '') {
        case 'list':
            $stmt = $pdo->prepare("SELECT ID, OriginalName, SizeBytes, Source, TargetDocumentID, CreatedAt FROM inbox_files WHERE UserID = ? ORDER BY ID");
            $stmt->execute([$me]);
            echo json_encode(['success' => true, 'files' => $stmt->fetchAll()]);
            break;

        case 'take':
            // Moves the file into this browser session's scan list
            $r = inboxRow($pdo, $in['ID'] ?? 0, $me);
            if (!is_file(absPath($r['StoredPath']))) {
                $pdo->prepare("DELETE FROM inbox_files WHERE ID = ?")->execute([$r['ID']]);
                jsonFail(410, 'ไฟล์นี้ไม่มีอยู่แล้ว');
            }
            stageFile($r['StoredPath'], 'scan');
            $_SESSION['staged_names'][$r['StoredPath']] = $r['OriginalName'];
            $pdo->prepare("DELETE FROM inbox_files WHERE ID = ?")->execute([$r['ID']]);
            echo json_encode(['success' => true, 'file' => [
                'fileId' => 'uploads/' . $r['StoredPath'], 'name' => $r['OriginalName'], 'size' => (int)$r['SizeBytes'],
                'format' => strtolower(pathinfo($r['StoredPath'], PATHINFO_EXTENSION)), 'target' => $r['TargetDocumentID'],
            ]]);
            break;

        case 'discard':
            $r = inboxRow($pdo, $in['ID'] ?? 0, $me);
            @unlink(absPath($r['StoredPath']));
            $pdo->prepare("DELETE FROM inbox_files WHERE ID = ?")->execute([$r['ID']]);
            echo json_encode(['success' => true]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('inbox.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
