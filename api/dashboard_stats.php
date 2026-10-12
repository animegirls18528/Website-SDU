<?php
require_once '../config/auth.php';
requireAdmin();
require_once '../config/database.php';
require_once '../config/helpers.php';
header('Content-Type: application/json');

try {
    $response = [];
    $warnDays = max(1, (int)getSetting($pdo, 'expiry_warning_days', '90'));

    // Documents added in the last 7 days, one row per day (including empty days)
    $stmt = $pdo->query("SELECT DATE(CreatedAt) AS Date, COUNT(*) AS Count FROM documents
                         WHERE CreatedAt >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND DeletedAt IS NULL GROUP BY DATE(CreatedAt)");
    $counts = array_column($stmt->fetchAll(), 'Count', 'Date');
    $response['docsThisWeek'] = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $response['docsThisWeek'][] = ['Date' => $day, 'Count' => (int)($counts[$day] ?? 0)];
    }

    // Largest folders; the rest are grouped as "อื่นๆ" so the chart stays readable
    $rows = $pdo->query("SELECT COALESCE(f.FolderName, 'ไม่มีแฟ้มจัดเก็บ') AS FolderName, COUNT(*) AS Count
                         FROM documents d LEFT JOIN folders f ON d.FolderId = f.ID
                         WHERE d.DeletedAt IS NULL GROUP BY d.FolderId ORDER BY Count DESC")->fetchAll();
    $top = array_slice($rows, 0, 7);
    $rest = array_sum(array_column(array_slice($rows, 7), 'Count'));
    if ($rest > 0) $top[] = ['FolderName' => 'อื่นๆ (' . (count($rows) - 7) . ' แฟ้ม)', 'Count' => $rest];
    $response['docsByFolder'] = $top;

    $one = fn($sql, ...$p) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();

    $response['activeUsers'] = $one("SELECT COUNT(DISTINCT UserID) FROM audit_logs WHERE CreatedAt >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $response['totalDocs'] = $one("SELECT COUNT(*) FROM documents WHERE DeletedAt IS NULL");
    $response['expiringSoon'] = $one("SELECT COUNT(*) FROM documents WHERE DeletedAt IS NULL AND ExpiryDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)", $warnDays);
    $response['expired'] = $one("SELECT COUNT(*) FROM documents WHERE DeletedAt IS NULL AND ExpiryDate < CURDATE()");
    $response['pendingReview'] = $one("SELECT COUNT(*) FROM documents WHERE DeletedAt IS NULL AND WorkflowStatus = 'PendingReview'");
    $response['destructionRequests'] = $one("SELECT COUNT(*) FROM documents WHERE DeletedAt IS NULL AND DestructionRequestedAt IS NOT NULL");
    $response['inTrash'] = $one("SELECT COUNT(*) FROM documents WHERE DeletedAt IS NOT NULL");
    $response['storageBytes'] = (int)$pdo->query("SELECT COALESCE(SUM(SizeBytes), 0) FROM document_files")->fetchColumn();
    $response['expiryWarningDays'] = $warnDays;

    $stmt = $pdo->query("
        SELECT a.ID, a.Action, a.Details, a.IPAddress, a.CreatedAt, a.DocumentID, COALESCE(u.Username, 'System') as Username
        FROM audit_logs a
        LEFT JOIN users u ON a.UserID = u.ID
        ORDER BY a.ID DESC
        LIMIT 300
    ");
    $response['recentLogs'] = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $response]);
} catch (Exception $e) {
    error_log('dashboard_stats.php: ' . $e->getMessage());
    jsonFail(500, 'โหลดสถิติไม่สำเร็จ');
}
