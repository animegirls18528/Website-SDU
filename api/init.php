<?php
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/helpers.php';

header('Content-Type: application/json');

try {
    runDailyMaintenance($pdo);

    $response = [
        'userEmail' => $_SESSION['username'] ?? '',
        'currentUser' => [
            'id' => (int)$_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role'],
            'canEdit' => canEdit(),
        ],
        'settings' => [
            'expiryWarningDays' => (int)getSetting($pdo, 'expiry_warning_days', '90'),
            'retentionYearsDefault' => (int)getSetting($pdo, 'retention_years_default', '10'),
            'trashRetentionDays' => (int)getSetting($pdo, 'trash_retention_days', '30'),
            'uploadLimitBytes' => uploadLimitBytes(),
        ],
    ];

    // Only the folders this user may see, with document counts
    $params = [];
    $scope = str_replace('d.FolderId', 'f.ID', folderScopeSql($pdo, $params));
    $stmt = $pdo->prepare("SELECT f.ID, f.FolderName, f.Description, f.SortOrder,
                                  (SELECT COUNT(*) FROM documents d WHERE d.FolderId = f.ID AND d.DeletedAt IS NULL) AS DocCount
                           FROM folders f WHERE $scope
                           ORDER BY f.SortOrder IS NULL, f.SortOrder, f.FolderName");
    $stmt->execute($params);
    $response['folders'] = $stmt->fetchAll();

    $response['customFields'] = $pdo->query("SELECT FieldName, FieldType, IsRequired FROM custom_fields")->fetchAll();
    $response['prefixes'] = $pdo->query("SELECT PrefixValue, PrefixName, RetentionYears FROM prefixes ORDER BY PrefixValue")->fetchAll();

    echo json_encode($response);
} catch (Exception $e) {
    error_log('init.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'โหลดข้อมูลเริ่มต้นไม่สำเร็จ']);
}
?>
