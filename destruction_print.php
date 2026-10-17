<?php
/**
 * Printable forms for record destruction (ระเบียบงานสารบรรณ):
 *   destruction_print.php?id=N      บัญชีหนังสือขอทำลาย with committee signature lines
 *   destruction_print.php?year=2569 annual destruction report
 */
require_once 'config/auth.php';
startSecureSession();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!isAdmin()) { http_response_code(403); exit('เฉพาะผู้ดูแลระบบเท่านั้น'); }
require_once 'config/database.php';
require_once 'config/helpers.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
$thaiDate = fn($d) => $d ? (int)date('j', strtotime($d)) . ' ' . $thaiMonths[(int)date('n', strtotime($d))] . ' ' . ((int)date('Y', strtotime($d)) + 543) : '-';
$shortDate = fn($d) => $d ? date('j/n/', strtotime($d)) . ((int)date('Y', strtotime($d)) + 543) : '-';
$org = getSetting($pdo, 'org_name', 'กองคลัง');

$batch = null;
$items = [];
$yearly = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM destruction_batches WHERE ID = ?");
    $stmt->execute([(int)$_GET['id']]);
    $batch = $stmt->fetch();
    if ($batch) {
        $batch['Committee'] = json_decode((string)$batch['Committee'], true) ?: [];
        $stmt = $pdo->prepare("SELECT * FROM destruction_items WHERE BatchID = ? ORDER BY ExpiryDate, DocumentID");
        $stmt->execute([$batch['ID']]);
        $items = $stmt->fetchAll();
    }
} elseif (isset($_GET['year'])) {
    $year = (int)$_GET['year'];
    $stmt = $pdo->prepare("SELECT b.*, (SELECT COUNT(*) FROM destruction_items i WHERE i.BatchID = b.ID) AS Items,
                                  (SELECT COUNT(*) FROM destruction_items i WHERE i.BatchID = b.ID AND i.Decision = 'destroy') AS Destroyed,
                                  (SELECT COUNT(*) FROM destruction_items i WHERE i.BatchID = b.ID AND i.Decision = 'keep') AS Kept
                           FROM destruction_batches b WHERE b.FiscalYear = ? ORDER BY b.ID");
    $stmt->execute([$year]);
    $yearly = ['year' => $year, 'batches' => $stmt->fetchAll()];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title><?= $batch ? $h($batch['Title']) : 'รายงานการทำลายเอกสาร' ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    @page { size: A4 landscape; margin: 12mm; }
    body { font-family: 'Sarabun', sans-serif; font-size: 15px; color: #000; margin: 0; background: #e2e8f0; }
    .toolbar { background: #0f172a; color: #fff; padding: 10px 16px; position: sticky; top: 0; }
    .toolbar button { background: #2563eb; color: #fff; border: 0; padding: 8px 16px; border-radius: 8px; font-family: inherit; font-size: 15px; cursor: pointer; }
    .paper { background: #fff; max-width: 277mm; margin: 16px auto; padding: 12mm; }
    h1 { text-align: center; font-size: 20px; margin: 0 0 4px; }
    .sub { text-align: center; margin-bottom: 12px; }
    .meta { display: flex; justify-content: space-between; margin-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #000; padding: 4px 6px; vertical-align: top; }
    th { background: #f1f5f9; font-weight: 600; }
    td.c { text-align: center; }
    .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px 18px; margin-top: 24px; page-break-inside: avoid; }
    .sign div { text-align: center; line-height: 1.9; }
    @media print { body { background: #fff; } .toolbar { display: none; } .paper { margin: 0; padding: 0; max-width: none; } }
  </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">🖨 พิมพ์</button></div>
<div class="paper">
<?php if ($batch): ?>
  <h1>บัญชีหนังสือขอทำลาย ประจำปี พ.ศ. <?= (int)$batch['FiscalYear'] ?></h1>
  <div class="sub"><?= $h($batch['Title']) ?></div>
  <div class="meta"><span>ส่วนราชการ: <?= $h($org) ?></span><span>วันที่ <?= $h($thaiDate($batch['CreatedAt'])) ?></span><span>จำนวน <?= count($items) ?> รายการ</span></div>
  <table>
    <thead><tr><th style="width:52px;">ลำดับที่</th><th style="width:110px;">ที่</th><th style="width:90px;">ลงวันที่</th><th>เรื่อง</th><th style="width:150px;">รหัสแฟ้ม</th><th style="width:150px;">การพิจารณา</th><th style="width:120px;">หมายเหตุ</th></tr></thead>
    <tbody>
    <?php foreach ($items as $n => $i): ?>
      <tr>
        <td class="c"><?= $n + 1 ?></td>
        <td><?= $h($i['DocumentID']) ?></td>
        <td class="c"><?= $h($shortDate($i['DocDate'])) ?></td>
        <td><?= $h($i['Title']) ?></td>
        <td><?= $h($i['FolderName']) ?></td>
        <td><?= $i['Decision'] === 'keep' ? 'ขยายเวลาเก็บ ' . (int)$i['KeepYears'] . ' ปี' : 'ทำลาย' ?></td>
        <td><?= $h($i['Note'] ?: 'ครบอายุ ' . $shortDate($i['ExpiryDate'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($batch['Note']): ?><p><b>ความเห็นคณะกรรมการ:</b> <?= nl2br($h($batch['Note'])) ?></p><?php endif; ?>
  <div class="sign">
    <?php foreach ($batch['Committee'] ?: [['name' => '', 'position' => '', 'role' => 'ประธานกรรมการ'], ['name' => '', 'position' => '', 'role' => 'กรรมการ'], ['name' => '', 'position' => '', 'role' => 'กรรมการและเลขานุการ']] as $m): ?>
      <div>ลงชื่อ ..........................................<br>(<?= $h($m['name'] ?: '..........................................') ?>)<br><?= $h($m['position']) ?><br><?= $h($m['role']) ?></div>
    <?php endforeach; ?>
  </div>
<?php elseif ($yearly): ?>
  <h1>รายงานการทำลายเอกสาร ประจำปีงบประมาณ พ.ศ. <?= (int)$yearly['year'] ?></h1>
  <div class="sub"><?= $h($org) ?></div>
  <table>
    <thead><tr><th style="width:60px;">ลำดับ</th><th>บัญชีขอทำลาย</th><th style="width:110px;">สถานะ</th><th style="width:110px;">วันที่อนุมัติ</th><th style="width:110px;">วันที่ทำลาย</th><th style="width:90px;">ทั้งหมด</th><th style="width:90px;">ทำลาย</th><th style="width:90px;">เก็บต่อ</th></tr></thead>
    <tbody>
    <?php $sum = [0, 0, 0]; foreach ($yearly['batches'] as $n => $b): $sum[0] += $b['Items']; $sum[1] += $b['Destroyed']; $sum[2] += $b['Kept']; ?>
      <tr><td class="c"><?= $n + 1 ?></td><td><?= $h($b['Title']) ?></td>
        <td><?= ['draft' => 'ร่าง', 'review' => 'กำลังพิจารณา', 'approved' => 'อนุมัติแล้ว', 'done' => 'ทำลายแล้ว'][$b['Status']] ?? $h($b['Status']) ?></td>
        <td class="c"><?= $h($shortDate($b['ApprovedAt'])) ?></td><td class="c"><?= $h($shortDate($b['DoneAt'])) ?></td>
        <td class="c"><?= (int)$b['Items'] ?></td><td class="c"><?= (int)$b['Destroyed'] ?></td><td class="c"><?= (int)$b['Kept'] ?></td></tr>
    <?php endforeach; ?>
      <tr><th colspan="5" style="text-align:right;">รวม</th><th><?= $sum[0] ?></th><th><?= $sum[1] ?></th><th><?= $sum[2] ?></th></tr>
    </tbody>
  </table>
<?php else: ?>
  <p>ไม่พบข้อมูล</p>
<?php endif; ?>
</div>
</body>
</html>
