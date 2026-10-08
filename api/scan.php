<?php
/**
 * Scanner API Endpoint
 * --------------------
 * สั่งสแกนเอกสารจากเครื่อง Canon DR-G2110 ผ่าน NAPS2 Console CLI
 * 
 * Actions:
 *   - scan           : เริ่มสแกนเอกสาร
 *   - listDevices     : แสดงรายชื่อเครื่องสแกนที่พบ
 *   - checkStatus     : เช็คสถานะ NAPS2 / scanner
 *   - getScanSettings : ดึงค่า default scan settings
 */

require_once '../config/auth.php';
requireLogin();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../config/helpers.php';
require_once '../config/scanner.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ========================================
    // เช็คว่ามี NAPS2 ติดตั้งอยู่หรือไม่
    // ========================================
    case 'checkStatus':
        $naps2Path = NAPS2_CONSOLE_PATH;
        $naps2Exists = file_exists($naps2Path);
        
        $result = [
            'success' => true,
            'naps2Installed' => $naps2Exists,
            'naps2Path' => $naps2Path,
            'scannerDevice' => SCANNER_DEVICE_NAME,
            'defaults' => json_decode(SCANNER_DEFAULTS, true),
        ];
        
        // Try to detect scanner if NAPS2 is installed
        if ($naps2Exists) {
            $cmd = '"' . $naps2Path . '" --listdevices --driver twain 2>&1';
            $output = [];
            exec($cmd, $output, $returnCode);
            $result['detectedDevices'] = $output;
            $result['scannerReady'] = !empty($output);
        }
        
        echo json_encode($result);
        break;

    // ========================================
    // แสดงรายชื่อเครื่องสแกนที่เชื่อมต่ออยู่
    // ========================================
    case 'listDevices':
        $naps2Path = NAPS2_CONSOLE_PATH;
        if (!file_exists($naps2Path)) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบ NAPS2 Console กรุณาติดตั้งก่อน']);
            break;
        }
        
        $output = [];
        $drivers = ['twain', 'wia'];
        $allDevices = [];
        
        foreach ($drivers as $driver) {
            $cmd = '"' . $naps2Path . '" --listdevices --driver ' . $driver . ' 2>&1';
            $driverOutput = [];
            exec($cmd, $driverOutput, $returnCode);
            foreach ($driverOutput as $device) {
                $device = trim($device);
                if (!empty($device)) {
                    $allDevices[] = [
                        'name' => $device,
                        'driver' => $driver
                    ];
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'devices' => $allDevices
        ]);
        break;

    // ========================================
    // สั่งสแกนเอกสาร
    // ========================================
    case 'scan':
        $naps2Path = NAPS2_CONSOLE_PATH;
        if (!file_exists($naps2Path)) {
            echo json_encode([
                'success' => false, 
                'message' => 'ไม่พบ NAPS2 Console กรุณาติดตั้ง NAPS2 จาก https://www.naps2.com/download'
            ]);
            break;
        }
        
        // Get scan parameters (with defaults)
        $defaults = json_decode(SCANNER_DEFAULTS, true);
        $driver     = $input['driver']    ?? $defaults['driver'];
        $dpi        = intval($input['dpi'] ?? $defaults['dpi']);
        $colorMode  = $input['colorMode'] ?? $defaults['colorMode'];
        $source     = $input['source']    ?? $defaults['source'];
        $format     = $input['format']    ?? $defaults['format'];
        $pageSize   = $input['pageSize']  ?? $defaults['pageSize'];
        $deviceName = $input['device']    ?? SCANNER_DEVICE_NAME;
        
        // Sanitize values
        $allowedDrivers = ['twain', 'wia', 'escl'];
        $allowedDpi = [150, 200, 300, 400, 600];
        $allowedColor = ['color', 'gray', 'bw'];
        $allowedSource = ['feeder', 'glass', 'duplex'];
        $allowedFormat = ['pdf', 'png', 'jpg', 'tiff'];
        $allowedPageSize = ['a4', 'letter', 'legal', 'a3'];
        
        if (!in_array($driver, $allowedDrivers)) $driver = 'twain';
        if (!in_array($dpi, $allowedDpi)) $dpi = 300;
        if (!in_array($colorMode, $allowedColor)) $colorMode = 'color';
        if (!in_array($source, $allowedSource)) $source = 'feeder';
        if (!in_array($format, $allowedFormat)) $format = 'pdf';
        if (!in_array($pageSize, $allowedPageSize)) $pageSize = 'a4';
        
        // Generate output filename
        $timestamp = date('Ymd_His');
        $uniqueId = uniqid();
        $outputFilename = "scan_{$timestamp}_{$uniqueId}.{$format}";
        $outputPath = SCAN_OUTPUT_DIR . $outputFilename;
        
        // Ensure uploads directory exists
        if (!is_dir(SCAN_OUTPUT_DIR)) {
            mkdir(SCAN_OUTPUT_DIR, 0755, true);
        }
        
        // Build NAPS2 Console command
        // Reference: https://www.naps2.com/doc/command-line
        $cmdParts = [
            '"' . $naps2Path . '"',
            '-o "' . $outputPath . '"',
            '--driver ' . escapeshellarg($driver),
            '--device ' . escapeshellarg($deviceName),
            '--dpi ' . $dpi,
            '--source ' . $source,
            '--pagesize ' . $pageSize,
            '--force',           // Don't prompt for device selection
            '--progress',        // Show progress
        ];
        
        // Color mode mapping
        switch ($colorMode) {
            case 'color': $cmdParts[] = '--bitdepth color'; break;
            case 'gray':  $cmdParts[] = '--bitdepth gray'; break;
            case 'bw':    $cmdParts[] = '--bitdepth bw'; break;
        }
        
        // Duplex scanning
        if ($source === 'duplex') {
            $cmdParts[] = '--source duplex';
        }
        
        $cmd = implode(' ', $cmdParts) . ' 2>&1';
        
        // Log the scan attempt
        addAuditLog($pdo, $_SESSION['user_id'] ?? null, "Scan Document", 
            "Started scan: {$deviceName}, DPI:{$dpi}, Color:{$colorMode}, Source:{$source}, Format:{$format}");
        
        // Execute scan command with timeout
        $descriptorspec = [
            0 => ["pipe", "r"],   // stdin
            1 => ["pipe", "w"],   // stdout
            2 => ["pipe", "w"]    // stderr
        ];
        
        $process = proc_open($cmd, $descriptorspec, $pipes, null, null);
        
        if (is_resource($process)) {
            fclose($pipes[0]);
            
            // Set timeout
            $timeout = SCAN_TIMEOUT;
            $startTime = time();
            $stdout = '';
            $stderr = '';
            
            // Read output with timeout
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) break;
                if ((time() - $startTime) > $timeout) {
                    proc_terminate($process);
                    echo json_encode([
                        'success' => false,
                        'message' => "หมดเวลาสแกน (Timeout: {$timeout} วินาที) - กรุณาตรวจสอบเครื่องสแกน"
                    ]);
                    break 2;
                }
                
                $stdout .= fread($pipes[1], 4096);
                $stderr .= fread($pipes[2], 4096);
                usleep(100000); // 100ms
            }
            
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            
            fclose($pipes[1]);
            fclose($pipes[2]);
            
            $returnCode = proc_close($process);
            
            // Check result
            if ($returnCode === 0 && file_exists($outputPath)) {
                $fileSize = filesize($outputPath);
                $fileSizeFormatted = $fileSize > 1048576 
                    ? round($fileSize / 1048576, 2) . ' MB' 
                    : round($fileSize / 1024, 2) . ' KB';
                
                addAuditLog($pdo, $_SESSION['user_id'] ?? null, "Scan Complete", 
                    "Scanned file: {$outputFilename} ({$fileSizeFormatted})");
                
                echo json_encode([
                    'success' => true,
                    'message' => 'สแกนเอกสารสำเร็จ',
                    'fileId' => 'uploads/' . $outputFilename,
                    'filename' => $outputFilename,
                    'fileSize' => $fileSizeFormatted,
                    'format' => $format,
                    'dpi' => $dpi,
                    'colorMode' => $colorMode,
                    'source' => $source
                ]);
            } else {
                // Clean up failed file if exists
                if (file_exists($outputPath)) {
                    unlink($outputPath);
                }
                
                $errorMsg = trim($stderr ?: $stdout);
                if (empty($errorMsg)) {
                    $errorMsg = "สแกนไม่สำเร็จ (Exit Code: {$returnCode})";
                }
                
                // Provide user-friendly error messages
                if (stripos($errorMsg, 'no device') !== false || stripos($errorMsg, 'not found') !== false) {
                    $errorMsg = "ไม่พบเครื่องสแกน \"{$deviceName}\" - กรุณาตรวจสอบการเชื่อมต่อ USB และเปิดเครื่องสแกน";
                } elseif (stripos($errorMsg, 'paper') !== false || stripos($errorMsg, 'empty') !== false) {
                    $errorMsg = "ไม่มีกระดาษในถาดป้อนเอกสาร - กรุณาใส่กระดาษแล้วลองใหม่";
                } elseif (stripos($errorMsg, 'busy') !== false || stripos($errorMsg, 'locked') !== false) {
                    $errorMsg = "เครื่องสแกนกำลังทำงานอยู่ - กรุณารอสักครู่แล้วลองใหม่";
                } elseif (stripos($errorMsg, 'jam') !== false) {
                    $errorMsg = "กระดาษติด - กรุณาเปิดเครื่องสแกนและนำกระดาษที่ติดออก";
                }
                
                addAuditLog($pdo, $_SESSION['user_id'] ?? null, "Scan Failed", 
                    "Error: {$errorMsg}");
                
                echo json_encode([
                    'success' => false,
                    'message' => $errorMsg,
                    'debug' => [
                        'cmd' => $cmd,
                        'stdout' => $stdout,
                        'stderr' => $stderr,
                        'returnCode' => $returnCode
                    ]
                ]);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'ไม่สามารถเรียก NAPS2 Console ได้ - กรุณาตรวจสอบ path ของ NAPS2'
            ]);
        }
        break;

    // ========================================
    // ดึงค่า settings ปัจจุบัน
    // ========================================
    case 'getScanSettings':
        echo json_encode([
            'success' => true,
            'defaults' => json_decode(SCANNER_DEFAULTS, true),
            'deviceName' => SCANNER_DEVICE_NAME,
            'options' => [
                'dpi' => [150, 200, 300, 400, 600],
                'colorMode' => [
                    ['value' => 'color', 'label' => 'สี (Color)'],
                    ['value' => 'gray', 'label' => 'ขาวดำ (Grayscale)'],
                    ['value' => 'bw', 'label' => 'ขาวดำชัด (Black & White)'],
                ],
                'source' => [
                    ['value' => 'feeder', 'label' => 'ถาดป้อนเอกสาร (ADF)'],
                    ['value' => 'duplex', 'label' => 'สแกน 2 หน้า (Duplex ADF)'],
                    ['value' => 'glass', 'label' => 'กระจก (Flatbed)'],
                ],
                'format' => [
                    ['value' => 'pdf', 'label' => 'PDF'],
                    ['value' => 'png', 'label' => 'PNG'],
                    ['value' => 'jpg', 'label' => 'JPEG'],
                    ['value' => 'tiff', 'label' => 'TIFF'],
                ],
                'pageSize' => [
                    ['value' => 'a4', 'label' => 'A4'],
                    ['value' => 'a3', 'label' => 'A3'],
                    ['value' => 'letter', 'label' => 'Letter'],
                    ['value' => 'legal', 'label' => 'Legal'],
                ],
            ]
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
?>
