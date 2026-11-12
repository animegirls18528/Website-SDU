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
if (is_file(__DIR__ . '/local.php')) require_once __DIR__ . '/local.php';
if (!defined('NAPS2_CONSOLE_PATH')) {
    $installed = 'C:\\Program Files\\NAPS2\\NAPS2.Console.exe';
    $portable = __DIR__ . '/../tools/scanner-runtime/naps2/App/NAPS2.Console.exe';
    define('NAPS2_CONSOLE_PATH', is_file($installed) ? $installed : $portable);
}

// Scanner device name (ตรงกับชื่อที่ปรากฏใน TWAIN driver)
// ดูชื่อเครื่องได้จาก: NAPS2.Console.exe --listdevices --driver twain
defined('SCANNER_DEVICE_NAME') || define('SCANNER_DEVICE_NAME', 'CANON DR-G2110');
defined('SCANNER_DEFAULT_DRIVER') || define('SCANNER_DEFAULT_DRIVER', 'twain');
// A trusted, locally installed NAPS2 profile may select a compatible TWAIN DSM
// (for example X64). Only used for the configured TWAIN device, never from input.
defined('NAPS2_TWAIN_PROFILE') || define('NAPS2_TWAIN_PROFILE', '');

// Default scan settings
define('SCANNER_DEFAULTS', json_encode([
    'driver'     => SCANNER_DEFAULT_DRIVER, // twain, wia, escl
    'dpi'        => 300,         // 150, 200, 300, 400, 600
    'colorMode'  => 'color',     // color, gray, bw (black & white)
    'source'     => 'feeder',    // feeder (ADF), glass (flatbed), duplex
    'format'     => 'pdf',       // pdf, png, jpg, tiff
    'pageSize'   => 'a4',        // a4, letter, legal, a3
    'deskew'     => true,        // straighten skewed pages
    'skipBlank'  => false,       // NAPS2 Console does not expose blank-page removal
    'ocr'        => false,       // NAPS2 OCR -> searchable PDF (needs NAPS2 OCR language pack)
    'splitPatchT'=> false,       // split into separate documents at Patch-T separator sheets
    'pdfa'       => false,       // save as PDF/A-2b for long-term archiving
]));

// NAPS2 OCR language(s), Tesseract codes
define('SCAN_OCR_LANG', 'tha+eng');

// Scan output directory (same as upload directory)
define('SCAN_OUTPUT_DIR', realpath(__DIR__ . '/../uploads') . DIRECTORY_SEPARATOR);

// Maximum scan timeout in seconds (a full 60-sheet duplex batch at 300 DPI with OCR can take a while)
define('SCAN_TIMEOUT', 600);
?>
