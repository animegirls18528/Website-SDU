<?php
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/helpers.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input && isset($_POST['action'])) {
    $input = $_POST;
}
$input = $input ?: [];
$action = $input['action'] ?? '';
$me = (int)$_SESSION['user_id'];

// Staff may create folders (used from the dashboard); every other setting is admin-only.
if ($action === 'addFolder') {
    if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
} elseif (!isAdmin()) {
    jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
}

function dirSizeBytes($dir) {
    $total = 0;
    if (!is_dir($dir)) return 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) $total += $f->getSize();
    }
    return $total;
}

try {
    switch ($action) {
        case 'addFolder':
            $name = trim((string)($input['FolderName'] ?? ''));
            if ($name === '') jsonFail(400, 'กรุณาระบุชื่อแฟ้ม');
            $stmt = $pdo->prepare("INSERT INTO folders (FolderName, Description) VALUES (?, ?)");
            $stmt->execute([$name, $input['Description'] ?? '']);
            $id = (int)$pdo->lastInsertId();
            // A user limited to certain folders automatically gets the folder they created
            $scope = $pdo->prepare("SELECT FolderScope FROM users WHERE ID = ?");
            $scope->execute([$me]);
            if ($scope->fetchColumn() === 'restricted') {
                $pdo->prepare("INSERT IGNORE INTO folder_permissions (UserID, FolderID) VALUES (?, ?)")->execute([$me, $id]);
            }
            addAuditLog($pdo, $me, 'Create Folder', "Created folder: $name");
            echo json_encode(['success' => true, 'ID' => $id, 'FolderName' => $name, 'Description' => $input['Description'] ?? '']);
            break;

        case 'updateFolder':
            $name = trim((string)($input['FolderName'] ?? ''));
            if ($name === '') jsonFail(400, 'กรุณาระบุชื่อแฟ้ม');
            $pdo->prepare("UPDATE folders SET FolderName = ?, Description = ? WHERE ID = ?")
                ->execute([$name, $input['Description'] ?? '', (int)$input['ID']]);
            addAuditLog($pdo, $me, 'Update Folder', "Renamed folder #{$input['ID']} to $name");
            echo json_encode(['success' => true]);
            break;

        case 'deleteFolder':
            $pdo->prepare("DELETE FROM folders WHERE ID = ?")->execute([(int)$input['ID']]);
            addAuditLog($pdo, $me, 'Delete Folder', "Deleted folder #{$input['ID']}");
            echo json_encode(['success' => true]);
            break;

        case 'deleteFolders':
            // Several folders at once; their documents stay and become "no folder" (FK ON DELETE SET NULL)
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($input['IDs'] ?? [])))));
            if (!$ids) jsonFail(400, 'กรุณาเลือกแฟ้มอย่างน้อย 1 แฟ้ม');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT ID, FolderName FROM folders WHERE ID IN ($in)");
            $stmt->execute($ids);
            $names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM folders WHERE ID IN ($in)")->execute($ids);
            $pdo->commit();
            addAuditLog($pdo, $me, 'Delete Folder', 'Deleted ' . count($names) . ' folder(s): ' . mb_substr(implode(', ', $names), 0, 1000));
            echo json_encode(['success' => true, 'deleted' => count($names)]);
            break;

        case 'addPrefix':
            $value = strtoupper(trim((string)($input['PrefixValue'] ?? '')));
            if (!preg_match('/^[A-Z0-9-]{1,20}$/', $value)) jsonFail(400, 'ตัวอักษรย่อใช้ได้เฉพาะ A-Z, 0-9 และ - (ไม่เกิน 20 ตัว)');
            $years = ($input['RetentionYears'] ?? '') !== '' ? max(1, (int)$input['RetentionYears']) : null;
            $stmt = $pdo->prepare("INSERT INTO prefixes (PrefixValue, PrefixName, RetentionYears) VALUES (?, ?, ?)");
            $stmt->execute([$value, $input['PrefixName'] ?? '', $years]);
            addAuditLog($pdo, $me, 'Update Settings', "Added prefix $value");
            echo json_encode(['success' => true, 'PrefixValue' => $value, 'PrefixName' => $input['PrefixName'] ?? '', 'RetentionYears' => $years]);
            break;

        case 'updatePrefix':
            $years = ($input['RetentionYears'] ?? '') !== '' ? max(1, (int)$input['RetentionYears']) : null;
            $pdo->prepare("UPDATE prefixes SET PrefixName = ?, RetentionYears = ? WHERE PrefixValue = ?")
                ->execute([$input['PrefixName'] ?? '', $years, $input['PrefixValue'] ?? '']);
            addAuditLog($pdo, $me, 'Update Settings', "Prefix {$input['PrefixValue']}: retention " . ($years ?? 'default') . " years");
            echo json_encode(['success' => true]);
            break;

        case 'deletePrefix':
            $pdo->prepare("DELETE FROM prefixes WHERE PrefixValue = ?")->execute([$input['PrefixValue'] ?? '']);
            addAuditLog($pdo, $me, 'Update Settings', "Deleted prefix {$input['PrefixValue']}");
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

        case 'getSystemSettings':
            require_once '../config/storage.php';
            require_once '../config/scanner.php';
            echo json_encode(['success' => true, 'settings' => [
                'public_base_url' => getSetting($pdo, 'public_base_url'),
                'retention_years_default' => getSetting($pdo, 'retention_years_default', '10'),
                'trash_retention_days' => getSetting($pdo, 'trash_retention_days', '30'),
                'expiry_warning_days' => getSetting($pdo, 'expiry_warning_days', '90'),
            ], 'environment' => [
                'detectedBaseUrl' => publicBaseUrl($pdo),
                'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'naps2' => file_exists(NAPS2_CONSOLE_PATH),
                'tesseract' => tesseractAvailable(),
                'phpCli' => is_file(PHP_CLI_PATH),
                'backupDir' => BACKUP_DIR,
            ]]);
            break;

        case 'saveSystemSettings':
            $url = trim((string)($input['public_base_url'] ?? ''));
            if ($url !== '' && !preg_match('#^https?://[^\s]+$#', $url)) jsonFail(400, 'URL ไม่ถูกต้อง (ต้องขึ้นต้นด้วย http:// หรือ https://)');
            setSetting($pdo, 'public_base_url', rtrim($url, '/'));
            setSetting($pdo, 'retention_years_default', max(1, min(100, (int)($input['retention_years_default'] ?? 10))));
            setSetting($pdo, 'trash_retention_days', max(0, min(3650, (int)($input['trash_retention_days'] ?? 30))));
            setSetting($pdo, 'expiry_warning_days', max(1, min(3650, (int)($input['expiry_warning_days'] ?? 90))));
            addAuditLog($pdo, $me, 'Update Settings', 'Updated system settings');
            echo json_encode(['success' => true]);
            break;

        case 'getPolicy':
            require_once '../config/docsign.php';
            $keys = ['download_roles', 'watermark_mode', 'pii_roles', 'require_admin_2fa', 'amount_field', 'department_field',
                     'org_name', 'org_name_en', 'sign_enabled'];
            $out = [];
            foreach ($keys as $k) $out[$k] = getSetting($pdo, $k, '');
            echo json_encode(['success' => true, 'policy' => $out, 'certificate' => signingCertInfo()]);
            break;

        case 'savePolicy':
            $roles = fn($v) => implode(',', array_values(array_intersect(['admin', 'staff', 'viewer'], (array)$v)));
            setSetting($pdo, 'download_roles', $roles(array_merge(['admin'], (array)($input['download_roles'] ?? []))));
            setSetting($pdo, 'pii_roles', $roles(array_merge(['admin'], (array)($input['pii_roles'] ?? []))));
            setSetting($pdo, 'watermark_mode', in_array($input['watermark_mode'] ?? '', ['off', 'download', 'all'], true) ? $input['watermark_mode'] : 'download');
            setSetting($pdo, 'require_admin_2fa', !empty($input['require_admin_2fa']) ? '1' : '0');
            setSetting($pdo, 'sign_enabled', !empty($input['sign_enabled']) ? '1' : '0');
            foreach (['amount_field', 'department_field', 'org_name'] as $k) setSetting($pdo, $k, mb_substr(trim((string)($input[$k] ?? '')), 0, 100));
            setSetting($pdo, 'org_name_en', preg_replace('/[^\x20-\x7E]/', '', mb_substr(trim((string)($input['org_name_en'] ?? '')), 0, 100)));
            if (!empty($input['sign_enabled'])) {
                require_once '../config/docsign.php';
                if (!ensureSigningCert($pdo)) jsonFail(500, 'สร้างใบรับรองสำหรับลงลายมือชื่อไม่สำเร็จ (ตรวจ OPENSSL_CONF_PATH ใน config/app.php)');
            }
            addAuditLog($pdo, $me, 'Update Settings', 'แก้ไขนโยบายการเข้าถึง ลายน้ำ PDPA และความปลอดภัย');
            echo json_encode(['success' => true]);
            break;

        case 'uploadCert':
            // Organisation certificate from a CA (.p12 / .pfx) replaces the self-signed one
            require_once '../config/docsign.php';
            if (!isset($_FILES['cert']) || $_FILES['cert']['error'] !== UPLOAD_ERR_OK) jsonFail(400, 'ไม่ได้รับไฟล์ใบรับรอง');
            if ($_FILES['cert']['size'] > 100000) jsonFail(400, 'ไฟล์ใบรับรองใหญ่ผิดปกติ');
            $err = installSigningP12(file_get_contents($_FILES['cert']['tmp_name']), (string)($_POST['password'] ?? ''));
            @unlink($_FILES['cert']['tmp_name']);
            if ($err) jsonFail(400, $err);
            addAuditLog($pdo, $me, 'Update Settings', 'ติดตั้งใบรับรองลายมือชื่อดิจิทัลขององค์กร');
            echo json_encode(['success' => true, 'certificate' => signingCertInfo()]);
            break;

        case 'storageReport':
            require_once '../config/storage.php';
            $files = listUploadFiles();
            $orphans = findOrphanFiles($pdo);
            $byYear = [];
            foreach ($files as $rel => $info) {
                $year = preg_match('#^(\d{4})/#', $rel, $m) ? $m[1] : 'ไม่ระบุ';
                $byYear[$year] = ($byYear[$year] ?? 0) + $info['size'];
            }
            krsort($byYear);
            echo json_encode(['success' => true, 'report' => [
                'fileCount' => count($files),
                'totalBytes' => array_sum(array_column($files, 'size')),
                'diskFreeBytes' => @disk_free_space(uploadsDir()) ?: 0,
                'diskTotalBytes' => @disk_total_space(uploadsDir()) ?: 0,
                'byYear' => $byYear,
                'documentsWithFiles' => (int)$pdo->query("SELECT COUNT(DISTINCT DocumentID) FROM document_files WHERE IsCurrent = 1")->fetchColumn(),
                'oldVersions' => (int)$pdo->query("SELECT COUNT(*) FROM document_files WHERE IsCurrent = 0")->fetchColumn(),
                'documentsWithoutText' => (int)$pdo->query("SELECT COUNT(DISTINCT d.ID) FROM documents d JOIN document_files f ON f.DocumentID = d.ID AND f.IsCurrent = 1 WHERE d.OcrText IS NULL")->fetchColumn(),
                'orphans' => array_slice($orphans, 0, 500),
                'orphanCount' => count($orphans),
                'orphanBytes' => array_sum(array_column($orphans, 'size')),
            ]]);
            break;

        case 'deleteOrphans':
            require_once '../config/storage.php';
            $orphanSet = array_column(findOrphanFiles($pdo), 'path', 'path');
            $deleted = 0;
            foreach ((array)($input['paths'] ?? []) as $p) {
                $rel = normalizeFileRef($p) ?? (preg_match('#^\d{4}/\d{2}/[A-Za-z0-9_]+\.\w+$#', (string)$p) ? $p : null);
                if ($rel && isset($orphanSet[$rel]) && is_file(absPath($rel))) {
                    unlink(absPath($rel));
                    $deleted++;
                }
            }
            addAuditLog($pdo, $me, 'Storage Cleanup', "Deleted $deleted orphan file(s)");
            echo json_encode(['success' => true, 'deleted' => $deleted]);
            break;

        case 'reindexAll':
            spawnBackground('extract_text.php', ['--all']);
            addAuditLog($pdo, $me, 'Reindex', 'Started text indexing for documents without text');
            echo json_encode(['success' => true]);
            break;

        case 'listBackups':
            $sets = [];
            foreach (glob(BACKUP_DIR . DIRECTORY_SEPARATOR . 'edms_*', GLOB_ONLYDIR) ?: [] as $dir) {
                $m = json_decode((string)@file_get_contents("$dir/manifest.json"), true) ?: ['name' => basename($dir), 'state' => 'unknown'];
                $m['totalBytes'] = dirSizeBytes($dir);
                $sets[] = $m;
            }
            usort($sets, fn($a, $b) => strcmp($b['name'], $a['name']));
            echo json_encode(['success' => true, 'backups' => $sets, 'dir' => BACKUP_DIR, 'keep' => BACKUP_KEEP]);
            break;

        case 'runBackup':
            foreach (glob(BACKUP_DIR . DIRECTORY_SEPARATOR . 'edms_*/manifest.json') ?: [] as $mf) {
                $m = json_decode((string)file_get_contents($mf), true);
                if (($m['state'] ?? '') === 'running' && strtotime($m['startedAt']) > time() - 3600) {
                    jsonFail(409, 'กำลังสำรองข้อมูลอยู่ กรุณารอให้เสร็จก่อน');
                }
            }
            spawnBackground('backup.php');
            addAuditLog($pdo, $me, 'Backup', 'Started manual backup');
            echo json_encode(['success' => true]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (PDOException $e) {
    $msg = $e->getCode() === '23000' ? 'ข้อมูลนี้มีอยู่แล้ว' : 'เกิดข้อผิดพลาดของฐานข้อมูล';
    error_log('settings.php: ' . $e->getMessage());
    jsonFail(400, $msg);
} catch (Exception $e) {
    error_log('settings.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ');
}
