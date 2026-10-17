<?php
// In-app notifications (the bell): computed from current data, so nothing needs to be marked as read.
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/helpers.php';
header('Content-Type: application/json');

$me = (int)$_SESSION['user_id'];
$items = [];
$params = [];
$scope = folderScopeSql($pdo, $params);
$count = function ($sql, $extra = []) use ($pdo, $params) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($extra, $params));
    return (int)$stmt->fetchColumn();
};
$add = function ($n, $icon, $tone, $text, $link) use (&$items) {
    if ($n > 0) $items[] = ['count' => $n, 'icon' => $icon, 'tone' => $tone, 'text' => $text, 'link' => $link];
};

try {
    $warn = max(1, (int)getSetting($pdo, 'expiry_warning_days', '90'));

    if (isAdmin()) {
        $add($count("SELECT COUNT(*) FROM documents d WHERE d.DeletedAt IS NULL AND d.WorkflowStatus = 'PendingReview' AND $scope"),
            'pending_actions', 'purple', 'เอกสารรออนุมัติ', ['status' => 'pending']);
        $add($count("SELECT COUNT(*) FROM documents d WHERE d.DeletedAt IS NULL AND d.DestructionRequestedAt IS NOT NULL AND $scope"),
            'delete_sweep', 'danger', 'คำขอทำลายรออนุมัติ', ['status' => 'destruction']);
        $last = $pdo->query("SELECT State, Problems FROM integrity_runs WHERE Kind = 'integrity' AND State <> 'running' ORDER BY ID DESC LIMIT 1")->fetch();
        if ($last && $last['State'] === 'problems') $add((int)$last['Problems'], 'gpp_bad', 'danger', 'ไฟล์เสียหรือถูกแก้ไข (ผลตรวจล่าสุด)', ['view' => 'settings', 'tab' => 'storage']);
        $lastBackup = null;
        foreach (glob(BACKUP_DIR . DIRECTORY_SEPARATOR . 'edms_*', GLOB_ONLYDIR) ?: [] as $dir) $lastBackup = max($lastBackup, basename($dir));
        if (!$lastBackup) {
            $items[] = ['count' => 0, 'icon' => 'backup', 'tone' => 'warning', 'text' => 'ยังไม่เคยสำรองข้อมูล', 'link' => ['view' => 'settings', 'tab' => 'storage']];
        } elseif (strtotime(preg_replace('/^edms_(\d{4})(\d{2})(\d{2}).*/', '$1-$2-$3', $lastBackup)) < strtotime('-7 days')) {
            $items[] = ['count' => 0, 'icon' => 'backup', 'tone' => 'warning', 'text' => 'ไม่ได้สำรองข้อมูลเกิน 7 วัน', 'link' => ['view' => 'settings', 'tab' => 'storage']];
        }
        $need2fa = getSetting($pdo, 'require_admin_2fa', '0') === '1';
        $stmt = $pdo->prepare("SELECT TotpEnabled FROM users WHERE ID = ?");
        $stmt->execute([$me]);
        if (!(int)$stmt->fetchColumn()) {
            $items[] = ['count' => 0, 'icon' => 'phonelink_lock', 'tone' => $need2fa ? 'danger' : 'warning',
                        'text' => $need2fa ? 'ต้องเปิดการยืนยันตัวตนสองขั้นสำหรับผู้ดูแลระบบ' : 'แนะนำให้เปิดการยืนยันตัวตนสองขั้น', 'link' => ['view' => 'security']];
        }
    }

    if (canEdit()) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents d WHERE d.DeletedAt IS NULL AND d.WorkflowStatus = 'Draft' AND d.CreatedBy = ? AND $scope");
        $stmt->execute(array_merge([$me], $params));
        $add((int)$stmt->fetchColumn(), 'edit_note', 'muted', 'ฉบับร่างของฉันที่ยังไม่ส่งตรวจ', ['status' => 'draft']);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inbox_files WHERE UserID = ?");
        $stmt->execute([$me]);
        $add((int)$stmt->fetchColumn(), 'move_to_inbox', 'info', 'ไฟล์จากเครื่องสแกนอื่นรอจัดเก็บ', ['view' => 'scan']);
        $add($count("SELECT COUNT(*) FROM documents d WHERE d.DeletedAt IS NULL AND d.CoverPrintedAt IS NOT NULL AND (d.FileId IS NULL OR d.FileId = '') AND $scope"),
            'qr_code_2', 'info', 'พิมพ์ใบปะหน้าแล้วแต่ยังไม่ได้สแกน', ['status' => 'nofile']);
    }

    $add($count("SELECT COUNT(*) FROM documents d WHERE d.DeletedAt IS NULL AND d.ExpiryDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND $scope", [$warn]),
        'schedule', 'warning', "เอกสารครบอายุภายใน $warn วัน", ['status' => 'expiring']);
    $add($count("SELECT COUNT(*) FROM loans lo JOIN documents d ON d.ID = lo.DocumentID WHERE lo.ReturnedAt IS NULL AND lo.DueDate < CURDATE() AND $scope"),
        'assignment_late', 'danger', 'ยืมตัวจริงเกินกำหนดคืน', ['loan' => 'overdue']);

    $total = array_sum(array_map(fn($i) => max(1, $i['count']), array_filter($items, fn($i) => $i['tone'] !== 'muted')));
    echo json_encode(['success' => true, 'items' => $items, 'badge' => $total]);
} catch (Exception $e) {
    error_log('notifications.php: ' . $e->getMessage());
    jsonFail(500, 'โหลดการแจ้งเตือนไม่สำเร็จ');
}
