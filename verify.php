<?php
require_once 'config/database.php';
$id = $_GET['id'] ?? '';
$doc = null;

if ($id) {
    $stmt = $pdo->prepare("SELECT d.ID, d.Filename, COALESCE(f.FolderName, 'ไม่มีแฟ้ม') as FolderName, d.PaymentDate, d.Status, d.CreatedAt 
                           FROM documents d 
                           LEFT JOIN folders f ON d.FolderId = f.ID 
                           WHERE d.ID = ? AND d.Status != 'Deleted'");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
          max-width: 440px;
          width: 100%;
          animation: pop 0.4s ease;
        }
        @keyframes pop {
          from { opacity: 0; transform: scale(0.96); }
          to { opacity: 1; transform: scale(1); }
        }
        .icon-wrap {
          width: 76px;
          height: 76px;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          margin: 0 auto 18px;
        }
        .icon-wrap.success {
          background: #ecfdf5;
          color: #059669;
          border: 2px solid #a7f3d0;
        }
        .icon-wrap.error {
          background: #fef2f2;
          color: #dc2626;
          border: 2px solid #fecaca;
        }
        .icon-wrap .material-icons-round {
          font-size: 42px;
        }
        h1 {
          font-family: var(--font-head);
          margin: 0 0 6px 0;
          color: #0f172a;
          font-size: 22px;
          font-weight: 700;
        }
        p.subtitle {
          color: #64748b;
          margin-bottom: 24px;
          font-size: 14.5px;
          line-height: 1.5;
        }
        .details {
          text-align: left;
          background: #f8fafc;
          padding: 18px;
          border-radius: 14px;
          border: 1px solid #e2e8f0;
          font-size: 14.5px;
        }
        .details div {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 12px;
          border-bottom: 1px dashed #e2e8f0;
          padding-bottom: 10px;
        }
        .details div:last-child {
          border-bottom: none;
          margin-bottom: 0;
          padding-bottom: 0;
        }
        .details strong {
          color: #64748b;
          font-weight: 500;
          font-size: 13.5px;
        }
        .details span.val {
          color: #0f172a;
          font-weight: 600;
          text-align: right;
          word-break: break-word;
        }
        .status-badge {
          display: inline-flex;
          align-items: center;
          gap: 4px;
          padding: 4px 10px;
          border-radius: 999px;
          background: #d1fae5;
          color: #065f46;
          font-weight: 600;
          font-size: 13px;
        }
        .footer-note {
          margin-top: 24px;
          font-size: 12.5px;
          color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($doc): ?>
            <div class="icon-wrap success">
              <span class="material-icons-round">verified</span>
            </div>
            <h1>เอกสารถูกต้อง (Verified)</h1>
            <p class="subtitle">เอกสารฉบับนี้ได้รับการตรวจสอบความถูกต้อง ออกโดยระบบและเป็นของแท้</p>
            <div class="details">
                <div><strong>รหัสอ้างอิง</strong> <span class="val"><?= htmlspecialchars($doc['ID']) ?></span></div>
                <div><strong>ชื่อเรื่อง</strong> <span class="val"><?= htmlspecialchars($doc['Filename']) ?></span></div>
                <div><strong>แฟ้มจัดเก็บ</strong> <span class="val"><?= htmlspecialchars($doc['FolderName']) ?></span></div>
                <div><strong>วันที่ออกเอกสาร</strong> <span class="val"><?= htmlspecialchars(date('d/m/Y', strtotime($doc['CreatedAt']))) ?></span></div>
                <div><strong>สถานะ</strong> <span class="status-badge"><span class="material-icons-round" style="font-size:14px;">check_circle</span> <?= htmlspecialchars($doc['Status']) ?></span></div>
            </div>
        <?php else: ?>
            <div class="icon-wrap error">
              <span class="material-icons-round">gpp_bad</span>
            </div>
            <h1>ไม่พบเอกสาร</h1>
            <p class="subtitle">เอกสารฉบับนี้อาจถูกยกเลิก ลบออกจากระบบ หรือไม่มีอยู่จริง กรุณาตรวจสอบรหัสเอกสารอีกครั้ง</p>
        <?php endif; ?>
        <div class="footer-note">
          ระบบจัดเก็บเอกสารอิเล็กทรอนิกส์ &copy; <?= date('Y') ?>
        </div>
    </div>
</body>
</html>
