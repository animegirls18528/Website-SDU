<?php
/**
 * Reviewer link (no login): lists only the documents chosen for this link and serves watermarked copies.
 *   share.php?t=<token>              list
 *   share.php?t=<token>&f=<fileId>   view a file (add &download=1 when the link allows downloads)
 */
require_once 'config/database.php';
require_once 'config/helpers.php';
require_once 'config/storage.php';

$token = preg_match('/^[a-f0-9]{48}$/', $_GET['t'] ?? '') ? $_GET['t'] : '';
$link = null;
if ($token !== '') {
    $stmt = $pdo->prepare("SELECT * FROM share_links WHERE TokenHash = ?");
    $stmt->execute([hash('sha256', $token)]);
    $link = $stmt->fetch();
}
$state = !$link ? 'missing' : ($link['RevokedAt'] ? 'revoked' : (strtotime($link['ExpiresAt']) < time() ? 'expired' : 'ok'));
if ($state !== 'ok') http_response_code($state === 'missing' ? 404 : 410);
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$thaiDate = fn($d) => $d ? date('j/n/', strtotime($d)) . (date('Y', strtotime($d)) + 543) : '-';

if ($state === 'ok' && isset($_GET['f'])) {
    $stmt = $pdo->prepare("SELECT f.StoredPath, f.OriginalName, d.ID FROM document_files f
                           JOIN share_link_docs x ON x.DocumentID = f.DocumentID AND x.LinkID = ?
                           JOIN documents d ON d.ID = f.DocumentID AND d.DeletedAt IS NULL
                           WHERE f.ID = ? AND f.IsCurrent = 1");
    $stmt->execute([$link['ID'], (int)$_GET['f']]);
    $file = $stmt->fetch();
    $download = isset($_GET['download']) && (int)$link['AllowDownload'] === 1;
    if (!$file || !is_file($real = absPath($file['StoredPath']))) {
        http_response_code(404);
        exit('File not found');
    }
    $ext = strtolower(pathinfo($file['StoredPath'], PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        require_once 'config/docsign.php';
        $tmp = tempnam(sys_get_temp_dir(), 'shr_');
        $who = 'REVIEW COPY - LINK #' . $link['ID'];
        if (!watermarkPdf($real, $tmp, $who, "Shared review copy, link #{$link['ID']}, opened " . date('Y-m-d H:i:s') . " from " . ($_SERVER['REMOTE_ADDR'] ?? '') . " - not for redistribution")) {
            @unlink($tmp);
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            exit('ไม่สามารถเปิดไฟล์นี้ผ่านลิงก์ได้ กรุณาติดต่อเจ้าของเอกสาร');
        }
        signPdf($pdo, $tmp);
        $real = $tmp;
        register_shutdown_function(fn() => @unlink($tmp));
    }
    $pdo->prepare("UPDATE share_links SET LastAccessAt = NOW(), AccessCount = AccessCount + 1 WHERE ID = ?")->execute([$link['ID']]);
    addAuditLog($pdo, null, 'Share Access', "ลิงก์ #{$link['ID']} " . ($download ? 'ดาวน์โหลด' : 'เปิดดู') . " {$file['OriginalName']}", $file['ID']);
    header('Content-Type: ' . MIME_BY_EXT[$ext]);
    clearstatcache();
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $file['ID']) . '.' . $ext . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex');
    readfile($real);
    exit;
}

$docs = [];
if ($state === 'ok') {
    $pdo->prepare("UPDATE share_links SET LastAccessAt = NOW(), AccessCount = AccessCount + 1 WHERE ID = ?")->execute([$link['ID']]);
    addAuditLog($pdo, null, 'Share Access', "ลิงก์ #{$link['ID']} เปิดรายการเอกสาร จาก " . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $stmt = $pdo->prepare("SELECT d.ID, d.Filename, d.PaymentDate, COALESCE(fo.FolderName, '') AS FolderName
                           FROM share_link_docs x JOIN documents d ON d.ID = x.DocumentID AND d.DeletedAt IS NULL
                           LEFT JOIN folders fo ON fo.ID = d.FolderId WHERE x.LinkID = ? ORDER BY d.ID");
    $stmt->execute([$link['ID']]);
    $docs = $stmt->fetchAll();
    $files = $pdo->prepare("SELECT ID, OriginalName, PageCount FROM document_files WHERE DocumentID = ? AND IsCurrent = 1 ORDER BY ID");
    foreach ($docs as &$d) { $files->execute([$d['ID']]); $d['files'] = $files->fetchAll(); }
    unset($d);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>เอกสารสำหรับผู้ตรวจสอบ</title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
  <style>
    :root { --primary: #2563eb; --muted: #64748b; --border: #e2e8f0; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Sarabun', sans-serif; background: #f1f5f9; color: #0f172a; padding: 24px 16px; }
    .wrap { max-width: 920px; margin: 0 auto; }
    .head { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 22px; margin-bottom: 16px; display: flex; gap: 14px; align-items: flex-start; }
    .head .ico { width: 46px; height: 46px; border-radius: 12px; background: #eff6ff; color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    h1 { font-size: 1.25rem; }
    .meta { color: var(--muted); font-size: 14px; margin-top: 4px; line-height: 1.6; }
    .notice { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; border-radius: 12px; padding: 10px 14px; font-size: 13.5px; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--border); border-radius: 16px; overflow: hidden; font-size: 14px; }
    th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    th { background: #f8fafc; color: #475569; font-size: 12.5px; }
    .id { font-weight: 700; color: var(--primary); white-space: nowrap; }
    a.btn { display: inline-flex; align-items: center; gap: 4px; padding: 5px 10px; border: 1.5px solid var(--border); border-radius: 8px; color: #334155; text-decoration: none; font-size: 13px; margin: 2px 4px 2px 0; background: #fff; }
    a.btn:hover { border-color: var(--primary); color: var(--primary); }
    .center { text-align: center; background: #fff; border-radius: 16px; padding: 48px 20px; border: 1px solid var(--border); }
    .center .material-icons-round { font-size: 52px; color: #dc2626; }
    @media (max-width: 640px) { .hide-sm { display: none; } }
  </style>
</head>
<body>
<div class="wrap">
<?php if ($state !== 'ok'): ?>
  <div class="center">
    <span class="material-icons-round"><?= $state === 'expired' ? 'event_busy' : 'link_off' ?></span>
    <h1 style="margin-top:10px;"><?= $state === 'expired' ? 'ลิงก์หมดอายุแล้ว' : ($state === 'revoked' ? 'ลิงก์ถูกยกเลิกแล้ว' : 'ไม่พบลิงก์นี้') ?></h1>
    <p class="meta">กรุณาติดต่อหน่วยงานเจ้าของเอกสารเพื่อขอลิงก์ใหม่</p>
  </div>
<?php else: ?>
  <div class="head">
    <div class="ico"><span class="material-icons-round">verified_user</span></div>
    <div>
      <h1>เอกสารสำหรับผู้ตรวจสอบ</h1>
      <div class="meta">สำหรับ: <b><?= $h($link['Recipient']) ?></b><?= $link['Note'] ? ' · ' . $h($link['Note']) : '' ?><br>
        ลิงก์ใช้ได้ถึง <?= $h($thaiDate($link['ExpiresAt'])) ?> · <?= count($docs) ?> เอกสาร · <?= (int)$link['AllowDownload'] ? 'ดาวน์โหลดได้' : 'เปิดดูได้อย่างเดียว' ?></div>
    </div>
  </div>
  <div class="notice">ทุกไฟล์มีลายน้ำ "REVIEW COPY" และระบบบันทึกการเปิดดูทุกครั้ง ห้ามเผยแพร่ต่อ</div>
  <table>
    <thead><tr><th>รหัส</th><th>เรื่อง</th><th class="hide-sm">แฟ้ม</th><th class="hide-sm">วันที่จ่ายเงิน</th><th>ไฟล์</th></tr></thead>
    <tbody>
    <?php foreach ($docs as $d): ?>
      <tr>
        <td class="id"><?= $h($d['ID']) ?></td>
        <td><?= $h($d['Filename']) ?></td>
        <td class="hide-sm"><?= $h($d['FolderName']) ?></td>
        <td class="hide-sm"><?= $h($thaiDate($d['PaymentDate'])) ?></td>
        <td>
          <?php if (!$d['files']): ?><span class="meta">ไม่มีไฟล์</span><?php endif; ?>
          <?php foreach ($d['files'] as $i => $f): ?>
            <a class="btn" target="_blank" rel="noopener" href="share.php?t=<?= $h($token) ?>&f=<?= (int)$f['ID'] ?>"><span class="material-icons-round" style="font-size:16px;">visibility</span> ไฟล์ <?= $i + 1 ?><?= $f['PageCount'] ? ' (' . (int)$f['PageCount'] . ' หน้า)' : '' ?></a>
            <?php if ((int)$link['AllowDownload']): ?><a class="btn" href="share.php?t=<?= $h($token) ?>&f=<?= (int)$f['ID'] ?>&download=1"><span class="material-icons-round" style="font-size:16px;">download</span></a><?php endif; ?>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
</body>
</html>
