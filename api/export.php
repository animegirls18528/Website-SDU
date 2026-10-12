<?php
// CSV export of the documents matching the current search filters (same filters as api/search.php).
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/search.php';

[$from, $where, $params] = buildDocumentFilter($pdo, $_GET);
$order = documentOrderSql($_GET['sort'] ?? '');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="document_report_' . date('Ymd_His') . '.csv"');

$output = fopen('php://output', 'w');
// Add UTF-8 BOM for Excel compatibility with Thai characters
fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, ['รหัสเอกสาร (ID)', 'ชื่อเอกสาร/เรื่อง (Filename)', 'แฟ้มจัดเก็บ (Folder)', 'วันที่จ่ายเงิน (Payment Date)',
                  'วันครบอายุ (Expiry Date)', 'สถานะ (Status)', 'ขั้นตอน (Workflow)', 'แท็ก (Tags)', 'ดัชนีเพิ่มเติม', 'วันที่นำเข้าระบบ (Created At)']);

$workflow = ['Draft' => 'ฉบับร่าง', 'PendingReview' => 'รอตรวจสอบ', 'Approved' => 'อนุมัติแล้ว'];
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$stmt = $pdo->prepare("
    SELECT d.ID, d.Filename, COALESCE(f.FolderName, 'ไม่มีแฟ้ม') AS FolderName, d.PaymentDate, d.ExpiryDate,
           CASE WHEN d.ExpiryDate < CURDATE() THEN 'หมดอายุ' ELSE 'ปกติ' END AS Status,
           d.WorkflowStatus, d.Tags, d.CustomData, d.CreatedAt
    $from
    LEFT JOIN folders f ON d.FolderId = f.ID
    WHERE $where ORDER BY $order");
$stmt->execute($params);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $custom = json_decode($row['CustomData'] ?: '{}', true) ?: [];
    unset($custom['LegacyID']);
    $customText = implode('; ', array_map(fn($k, $v) => "$k: " . (is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)), array_keys($custom), $custom));
    fputcsv($output, [
        $row['ID'], $row['Filename'], $row['FolderName'], $row['PaymentDate'], $row['ExpiryDate'], $row['Status'],
        $workflow[$row['WorkflowStatus']] ?? $row['WorkflowStatus'], $row['Tags'], $customText, $row['CreatedAt'],
    ]);
}
fclose($output);
