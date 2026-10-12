<?php
/**
 * Public document verification (target of the QR code stamped on each PDF).
 *   verify.php?t=<token>   QR codes from this version: random, unguessable token
 *   verify.php?id=<ID>     QR codes stamped by older versions: confirms the ID only
 * Anyone holding the file can also upload it here to check it is byte-for-byte the archived copy.
 */
require_once 'config/database.php';

$token = preg_match('/^[a-f0-9]{32}$/', $_GET['t'] ?? '') ? $_GET['t'] : '';
$legacyId = $token === '' ? trim((string)($_GET['id'] ?? '')) : '';

$doc = null;
if ($token !== '') {
    $stmt = $pdo->prepare("SELECT d.ID, d.Filename, COALESCE(f.FolderName, 'ไม่มีแฟ้ม') AS FolderName, d.PaymentDate, d.CreatedAt,
                                  d.ExpiryDate, d.WorkflowStatus
                           FROM documents d LEFT JOIN folders f ON d.FolderId = f.ID
                           WHERE d.VerifyToken = ? AND d.DeletedAt IS NULL");
    $stmt->execute([$token]);
    $doc = $stmt->fetch();
} elseif ($legacyId !== '') {
    $stmt = $pdo->prepare("SELECT ID, CreatedAt, ExpiryDate, WorkflowStatus FROM documents WHERE ID = ? AND DeletedAt IS NULL");
    $stmt->execute([$legacyId]);
    $doc = $stmt->fetch();
}

// Optional: compare an uploaded file with the archived copy
$hashResult = null;
if ($doc && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    if ($_FILES['file']['size'] > 100 * 1024 * 1024) {
        $hashResult = 'toolarge';
    } else {
        $hash = hash_file('sha256', $_FILES['file']['tmp_name']);
        $stmt = $pdo->prepare("SELECT IsCurrent FROM document_files WHERE DocumentID = ? AND (Sha256 = ? OR OriginalSha256 = ?)
                               ORDER BY IsCurrent DESC LIMIT 1");
        $stmt->execute([$doc['ID'], $hash, $hash]);
        $match = $stmt->fetchColumn();
        $hashResult = $match === false ? 'mismatch' : ((int)$match === 1 ? 'match' : 'old');
    }
    @unlink($_FILES['file']['tmp_name']);
}

$expired = $doc && $doc['ExpiryDate'] && strtotime($doc['ExpiryDate']) < strtotime('today');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$thaiDate = function ($d) {
    if (!$d) return '-';
    $t = strtotime($d);
    return date('j/n/', $t) . (date('Y', $t) + 543);
};
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>ตรวจสอบเอกสาร - Document Verification</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <style>
        :root {
          --font-body: 'Sarabun', sans-serif;
          --font-head: 'Plus Jakarta Sans', 'Sarabun', sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
          font-family: var(--font-body);
          background: #0f172a;
          background-image:
            radial-gradient(at 0% 0%, rgba(37, 99, 235, 0.25) 0px, transparent 50%),
            radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.2) 0px, transparent 50%);
          display: flex;
          justify-content: center;
          align-items: center;
          min-height: 100vh;
          padding: 24px 16px;
        }
        .card {
          background: #ffffff;
          padding: 36px 30px;
          border-radius: 24px;
          box-shadow: 0 25px 50px -12px rgba(0,0,0,0.35);
          text-align: center;
          max-width: 460px;
          width: 100%;
          animation: pop 0.4s ease;
        }
        @keyframes pop {
          from { opacity: 0; transform: scale(0.96); }
          to { opacity: 1; transform: scale(1); }
        }
        .icon-wrap {
          width: 76px; height: 76px; border-radius: 50%;
          display: flex; align-items: center; justify-content: center;
          margin: 0 auto 18px;
        }
        .icon-wrap.success { background: #ecfdf5; color: #059669; border: 2px solid #a7f3d0; }
        .icon-wrap.warn { background: #fffbeb; color: #d97706; border: 2px solid #fde68a; }
        .icon-wrap.error { background: #fef2f2; color: #dc2626; border: 2px solid #fecaca; }
        .icon-wrap .material-icons-round { font-size: 42px; }
        h1 { font-family: var(--font-head); margin: 0 0 6px 0; color: #0f172a; font-size: 22px; font-weight: 700; }
        p.subtitle { color: #64748b; margin-bottom: 24px; font-size: 14.5px; line-height: 1.5; }
        .details {
          text-align: left; background: #f8fafc; padding: 18px; border-radius: 14px;
          border: 1px solid #e2e8f0; font-size: 14.5px;
        }
        .details div {
          display: flex; justify-content: space-between; align-items: center; gap: 12px;
          margin-bottom: 12px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 10px;
        }
        .details div:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .details strong { color: #64748b; font-weight: 500; font-size: 13.5px; white-space: nowrap; }
        .details span.val { color: #0f172a; font-weight: 600; text-align: right; word-break: break-word; }
        .status-badge {
          display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px;
          background: #d1fae5; color: #065f46; font-weight: 600; font-size: 13px;
        }
        .status-badge.warn { background: #fef3c7; color: #92400e; }
        .hash-box { margin-top: 22px; text-align: left; border: 1.5px dashed #cbd5e1; border-radius: 14px; padding: 16px; }
        .hash-box h2 { font-size: 15px; color: #0f172a; margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
        .hash-box p { font-size: 13px; color: #64748b; margin-bottom: 12px; line-height: 1.5; }
        .hash-box input[type=file] { width: 100%; font-family: inherit; font-size: 13px; margin-bottom: 10px; }
        .hash-box button {
          width: 100%; padding: 10px; border: none; border-radius: 10px; background: #2563eb; color: #fff;
          font-family: inherit; font-weight: 600; font-size: 14px; cursor: pointer;
        }
        .result { margin-top: 14px; padding: 12px 14px; border-radius: 12px; font-size: 14px; font-weight: 600; display: flex; gap: 8px; align-items: flex-start; text-align: left; }
        .result.ok { background: #ecfdf5; color: #065f46; }
        .result.bad { background: #fef2f2; color: #991b1b; }
        .result.warn { background: #fffbeb; color: #92400e; }
        .footer-note { margin-top: 24px; font-size: 12.5px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($doc && $token !== ''): ?>
            <div class="icon-wrap <?= $expired ? 'warn' : 'success' ?>">
              <span class="material-icons-round"><?= $expired ? 'history' : 'verified' ?></span>
            </div>
            <h1>เอกสารนี้มีอยู่ในระบบ</h1>
            <p class="subtitle">QR Code นี้ออกโดยระบบจัดเก็บเอกสาร ตรวจสอบว่าข้อมูลด้านล่างตรงกับเอกสารที่ท่านถืออยู่<?= $expired ? ' (เอกสารครบอายุการเก็บรักษาแล้ว)' : '' ?></p>
            <div class="details">
                <div><strong>รหัสอ้างอิง</strong> <span class="val"><?= $h($doc['ID']) ?></span></div>
                <div><strong>ชื่อเรื่อง</strong> <span class="val"><?= $h($doc['Filename']) ?></span></div>
                <div><strong>แฟ้มจัดเก็บ</strong> <span class="val"><?= $h($doc['FolderName']) ?></span></div>
                <div><strong>วันที่จ่ายเงิน</strong> <span class="val"><?= $h($thaiDate($doc['PaymentDate'])) ?></span></div>
                <div><strong>วันที่นำเข้าระบบ</strong> <span class="val"><?= $h($thaiDate($doc['CreatedAt'])) ?></span></div>
                <div><strong>สถานะ</strong>
                  <?php if ($expired): ?>
                    <span class="status-badge warn"><span class="material-icons-round" style="font-size:14px;">history</span> ครบอายุการเก็บ</span>
                  <?php else: ?>
                    <span class="status-badge"><span class="material-icons-round" style="font-size:14px;">check_circle</span> ใช้งานอยู่</span>
                  <?php endif; ?>
                </div>
            </div>
        <?php elseif ($doc): ?>
            <div class="icon-wrap warn">
              <span class="material-icons-round">info</span>
            </div>
            <h1>พบรหัสเอกสารในระบบ</h1>
            <p class="subtitle">QR Code รุ่นเก่ายืนยันได้เฉพาะว่ามีรหัส <b><?= $h($doc['ID']) ?></b> ในระบบ
              หากต้องการยืนยันว่าไฟล์ไม่ถูกแก้ไข ให้อัปโหลดไฟล์ด้านล่าง หรือติดต่อหน่วยงานเจ้าของเอกสาร</p>
        <?php else: ?>
            <div class="icon-wrap error">
              <span class="material-icons-round">gpp_bad</span>
            </div>
            <h1>ไม่พบเอกสาร</h1>
            <p class="subtitle">เอกสารฉบับนี้อาจถูกยกเลิก ลบออกจากระบบ หรือไม่มีอยู่จริง กรุณาตรวจสอบกับหน่วยงานเจ้าของเอกสาร</p>
        <?php endif; ?>

        <?php if ($doc): ?>
            <form class="hash-box" method="post" enctype="multipart/form-data">
                <h2><span class="material-icons-round" style="font-size:18px; color:#2563eb;">fingerprint</span> ตรวจว่าไฟล์ไม่ถูกแก้ไข</h2>
                <p>เลือกไฟล์ PDF ที่ได้รับมา ระบบจะเทียบลายนิ้วมือดิจิทัล (SHA-256) กับต้นฉบับที่จัดเก็บไว้ ไฟล์จะไม่ถูกบันทึก</p>
                <input type="file" name="file" accept=".pdf,image/*,.tif,.tiff" required>
                <button type="submit">ตรวจสอบไฟล์</button>
                <?php if ($hashResult === 'match'): ?>
                    <div class="result ok"><span class="material-icons-round">verified</span> ไฟล์ตรงกับต้นฉบับที่จัดเก็บไว้ทุกไบต์ ไม่ถูกแก้ไข</div>
                <?php elseif ($hashResult === 'old'): ?>
                    <div class="result warn"><span class="material-icons-round">history</span> ไฟล์ตรงกับฉบับเก่าที่ถูกแทนที่แล้ว ไม่ใช่ฉบับปัจจุบัน</div>
                <?php elseif ($hashResult === 'mismatch'): ?>
                    <div class="result bad"><span class="material-icons-round">report</span> ไฟล์ไม่ตรงกับต้นฉบับ อาจถูกแก้ไข หรือเป็นไฟล์ที่พิมพ์/สแกนซ้ำ</div>
                <?php elseif ($hashResult === 'toolarge'): ?>
                    <div class="result bad"><span class="material-icons-round">error</span> ไฟล์ใหญ่เกิน 100 MB</div>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <div class="footer-note">
          ระบบจัดเก็บเอกสารอิเล็กทรอนิกส์ &copy; <?= date('Y') + 543 ?>
        </div>
    </div>
</body>
</html>
