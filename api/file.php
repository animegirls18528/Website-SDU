<?php
// Serves document files to logged-in users who may access the document's folder. uploads/ itself is blocked by .htaccess.
require_once '../config/auth.php';
startSecureSession();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}
require_once '../config/database.php';
require_once '../config/storage.php';

$rel = normalizeFileRef($_GET['f'] ?? '');
if (!$rel) {
    http_response_code(400);
    exit('Invalid file');
}

$path = absPath($rel);
$real = realpath($path);
if (!$real || strpos($real, uploadsDir() . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
    http_response_code(404);
    exit('File not found');
}

// Which document owns this file? Unattached files are only visible to the user who staged them.
$stmt = $pdo->prepare("SELECT d.ID, d.FolderId, d.DeletedAt, d.PiiFlag FROM document_files f JOIN documents d ON d.ID = f.DocumentID
                       WHERE f.StoredPath = ? OR f.OriginalPath = ? LIMIT 1");
$stmt->execute([$rel, $rel]);
$doc = $stmt->fetch();
if (!$doc) {
    // Files attached by older code are only listed in documents.FileId
    $stmt = $pdo->prepare("SELECT ID, FolderId, DeletedAt, PiiFlag FROM documents WHERE FIND_IN_SET(?, FileId) LIMIT 1");
    $stmt->execute(['uploads/' . $rel]);
    $doc = $stmt->fetch();
}

if ($doc) {
    if (!canAccessFolder($pdo, $doc['FolderId']) || ($doc['DeletedAt'] && !isAdmin())) {
        http_response_code(403);
        exit('Forbidden');
    }
} elseif (!isStaged($rel) && !isAdmin()) {
    http_response_code(403);
    exit('Forbidden');
}

require_once '../config/pii.php';
require_once '../config/access.php';
$download = isset($_GET['download']);
// PDPA: files with personal data only for roles allowed to see it (scans cannot be redacted reliably)
if ($doc && (int)$doc['PiiFlag'] > 0 && !canSeePii($pdo)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('เอกสารนี้มีข้อมูลส่วนบุคคล บัญชีของคุณไม่มีสิทธิ์เปิดไฟล์');
}
if ($download && !canDownloadFiles($pdo)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('บัญชีของคุณเปิดดูได้อย่างเดียว ดาวน์โหลดไม่ได้');
}
// Log each file at most once per 10 minutes per session (PDF viewers fetch the same file several times)
if ($doc) {
    $key = ($download ? 'D:' : 'V:') . $rel;
    if (($_SESSION['file_log'][$key] ?? 0) < time() - 600) {
        $_SESSION['file_log'][$key] = time();
        addAuditLog($pdo, $_SESSION['user_id'], $download ? 'Download File' : 'View File', basename($rel), $doc['ID']);
    }
}

$ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
$name = $doc ? preg_replace('/[^A-Za-z0-9_.-]/', '_', $doc['ID']) . '_' . basename($rel) : basename($rel);

// Watermark copies handed out (download, or every view so printing is covered too)
$mode = watermarkMode($pdo);
// Editors fetching a file into the page editor get it unmarked, otherwise the mark would end up in the new version
$forEditing = isset($_GET['edit']) && canEdit();
if ($doc && $ext === 'pdf' && !$forEditing && ($mode === 'all' || ($mode === 'download' && $download))) {
    require_once '../config/docsign.php';
    $tmp = tempnam(sys_get_temp_dir(), 'wm_');
    $who = ($_SESSION['username'] ?? '') . ' ' . date('Y-m-d H:i');
    if (!watermarkPdf($real, $tmp, 'COPY ' . $who, "Issued to " . ($_SESSION['username'] ?? '') . " from IP " . ($_SERVER['REMOTE_ADDR'] ?? '') . " at " . date('Y-m-d H:i:s') . " - doc " . $doc['ID'])) {
        @unlink($tmp);
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('ไม่สามารถใส่ลายน้ำในไฟล์นี้ได้ กรุณาติดต่อผู้ดูแลระบบ');
    }
    signPdf($pdo, $tmp); // the watermark rewrites the PDF; sign the copy again so it stays verifiable
    $real = $tmp;
    register_shutdown_function(fn() => @unlink($tmp));
}

session_write_close(); // don't hold the session lock while streaming
header('Content-Type: ' . MIME_BY_EXT[$ext]);
clearstatcache();
header('Content-Length: ' . filesize($real));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($real);
