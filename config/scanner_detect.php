<?php
/**
 * Scanner discovery via `NAPS2.Console --listdevices`
 * ---------------------------------------------------
 * - stdout และ stderr แยกกัน ข้อความ error จึงไม่ถูกนับเป็นชื่อเครื่องสแกน
 * - ตรวจ exit code และมีเวลาจำกัด ถ้า NAPS2 ค้างจะถูกปิดทิ้ง (รวม process ลูก)
 * - หลายไดรเวอร์ค้นพร้อมกัน เวลารอรวม = ไดรเวอร์ที่ช้าที่สุด ไม่ใช่ผลรวม
 *
 * ต้อง define NAPS2_CONSOLE_PATH ก่อน (config/scanner.php)
 */

/**
 * @return array driver => ['devices' => string[], 'error' => ?string]
 */
function detectScanners(array $drivers, $timeoutSec) {
    $procs = [];
    foreach ($drivers as $driver) {
        // Output goes to temp files, not pipes: no pipe-buffer deadlock on Windows, and nothing to poll
        $out = tempnam(sys_get_temp_dir(), 'n2o');
        $err = tempnam(sys_get_temp_dir(), 'n2e');
        $proc = @proc_open([NAPS2_CONSOLE_PATH, '--listdevices', '--driver', $driver],
            [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes);
        if (!is_resource($proc)) {
            @unlink($out); @unlink($err);
            $procs[$driver] = ['failed' => 'เรียก NAPS2 Console ไม่ได้ - ตรวจสอบ path ของ NAPS2'];
            continue;
        }
        fclose($pipes[0]);
        $procs[$driver] = ['proc' => $proc, 'out' => $out, 'err' => $err, 'exit' => null];
    }

    $deadline = microtime(true) + $timeoutSec;
    do {
        $running = false;
        foreach ($procs as &$p) {
            if (!isset($p['proc']) || $p['exit'] !== null) continue;
            $st = proc_get_status($p['proc']);
            // exitcode is only reported on the first call after the process ends, so keep it now
            if ($st['running']) $running = true;
            else $p['exit'] = (int)$st['exitcode'];
        }
        unset($p);
        if ($running) usleep(100000);
    } while ($running && microtime(true) < $deadline);

    $result = [];
    foreach ($procs as $driver => $p) {
        if (isset($p['failed'])) {
            $result[$driver] = ['devices' => [], 'error' => $p['failed']];
            continue;
        }
        $timedOut = $p['exit'] === null;
        if ($timedOut) killProcessTree($p['proc']);
        proc_close($p['proc']);
        $stdout = trim((string)@file_get_contents($p['out']));
        $stderr = trim((string)@file_get_contents($p['err']));
        @unlink($p['out']);
        @unlink($p['err']);

        $devices = [];
        $error = null;
        if ($timedOut) {
            $error = "ค้นหาเครื่องเกิน {$timeoutSec} วินาที";
        } elseif ($p['exit'] !== 0) {
            $error = firstLine($stderr ?: $stdout) ?: "NAPS2 ทำงานไม่สำเร็จ (Exit Code: {$p['exit']})";
        } else {
            foreach (preg_split('/\R/', $stdout) as $line) {
                $line = trim($line);
                if ($line !== '' && !looksLikeNaps2Message($line)) $devices[] = $line;
            }
            if (!$devices && $stderr !== '') $error = firstLine($stderr);
        }
        $result[$driver] = ['devices' => array_values(array_unique($devices)), 'error' => $error];
    }
    return $result;
}

/** Lines NAPS2/.NET prints that are messages, not device names. */
function looksLikeNaps2Message($line) {
    return (bool)preg_match('/^(error|warning|exception|unhandled|usage:|at\s)|exception:|is not supported|not available|no devices? found/i', $line);
}

function firstLine($text) {
    $line = trim(strtok((string)$text, "\r\n") ?: '');
    return mb_substr($line, 0, 300);
}

/** NAPS2 may start a worker process (32-bit TWAIN); kill the whole tree so nothing keeps the scanner open. */
function killProcessTree($proc) {
    $st = proc_get_status($proc);
    if (DIRECTORY_SEPARATOR === '\\' && !empty($st['pid'])) {
        exec('taskkill /F /T /PID ' . (int)$st['pid'] . ' >NUL 2>NUL');
    }
    proc_terminate($proc);
}

/** NAPS2's --device matches by name containing the given text, case-insensitive; mirror that. */
function deviceNameMatches($name, $wanted) {
    $wanted = trim((string)$wanted);
    return $wanted !== '' && stripos($name, $wanted) !== false;
}
