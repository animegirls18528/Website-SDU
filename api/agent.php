<?php
/**
 * Upload endpoint for the scan agent on other office PCs (tools/agent/edms-agent.ps1).
 * Authenticated with a personal token (Authorization: Bearer edms_...), not a browser session.
 * Files land in the token owner's inbox; the owner attaches them to documents from the Scan page.
 *
 *   POST multipart: file=<pdf|jpg|png|tif>  [target=<document ID>]  [source=<PC name>]
 *   GET  ?ping=1    check the token
 */
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once '../config/storage.php';
header('Content-Type: application/json');

function agentFail($code, $msg) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!$auth && function_exists('apache_request_headers')) {
    $headers = array_change_key_case(apache_request_headers(), CASE_LOWER);
    $auth = $headers['authorization'] ?? '';
}
if (!preg_match('/^Bearer\s+(edms_[a-f0-9]{48})$/', trim($auth), $m)) agentFail(401, 'Missing or malformed token');

$stmt = $pdo->prepare("SELECT t.ID, t.UserID, t.Name, u.Username, u.Role FROM api_tokens t JOIN users u ON u.ID = t.UserID
                       WHERE t.TokenHash = ? AND t.RevokedAt IS NULL");
$stmt->execute([hash('sha256', $m[1])]);
$tok = $stmt->fetch();
if (!$tok) agentFail(401, 'Invalid or revoked token');
if (!in_array($tok['Role'], ['admin', 'staff'], true)) agentFail(403, 'This account cannot add documents');
$pdo->prepare("UPDATE api_tokens SET LastUsedAt = NOW() WHERE ID = ?")->execute([$tok['ID']]);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'user' => $tok['Username'], 'token' => $tok['Name'], 'maxBytes' => uploadLimitBytes()]);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    agentFail(400, 'No file received (limit ' . round(uploadLimitBytes() / 1048576) . ' MB)');
}
$file = $_FILES['file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff'], true) || !in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/tiff'], true)) {
    agentFail(400, 'Only PDF, JPG, PNG and TIFF files are accepted');
}

$rel = newStoragePath('scan', $ext);
if (!move_uploaded_file($file['tmp_name'], absPath($rel))) agentFail(500, 'Could not store the file');

$target = preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,99}$/', $_POST['target'] ?? '') ? $_POST['target'] : null;
$source = mb_substr(trim((string)($_POST['source'] ?? $tok['Name'])), 0, 100);
$pdo->prepare("INSERT INTO inbox_files (UserID, StoredPath, OriginalName, SizeBytes, Source, TargetDocumentID) VALUES (?, ?, ?, ?, ?, ?)")
    ->execute([$tok['UserID'], $rel, mb_substr(basename($file['name']), 0, 255), filesize(absPath($rel)), $source, $target]);
$inboxId = (int)$pdo->lastInsertId();
addAuditLog($pdo, $tok['UserID'], 'Agent Upload', "รับไฟล์จาก $source: {$file['name']}" . ($target ? " → $target" : ''));
echo json_encode(['success' => true, 'inboxId' => $inboxId]);
