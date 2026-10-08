CREATE DATABASE IF NOT EXISTS dms_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dms_system;

CREATE TABLE IF NOT EXISTS folders (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    FolderName VARCHAR(255) NOT NULL,
    Description TEXT,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    Username VARCHAR(50) NOT NULL UNIQUE,
    Password VARCHAR(255) NOT NULL,
    Role VARCHAR(20) DEFAULT 'staff',
    failed_attempts INT DEFAULT 0,
    locked_until DATETIME NULL,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS prefixes (
    PrefixValue VARCHAR(50) PRIMARY KEY,
    PrefixName VARCHAR(255) NOT NULL,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS custom_fields (
    FieldName VARCHAR(255) PRIMARY KEY,
    FieldType VARCHAR(50) NOT NULL,
    IsRequired VARCHAR(10) NOT NULL DEFAULT 'No',
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS documents (
    ID VARCHAR(100) PRIMARY KEY,
    Filename VARCHAR(255) NOT NULL,
    FolderId INT,
    PaymentDate DATE,
    ExpiryDate DATE,
    Status VARCHAR(50) DEFAULT 'Active',
    FileId TEXT, -- Local file paths, separated by comma
    CustomData JSON,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (FolderId) REFERENCES folders(ID) ON DELETE SET NULL
);

-- Insert default folder if not exists
INSERT IGNORE INTO folders (ID, FolderName, Description) VALUES (1, 'แฟ้มทั่วไป', 'สำหรับเอกสารทั่วไป');

-- Insert default prefix if not exists
INSERT IGNORE INTO prefixes (PrefixValue, PrefixName) VALUES ('DOC', 'ทั่วไป');

CREATE TABLE IF NOT EXISTS system_settings (
    SettingKey VARCHAR(100) PRIMARY KEY,
    SettingValue TEXT,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT,
    Action VARCHAR(100) NOT NULL,
    Details TEXT,
    IPAddress VARCHAR(45),
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (UserID) REFERENCES users(ID) ON DELETE SET NULL
);

INSERT IGNORE INTO system_settings (SettingKey, SettingValue) VALUES ('line_notify_token', '');

