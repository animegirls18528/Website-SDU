<?php
/**
 * Additive schema upgrades, applied once per version on first request after deploy.
 * Only adds tables/columns/indexes — the same database is shared with other tools (psscan),
 * so nothing here renames or drops existing columns.
 */

require_once __DIR__ . '/migrate_v5.php';

const EDMS_SCHEMA_VERSION = 5;

/** Empty database: create the base schema from schema.sql (equivalent to version 4). */
function edmsInstallFresh(PDO $pdo) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/schema.sql'));
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $stmt) {
        if (trim($stmt) !== '') $pdo->exec($stmt);
    }
    $pdo->prepare("INSERT INTO system_settings (SettingKey, SettingValue) VALUES ('edms_schema_version', '4')
                   ON DUPLICATE KEY UPDATE SettingValue = '4'")->execute();
}

/** Portable "ADD COLUMN IF NOT EXISTS" (MySQL 8 has no IF NOT EXISTS for columns). */
function edmsAddColumn(PDO $pdo, $table, $column, $definition) {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    if (!$stmt->fetchColumn()) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}

function edmsAddIndex(PDO $pdo, $table, $name, $columns) {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $name]);
    if (!$stmt->fetchColumn()) $pdo->exec("CREATE INDEX `$name` ON `$table` ($columns)");
}

function edmsMigrate(PDO $pdo) {
    if (!$pdo->query("SHOW TABLES LIKE 'system_settings'")->fetchColumn()) {
        edmsInstallFresh($pdo);
    }
    $current = (int)$pdo->query("SELECT SettingValue FROM system_settings WHERE SettingKey = 'edms_schema_version'")->fetchColumn();
    if ($current >= EDMS_SCHEMA_VERSION) return;

    // Serialize concurrent first requests
    try {
        $got = (int)$pdo->query("SELECT GET_LOCK('edms_migrate', 60)")->fetchColumn();
    } catch (PDOException $e) {
        $got = 0;
    }
    try {
        $current = (int)$pdo->query("SELECT SettingValue FROM system_settings WHERE SettingKey = 'edms_schema_version'")->fetchColumn();
        if ($current < 1) edmsMigrateV1($pdo);
        if ($current < 2) edmsMigrateV2($pdo);
        if ($current < 3) edmsMigrateV3($pdo);
        if ($current < 4) edmsMigrateV4($pdo);
        if ($current < 5) edmsMigrateV5($pdo);
        $pdo->prepare("INSERT INTO system_settings (SettingKey, SettingValue) VALUES ('edms_schema_version', ?)
                       ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)")->execute([EDMS_SCHEMA_VERSION]);
    } finally {
        if ($got) $pdo->query("SELECT RELEASE_LOCK('edms_migrate')");
    }
}

