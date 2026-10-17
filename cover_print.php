<?php
// Printable cover sheets (one A4 page per document): cover_print.php?ids=DOC000001,DOC000002
require_once 'config/auth.php';
startSecureSession();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'config/database.php';
require_once 'config/helpers.php';
require_once 'vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

$ids = array_slice(array_values(array_filter(array_map('trim', explode(',', (string)($_GET['ids'] ?? ''))), 'strlen')), 0, 200);
$docs = [];
if ($ids) {
    $params = $ids;
    $scope = folderScopeSql($pdo, $params);
    $stmt = $pdo->prepare("SELECT d.ID, d.Filename, d.PaymentDate, d.ExpectedPages, d.Tags, COALESCE(f.FolderName, '-') AS FolderName,
                                  " . locationLabelSql('l') . " AS Location
                           FROM documents d LEFT JOIN folders f ON f.ID = d.FolderId LEFT JOIN locations l ON l.ID = d.LocationId
                           WHERE d.ID IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND d.DeletedAt IS NULL AND $scope");
    $stmt->execute($params);
    $byId = array_column($stmt->fetchAll(), null, 'ID');
    foreach ($ids as $id) if (isset($byId[$id])) $docs[] = $byId[$id];
    if ($docs) {
        $pdo->prepare("UPDATE documents SET CoverPrintedAt = NOW() WHERE ID IN (" . implode(',', array_fill(0, count($docs), '?')) . ")")
            ->execute(array_column($docs, 'ID'));
    }
}
$org = getSetting($pdo, 'org_name', 'กองคลัง');
$qrOptions = new QROptions(['outputType' => QRCode::OUTPUT_IMAGE_PNG, 'eccLevel' => QRCode::ECC_M, 'scale' => 8]);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$thaiDate = fn($d) => $d ? date('j/n/', strtotime($d)) . (date('Y', strtotime($d)) + 543) : '-';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>ใบปะหน้าสำหรับสแกน (<?= count($docs) ?> ใบ)</title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.5/JsBarcode.all.min.js"></script>
  <style>
    @page { size: A4; margin: 14mm; }
    * { box-sizing: border-box; }
    body { font-family: 'Sarabun', sans-serif; margin: 0; color: #0f172a; background: #e2e8f0; }
    .toolbar { position: sticky; top: 0; background: #0f172a; color: #fff; padding: 10px 16px; display: flex; gap: 12px; align-items: center; z-index: 2; }
    .toolbar button { background: #2563eb; color: #fff; border: 0; padding: 8px 16px; border-radius: 8px; font-family: inherit; font-size: 15px; cursor: pointer; }
    .sheet { background: #fff; width: 210mm; min-height: 297mm; margin: 16px auto; padding: 14mm; display: flex; flex-direction: column; page-break-after: always; }
    .org { font-size: 14px; color: #475569; display: flex; justify-content: space-between; border-bottom: 2px solid #0f172a; padding-bottom: 6px; }
    .title-band { margin: 14mm 0 8mm; text-align: center; }
    .title-band .k { font-size: 18px; letter-spacing: 6px; color: #475569; }
    .title-band .id { font-size: 44px; font-weight: 700; letter-spacing: 2px; }
    .main { display: flex; gap: 10mm; align-items: flex-start; }
    .qr { width: 70mm; height: 70mm; border: 1px solid #cbd5e1; padding: 3mm; }
    .qr img { width: 100%; height: 100%; image-rendering: pixelated; }
    table { border-collapse: collapse; flex: 1; font-size: 16px; }
    td { padding: 6px 4px; border-bottom: 1px dashed #cbd5e1; vertical-align: top; }
    td:first-child { color: #475569; width: 34mm; white-space: nowrap; }
    .barcode { text-align: center; margin-top: 12mm; }
    .note { margin-top: auto; border: 2px dashed #94a3b8; border-radius: 6px; padding: 5mm; font-size: 14px; color: #334155; line-height: 1.6; }
    .empty { text-align: center; padding: 60px; font-size: 18px; }
    @media print { body { background: #fff; } .toolbar { display: none; } .sheet { margin: 0; width: auto; min-height: auto; height: 268mm; padding: 0; } }
  </style>
</head>
<body>
  <div class="toolbar">
    <button onclick="window.print()">🖨 พิมพ์ใบปะหน้า <?= count($docs) ?> ใบ</button>
    <span>วางใบปะหน้าไว้หน้าแรกของเอกสารแต่ละชุด แล้วใส่ทั้งปึกเข้าเครื่องสแกน</span>
  </div>
<?php if (!$docs): ?>
  <div class="sheet"><div class="empty">ไม่พบเอกสารที่จะพิมพ์ใบปะหน้า</div></div>
<?php endif; ?>
<?php foreach ($docs as $d): ?>
  <div class="sheet">
    <div class="org"><span><?= $h($org) ?> · ระบบจัดเก็บเอกสารอิเล็กทรอนิกส์</span><span>พิมพ์ <?= $h($thaiDate(date('Y-m-d'))) ?></span></div>
    <div class="title-band"><div class="k">ใบปะหน้าเอกสาร</div><div class="id"><?= $h($d['ID']) ?></div></div>
    <div class="main">
      <div class="qr"><img src="<?= (new QRCode($qrOptions))->render('EDMSCOVER:' . $d['ID']) ?>" alt="QR"></div>
      <table>
        <tr><td>เรื่อง</td><td><b><?= $h($d['Filename']) ?></b></td></tr>
        <tr><td>แฟ้มจัดเก็บ</td><td><?= $h($d['FolderName']) ?></td></tr>
        <tr><td>วันที่จ่ายเงิน</td><td><?= $h($thaiDate($d['PaymentDate'])) ?></td></tr>
        <tr><td>จำนวนหน้า</td><td><?= $d['ExpectedPages'] ? (int)$d['ExpectedPages'] . ' หน้า (ไม่รวมใบปะหน้า)' : '-' ?></td></tr>
        <tr><td>ที่เก็บตัวจริง</td><td><?= $h($d['Location'] ?: '-') ?></td></tr>
        <tr><td>แท็ก</td><td><?= $h($d['Tags'] ?: '-') ?></td></tr>
      </table>
    </div>
    <div class="barcode"><svg class="bc" data-code="<?= $h($d['ID']) ?>"></svg></div>
    <div class="note">
      <b>วิธีใช้:</b> วางใบนี้เป็นแผ่นแรกของเอกสาร <?= $h($d['ID']) ?> แล้วสแกนต่อกันทั้งปึกได้เลย ระบบจะอ่าน QR Code
      แยกเอกสารและจัดเก็บเข้ารหัสนี้ให้อัตโนมัติ ใบปะหน้าจะไม่ถูกเก็บรวมในไฟล์เอกสาร
    </div>
  </div>
<?php endforeach; ?>
<script>
  document.querySelectorAll('svg.bc').forEach(el => {
    try { JsBarcode(el, el.dataset.code, { format: 'CODE128', width: 2, height: 60, displayValue: true, fontSize: 18 }); } catch (e) { el.remove(); }
  });
</script>
</body>
</html>
