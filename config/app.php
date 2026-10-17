<?php
/**
 * Application paths and external tools.
 * ปรับค่าในไฟล์นี้ให้ตรงกับเครื่องเซิร์ฟเวอร์ หรือกำหนดทับในไฟล์ config/local.php (ไม่ขึ้น git)
 */

// Machine-specific overrides: constants defined in config/local.php win over the defaults below.
if (is_file(__DIR__ . '/local.php')) require_once __DIR__ . '/local.php';

// PHP CLI used to run background jobs (scan queue, OCR). Apache's PHP_BINARY is httpd.exe, so it must be set here.
defined('PHP_CLI_PATH') || define('PHP_CLI_PATH', 'C:\\xampp\\php\\php.exe');

// mysqldump / mysql used by the backup tool and the restore test
defined('MYSQLDUMP_PATH') || define('MYSQLDUMP_PATH', 'C:\\xampp\\mysql\\bin\\mysqldump.exe');
defined('MYSQL_PATH') || define('MYSQL_PATH', 'C:\\xampp\\mysql\\bin\\mysql.exe');

// Backups must live OUTSIDE the web root (htdocs) so they can never be downloaded over HTTP.
defined('BACKUP_DIR') || define('BACKUP_DIR', 'C:\\xampp\\edms_backups');
defined('BACKUP_KEEP') || define('BACKUP_KEEP', 14); // number of backup sets to keep

// Tesseract OCR (optional). Install from https://github.com/UB-Mannheim/tesseract/wiki with the Thai language pack.
// Leave empty to disable OCR of image files. PDFs scanned with NAPS2 OCR are searchable either way.
defined('TESSERACT_PATH') || define('TESSERACT_PATH', '');
defined('OCR_LANG') || define('OCR_LANG', 'tha+eng');

// Uploaded and scanned files
define('UPLOAD_DIR', realpath(__DIR__ . '/../uploads'));
defined('MAX_UPLOAD_BYTES') || define('MAX_UPLOAD_BYTES', 100 * 1024 * 1024);

// Digital-signature certificate and key (outside the web root) and the openssl.cnf used to create the self-signed certificate
defined('SIGN_KEY_DIR') || define('SIGN_KEY_DIR', 'C:\\xampp\\edms_keys');
defined('OPENSSL_CONF_PATH') || define('OPENSSL_CONF_PATH', 'C:/xampp/php/extras/ssl/openssl.cnf');

function iniBytes($v) {
    $v = trim((string)$v);
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

/** Largest file this server really accepts: our own cap or PHP's upload/post limits, whichever is smaller. */
function uploadLimitBytes() {
    $limits = [MAX_UPLOAD_BYTES];
    $file = iniBytes(ini_get('upload_max_filesize'));
    $post = iniBytes(ini_get('post_max_size'));
    if ($file > 0) $limits[] = $file;
    if ($post > 0) $limits[] = $post - 64 * 1024; // the request also carries the other form fields
    return min($limits);
}
