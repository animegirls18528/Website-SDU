<?php
// Saved search filters per user.
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$me = (int)$_SESSION['user_id'];

const SAVED_FILTER_KEYS = ['q', 'folderId', 'status', 'dateField', 'dateFrom', 'dateTo', 'sort', 'fiscalYear', 'amountMin', 'amountMax',
                           'department', 'fieldName', 'fieldValue', 'locationId', 'loan', 'pii'];

try {
    switch ($in['action'] ?? '') {
        case 'list':
            $stmt = $pdo->prepare("SELECT ID, Name, Filters FROM saved_searches WHERE UserID = ? ORDER BY Name");
            $stmt->execute([$me]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) $r['Filters'] = json_decode($r['Filters'], true) ?: [];
            echo json_encode(['success' => true, 'saved' => $rows]);
            break;

        case 'save':
            $name = mb_substr(trim((string)($in['Name'] ?? '')), 0, 100);
            if ($name === '') jsonFail(400, 'กรุณาตั้งชื่อ');
            $filters = array_intersect_key((array)($in['Filters'] ?? []), array_flip(SAVED_FILTER_KEYS));
            $stmt = $pdo->prepare("SELECT ID FROM saved_searches WHERE UserID = ? AND Name = ?");
            $stmt->execute([$me, $name]);
            if ($id = $stmt->fetchColumn()) {
                $pdo->prepare("UPDATE saved_searches SET Filters = ? WHERE ID = ?")->execute([json_encode($filters, JSON_UNESCAPED_UNICODE), $id]);
            } else {
                $pdo->prepare("INSERT INTO saved_searches (UserID, Name, Filters) VALUES (?, ?, ?)")->execute([$me, $name, json_encode($filters, JSON_UNESCAPED_UNICODE)]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $pdo->prepare("DELETE FROM saved_searches WHERE ID = ? AND UserID = ?")->execute([(int)($in['ID'] ?? 0), $me]);
            echo json_encode(['success' => true]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('saved.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
