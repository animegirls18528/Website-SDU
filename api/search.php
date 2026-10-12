<?php
// Paged, server-side document search (replaces loading every document into the browser).
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/search.php';

header('Content-Type: application/json');

$f = json_decode(file_get_contents('php://input'), true) ?: $_GET;
$page = max(1, (int)($f['page'] ?? 1));
$pageSize = min(200, max(10, (int)($f['pageSize'] ?? 50)));

try {
    [$from, $where, $params, $isTextSearch] = buildDocumentFilter($pdo, $f);

    $order = documentOrderSql($f['sort'] ?? '');
    $offset = ($page - 1) * $pageSize;
    $stmt = $pdo->prepare("
        SELECT " . ($isTextSearch ? "SQL_CALC_FOUND_ROWS " : "") . "d.ID, d.Filename, d.FolderId, COALESCE(fo.FolderName, '') AS FolderName, d.PaymentDate, d.ExpiryDate,
               CASE WHEN d.ExpiryDate < CURDATE() THEN 'Expired' ELSE 'Active' END AS Status,
               d.WorkflowStatus, d.Tags, d.CustomData, d.CreatedAt, d.DeletedAt, d.DeleteReason,
               d.DestructionRequestedAt, d.DestructionReason,
               (d.FileId IS NOT NULL AND d.FileId <> '') AS HasFiles,
               (d.OcrText IS NOT NULL) AS HasText
        $from LEFT JOIN folders fo ON fo.ID = d.FolderId
        WHERE $where ORDER BY $order LIMIT $pageSize OFFSET $offset");
    $stmt->execute($params);
    $docs = $stmt->fetchAll();
    if ($isTextSearch) {
        // The text scan is the expensive part; let it produce the total too
        $total = (int)$pdo->query("SELECT FOUND_ROWS()")->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) $from WHERE $where");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
    }

    // Show where the words matched inside the document text
    $q = trim((string)($f['q'] ?? ''));
    $snippetStmt = $q !== '' ? $pdo->prepare("SELECT OcrText FROM documents WHERE ID = ?") : null;
    foreach ($docs as &$d) {
        $d['CustomData'] = json_decode($d['CustomData'] ?: '{}', true) ?: new stdClass();
        $d['HasFiles'] = (bool)$d['HasFiles'];
        $d['HasText'] = (bool)$d['HasText'];
        if ($snippetStmt && $d['HasText']) {
            $snippetStmt->execute([$d['ID']]);
            $text = (string)$snippetStmt->fetchColumn();
            $first = preg_split('/\s+/u', $q)[0];
            $pos = mb_stripos($text, $first);
            if ($pos !== false) {
                $d['Snippet'] = trim(mb_substr($text, max(0, $pos - 60), 160));
            }
        }
    }

    echo json_encode(['success' => true, 'documents' => $docs, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize]);
} catch (Exception $e) {
    error_log('search.php: ' . $e->getMessage());
    jsonFail(500, 'ค้นหาไม่สำเร็จ');
}
