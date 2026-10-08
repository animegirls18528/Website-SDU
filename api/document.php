<?php
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once '../vendor/autoload.php';

use setasign\Fpdi\Fpdi;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

function stampQRCodeOnPdf($filepath, $docId) {
    if (!file_exists($filepath)) return;
    if (strtolower(pathinfo($filepath, PATHINFO_EXTENSION)) !== 'pdf') return;

    $baseUri = "http://" . $_SERVER['HTTP_HOST'] . str_replace('\\', '/', dirname(dirname($_SERVER['PHP_SELF'])));
    $qrUrl = $baseUri . "/verify.php?id=" . urlencode($docId);
    
    $options = new QROptions([
        'version'    => 5,
        'outputType' => QRCode::OUTPUT_IMAGE_PNG,
        'eccLevel'   => QRCode::ECC_L,
        'scale'      => 5,
    ]);
    
    $qrImage = (new QRCode($options))->render($qrUrl);
    
    $tmpQr = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
    file_put_contents($tmpQr, base64_decode(explode(',', $qrImage)[1]));

    try {
        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($filepath);
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);
            
            if ($size['width'] > $size['height']) {
                $pdf->AddPage('L', [$size['width'], $size['height']]);
            } else {
                $pdf->AddPage('P', [$size['width'], $size['height']]);
            }
            
            $pdf->useTemplate($templateId);
            
            $qrSize = 25; 
            $x = $size['width'] - $qrSize - 10; 
            $y = $size['height'] - $qrSize - 10; 
            
            $pdf->Image($tmpQr, $x, $y, $qrSize, $qrSize, 'PNG');
            
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetXY($x, $y + $qrSize + 1);
            $pdf->Cell($qrSize, 4, $docId, 0, 0, 'C');
        }
        $pdf->Output($filepath, 'F');
    } catch (Exception $e) {
        error_log("Failed to stamp PDF: " . $e->getMessage());
    }
    
    @unlink($tmpQr);
}


header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input && isset($_POST['action'])) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'createDocument':
        case 'updateDocumentMetadata':
            // Logic for inserting or updating a document
            $docId = $input['ID'] ?? $input['newId']; // newId when updating ID
            $oldId = $input['oldId'] ?? $docId;
            $filename = $input['Filename'];
            $folderId = $input['FolderId'] ?? null;
            $paymentDate = $input['PaymentDate'] ?: null;
            
            // Calculate ExpiryDate (10 years)
            $expiryDate = null;
            if ($paymentDate) {
                $expiryDate = date('Y-m-d', strtotime('+10 years', strtotime($paymentDate)));
            }
            $status = 'Active';
            if ($expiryDate && strtotime($expiryDate) < time()) {
                $status = 'Expired';
            }

            $customData = isset($input['CustomData']) ? json_encode($input['CustomData']) : '{}';
            
            // Handle file merging/updating
            $fileId = '';
            if (isset($input['newFileId']) && $input['newFileId']) {
                if (isset($input['appendFiles']) && $input['appendFiles'] === true) {
                    $fileId = $input['oldFileId'] ? $input['oldFileId'] . ',' . $input['newFileId'] : $input['newFileId'];
                } else {
                    // overwrite
                    $fileId = $input['newFileId'];
                    // TODO: Could delete old file from server here
                }
            } else {
                $fileId = $input['oldFileId'] ?? '';
            }

            // Check if exists
            $stmt = $pdo->prepare("SELECT ID FROM documents WHERE ID = ?");
            $stmt->execute([$oldId]);
            $exists = $stmt->fetch();

            if ($exists) {
                // Update
                $stmt = $pdo->prepare("UPDATE documents SET ID=?, Filename=?, FolderId=COALESCE(?, FolderId), PaymentDate=?, ExpiryDate=?, Status=?, FileId=?, CustomData=? WHERE ID=?");
                $stmt->execute([$docId, $filename, $folderId, $paymentDate, $expiryDate, $status, $fileId, $customData, $oldId]);
                addAuditLog($pdo, $_SESSION['user_id'] ?? null, "Update Document", "Updated document ID: $oldId to $docId, Name: $filename");
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO documents (ID, Filename, FolderId, PaymentDate, ExpiryDate, Status, FileId, CustomData) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$docId, $filename, $folderId, $paymentDate, $expiryDate, $status, $fileId, $customData]);
                addAuditLog($pdo, $_SESSION['user_id'] ?? null, "Create Document", "Created document ID: $docId, Name: $filename");
            }
            
            // Stamp QR Code if there are new files
            if (isset($input['newFileId']) && $input['newFileId']) {
                $newFiles = explode(',', $input['newFileId']);
                foreach ($newFiles as $f) {
                    $safeFilename = basename(trim($f));
                    $filepath = '../uploads/' . $safeFilename;
                    stampQRCodeOnPdf($filepath, $docId);
                }
            }

            $stmt = $pdo->prepare("SELECT * FROM documents WHERE ID = ?");
            $stmt->execute([$docId]);
            $updatedDoc = $stmt->fetch();
            $updatedDoc['CustomData'] = json_decode($updatedDoc['CustomData'], true);

            echo json_encode(['success' => true, 'updatedDoc' => $updatedDoc]);
            break;

        case 'deleteDocument':
            if (!isAdmin()) jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้นที่ลบเอกสารได้');
            $docId = $input['ID'];
            // Get file paths to delete physical files
            $stmt = $pdo->prepare("SELECT FileId FROM documents WHERE ID = ?");
            $stmt->execute([$docId]);
            $doc = $stmt->fetch();
            if ($doc && $doc['FileId']) {
                $files = explode(',', $doc['FileId']);
                foreach ($files as $file) {
                    $safeFilename = basename(trim($file));
                    $filepath = '../uploads/' . $safeFilename;
                    if (file_exists($filepath) && is_file($filepath)) {
                        unlink($filepath);
                    }
                }
            }

            $stmt = $pdo->prepare("DELETE FROM documents WHERE ID = ?");
            $stmt->execute([$docId]);
            addAuditLog($pdo, $_SESSION['user_id'] ?? null, "Delete Document", "Deleted document ID: $docId");
            echo json_encode(['success' => true]);
            break;

        case 'getNextId':
            $prefix = $input['Prefix'] ?? '';
            if (empty($prefix)) {
                echo json_encode(['success' => false, 'message' => 'Prefix is required']);
                exit;
            }
            
            $stmt = $pdo->prepare("SELECT ID FROM documents WHERE ID LIKE ? ORDER BY LENGTH(ID) DESC, ID DESC LIMIT 1");
            $stmt->execute([$prefix . '%']);
            $lastDoc = $stmt->fetch();
            
            $nextNumber = 1;
            if ($lastDoc) {
                $lastId = $lastDoc['ID'];
                $numericPart = substr($lastId, strlen($prefix));
                if (is_numeric($numericPart)) {
                    $nextNumber = intval($numericPart) + 1;
                }
            }
            
            $nextIdStr = sprintf("%06d", $nextNumber);
            echo json_encode(['success' => true, 'nextNumber' => $nextIdStr]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
