<?php
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input && isset($_POST['action'])) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

// Everyone may create folders (used from the dashboard); every other setting is admin-only.
if ($action !== 'addFolder' && !isAdmin()) {
    jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
}

try {
    switch ($action) {
        case 'addFolder':
            $stmt = $pdo->prepare("INSERT INTO folders (FolderName, Description) VALUES (?, ?)");
            $stmt->execute([$input['FolderName'], $input['Description']]);
            $id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'ID' => $id, 'FolderName' => $input['FolderName'], 'Description' => $input['Description']]);
            break;
            
        case 'deleteFolder':
            $stmt = $pdo->prepare("DELETE FROM folders WHERE ID = ?");
            $stmt->execute([$input['ID']]);
            echo json_encode(['success' => true]);
            break;

        case 'addPrefix':
            $stmt = $pdo->prepare("INSERT INTO prefixes (PrefixValue, PrefixName) VALUES (?, ?)");
            $stmt->execute([$input['PrefixValue'], $input['PrefixName']]);
            echo json_encode(['success' => true, 'PrefixValue' => $input['PrefixValue'], 'PrefixName' => $input['PrefixName']]);
            break;

        case 'deletePrefix':
            $stmt = $pdo->prepare("DELETE FROM prefixes WHERE PrefixValue = ?");
            $stmt->execute([$input['PrefixValue']]);
            echo json_encode(['success' => true]);
            break;

        case 'addField':
            $stmt = $pdo->prepare("INSERT INTO custom_fields (FieldName, FieldType, IsRequired) VALUES (?, ?, ?)");
            $stmt->execute([$input['FieldName'], $input['FieldType'], $input['IsRequired']]);
            echo json_encode(['success' => true, 'FieldName' => $input['FieldName'], 'FieldType' => $input['FieldType'], 'IsRequired' => $input['IsRequired']]);
            break;

        case 'deleteField':
            $stmt = $pdo->prepare("DELETE FROM custom_fields WHERE FieldName = ?");
            $stmt->execute([$input['FieldName']]);
            echo json_encode(['success' => true]);
            break;

        case 'updateLineToken':
            $token = $input['Token'] ?? '';
            $stmt = $pdo->prepare("INSERT INTO system_settings (SettingKey, SettingValue) VALUES ('line_notify_token', ?) ON DUPLICATE KEY UPDATE SettingValue = ?");
            $stmt->execute([$token, $token]);
            
            require_once '../config/helpers.php';
            addAuditLog($pdo, $_SESSION['user_id'] ?? null, "Update Settings", "Updated LINE Notify Token");
            
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
