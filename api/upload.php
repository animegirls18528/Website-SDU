<?php
require_once '../config/auth.php';
requireLogin();
header('Content-Type: application/json');

// Include composer autoload if exists
if (file_exists('../vendor/autoload.php')) {
    require_once '../vendor/autoload.php';
}
require_once '../config/database.php';
require_once '../config/helpers.php';
use setasign\Fpdi\Fpdi;

$uploadDir = '../uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Allowed extensions and MIME types
$allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff'];
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/tiff'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['file'];
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($ext, $allowedExts) || !in_array($mime, $allowedMimes)) {
            echo json_encode(['success' => false, 'message' => 'ประเภทไฟล์ไม่ได้รับอนุญาต']);
            exit;
        }

        // Generate a safe unique filename
        $filename = uniqid('doc_') . '_' . time() . '.' . $ext;
        $destination = $uploadDir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            // Process PDF pages deletion if applicable
            if ($ext === 'pdf' && isset($_POST['pagesToDelete']) && trim($_POST['pagesToDelete']) !== '') {
                try {
                    $pdf = new Fpdi();
                    $pageCount = $pdf->setSourceFile($destination);
                    $pagesToDeleteRaw = explode(',', $_POST['pagesToDelete']);
                    $pagesToDelete = array_map('intval', $pagesToDeleteRaw);
                    
                    for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                        if (!in_array($pageNo, $pagesToDelete)) {
                            $templateId = $pdf->importPage($pageNo);
                            $size = $pdf->getTemplateSize($templateId);
                            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                            $pdf->useTemplate($templateId);
                        }
                    }
                    // Overwrite the original uploaded file
                    $pdf->Output('F', $destination);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Error processing PDF (FPDI): ' . $e->getMessage()]);
                    exit;
                }
            }

            // Return relative path to be used as ID/URL
            addAuditLog($pdo, $_SESSION['user_id'] ?? null, "Upload File", "Uploaded file: $filename");
            echo json_encode(['success' => true, 'fileId' => 'uploads/' . $filename]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
