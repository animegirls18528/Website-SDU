<?php
/**
 * Scanner Configuration
 * ---------------------
 * เครื่องสแกน Canon imageFORMULA DR-G2110
 * ใช้ NAPS2 Console CLI เป็นตัวเชื่อมต่อกับ TWAIN driver
 * 
 * ติดตั้ง NAPS2: https://www.naps2.com/download
 * เลือก "NAPS2 (Desktop)" แล้วติดตั้งตามปกติ
 */

// Path to NAPS2 Console executable
// ปรับ path ให้ตรงกับเครื่องที่ติดตั้ง
define('NAPS2_CONSOLE_PATH', 'C:\\Program Files\\NAPS2\\NAPS2.Console.exe');

// Scanner device name (ตรงกับชื่อที่ปรากฏใน TWAIN driver)
// ดูชื่อเครื่องได้จาก: NAPS2.Console.exe --listdevices --driver twain
define('SCANNER_DEVICE_NAME', 'CANON DR-G2110');

// Default scan settings
define('SCANNER_DEFAULTS', json_encode([
    'driver'     => 'twain',     // twain, wia, escl
    'dpi'        => 300,         // 150, 200, 300, 400, 600
    'colorMode'  => 'color',     // color, gray, bw (black & white)
    'source'     => 'feeder',    // feeder (ADF), glass (flatbed), duplex
    'format'     => 'pdf',       // pdf, png, jpg, tiff
    'pageSize'   => 'a4',        // a4, letter, legal, a3
]));

// Scan output directory (same as upload directory)
define('SCAN_OUTPUT_DIR', realpath(__DIR__ . '/../uploads') . DIRECTORY_SEPARATOR);

// Maximum scan timeout in seconds
define('SCAN_TIMEOUT', 120);
?>
