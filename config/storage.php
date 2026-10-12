<?php
/**
 * File storage for documents.
 * Files live under uploads/YYYY/MM/. For PDFs the untouched original is kept next to the QR-stamped copy
 * (<name>_orig.pdf) and both SHA-256 hashes are recorded in document_files.
 */
require_once __DIR__ . '/helpers.php';

use setasign\Fpdi\Fpdi;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

const FILE_NAME_PATTERN = '#^(\d{4}/\d{2}/)?(doc|scan)_[A-Za-z0-9_]+\.(pdf|jpe?g|png|tiff?)$#';
const MIME_BY_EXT = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'tif' => 'image/tiff', 'tiff' => 'image/tiff'];

function uploadsDir() {
    return UPLOAD_DIR;
}

/** 'uploads/2026/10/x.pdf' or '2026/10/x.pdf' -> '2026/10/x.pdf'; null when malformed. */
function normalizeFileRef($ref) {
    $rel = preg_replace('#^uploads/#', '', str_replace('\\', '/', trim((string)$ref)));
    return preg_match(FILE_NAME_PATTERN, $rel) ? $rel : null;
}

function absPath($rel) {
    return uploadsDir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

/** New relative path in this month's folder, e.g. 2026/10/doc_65f1c2_1760000000.pdf */
function newStoragePath($kind, $ext) {
    $dir = date('Y') . '/' . date('m');
    $abs = uploadsDir() . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');
    if (!is_dir($abs)) mkdir($abs, 0755, true);
    return $dir . '/' . $kind . '_' . bin2hex(random_bytes(6)) . '_' . time() . '.' . strtolower($ext);
}

function originalPathFor($rel) {
    return preg_replace('#\.(\w+)$#', '_orig.$1', $rel);
}

// ---------- staging (uploaded/scanned but not yet attached) ----------

function stageFile($rel, $context = 'upload') {
    $_SESSION['staged_files'][$rel] = time();
    $_SESSION['staged_ctx'][$rel] = $context;
}

/** Files staged in this session for a screen (upload/scan) that still exist, so a page reload does not lose them. */
function stagedFilesFor($context) {
    $out = [];
    foreach ($_SESSION['staged_files'] ?? [] as $rel => $t) {
        if (($_SESSION['staged_ctx'][$rel] ?? 'upload') !== $context) continue;
        if (!is_file(absPath($rel))) { unstageFile($rel); continue; }
        $out[] = ['fileId' => 'uploads/' . $rel, 'name' => $_SESSION['staged_names'][$rel] ?? basename($rel),
                  'size' => filesize(absPath($rel)), 'format' => strtolower(pathinfo($rel, PATHINFO_EXTENSION))];
    }
    return $out;
}

function isStaged($rel) {
    return isset($_SESSION['staged_files'][$rel]);
}

function unstageFile($rel) {
    unset($_SESSION['staged_files'][$rel], $_SESSION['staged_ctx'][$rel], $_SESSION['staged_names'][$rel]);
}

// ---------- PDF helpers ----------

function pdfPageCount($abs) {
    try {
        $pdf = new Fpdi();
        return $pdf->setSourceFile($abs);
    } catch (Throwable $e) {
        // FPDI's free parser can't read compressed xref streams; fall back to a rough count
        $data = @file_get_contents($abs);
        if ($data === false) return null;
        $n = preg_match_all('#/Type\s*/Page[^s]#', $data);
        return $n ?: null;
    }
}

function publicBaseUrl($pdo) {
    $base = rtrim(getSetting($pdo, 'public_base_url', ''), '/');
    if ($base !== '') return $base;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $appPath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php'))), '/');
    return $scheme . '://' . $host . $appPath;
}

function ensureVerifyToken($pdo, $docId) {
    $stmt = $pdo->prepare("SELECT VerifyToken FROM documents WHERE ID = ?");
    $stmt->execute([$docId]);
    $token = $stmt->fetchColumn();
    if ($token) return $token;
    $token = bin2hex(random_bytes(16));
    $pdo->prepare("UPDATE documents SET VerifyToken = ? WHERE ID = ?")->execute([$token, $docId]);
    return $token;
}

/** Write $dest = $src with a verification QR code in the bottom-right corner of every page. */
function stampQrCode($src, $dest, $url, $label) {
    $options = new QROptions([
        'outputType' => QRCode::OUTPUT_IMAGE_PNG,
        'eccLevel'   => QRCode::ECC_M,
        'scale'      => 5,
        'outputBase64' => false,
    ]);
    $tmpQr = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
    file_put_contents($tmpQr, (new QRCode($options))->render($url));

    try {
        $pdf = new Fpdi();
        // The label under the QR sits inside FPDF's default bottom margin; without this every page gets a blank page after it
        $pdf->SetAutoPageBreak(false);
        $pageCount = $pdf->setSourceFile($src);
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);
            $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            $qrSize = 22;
            $x = $size['width'] - $qrSize - 8;
            $y = $size['height'] - $qrSize - 10;
            $pdf->Image($tmpQr, $x, $y, $qrSize, $qrSize, 'PNG');
            $pdf->SetFont('Arial', '', 7);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetXY($x - 4, $y + $qrSize + 0.5);
            $pdf->Cell($qrSize + 8, 3.5, $label, 0, 0, 'C');
        }
        $pdf->Output('F', $dest);
        return true;
    } catch (Throwable $e) {
        error_log("QR stamp failed for $src: " . $e->getMessage());
        return false;
    } finally {
        @unlink($tmpQr);
    }
}

