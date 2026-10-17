<?php
// Management reports: documents per fiscal year and type, scanning work per person, disk forecast, PDPA access log.
require_once '../config/auth.php';
requireAdmin();
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once '../config/storage.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    switch ($in['report'] ?? '') {
        case 'fiscal':
            // Thai fiscal year (Oct–Sep) from the payment date; type = the ID's letter prefix
            $rows = $pdo->query("SELECT CASE WHEN d.PaymentDate IS NULL THEN NULL ELSE YEAR(d.PaymentDate) + 543 + (MONTH(d.PaymentDate) >= 10) END AS FY,
                                        REGEXP_REPLACE(d.ID, '[0-9]+$', '') AS Prefix, COUNT(*) AS Docs,
                                        SUM(d.FileId IS NOT NULL AND d.FileId <> '') AS WithFiles
                                 FROM documents d WHERE d.DeletedAt IS NULL GROUP BY FY, Prefix ORDER BY FY IS NULL, FY DESC, Prefix")->fetchAll();
            $names = array_column($pdo->query("SELECT PrefixValue, PrefixName FROM prefixes")->fetchAll(), 'PrefixName', 'PrefixValue');
            foreach ($rows as &$r) {
                $r['PrefixName'] = $names[$r['Prefix']] ?? '';
                $r['Docs'] = (int)$r['Docs'];
                $r['WithFiles'] = (int)$r['WithFiles'];
            }
            echo json_encode(['success' => true, 'rows' => $rows]);
            break;

        case 'productivity':
            $months = max(1, min(24, (int)($in['months'] ?? 6)));
            $since = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
            $stmt = $pdo->prepare("SELECT u.Username, m.Month,
                                          SUM(m.Docs) AS Docs, SUM(m.Files) AS Files, SUM(m.Pages) AS Pages, SUM(m.Scans) AS Scans
                                   FROM (
                                     SELECT CreatedBy AS UserID, DATE_FORMAT(CreatedAt, '%Y-%m') AS Month, COUNT(*) AS Docs, 0 AS Files, 0 AS Pages, 0 AS Scans
                                       FROM documents WHERE CreatedAt >= ? AND CreatedBy IS NOT NULL GROUP BY UserID, Month
                                     UNION ALL
                                     SELECT UploadedBy, DATE_FORMAT(CreatedAt, '%Y-%m'), 0, COUNT(*), COALESCE(SUM(PageCount), 0), 0
                                       FROM document_files WHERE CreatedAt >= ? AND UploadedBy IS NOT NULL GROUP BY UploadedBy, DATE_FORMAT(CreatedAt, '%Y-%m')
                                     UNION ALL
                                     SELECT UserID, DATE_FORMAT(CreatedAt, '%Y-%m'), 0, 0, 0, COUNT(*)
                                       FROM scan_jobs WHERE CreatedAt >= ? AND Status = 'done' GROUP BY UserID, DATE_FORMAT(CreatedAt, '%Y-%m')
                                   ) m JOIN users u ON u.ID = m.UserID
                                   GROUP BY u.Username, m.Month ORDER BY m.Month DESC, Pages DESC");
            $stmt->execute([$since, $since, $since]);
            echo json_encode(['success' => true, 'rows' => $stmt->fetchAll(), 'since' => $since]);
            break;

        case 'disk':
            // Growth of stored files over the last 90 days → when will the disk be full at this rate?
            $rows = $pdo->query("SELECT DATE(CreatedAt) AS Day, SUM(SizeBytes) AS Bytes FROM document_files
                                 WHERE CreatedAt >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY Day ORDER BY Day")->fetchAll();
            $total90 = array_sum(array_column($rows, 'Bytes'));
            $first = $rows ? strtotime($rows[0]['Day']) : time();
            $days = max(1, (int)ceil((time() - $first) / 86400));
            $perDay = $total90 / $days;
            // Originals are stored next to the stamped copies, so real growth is higher than SizeBytes alone
            $onDisk = array_sum(array_column(listUploadFiles(), 'size'));
            $recorded = (int)$pdo->query("SELECT COALESCE(SUM(SizeBytes), 0) FROM document_files")->fetchColumn();
            $factor = $recorded > 0 ? max(1, $onDisk / $recorded) : 2;
            $free = (float)(@disk_free_space(uploadsDir()) ?: 0);
            $daysLeft = $perDay > 0 ? (int)floor($free / ($perDay * $factor)) : null;
            echo json_encode(['success' => true, 'daily' => $rows, 'perDayBytes' => $perDay * $factor, 'freeBytes' => $free,
                              'totalBytes' => (float)(@disk_total_space(uploadsDir()) ?: 0), 'filesBytes' => $onDisk,
                              'daysLeft' => $daysLeft, 'fullDate' => $daysLeft !== null && $daysLeft <= 36500 ? date('Y-m-d', strtotime("+$daysLeft days")) : null]);
            break;

        case 'pii':
            // Who opened documents that contain personal data (PDPA accountability)
            $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['from'] ?? '') ? $in['from'] : date('Y-m-d', strtotime('-30 days'));
            $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['to'] ?? '') ? $in['to'] : date('Y-m-d');
            $stmt = $pdo->prepare("SELECT a.CreatedAt, COALESCE(u.Username, 'ลิงก์ผู้ตรวจ') AS Username, a.Action, a.IPAddress,
                                          d.ID, d.Filename, d.PiiSummary
                                   FROM audit_logs a JOIN documents d ON d.ID = a.DocumentID LEFT JOIN users u ON u.ID = a.UserID
                                   WHERE d.PiiFlag > 0 AND a.Action IN ('View PII', 'View File', 'Download File', 'Share Access')
                                     AND a.CreatedAt >= ? AND a.CreatedAt < DATE_ADD(?, INTERVAL 1 DAY)
                                   ORDER BY a.ID DESC LIMIT 2000");
            $stmt->execute([$from, $to]);
            $rows = $stmt->fetchAll();
            $flagged = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE PiiFlag > 0 AND DeletedAt IS NULL")->fetchColumn();
            echo json_encode(['success' => true, 'rows' => $rows, 'from' => $from, 'to' => $to, 'flaggedDocs' => $flagged]);
            break;

        default:
            jsonFail(400, 'Invalid report');
    }
} catch (Exception $e) {
    error_log('reports.php: ' . $e->getMessage());
    jsonFail(500, 'สร้างรายงานไม่สำเร็จ');
}
