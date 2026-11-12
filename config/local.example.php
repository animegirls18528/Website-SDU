<?php
/**
 * Machine-specific settings. Copy this file to config/local.php (which is git-ignored) and edit.
 * Everything here is optional: anything you leave out falls back to the defaults in config/database.php and config/app.php.
 */

// Database (read by config/database.php)
putenv('DB_HOST=localhost');
putenv('DB_PORT=3306');
putenv('DB_NAME=dms_system');
putenv('DB_USER=root');
putenv('DB_PASS=');

// Paths (constants must be defined here, before config/app.php defines its defaults)
// define('BACKUP_DIR', 'D:\\edms_backups');          // keep OUTSIDE htdocs
// define('SIGN_KEY_DIR', 'D:\\edms_keys');           // digital-signature key, keep OUTSIDE htdocs
// define('OPENSSL_CONF_PATH', 'C:/xampp/php/extras/ssl/openssl.cnf');
// define('PHP_CLI_PATH', 'C:\\xampp\\php\\php.exe');
// Scanner: choose the driver and name reported by NAPS2 --listdevices.
// define('SCANNER_DEFAULT_DRIVER', 'wia');
// define('SCANNER_DEVICE_NAME', 'CANON DR-G2110 USB');
// define('NAPS2_CONSOLE_PATH', 'C:\\Program Files\\NAPS2\\NAPS2.Console.exe');
// Optional named NAPS2 profile for the configured TWAIN device (e.g. TwainImpl=X64).
// Its processing settings should be off; the web request supplies DPI/color/deskew/OCR.
// define('NAPS2_TWAIN_PROFILE', 'EDMS Canon TWAIN x64');
