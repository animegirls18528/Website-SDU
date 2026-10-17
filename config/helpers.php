<?php
require_once __DIR__ . '/app.php';

function addAuditLog($pdo, $userId, $action, $details = '', $documentId = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $stmt = $pdo->prepare("INSERT INTO audit_logs (UserID, Action, Details, IPAddress, DocumentID) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $details, $ip, $documentId]);
        return true;
    } catch (Exception $e) {
        error_log("Audit Log Error: " . $e->getMessage());
        return false;
    }
}

// ---------- system settings ----------

function getSetting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare("SELECT SettingValue FROM system_settings WHERE SettingKey = ?");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return ($v === false || $v === null) ? $default : $v;
}

function setSetting($pdo, $key, $value) {
    $pdo->prepare("INSERT INTO system_settings (SettingKey, SettingValue) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)")->execute([$key, (string)$value]);
}

// ---------- folder permissions ----------

/** Folder IDs the current user may access, or null when unrestricted. */
function allowedFolderIds($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache === 'all' ? null : $cache;
    if (isAdmin()) { $cache = 'all'; return null; }
    $stmt = $pdo->prepare("SELECT FolderScope FROM users WHERE ID = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    if ($stmt->fetchColumn() !== 'restricted') { $cache = 'all'; return null; }
    $stmt = $pdo->prepare("SELECT FolderID FROM folder_permissions WHERE UserID = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $cache = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return $cache;
}

function canAccessFolder($pdo, $folderId) {
    $allowed = allowedFolderIds($pdo);
    if ($allowed === null) return true;
    return $folderId !== null && in_array((int)$folderId, $allowed, true);
}

/** SQL fragment restricting documents alias `d` to the user's folders. */
function folderScopeSql($pdo, &$params) {
    $allowed = allowedFolderIds($pdo);
    if ($allowed === null) return '1=1';
    if (!$allowed) return '1=0';
    $params = array_merge($params, $allowed);
    return 'd.FolderId IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
}

// ---------- document numbering & retention ----------

/** Longest configured prefix that the ID starts with. */
function matchPrefix($pdo, $docId) {
    $best = null;
    foreach ($pdo->query("SELECT PrefixValue, RetentionYears FROM prefixes")->fetchAll() as $p) {
        if ($p['PrefixValue'] !== '' && strpos($docId, $p['PrefixValue']) === 0 &&
            ($best === null || strlen($p['PrefixValue']) > strlen($best['PrefixValue']))) {
            $best = $p;
        }
    }
    return $best;
}

function retentionYearsFor($pdo, $docId) {
    $p = matchPrefix($pdo, $docId);
    if ($p && $p['RetentionYears'] !== null && (int)$p['RetentionYears'] > 0) return (int)$p['RetentionYears'];
    return max(1, (int)getSetting($pdo, 'retention_years_default', '10'));
}

function highestNumberInUse($pdo, $prefix) {
    $stmt = $pdo->prepare("SELECT ID FROM documents WHERE ID LIKE ? ORDER BY LENGTH(ID) DESC, ID DESC LIMIT 1");
    $stmt->execute([str_replace(['%', '_'], ['\%', '\_'], $prefix) . '%']);
    $last = $stmt->fetchColumn();
    if ($last === false) return 0;
    $num = substr($last, strlen($prefix));
    return ctype_digit($num) ? (int)$num : 0;
}

/** Number the next document would get (does not reserve it). */
function peekNextNumber($pdo, $prefix) {
    $stmt = $pdo->prepare("SELECT LastNumber FROM doc_sequences WHERE Prefix = ?");
    $stmt->execute([$prefix]);
    $seq = $stmt->fetchColumn();
    return max((int)$seq, highestNumberInUse($pdo, $prefix)) + 1;
}

/** Atomically reserve the next free number for a prefix. Call inside a transaction. */
function reserveNextId($pdo, $prefix) {
    $pdo->prepare("INSERT IGNORE INTO doc_sequences (Prefix, LastNumber) VALUES (?, ?)")
        ->execute([$prefix, highestNumberInUse($pdo, $prefix)]);
    $stmt = $pdo->prepare("SELECT LastNumber FROM doc_sequences WHERE Prefix = ? FOR UPDATE");
    $stmt->execute([$prefix]);
    $n = max((int)$stmt->fetchColumn(), highestNumberInUse($pdo, $prefix));
    $exists = $pdo->prepare("SELECT 1 FROM documents WHERE ID = ?");
    do {
        $n++;
        $id = $prefix . sprintf('%06d', $n);
        $exists->execute([$id]);
    } while ($exists->fetchColumn());
    $pdo->prepare("UPDATE doc_sequences SET LastNumber = ? WHERE Prefix = ?")->execute([$n, $prefix]);
    return $id;
}

// ---------- background jobs ----------

/** Start `php tools/<script> args...` detached from the web request. */
function spawnBackground($script, array $args = []) {
    $php = PHP_CLI_PATH;
    if (!is_file($php)) {
        $php = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
    }
    $path = realpath(__DIR__ . '/../tools/' . $script);
    $parts = array_map('escapeshellarg', array_merge([$path], array_map('strval', $args)));
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'start "" /B "' . $php . '" ' . implode(' ', $parts) . ' > NUL 2>&1';
        pclose(popen($cmd, 'r'));
    } else {
        exec(escapeshellarg($php) . ' ' . implode(' ', $parts) . ' > /dev/null 2>&1 &');
    }
}

// ---------- daily maintenance ----------

/** Refresh expiry status and empty old trash, at most once a day. */
function runDailyMaintenance($pdo) {
    $today = date('Y-m-d');
    if (getSetting($pdo, 'last_maintenance') === $today) return;
    setSetting($pdo, 'last_maintenance', $today);

    $pdo->exec("UPDATE documents SET Status = 'Expired' WHERE Status = 'Active' AND ExpiryDate IS NOT NULL AND ExpiryDate < CURDATE()");
    $pdo->exec("UPDATE documents SET Status = 'Active' WHERE Status = 'Expired' AND ExpiryDate >= CURDATE()");

    // Documents added by other tools (e.g. legacy import) still need a search row
    require_once __DIR__ . '/search.php';
    rebuildMissingSearchRows($pdo);

    $days = (int)getSetting($pdo, 'trash_retention_days', '30');
    if ($days > 0) {
        require_once __DIR__ . '/storage.php';
        $stmt = $pdo->prepare("SELECT ID FROM documents WHERE DeletedAt IS NOT NULL AND DeletedAt < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            purgeDocument($pdo, $id);
            addAuditLog($pdo, null, 'Purge Document', "Auto-purged from trash after $days days: $id", $id);
        }
    }
}

/** "ห้อง 201 › ตู้ A › ชั้น 2 › กล่อง 7" — the word is not repeated when the value already starts with it. */
function locationLabel($l) {
    $part = function ($word, $v) { $v = trim((string)$v); return $v === '' ? '' : (mb_strpos($v, $word) === 0 ? $v : "$word $v"); };
    return implode(' › ', array_filter([trim((string)($l['Room'] ?? '')), $part('ตู้', $l['Cabinet'] ?? ''), $part('ชั้น', $l['Shelf'] ?? ''), $part('กล่อง', $l['Box'] ?? '')], 'strlen'));
}

/** Same label built in SQL for list queries ($a = alias of the locations table). */
function locationLabelSql($a) {
    $part = fn($col, $word) => "CASE WHEN NULLIF(TRIM($a.$col), '') IS NULL THEN NULL WHEN $a.$col LIKE '$word%' THEN $a.$col ELSE CONCAT('$word ', $a.$col) END";
    return "NULLIF(CONCAT_WS(' › ', NULLIF(TRIM($a.Room), ''), " . $part('Cabinet', 'ตู้') . ', ' . $part('Shelf', 'ชั้น') . ', ' . $part('Box', 'กล่อง') . "), '')";
}
