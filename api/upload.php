<?php
// Receives a file into staging (uploads/YYYY/MM/). It becomes part of a document when the document is saved.
require_once '../config/auth.php';
requireEditor();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../config/storage.php';

$allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff'];
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/tiff'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonFail(405, 'Invalid request method.');
}

$context = in_array($_POST['context'] ?? '', ['upload', 'scan', 'edit'], true) ? $_POST['context'] : 'upload';
$limitMb = round(uploadLimitBytes() / 1048576);

// Files still waiting on a screen (restored after a page reload)
if (($_POST['action'] ?? '') === 'listStaged') {
    echo json_encode(['success' => true, 'files' => stagedFilesFor($context)]);
    exit;
}

// Drop a staged file that the user removed or replaced (e.g. after editing its pages)
if (($_POST['action'] ?? '') === 'discard') {
    $rel = normalizeFileRef($_POST['fileId'] ?? '');
    if ($rel && isStaged($rel)) {
        @unlink(absPath($rel));
        unstageFile($rel);
    }
    echo json_encode(['success' => true]);
    exit;
}

// A request over post_max_size arrives with no fields and no files at all
if (empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > iniBytes(ini_get('post_max_size'))) {
    jsonFail(413, "ไฟล์มีขนาดใหญ่เกิน $limitMb MB ที่เซิร์ฟเวอร์รับได้");
}
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['file']['error'] ?? -1;
    $msg = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? "ไฟล์มีขนาดใหญ่เกิน $limitMb MB ที่เซิร์ฟเวอร์รับได้"
        : 'ไม่ได้รับไฟล์ หรืออัปโหลดผิดพลาด';
    jsonFail(400, $msg);
}

$file = $_FILES['file'];
if ($file['size'] > uploadLimitBytes()) {
    jsonFail(400, "ไฟล์มีขนาดเกิน $limitMb MB");
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($ext, $allowedExts, true) || !in_array($mime, $allowedMimes, true)) {
    jsonFail(400, 'ประเภทไฟล์ไม่ได้รับอนุญาต (รองรับ PDF, JPG, PNG, TIFF)');
}

$rel = newStoragePath('doc', $ext);
if (!move_uploaded_file($file['tmp_name'], absPath($rel))) {
    jsonFail(500, 'บันทึกไฟล์ไม่สำเร็จ');
}

stageFile($rel, $context);
$_SESSION['staged_names'][$rel] = mb_substr(basename($file['name']), 0, 255);

// Hash of the file as the user picked it (the browser may convert it to PDF first), for duplicate warnings
$sourceSha = preg_match('/^[a-f0-9]{64}$/', (string)($_POST['sourceSha256'] ?? '')) ? $_POST['sourceSha256'] : null;

// When this upload replaces an edited staged file, remove the old one
$replaces = normalizeFileRef($_POST['replaces'] ?? '');
if ($replaces && isStaged($replaces)) {
    $_SESSION['staged_names'][$rel] = $_SESSION['staged_names'][$replaces] ?? $_SESSION['staged_names'][$rel];
    $sourceSha = $sourceSha ?? ($_SESSION['staged_source_sha'][$replaces] ?? null);
    @unlink(absPath($replaces));
    unset($_SESSION['staged_source_sha'][$replaces]);
    unstageFile($replaces);
}
if ($sourceSha) $_SESSION['staged_source_sha'][$rel] = $sourceSha;

// Is the same file already in the archive?
$serverSha = hash_file('sha256', absPath($rel));
$stmt = $pdo->prepare("SELECT DISTINCT d.ID, d.Filename, d.FolderId FROM document_files f JOIN documents d ON d.ID = f.DocumentID
                       WHERE d.DeletedAt IS NULL AND (f.SourceSha256 IN (?, ?) OR f.OriginalSha256 IN (?, ?) OR f.Sha256 IN (?, ?)) LIMIT 5");
$stmt->execute([$sourceSha ?? $serverSha, $serverSha, $sourceSha ?? $serverSha, $serverSha, $sourceSha ?? $serverSha, $serverSha]);
$duplicates = [];
foreach ($stmt->fetchAll() as $d) {
    // Do not reveal titles of documents in folders this user cannot open
    $duplicates[] = canAccessFolder($pdo, $d['FolderId']) ? ['ID' => $d['ID'], 'Filename' => $d['Filename']] : ['ID' => null, 'Filename' => 'เอกสารในแฟ้มที่คุณไม่มีสิทธิ์เข้าถึง'];
}

addAuditLog($pdo, $_SESSION['user_id'] ?? null, "Upload File", "Uploaded file: " . basename($rel) . " ({$file['name']})");
echo json_encode([
    'success' => true,
    'fileId' => 'uploads/' . $rel,
    'name' => $_SESSION['staged_names'][$rel],
    'size' => filesize(absPath($rel)),
    'format' => $ext,
    'duplicates' => $duplicates,
]);