// ---------- attaching files to documents ----------

/**
 * Attach staged files to a document. $mode 'append' adds files; 'replace' keeps the current files as an older
 * version and makes these the current set. Returns ['attached' => n, 'stampFailed' => [names]].
 */
function attachFiles($pdo, $docId, array $rels, $mode, $userId) {
    $result = ['attached' => 0, 'stampFailed' => []];
    if (!$rels) return $result;

    if ($mode === 'replace') {
        $pdo->prepare("UPDATE document_files SET IsCurrent = 0, ReplacedAt = NOW() WHERE DocumentID = ? AND IsCurrent = 1")
            ->execute([$docId]);
    }
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(Version), 0) FROM document_files WHERE DocumentID = ?");
    $stmt->execute([$docId]);
    $maxVersion = (int)$stmt->fetchColumn();
    $version = $mode === 'replace' ? $maxVersion + 1 : max(1, $maxVersion);

    $token = ensureVerifyToken($pdo, $docId);
    $verifyUrl = publicBaseUrl($pdo) . '/verify.php?t=' . $token;

    $ins = $pdo->prepare("INSERT INTO document_files
        (DocumentID, StoredPath, OriginalPath, OriginalName, MimeType, SizeBytes, PageCount, Sha256, OriginalSha256, Version, UploadedBy)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($rels as $rel) {
        $abs = absPath($rel);
        if (!is_file($abs)) continue;
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $origSha = hash_file('sha256', $abs);
        $storedRel = $rel;
        $origRel = null;
        $pages = null;

        if ($ext === 'pdf') {
            $pages = pdfPageCount($abs);
            $origRel = originalPathFor($rel);
            rename($abs, absPath($origRel));
            if (!stampQrCode(absPath($origRel), $abs, $verifyUrl, $docId)) {
                copy(absPath($origRel), $abs);
                $result['stampFailed'][] = basename($rel);
            }
        }

        $ins->execute([
            $docId, $storedRel, $origRel, $_SESSION['staged_names'][$rel] ?? basename($rel),
            MIME_BY_EXT[$ext] ?? 'application/octet-stream', filesize($abs), $pages,
            hash_file('sha256', $abs), $origSha, $version, $userId,
        ]);
        unstageFile($rel);
        $result['attached']++;
    }
    syncFileIdColumn($pdo, $docId);
    return $result;
}

/** Keep the legacy comma-separated documents.FileId column in step with document_files. */
function syncFileIdColumn($pdo, $docId) {
    $stmt = $pdo->prepare("SELECT StoredPath FROM document_files WHERE DocumentID = ? AND IsCurrent = 1 ORDER BY ID");
    $stmt->execute([$docId]);
    $paths = array_map(fn($p) => 'uploads/' . $p, $stmt->fetchAll(PDO::FETCH_COLUMN));
    $pdo->prepare("UPDATE documents SET FileId = ? WHERE ID = ?")->execute([implode(',', $paths), $docId]);
}

function documentFiles($pdo, $docId, $currentOnly = true) {
    $sql = "SELECT f.ID, f.StoredPath, f.OriginalPath, f.OriginalName, f.MimeType, f.SizeBytes, f.PageCount, f.Sha256,
                   f.Version, f.IsCurrent, f.CreatedAt, f.ReplacedAt, u.Username AS UploadedBy
            FROM document_files f LEFT JOIN users u ON u.ID = f.UploadedBy
            WHERE f.DocumentID = ?" . ($currentOnly ? " AND f.IsCurrent = 1" : "") . " ORDER BY f.Version DESC, f.ID";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$docId]);
    return $stmt->fetchAll();
}

// ---------- text extraction / OCR ----------

function extractText($abs) {
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        if (filesize($abs) > 60 * 1024 * 1024) return '';
        try {
            $parser = new \Smalot\PdfParser\Parser();
            return trim($parser->parseFile($abs)->getText());
        } catch (Throwable $e) {
            error_log("Text extraction failed for $abs: " . $e->getMessage());
            return '';
        }
    }
    if (TESSERACT_PATH !== '' && is_file(TESSERACT_PATH) && in_array($ext, ['png', 'jpg', 'jpeg', 'tif', 'tiff'], true)) {
        $out = [];
        exec('"' . TESSERACT_PATH . '" ' . escapeshellarg($abs) . ' stdout -l ' . escapeshellarg(OCR_LANG) . ' 2>NUL', $out);
        return trim(implode("\n", $out));
    }
    return '';
}

function tesseractAvailable() {
    return TESSERACT_PATH !== '' && is_file(TESSERACT_PATH);
}

