<?php

function addAuditLog($pdo, $userId, $action, $details = '') {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $stmt = $pdo->prepare("INSERT INTO audit_logs (UserID, Action, Details, IPAddress) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $details, $ip]);
        return true;
    } catch (Exception $e) {
        error_log("Audit Log Error: " . $e->getMessage());
        return false;
    }
}
?>
