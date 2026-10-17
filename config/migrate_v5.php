<?php
/**
 * Schema for the v2 features. Portable MySQL 8 / MariaDB syntax (uses edmsAddColumn/edmsAddIndex from migrate.php).
 */

/** Sessions in the database: survive across servers and let users see and end their logins. */
function edmsMigrateV4(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        ID VARCHAR(128) NOT NULL PRIMARY KEY,
        Data MEDIUMBLOB NOT NULL,
        UpdatedAt INT NOT NULL,
        KEY idx_sessions_updated (UpdatedAt)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

/**
 * Cover-sheet batches, physical locations and loans, scan profiles, integrity checks, related documents,
 * saved searches, duplicate detection, reviewer share links, destruction batches, PDPA flags, 2FA,
 * session list, scan agents and their inbox.
 */
function edmsMigrateV5(PDO $pdo) {
    $cs = "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

    edmsAddColumn($pdo, 'sessions', 'UserID', 'INT NULL');
    edmsAddColumn($pdo, 'sessions', 'IP', 'VARCHAR(45) NULL');
    edmsAddColumn($pdo, 'sessions', 'UserAgent', 'VARCHAR(255) NULL');
    edmsAddColumn($pdo, 'sessions', 'CreatedAt', 'INT NULL');
    edmsAddIndex($pdo, 'sessions', 'idx_sessions_user', 'UserID');

    // Physical originals: where the paper is, and who has borrowed it
    $pdo->exec("CREATE TABLE IF NOT EXISTS locations (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        Room VARCHAR(100) NOT NULL,
        Cabinet VARCHAR(100) NULL,
        Shelf VARCHAR(100) NULL,
        Box VARCHAR(100) NULL,
        Note VARCHAR(255) NULL,
        CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $cs");
    edmsAddColumn($pdo, 'documents', 'LocationId', 'INT NULL');
    edmsAddColumn($pdo, 'documents', 'LocationNote', 'VARCHAR(255) NULL');
    edmsAddColumn($pdo, 'documents', 'CoverPrintedAt', 'DATETIME NULL');
    edmsAddColumn($pdo, 'documents', 'ExpectedPages', 'INT NULL');
    edmsAddColumn($pdo, 'documents', 'PiiFlag', 'TINYINT NOT NULL DEFAULT 0');   // 0 none, 1 detected, 2 marked by a person
    edmsAddColumn($pdo, 'documents', 'PiiSummary', 'VARCHAR(255) NULL');
    edmsAddIndex($pdo, 'documents', 'idx_location', 'LocationId');
    edmsAddIndex($pdo, 'documents', 'idx_pii', 'PiiFlag');

    $pdo->exec("CREATE TABLE IF NOT EXISTS loans (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        DocumentID VARCHAR(100) NOT NULL,
        BorrowerName VARCHAR(150) NOT NULL,
        BorrowerUserID INT NULL,
        Purpose VARCHAR(255) NULL,
        LoanedAt DATETIME NOT NULL,
        DueDate DATE NOT NULL,
        ReturnedAt DATETIME NULL,
        LoanedBy INT NULL,
        ReturnedBy INT NULL,
        Note VARCHAR(255) NULL,
        KEY idx_loans_doc (DocumentID),
        KEY idx_loans_open (ReturnedAt, DueDate),
        CONSTRAINT fk_loans_doc FOREIGN KEY (DocumentID) REFERENCES documents(ID) ON UPDATE CASCADE ON DELETE CASCADE
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS scan_profiles (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        Name VARCHAR(100) NOT NULL,
        Options TEXT NOT NULL,
        Prefix VARCHAR(50) NULL,
        FolderId INT NULL,
        ExpectedPages INT NULL,
        SortOrder INT NOT NULL DEFAULT 0,
        CreatedBy INT NULL,
        CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS integrity_runs (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        Kind VARCHAR(20) NOT NULL DEFAULT 'integrity',
        State VARCHAR(20) NOT NULL DEFAULT 'running',
        StartedAt DATETIME NOT NULL,
        FinishedAt DATETIME NULL,
        Checked INT NOT NULL DEFAULT 0,
        Problems INT NOT NULL DEFAULT 0,
        Details MEDIUMTEXT NULL,
        TriggeredBy INT NULL,
        KEY idx_runs_kind (Kind, ID)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS document_links (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        DocA VARCHAR(100) NOT NULL,
        DocB VARCHAR(100) NOT NULL,
        RelationType VARCHAR(50) NOT NULL,
        Note VARCHAR(255) NULL,
        CreatedBy INT NULL,
        CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_link (DocA, DocB),
        KEY idx_link_b (DocB),
        CONSTRAINT fk_link_a FOREIGN KEY (DocA) REFERENCES documents(ID) ON UPDATE CASCADE ON DELETE CASCADE,
        CONSTRAINT fk_link_b FOREIGN KEY (DocB) REFERENCES documents(ID) ON UPDATE CASCADE ON DELETE CASCADE
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS saved_searches (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        UserID INT NOT NULL,
        Name VARCHAR(100) NOT NULL,
        Filters TEXT NOT NULL,
        CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_saved_user (UserID)
    ) $cs");

    // Hash of the file exactly as the user picked it (before PDF conversion), for duplicate warnings
    edmsAddColumn($pdo, 'document_files', 'SourceSha256', 'CHAR(64) NULL');
    edmsAddIndex($pdo, 'document_files', 'idx_source_sha', 'SourceSha256');
    edmsAddIndex($pdo, 'document_files', 'idx_orig_sha', 'OriginalSha256');

    $pdo->exec("CREATE TABLE IF NOT EXISTS share_links (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        TokenHash CHAR(64) NOT NULL UNIQUE,
        Recipient VARCHAR(255) NOT NULL,
        Note VARCHAR(255) NULL,
        AllowDownload TINYINT NOT NULL DEFAULT 0,
        ExpiresAt DATETIME NOT NULL,
        RevokedAt DATETIME NULL,
        CreatedBy INT NULL,
        CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        LastAccessAt DATETIME NULL,
        AccessCount INT NOT NULL DEFAULT 0
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS share_link_docs (
        LinkID INT NOT NULL,
        DocumentID VARCHAR(100) NOT NULL,
        PRIMARY KEY (LinkID, DocumentID),
        CONSTRAINT fk_sld_link FOREIGN KEY (LinkID) REFERENCES share_links(ID) ON DELETE CASCADE,
        CONSTRAINT fk_sld_doc FOREIGN KEY (DocumentID) REFERENCES documents(ID) ON UPDATE CASCADE ON DELETE CASCADE
    ) $cs");

    // Destruction under the records-management regulation. Items keep a snapshot because documents get purged.
    $pdo->exec("CREATE TABLE IF NOT EXISTS destruction_batches (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        Title VARCHAR(255) NOT NULL,
        FiscalYear INT NOT NULL,
        Status VARCHAR(20) NOT NULL DEFAULT 'draft',
        Committee TEXT NULL,
        Note TEXT NULL,
        CreatedBy INT NULL,
        CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ApprovedBy INT NULL,
        ApprovedAt DATETIME NULL,
        DoneAt DATETIME NULL
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS destruction_items (
        BatchID INT NOT NULL,
        DocumentID VARCHAR(100) NOT NULL,
        Title VARCHAR(255) NOT NULL,
        DocDate DATE NULL,
        FolderName VARCHAR(255) NULL,
        ExpiryDate DATE NULL,
        Decision VARCHAR(10) NOT NULL DEFAULT 'destroy',
        KeepYears INT NULL,
        Note VARCHAR(255) NULL,
        PRIMARY KEY (BatchID, DocumentID),
        CONSTRAINT fk_di_batch FOREIGN KEY (BatchID) REFERENCES destruction_batches(ID) ON DELETE CASCADE
    ) $cs");

    // Two-factor authentication
    edmsAddColumn($pdo, 'users', 'TotpSecret', 'VARCHAR(64) NULL');
    edmsAddColumn($pdo, 'users', 'TotpEnabled', 'TINYINT NOT NULL DEFAULT 0');
    edmsAddColumn($pdo, 'users', 'RecoveryCodes', 'TEXT NULL');

    // Scan agents on other office PCs upload into a per-user inbox
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        UserID INT NOT NULL,
        Name VARCHAR(100) NOT NULL,
        TokenHash CHAR(64) NOT NULL UNIQUE,
        CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        LastUsedAt DATETIME NULL,
        RevokedAt DATETIME NULL,
        CONSTRAINT fk_tokens_user FOREIGN KEY (UserID) REFERENCES users(ID) ON DELETE CASCADE
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inbox_files (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        UserID INT NOT NULL,
        StoredPath VARCHAR(255) NOT NULL,
        OriginalName VARCHAR(255) NOT NULL,
        SizeBytes BIGINT NOT NULL DEFAULT 0,
        Source VARCHAR(100) NULL,
        TargetDocumentID VARCHAR(100) NULL,
        CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_inbox_user (UserID)
    ) $cs");

    $defaults = [
        'offsite_backup_dir' => '',
        'download_roles' => 'admin,staff,viewer',
        'watermark_mode' => 'download',        // off | download | all
        'pii_roles' => 'admin',
        'require_admin_2fa' => '0',
        'amount_field' => 'จำนวนเงิน',
        'department_field' => 'หน่วยงาน',
        'org_name' => 'กองคลัง',
        'sign_enabled' => '0',
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (SettingKey, SettingValue) VALUES (?, ?)");
    foreach ($defaults as $k => $v) $stmt->execute([$k, $v]);
}
