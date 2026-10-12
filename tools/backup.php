<?php
/**
 * Back up the database and uploaded files to BACKUP_DIR (outside the web root).
 *   php tools/backup.php
 * Schedule daily with Windows Task Scheduler, e.g.
 *   schtasks /Create /SC DAILY /ST 02:00 /TN "EDMS Backup" /TR "C:\xampp\php\php.exe C:\xampp\htdocs\Website-SDU-main\Website-SDU-main\tools\backup.php"
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/storage.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

$name = 'edms_' . date('Ymd_His');
$dir = BACKUP_DIR . DIRECTORY_SEPARATOR . $name;
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(1);
}
$status = ['name' => $name, 'startedAt' => date('c'), 'state' => 'running'];
file_put_contents("$dir/manifest.json", json_encode($status, JSON_PRETTY_PRINT));

try {
    // 1) database
    $sqlFile = "$dir/database.sql";
    $cmd = [MYSQLDUMP_PATH, '--host=' . $host, '--user=' . $username, '--single-transaction', '--routines',
            '--default-character-set=utf8mb4', '--result-file=' . $sqlFile, $dbname];
    if ($password !== '') array_splice($cmd, 3, 0, '--password=' . $password);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p) !== 0 || !is_file($sqlFile)) throw new RuntimeException("mysqldump failed: $err");

    $in = fopen($sqlFile, 'rb');
    $out = gzopen("$sqlFile.gz", 'wb6');
    while (!feof($in)) gzwrite($out, fread($in, 1 << 20));
    fclose($in); gzclose($out);
    unlink($sqlFile);
    $status['databaseBytes'] = filesize("$sqlFile.gz");

    // 2) uploaded files (stored as-is; PDFs and images are already compressed)
    $zip = new ZipArchive();
    if ($zip->open("$dir/uploads.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create uploads.zip');
    }
    $count = 0;
    foreach (listUploadFiles() as $rel => $info) {
        $zip->addFile(absPath($rel), $rel);
        $zip->setCompressionName($rel, ZipArchive::CM_STORE);
        $count++;
    }
    $zip->close();
    $status['files'] = $count;
    $status['uploadsBytes'] = is_file("$dir/uploads.zip") ? filesize("$dir/uploads.zip") : 0;
    $status['state'] = 'done';
    $status['finishedAt'] = date('c');
    addAuditLog($pdo, null, 'Backup', "Backup $name: $count files");
} catch (Throwable $e) {
    $status['state'] = 'failed';
    $status['error'] = $e->getMessage();
    addAuditLog($pdo, null, 'Backup Failed', $e->getMessage());
}
file_put_contents("$dir/manifest.json", json_encode($status, JSON_PRETTY_PRINT));
echo json_encode($status, JSON_PRETTY_PRINT), "\n";

// 3) keep only the newest BACKUP_KEEP successful sets
$sets = glob(BACKUP_DIR . DIRECTORY_SEPARATOR . 'edms_*', GLOB_ONLYDIR) ?: [];
rsort($sets);
foreach (array_slice($sets, BACKUP_KEEP) as $old) {
    foreach (glob("$old/*") as $f) unlink($f);
    rmdir($old);
}
exit($status['state'] === 'done' ? 0 : 1);
