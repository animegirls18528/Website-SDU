<?php
require_once '../config/auth.php';
requireAdmin();
require_once '../config/database.php';
header('Content-Type: application/json');

try {
    $response = [];

    // Documents uploaded this week (last 7 days)
    $stmt = $pdo->query("
        SELECT DATE(CreatedAt) as Date, COUNT(*) as Count 
        FROM documents 
        WHERE CreatedAt >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
        GROUP BY DATE(CreatedAt) 
        ORDER BY Date
    ");
    $response['docsThisWeek'] = $stmt->fetchAll();

    // Document proportion by Folder
    $stmt = $pdo->query("
        SELECT COALESCE(f.FolderName, 'ไม่มีแฟ้มจัดเก็บ') as FolderName, COUNT(d.ID) as Count 
        FROM documents d 
        LEFT JOIN folders f ON d.FolderId = f.ID 
        GROUP BY d.FolderId
    ");
    $response['docsByFolder'] = $stmt->fetchAll();
    
    // Active Users (count of users who made an action in last 30 days)
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT UserID) as ActiveUsers 
        FROM audit_logs 
        WHERE CreatedAt >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $active = $stmt->fetch();
    $response['activeUsers'] = $active ? (int)$active['ActiveUsers'] : 0;
    
    // Total Documents
    $stmt = $pdo->query("SELECT COUNT(*) as TotalDocs FROM documents");
    $total = $stmt->fetch();
    $response['totalDocs'] = $total ? (int)$total['TotalDocs'] : 0;

    // Recent Audit Logs
    $stmt = $pdo->query("
        SELECT a.ID, a.Action, a.Details, a.IPAddress, a.CreatedAt, COALESCE(u.Username, 'System') as Username 
        FROM audit_logs a 
        LEFT JOIN users u ON a.UserID = u.ID 
        ORDER BY a.CreatedAt DESC 
        LIMIT 50
    ");
    $response['recentLogs'] = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $response]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