function edmsMigrateV1(PDO $pdo) {
    $pdo->exec("ALTER TABLE documents
        ADD COLUMN IF NOT EXISTS VerifyToken VARCHAR(32) NULL,
        ADD COLUMN IF NOT EXISTS Tags VARCHAR(500) NULL,
        ADD COLUMN IF NOT EXISTS OcrText MEDIUMTEXT NULL,
        ADD COLUMN IF NOT EXISTS WorkflowStatus VARCHAR(20) NOT NULL DEFAULT 'Approved',
        ADD COLUMN IF NOT EXISTS CreatedBy INT NULL,
        ADD COLUMN IF NOT EXISTS UpdatedAt DATETIME NULL,
        ADD COLUMN IF NOT EXISTS DeletedAt DATETIME NULL,
        ADD COLUMN IF NOT EXISTS DeletedBy INT NULL,
        ADD COLUMN IF NOT EXISTS DeleteReason VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS DestructionRequestedAt DATETIME NULL,
        ADD COLUMN IF NOT EXISTS DestructionRequestedBy INT NULL,
        ADD COLUMN IF NOT EXISTS DestructionReason VARCHAR(255) NULL");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_verify_token ON documents (VerifyToken)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_deleted ON documents (DeletedAt)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_workflow ON documents (WorkflowStatus)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_destruction ON documents (DestructionRequestedAt)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_expiry ON documents (ExpiryDate)");

    $pdo->exec("ALTER TABLE prefixes ADD COLUMN IF NOT EXISTS RetentionYears INT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS FolderScope VARCHAR(10) NOT NULL DEFAULT 'all'");
    $pdo->exec("ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS DocumentID VARCHAR(100) NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_doc ON audit_logs (DocumentID)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs (CreatedAt)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS document_files (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        DocumentID VARCHAR(100) NOT NULL,
        StoredPath VARCHAR(255) NOT NULL,      -- relative to uploads/, e.g. 2026/10/doc_x.pdf (QR-stamped copy for PDFs)
        OriginalPath VARCHAR(255) NULL,        -- untouched original before stamping
        OriginalName VARCHAR(255) NULL,
        MimeType VARCHAR(100) NULL,
        SizeBytes BIGINT NOT NULL DEFAULT 0,
        PageCount INT NULL,
        Sha256 CHAR(64) NULL,
        OriginalSha256 CHAR(64) NULL,
        Version INT NOT NULL DEFAULT 1,
        IsCurrent TINYINT(1) NOT NULL DEFAULT 1,
        UploadedBy INT NULL,
        CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ReplacedAt DATETIME NULL,
        INDEX idx_doc (DocumentID, IsCurrent),
        INDEX idx_sha (Sha256),
        INDEX idx_stored (StoredPath),
        CONSTRAINT fk_docfiles_doc FOREIGN KEY (DocumentID) REFERENCES documents(ID) ON UPDATE CASCADE ON DELETE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS folder_permissions (
        UserID INT NOT NULL,
        FolderID INT NOT NULL,
        PRIMARY KEY (UserID, FolderID),
        CONSTRAINT fk_fp_user FOREIGN KEY (UserID) REFERENCES users(ID) ON DELETE CASCADE,
        CONSTRAINT fk_fp_folder FOREIGN KEY (FolderID) REFERENCES folders(ID) ON DELETE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS scan_jobs (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        UserID INT NULL,
        Status VARCHAR(20) NOT NULL DEFAULT 'queued',   -- queued, running, done, failed
        Options TEXT NULL,
        Files TEXT NULL,                                -- JSON array of produced uploads/ paths
        Message TEXT NULL,
        CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        StartedAt DATETIME NULL,
        FinishedAt DATETIME NULL,
        INDEX idx_status (Status)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS doc_sequences (
        Prefix VARCHAR(50) PRIMARY KEY,
        LastNumber INT NOT NULL DEFAULT 0
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $defaults = [
        'retention_years_default' => '10',
        'trash_retention_days' => '30',
        'expiry_warning_days' => '90',
        'public_base_url' => '',
        'last_maintenance' => '',
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (SettingKey, SettingValue) VALUES (?, ?)");
    foreach ($defaults as $k => $v) $stmt->execute([$k, $v]);

    // Register any files attached by the old code (comma-separated FileId) in document_files
    $rows = $pdo->query("SELECT d.ID, d.FileId FROM documents d
                         WHERE d.FileId IS NOT NULL AND d.FileId <> ''
                           AND NOT EXISTS (SELECT 1 FROM document_files f WHERE f.DocumentID = d.ID)")->fetchAll();
    $ins = $pdo->prepare("INSERT INTO document_files (DocumentID, StoredPath, OriginalName, MimeType, SizeBytes, Sha256) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        foreach (explode(',', $r['FileId']) as $f) {
            $rel = preg_replace('#^uploads/#', '', trim($f));
            if ($rel === '') continue;
            $abs = __DIR__ . '/../uploads/' . $rel;
            $exists = is_file($abs);
            $ins->execute([$r['ID'], $rel, basename($rel), $exists ? mime_content_type($abs) : null,
                           $exists ? filesize($abs) : 0, $exists ? hash_file('sha256', $abs) : null]);
        }
    }
}

/** Narrow, normalized search table: one LIKE scan instead of five over the wide documents table. */
function edmsMigrateV2(PDO $pdo) {
    @set_time_limit(0);
    $pdo->exec("CREATE TABLE IF NOT EXISTS document_search (
        DocumentID VARCHAR(100) NOT NULL PRIMARY KEY,
        SearchText MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        CONSTRAINT fk_search_doc FOREIGN KEY (DocumentID) REFERENCES documents(ID) ON UPDATE CASCADE ON DELETE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    require_once __DIR__ . '/search.php';
    rebuildMissingSearchRows($pdo);
}

/** Optimistic locking: every save bumps RowVersion so a stale edit can be detected. */
function edmsMigrateV3(PDO $pdo) {
    $pdo->exec("ALTER TABLE documents ADD COLUMN IF NOT EXISTS RowVersion INT NOT NULL DEFAULT 0");
    // Dates that slipped in before validation existed
    $pdo->exec("UPDATE documents SET PaymentDate = NULL, ExpiryDate = NULL WHERE PaymentDate = '0000-00-00'");
}
