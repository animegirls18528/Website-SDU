<?php
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';

header('Content-Type: application/json');

try {
    $response = [
        'userEmail' => $_SESSION['username'] ?? '',
        'currentUser' => ['id' => (int)$_SESSION['user_id'], 'username' => $_SESSION['username'], 'role' => $_SESSION['role']],
        'folders' => [],
        'customFields' => [],
        'prefixes' => [],
    ];

    // Fetch Folders
    $stmt = $pdo->query("SELECT ID, FolderName, Description FROM folders");
    $response['folders'] = $stmt->fetchAll();

    // Fetch Custom Fields
    $stmt = $pdo->query("SELECT FieldName, FieldType, IsRequired FROM custom_fields");
    $response['customFields'] = $stmt->fetchAll();

    // Fetch Prefixes
    $stmt = $pdo->query("SELECT PrefixValue, PrefixName FROM prefixes");
    $response['prefixes'] = $stmt->fetchAll();

    // Fetch Documents
    $stmt = $pdo->query("SELECT ID, Filename, FolderId, PaymentDate, ExpiryDate, Status, FileId, CustomData, CreatedAt FROM documents ORDER BY CreatedAt DESC");
    $docs = $stmt->fetchAll();
    
    // Parse JSON for CustomData
    foreach ($docs as &$doc) {
        if ($doc['CustomData']) {
            $doc['CustomData'] = json_decode($doc['CustomData'], true);
        } else {
            $doc['CustomData'] = new stdClass(); // Empty object
        }
    }
    $response['documents'] = $docs;

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