/** Rebuild documents.OcrText from the document's current files. */
function indexDocumentText($pdo, $docId) {
    $text = [];
    foreach (documentFiles($pdo, $docId) as $f) {
        $src = $f['OriginalPath'] ?: $f['StoredPath'];
        $t = extractText(absPath($src));
        if ($t !== '') $text[] = $t;
    }
    $joined = mb_substr(preg_replace('/[ \t]+/u', ' ', implode("\n", $text)), 0, 1000000);
    $pdo->prepare("UPDATE documents SET OcrText = ? WHERE ID = ?")->execute([$joined !== '' ? $joined : null, $docId]);
    require_once __DIR__ . '/search.php';
    updateSearchIndex($pdo, $docId);
    return mb_strlen($joined);
}

// ---------- deletion ----------

/** Permanently delete a document, every version of its files and its originals. */
function purgeDocument($pdo, $docId) {
    $stmt = $pdo->prepare("SELECT StoredPath, OriginalPath FROM document_files WHERE DocumentID = ?");
    $stmt->execute([$docId]);
    $paths = [];
    foreach ($stmt->fetchAll() as $f) {
        $paths[] = $f['StoredPath'];
        if ($f['OriginalPath']) $paths[] = $f['OriginalPath'];
    }
    $stmt = $pdo->prepare("SELECT FileId FROM documents WHERE ID = ?");
    $stmt->execute([$docId]);
    foreach (explode(',', (string)$stmt->fetchColumn()) as $f) {
        if ($rel = normalizeFileRef($f)) $paths[] = $rel;
    }
    foreach (array_unique($paths) as $rel) {
        $abs = absPath($rel);
        if (is_file($abs)) unlink($abs);
    }
    $pdo->prepare("DELETE FROM documents WHERE ID = ?")->execute([$docId]);
}

// ---------- storage report ----------

/** Every file under uploads/ as relative path => [size, mtime]. */
function listUploadFiles() {
    $files = [];
    $root = uploadsDir();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getFilename() === '.htaccess' || $f->getFilename()[0] === '.') continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        $files[$rel] = ['size' => $f->getSize(), 'mtime' => $f->getMTime()];
    }
    return $files;
}

/** Files nobody references and that are older than a day (younger ones may still be staged). */
function findOrphanFiles($pdo) {
    $referenced = [];
    foreach ($pdo->query("SELECT StoredPath, OriginalPath FROM document_files") as $r) {
        $referenced[$r['StoredPath']] = true;
        if ($r['OriginalPath']) $referenced[$r['OriginalPath']] = true;
    }
    foreach ($pdo->query("SELECT FileId FROM documents WHERE FileId IS NOT NULL AND FileId <> ''") as $r) {
        foreach (explode(',', $r['FileId']) as $f) {
            $referenced[preg_replace('#^uploads/#', '', trim($f))] = true;
        }
    }
    foreach ($pdo->query("SELECT Files FROM scan_jobs WHERE CreatedAt > DATE_SUB(NOW(), INTERVAL 1 DAY)") as $r) {
        foreach (json_decode((string)$r['Files'], true) ?: [] as $f) $referenced[$f] = true;
    }
    $orphans = [];
    $cutoff = time() - 86400;
    foreach (listUploadFiles() as $rel => $info) {
        if (!isset($referenced[$rel]) && $info['mtime'] < $cutoff) {
            $orphans[] = ['path' => $rel, 'size' => $info['size'], 'modified' => date('Y-m-d H:i', $info['mtime'])];
        }
    }
    return $orphans;
}

/**
 * After a document ID change, stamp the current PDFs again so the label under the QR shows the new ID.
 * The previously stamped copy is kept as a non-current row so holders of old printouts still verify as "old copy".
 */
function restampCurrentFiles($pdo, $docId, $userId) {
    $token = ensureVerifyToken($pdo, $docId);
    $verifyUrl = publicBaseUrl($pdo) . '/verify.php?t=' . $token;
    $stmt = $pdo->prepare("SELECT * FROM document_files WHERE DocumentID = ? AND IsCurrent = 1 AND OriginalPath IS NOT NULL");
    $stmt->execute([$docId]);
    $retire = $pdo->prepare("UPDATE document_files SET IsCurrent = 0, ReplacedAt = NOW() WHERE ID = ?");
    $ins = $pdo->prepare("INSERT INTO document_files
        (DocumentID, StoredPath, OriginalPath, OriginalName, MimeType, SizeBytes, PageCount, Sha256, OriginalSha256, Version, UploadedBy)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($stmt->fetchAll() as $f) {
        if (!is_file(absPath($f['OriginalPath']))) continue;
        $newRel = newStoragePath('doc', 'pdf');
        if (!stampQrCode(absPath($f['OriginalPath']), absPath($newRel), $verifyUrl, $docId)) continue;
        $retire->execute([$f['ID']]);
        $ins->execute([$docId, $newRel, $f['OriginalPath'], $f['OriginalName'], $f['MimeType'], filesize(absPath($newRel)),
                       $f['PageCount'], hash_file('sha256', absPath($newRel)), $f['OriginalSha256'], $f['Version'], $userId]);
    }
    syncFileIdColumn($pdo, $docId);
}
