<?php
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="document_report_' . date('Ymd_His') . '.csv"');

$output = fopen('php://output', 'w');
// Add UTF-8 BOM for Excel compatibility with Thai characters
fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Header
fputcsv($output, ['รหัสเอกสาร (ID)', 'ชื่อเอกสาร/เรื่อง (Filename)', 'แฟ้มจัดเก็บ (Folder)', 'วันที่จ่ายเงิน (Payment Date)', 'สถานะ (Status)', 'วันที่นำเข้าระบบ (Created At)']);

$stmt = $pdo->query("
    SELECT d.ID, d.Filename, COALESCE(f.FolderName, 'ไม่มีแฟ้ม') as FolderName, d.PaymentDate, d.Status, d.CreatedAt 
    FROM documents d 
    LEFT JOIN folders f ON d.FolderId = f.ID 
    ORDER BY d.CreatedAt DESC
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['ID'],
        $row['Filename'],
        $row['FolderName'],
        $row['PaymentDate'],
        $row['Status'],
        $row['CreatedAt']
    ]);
}
fclose($output);
?>
