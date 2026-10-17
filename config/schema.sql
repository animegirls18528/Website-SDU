-- Complete schema for an EMPTY database (e.g. hosted MySQL for the Vercel deployment).
-- Plain MySQL 8 / TiDB / MariaDB syntax. config/migrate.php runs it automatically when no tables exist,
-- then records the current schema version so the incremental (MariaDB-only) upgrades are skipped.

CREATE TABLE IF NOT EXISTS folders (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    FolderName VARCHAR(255) NOT NULL,
    Description TEXT,
    SortOrder INT NULL,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    Username VARCHAR(50) NOT NULL UNIQUE,
    Password VARCHAR(255) NOT NULL,
    Role VARCHAR(20) DEFAULT 'staff',
    FolderScope VARCHAR(10) NOT NULL DEFAULT 'all',
    failed_attempts INT DEFAULT 0,
    locked_until DATETIME NULL,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prefixes (
    PrefixValue VARCHAR(50) PRIMARY KEY,
    PrefixName VARCHAR(255) NOT NULL,
    RetentionYears INT NULL,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_fields (
    FieldName VARCHAR(255) PRIMARY KEY,
    FieldType VARCHAR(50) NOT NULL,
    IsRequired VARCHAR(10) NOT NULL DEFAULT 'No',
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
    ID VARCHAR(100) PRIMARY KEY,
    Filename VARCHAR(255) NOT NULL,
    FolderId INT NULL,
    PaymentDate DATE NULL,
    ExpiryDate DATE NULL,
    Status VARCHAR(50) DEFAULT 'Active',
    FileId TEXT,
    CustomData TEXT,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    VerifyToken VARCHAR(32) NULL,
    Tags VARCHAR(500) NULL,
    OcrText MEDIUMTEXT NULL,
    WorkflowStatus VARCHAR(20) NOT NULL DEFAULT 'Approved',
    CreatedBy INT NULL,
    UpdatedAt DATETIME NULL,
    DeletedAt DATETIME NULL,
    DeletedBy INT NULL,
    DeleteReason VARCHAR(255) NULL,
    DestructionRequestedAt DATETIME NULL,
    DestructionRequestedBy INT NULL,
    DestructionReason VARCHAR(255) NULL,
    RowVersion INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_verify_token (VerifyToken),
    KEY idx_payment_date (PaymentDate),
    KEY idx_created (CreatedAt),
    KEY idx_folder_created (FolderId, CreatedAt),
    KEY idx_expiry (ExpiryDate),
    KEY idx_deleted (DeletedAt),
    KEY idx_workflow (WorkflowStatus),
    KEY idx_destruction (DestructionRequestedAt),
    CONSTRAINT fk_documents_folder FOREIGN KEY (FolderId) REFERENCES folders(ID) ON DELETE SET NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    SettingKey VARCHAR(100) PRIMARY KEY,
    SettingValue TEXT,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT NULL,
    Action VARCHAR(100) NOT NULL,
    Details TEXT,
    IPAddress VARCHAR(45),
    DocumentID VARCHAR(100) NULL,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_doc (DocumentID),
    KEY idx_audit_created (CreatedAt),
    CONSTRAINT fk_audit_user FOREIGN KEY (UserID) REFERENCES users(ID) ON DELETE SET NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_files (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    DocumentID VARCHAR(100) NOT NULL,
    StoredPath VARCHAR(255) NOT NULL,
    OriginalPath VARCHAR(255) NULL,
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
    KEY idx_doc (DocumentID, IsCurrent),
    KEY idx_sha (Sha256),
    KEY idx_stored (StoredPath),
    CONSTRAINT fk_docfiles_doc FOREIGN KEY (DocumentID) REFERENCES documents(ID) ON UPDATE CASCADE ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_search (
    DocumentID VARCHAR(100) NOT NULL PRIMARY KEY,
    SearchText MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    CONSTRAINT fk_search_doc FOREIGN KEY (DocumentID) REFERENCES documents(ID) ON UPDATE CASCADE ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS folder_permissions (
    UserID INT NOT NULL,
    FolderID INT NOT NULL,
    PRIMARY KEY (UserID, FolderID),
    CONSTRAINT fk_fp_user FOREIGN KEY (UserID) REFERENCES users(ID) ON DELETE CASCADE,
    CONSTRAINT fk_fp_folder FOREIGN KEY (FolderID) REFERENCES folders(ID) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scan_jobs (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT NULL,
    Status VARCHAR(20) NOT NULL DEFAULT 'queued',
    Options TEXT NULL,
    Files TEXT NULL,
    Message TEXT NULL,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    StartedAt DATETIME NULL,
    FinishedAt DATETIME NULL,
    KEY idx_status (Status)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_sequences (
    Prefix VARCHAR(50) PRIMARY KEY,
    LastNumber INT NOT NULL DEFAULT 0
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    ID VARCHAR(128) NOT NULL PRIMARY KEY,
    Data MEDIUMBLOB NOT NULL,
    UpdatedAt INT NOT NULL,
    KEY idx_sessions_updated (UpdatedAt)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO folders (ID, FolderName, Description) VALUES (1, 'แฟ้มทั่วไป', 'สำหรับเอกสารทั่วไป');
INSERT IGNORE INTO prefixes (PrefixValue, PrefixName) VALUES ('DOC', 'ทั่วไป');
INSERT IGNORE INTO system_settings (SettingKey, SettingValue) VALUES
    ('retention_years_default', '10'), ('trash_retention_days', '30'), ('expiry_warning_days', '90'),
    ('public_base_url', ''), ('last_maintenance', '');
