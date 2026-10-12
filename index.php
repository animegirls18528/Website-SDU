<?php
require_once 'config/auth.php';
startSecureSession();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$isAdmin = isAdmin();
$canEdit = canEdit();
$roleLabel = ['admin' => 'ผู้ดูแลระบบ', 'staff' => 'เจ้าหน้าที่', 'viewer' => 'ผู้ดูเอกสาร'][$_SESSION['role']] ?? $_SESSION['role'];
$v = fn($f) => $f . '?v=' . @filemtime(__DIR__ . '/' . $f);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <base target="_top">
  <title>ระบบจัดเก็บเอกสาร</title>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <script>
    window.onerror = function(msg, url, line) {
      console.error("Error: ", msg);
      setTimeout(function() {
        var loader = document.getElementById('loader');
        if(loader) loader.style.display = 'none';
      }, 500);
      return false;
    };

    window.CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
    window.CURRENT_USER = <?= json_encode(['id' => (int)$_SESSION['user_id'], 'username' => $_SESSION['username'], 'role' => $_SESSION['role'], 'canEdit' => $canEdit]) ?>;

    // Attach the CSRF token to every same-origin request; send the user back to login when the session expires.
    (function() {
      const nativeFetch = window.fetch.bind(window);
      window.fetch = function(resource, options) {
        options = Object.assign({}, options);
        const url = new URL(typeof resource === 'string' ? resource : resource.url, location.href);
        if (url.origin === location.origin) {
          options.headers = new Headers(options.headers || {});
          options.headers.set('X-CSRF-Token', window.CSRF_TOKEN);
        }
        return nativeFetch(resource, options).then(res => {
          if (res.status === 401 && url.origin === location.origin) { location.href = 'login.php'; }
          return res;
        });
      };
    })();
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
  <link rel="stylesheet" href="<?= $v('assets/app.css') ?>">

  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.14.305/pdf.min.js"></script>
  <script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.5/JsBarcode.all.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>

  <nav class="sidebar">
    <div class="brand">
      <div class="brand-badge-icon">
        <span class="material-icons-round">folder_special</span>
      </div>
      <h2>
        ระบบจัดเก็บเอกสาร
        <span>Doc Management</span>
      </h2>
      <button class="btn btn-outline btn-icon menu-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open')" title="เมนู" aria-label="เมนู">
        <span class="material-icons-round" style="font-size:22px;">menu</span>
      </button>
    </div>
    <ul class="nav-links">
<?php if ($isAdmin): ?>
      <li data-view="analytics" onclick="App.navigate('analytics', this)">
        <span class="material-icons-round">insights</span>
        <span>แดชบอร์ดผู้บริหาร</span>
      </li>
<?php endif; ?>
      <li data-view="dashboard" class="active" onclick="App.navigate('dashboard', this)">
        <span class="material-icons-round">inventory_2</span>
        <span>คลังเอกสาร</span>
      </li>
<?php if ($canEdit): ?>
      <li data-view="scan" onclick="App.navigate('scan', this)">
        <span class="material-icons-round">scanner</span>
        <span>สแกนเอกสาร</span>
      </li>
      <li data-view="upload" onclick="App.navigate('upload', this)">
        <span class="material-icons-round">cloud_upload</span>
        <span>อัปโหลดเอกสาร</span>
      </li>
<?php endif; ?>
<?php if ($isAdmin): ?>
      <li data-view="settings" onclick="App.navigate('settings', this)">
        <span class="material-icons-round">tune</span>
        <span>ตั้งค่าระบบและผู้ใช้</span>
      </li>
<?php endif; ?>
    </ul>
  </nav>

  <main class="main-content">
    <header class="top-bar">
      <div class="search-box">
        <span class="material-icons-round" style="color:#94a3b8; font-size:20px;">search</span>
        <input type="text" id="globalSearch" placeholder="ค้นหา รหัส ชื่อเรื่อง แท็ก ดัชนี หรือข้อความในเอกสาร..." oninput="App.Docs.searchFromTopBar()">
      </div>

      <div class="top-user-profile" style="display:flex; align-items:center; gap:16px;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div class="user-avatar-circle">
            <?= htmlspecialchars(strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1))) ?>
          </div>
          <div style="line-height:1.25;">
            <div id="currentUserEmail" style="color:var(--text-main); font-weight:700; font-size: 0.95rem;">
              <?= htmlspecialchars($_SESSION['username'] ?? '') ?>
            </div>
            <div style="font-size:12px; color:var(--text-muted); display:flex; align-items:center; gap:4px; margin-top:2px;">
              <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:<?= $isAdmin ? '#f59e0b' : ($canEdit ? '#10b981' : '#64748b') ?>;"></span>
              <?= htmlspecialchars($roleLabel) ?>
            </div>
          </div>
        </div>

        <div style="display:flex; gap:6px; align-items:center; border-left:1px solid var(--border); padding-left:12px;">
          <button class="btn btn-outline btn-icon" onclick="App.Account.openPasswordModal()" title="เปลี่ยนรหัสผ่าน">
            <span class="material-icons-round" style="font-size:18px;">key</span>
          </button>
          <button class="btn btn-outline btn-icon" style="color:var(--danger); border-color:#fecaca; background:#fff;" onclick="window.location.href='logout.php'" title="ออกจากระบบ">
            <span class="material-icons-round" style="font-size:18px;">logout</span>
          </button>
        </div>
      </div>
    </header>
    <div id="app-view"></div>
  </main>

  <div id="loader" class="loader-overlay" style="display:none;">
    <div class="spinner-box">
      <div class="spinner"></div>
      <div class="loader-text" id="loader-text" style="font-weight:600; color:var(--text-main); font-size:1rem; text-align:center;">กำลังประมวลผล...</div>
    </div>
  </div>

  <!-- Modal: เปิดดูไฟล์ -->
  <div class="modal" id="viewer-modal" style="display:none;">
    <div class="modal-content" style="max-width:1000px; width:95%; height:92vh; display:flex; flex-direction:column; padding: 20px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; gap:12px;">
        <h2 style="margin:0; color:var(--primary); display:flex; align-items:center; gap:8px; font-size:1.2rem;">
          <span class="material-icons-round">description</span> <span id="viewer-title">เปิดดูเอกสาร</span>
        </h2>
        <div style="display:flex; gap:8px;">
          <a id="viewer-download" class="btn btn-outline" style="padding:7px 12px; text-decoration:none;" href="#"><span class="material-icons-round" style="font-size:18px;">download</span> ดาวน์โหลด</a>
          <button class="btn btn-outline btn-icon" onclick="App.Viewer.close()">
            <span class="material-icons-round" style="font-size: 20px;">close</span>
          </button>
        </div>
      </div>
      <div id="viewer-tabs" style="display:flex; gap:8px; margin-bottom:12px; overflow-x:auto;"></div>
      <div id="viewer-frame-container" style="flex-grow:1; background:#f8fafc; border-radius:12px; overflow:auto; border:1px solid var(--border);"></div>
    </div>
  </div>

  <!-- Modal: รายละเอียดเอกสาร -->
  <div class="modal" id="detail-modal" style="display:none;">
    <div class="modal-content" style="max-width:860px;">
      <div id="detail-body"></div>
    </div>
  </div>

  <!-- Modal: แก้ไขเอกสาร -->
  <div class="modal" id="edit-modal" style="display:none;">
    <div class="modal-content" style="max-width:720px;">
      <h2><span class="material-icons-round" style="vertical-align: middle; color:var(--primary);">edit_note</span> แก้ไขข้อมูลเอกสาร</h2>
      <div class="form-group">
        <label>รหัสเอกสาร (Barcode) *</label>
        <div style="display: flex; gap: 8px;">
          <select id="editDocPrefix" class="form-control" style="width: 200px;"></select>
          <input type="text" id="editDocIdInput" class="form-control" placeholder="ระบุตัวเลข เช่น 690001">
        </div>
      </div>
      <div class="form-group"><label>ชื่อเอกสาร / เรื่อง *</label><input type="text" id="edit-filename" class="form-control"></div>
      <div class="grid-2" style="gap:16px;">
        <div class="form-group"><label>แฟ้มจัดเก็บ</label><select id="edit-folder" class="form-control"></select></div>
        <div class="form-group"><label>วันที่จ่ายเงิน (ใช้คำนวณวันครบอายุ)</label><input type="date" id="edit-payment-date" class="form-control"></div>
      </div>
      <div class="form-group"><label>แท็ก (คั่นด้วยจุลภาค)</label><input type="text" id="edit-tags" class="form-control" placeholder="เช่น ใบเสร็จ, งบรายได้, ฝ่ายพัสดุ"></div>
      <div id="edit-custom-fields"></div>

      <div class="form-group" style="padding-top: 14px; border-top: 1px solid var(--border); margin-top: 16px;">
        <label style="color:var(--primary); font-size: 14.5px; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
          <span class="material-icons-round" style="font-size:20px;">attach_file</span> เพิ่มหรือเปลี่ยนไฟล์แนบ
        </label>
        <div style="background: #f8fafc; padding: 14px; border-radius: 12px; border: 1.5px solid var(--border); margin-bottom: 14px;">
          <label class="check-row" style="padding-top:0;">
            <input type="radio" name="edit-upload-mode" value="append" checked>
            <div><b>➕ แนบไฟล์เพิ่มเติม</b><small>ไฟล์เดิมยังอยู่ ไฟล์ใหม่ต่อท้าย</small></div>
          </label>
          <label class="check-row" style="padding-bottom:0;">
            <input type="radio" name="edit-upload-mode" value="replace">
            <div><b style="color:#b45309;">🔄 แทนที่ด้วยไฟล์ชุดใหม่ (สร้างเวอร์ชันใหม่)</b><small>ไฟล์เดิมถูกเก็บไว้ในประวัติเวอร์ชัน ดูย้อนหลังได้</small></div>
          </label>
        </div>
        <input type="file" id="edit-file-input" class="form-control" multiple accept=".pdf,image/*,.tif,.tiff" onchange="App.Editor.staged.addFiles(this)">
        <div id="edit-staged" style="margin-top:12px;"></div>
      </div>

      <div class="modal-actions">
        <button class="btn btn-outline" onclick="App.Editor.close()">ยกเลิก</button>
        <button class="btn btn-primary" onclick="App.Editor.save()"><span class="material-icons-round" style="font-size:18px;">save</span> บันทึก</button>
      </div>
    </div>
  </div>

  <!-- Modal: จัดหน้าเอกสาร -->
  <div class="modal" id="page-editor-modal" style="display:none;">
    <div class="modal-content" style="max-width:1100px; width:96%; height:92vh; display:flex; flex-direction:column; padding:22px;">
      <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
        <h2 style="margin:0; font-size:1.25rem; display:flex; align-items:center; gap:8px;"><span class="material-icons-round" style="color:var(--primary);">auto_fix_high</span> จัดหน้าเอกสาร</h2>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <button class="btn btn-outline" style="padding:7px 12px;" onclick="App.PageEditor.rotateAll(-90)"><span class="material-icons-round" style="font-size:18px;">rotate_left</span> หมุนทุกหน้า</button>
          <button class="btn btn-outline" style="padding:7px 12px;" onclick="App.PageEditor.rotateAll(90)"><span class="material-icons-round" style="font-size:18px;">rotate_right</span></button>
          <button class="btn btn-outline" style="padding:7px 12px;" onclick="App.PageEditor.reverse()"><span class="material-icons-round" style="font-size:18px;">swap_vert</span> กลับลำดับ</button>
        </div>
      </div>
      <p style="font-size:13px; color:var(--text-muted); margin-bottom:10px;">หมุน ลบ หรือย้ายตำแหน่งหน้า แล้วกดบันทึก ระบบจะรวมทุกหน้าเป็นไฟล์ PDF เดียว · <span id="pe-status"></span></p>
      <div id="pe-grid" class="pe-grid"></div>
      <div class="modal-actions" style="margin-top:14px;">
        <button class="btn btn-outline" onclick="App.PageEditor.close()">ยกเลิก</button>
        <button class="btn btn-primary" id="pe-save" onclick="App.PageEditor.save()"><span class="material-icons-round" style="font-size:18px;">save</span> บันทึกเป็น PDF</button>
      </div>
    </div>
  </div>

  <div class="modal" id="barcode-modal" style="display:none;">
    <div class="modal-content" style="text-align:center; max-width:440px;">
      <h2 style="margin-bottom:8px; color:var(--text-main);" class="no-print">บาร์โค้ดเอกสาร</h2>
      <p style="color:var(--text-muted); font-size:14px; margin-bottom:18px;" class="no-print">ใช้สำหรับติดหน้าแฟ้มหรือซองเอกสาร</p>
      <div style="padding:24px; background:#fff; border:2px dashed var(--border); border-radius:14px; margin-bottom:24px; display:inline-block;"><svg id="barcode-svg"></svg></div>
      <div style="display:flex; gap:12px; justify-content:center;" class="no-print">
        <button class="btn btn-outline" onclick="App.Docs.closeBarcode()">ปิด</button>
        <button class="btn btn-primary" onclick="window.print()"><span class="material-icons-round" style="font-size:18px;">print</span> พิมพ์บาร์โค้ด</button>
      </div>
    </div>
  </div>

  <!-- Quick Create Folder Modal -->
  <div class="modal" id="quick-folder-modal" style="display:none;">
    <div class="modal-content" style="max-width: 440px;">
      <h2 style="margin-bottom:16px; color:var(--text-main); display:flex; align-items:center; gap:8px;">
        <span class="material-icons-round" style="color:var(--primary);">create_new_folder</span> สร้างแฟ้มจัดเก็บใหม่
      </h2>
      <div class="form-group">
        <label>ชื่อแฟ้ม/กล่อง *</label>
        <input type="text" id="quickFolderName" class="form-control" placeholder="เช่น แฟ้มบัญชี 2569">
      </div>
      <div class="form-group">
        <label>รายละเอียดเพิ่มเติม</label>
        <input type="text" id="quickFolderDesc" class="form-control" placeholder="เช่น เก็บเอกสารใบแจ้งหนี้">
      </div>
      <div class="modal-actions" style="margin-top:24px;">
        <button class="btn btn-outline" onclick="App.Docs.closeFolderModal()">ยกเลิก</button>
        <button class="btn btn-primary" onclick="App.Docs.saveNewFolder()"><span class="material-icons-round" style="font-size:18px;">save</span> สร้างแฟ้ม</button>
      </div>
    </div>
  </div>

  <!-- Modal: กำหนดแฟ้มที่ผู้ใช้เข้าถึงได้ -->
  <div class="modal" id="folder-access-modal" style="display:none;">
    <div class="modal-content" style="max-width: 560px;">
      <h2 style="margin-bottom:6px; display:flex; align-items:center; gap:8px;"><span class="material-icons-round" style="color:var(--primary);">folder_shared</span> <span id="fa-title">สิทธิ์เข้าถึงแฟ้ม</span></h2>
      <label class="check-row"><input type="radio" name="fa-scope" value="all" onchange="App.Users.toggleFolderList()"> <div><b>เข้าถึงได้ทุกแฟ้ม</b></div></label>
      <label class="check-row"><input type="radio" name="fa-scope" value="restricted" onchange="App.Users.toggleFolderList()"> <div><b>เฉพาะแฟ้มที่เลือก</b><small>ผู้ใช้จะเห็น ค้นหา และเปิดไฟล์ได้เฉพาะเอกสารในแฟ้มเหล่านี้</small></div></label>
      <div id="fa-list-wrap">
        <input type="text" class="form-control" placeholder="กรองชื่อแฟ้ม..." style="margin:8px 0; padding:8px 12px;" oninput="App.Users.filterFolderList(this.value)">
        <div id="fa-list" style="max-height:300px; overflow-y:auto; border:1.5px solid var(--border); border-radius:12px; padding:6px 12px;"></div>
      </div>
      <div class="modal-actions">
        <button class="btn btn-outline" onclick="document.getElementById('folder-access-modal').style.display='none'">ยกเลิก</button>
        <button class="btn btn-primary" onclick="App.Users.saveFolderAccess()"><span class="material-icons-round" style="font-size:18px;">save</span> บันทึก</button>
      </div>
    </div>
  </div>

  <!-- Modal: เปลี่ยนรหัสผ่านตัวเอง / ตั้งรหัสผ่านใหม่ให้ผู้ใช้ -->
  <div class="modal" id="password-modal" style="display:none;">
    <div class="modal-content" style="max-width: 440px;">
      <h2 style="margin-bottom:18px; color:var(--text-main); display:flex; align-items:center; gap:8px;">
        <span class="material-icons-round" style="color:var(--primary);">lock_reset</span> <span id="password-modal-title">เปลี่ยนรหัสผ่าน</span>
      </h2>
      <div class="form-group" id="pw-current-group">
        <label>รหัสผ่านปัจจุบัน *</label>
        <input type="password" id="pwCurrent" class="form-control" autocomplete="current-password" placeholder="กรอกรหัสผ่านเดิม">
      </div>
      <div class="form-group">
        <label>รหัสผ่านใหม่ * (อย่างน้อย 8 ตัวอักษร)</label>
        <input type="password" id="pwNew" class="form-control" autocomplete="new-password" placeholder="กำหนดรหัสผ่านใหม่">
      </div>
      <div class="form-group">
        <label>ยืนยันรหัสผ่านใหม่ *</label>
        <input type="password" id="pwConfirm" class="form-control" autocomplete="new-password" placeholder="ยืนยันรหัสผ่านใหม่อีกครั้ง">
      </div>
      <div class="modal-actions" style="margin-top:24px;">
        <button class="btn btn-outline" onclick="App.Account.closePasswordModal()">ยกเลิก</button>
        <button class="btn btn-primary" onclick="App.Account.savePassword()"><span class="material-icons-round" style="font-size:18px;">save</span> บันทึก</button>
      </div>
    </div>
  </div>

  <!-- ==================== VIEW: คลังเอกสาร ==================== -->
  <template id="view-dashboard">
    <div class="dashboard-wrapper">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap:wrap; gap:16px;">
        <div><h1>คลังเอกสารอิเล็กทรอนิกส์</h1><p class="subtitle" style="margin:0;">สืบค้นจากรหัส ชื่อเรื่อง แท็ก ดัชนี และข้อความในเอกสาร</p></div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn btn-outline" onclick="App.Docs.exportCsv()"><span class="material-icons-round" style="font-size:18px;">download</span> ส่งออก CSV</button>
          <button class="btn btn-outline edit-only" onclick="App.Docs.showFolderModal()"><span class="material-icons-round" style="font-size:18px;">create_new_folder</span> สร้างแฟ้มใหม่</button>
          <button class="btn btn-primary edit-only" onclick="App.navigate('upload', App.navItem('upload'))"><span class="material-icons-round" style="font-size:18px;">add</span> เพิ่มเอกสาร</button>
        </div>
      </div>
      <div class="card" style="padding:0; overflow:hidden;">
        <div class="chips" id="status-chips"></div>
        <div class="filter-bar">
          <div style="flex:2; min-width:220px;">
            <label>คำค้นหา</label>
            <input type="text" id="f-q" class="form-control" placeholder="เช่น LG000123, ใบเสร็จ, 2559" oninput="App.Docs.onFilterInput()">
          </div>
          <div style="flex:2; min-width:220px;">
            <label>แฟ้มจัดเก็บ</label>
            <select id="f-folder" class="form-control" onchange="App.Docs.applyFilters()"></select>
          </div>
          <div style="min-width:130px;">
            <label>ช่วงวันที่ของ</label>
            <select id="f-datefield" class="form-control" onchange="App.Docs.applyFilters()">
              <option value="payment">วันที่จ่ายเงิน</option>
              <option value="created">วันที่นำเข้า</option>
            </select>
          </div>
          <div><label>ตั้งแต่</label><input type="date" id="f-from" class="form-control" onchange="App.Docs.applyFilters()"></div>
          <div><label>ถึง</label><input type="date" id="f-to" class="form-control" onchange="App.Docs.applyFilters()"></div>
          <div style="min-width:150px;">
            <label>เรียงตาม</label>
            <select id="f-sort" class="form-control" onchange="App.Docs.applyFilters()">
              <option value="">นำเข้าล่าสุด</option>
              <option value="id_asc">รหัส น้อย → มาก</option>
              <option value="id_desc">รหัส มาก → น้อย</option>
              <option value="payment_desc">วันที่จ่ายเงิน ใหม่ → เก่า</option>
              <option value="payment_asc">วันที่จ่ายเงิน เก่า → ใหม่</option>
              <option value="expiry_asc">ใกล้ครบอายุก่อน</option>
            </select>
          </div>
          <button class="btn btn-outline" style="padding:8px 12px;" onclick="App.Docs.resetFilters()" title="ล้างตัวกรอง"><span class="material-icons-round" style="font-size:18px;">filter_alt_off</span></button>
        </div>
        <div class="result-meta"><span id="result-count">กำลังโหลด...</span><span id="result-hint"></span></div>
        <div class="table-responsive" style="border:none; border-radius:0;">
          <table class="data-table">
            <thead><tr><th>รหัส</th><th>ชื่อเอกสาร / ดัชนี</th><th class="hide-sm">แฟ้มจัดเก็บ</th><th class="hide-sm">วันที่จ่ายเงิน</th><th class="hide-sm">ครบอายุ</th><th>สถานะ</th><th style="text-align:right;">จัดการ</th></tr></thead>
            <tbody id="doc-table-body"></tbody>
          </table>
        </div>
        <div class="pager" id="pager"></div>
      </div>
    </div>
  </template>

  <!-- ==================== VIEW: สแกนเอกสาร ==================== -->
  <template id="view-scan">
    <div class="upload-wrapper">
      <div style="margin-bottom:24px;">
        <h1 style="display:flex; align-items:center; gap:10px;">
          <span class="material-icons-round" style="color:#059669; font-size:32px;">scanner</span>
          สแกนเอกสารจากเครื่อง
        </h1>
        <p class="subtitle">สั่งสแกนจาก Canon imageFORMULA DR-G2110 (หรือเครื่องสแกนเครือข่าย eSCL) งานสแกนเข้าคิวและทำงานเบื้องหลัง</p>
      </div>
      <div class="grid-2">
        <div class="card" style="border-top: 4px solid #059669;">
          <h3 style="margin-bottom:20px; font-family:var(--font-heading); display:flex; align-items:center; gap:8px;">
            <span class="material-icons-round" style="color:#059669;">assignment</span> 1. ข้อมูลเอกสารและดัชนี
          </h3>
          <div data-docform="scan"></div>
        </div>

        <div class="card" style="border-top: 4px solid #10b981;">
          <h3 style="margin-bottom:20px; font-family:var(--font-heading); display:flex; align-items:center; gap:8px;">
            <span class="material-icons-round" style="color:#10b981;">scanner</span> 2. ตั้งค่าและสั่งสแกน
          </h3>

          <div id="scanner-status-bar" style="display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:var(--radius-md); background:#f8fafc; border:1.5px solid var(--border); margin-bottom:16px;">
            <span class="material-icons-round" id="scanner-status-icon" style="font-size:22px; color:#64748b;">cast_connected</span>
            <div style="flex:1;">
              <div style="font-size:13.5px; font-weight:600;" id="scanner-device-label">Canon imageFORMULA DR-G2110</div>
              <div style="font-size:12px; color:var(--text-muted);" id="scanner-status-text">กำลังตรวจสอบเครื่องสแกน...</div>
            </div>
            <button class="btn btn-outline" style="padding:4px 10px; font-size:12px; border-radius:8px;" onclick="App.Scanner.checkStatus()" title="ตรวจสอบเครื่องสแกน">
              <span class="material-icons-round" style="font-size:16px;">refresh</span>
            </button>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:8px;">
            <div class="form-group" style="margin:0; grid-column: span 2;">
              <label style="font-size:12.5px; color:var(--text-muted);">เครื่องสแกน</label>
              <div style="display:flex; gap:8px;">
                <select id="scanDevice" class="form-control" style="font-size:13.5px; padding:9px 12px;"><option value="">ค่าเริ่มต้น (CANON DR-G2110 · TWAIN)</option></select>
                <button class="btn btn-outline" style="padding:6px 10px; white-space:nowrap; font-size:12.5px;" onclick="App.Scanner.listDevices()">ค้นหาเครื่อง</button>
              </div>
            </div>
            <div class="form-group" style="margin:0;">
              <label style="font-size:12.5px; color:var(--text-muted);">ความละเอียด (DPI)</label>
              <select id="scanDpi" class="form-control" style="font-size:13.5px; padding:9px 12px;">
                <option value="150">150 DPI (เร็ว)</option>
                <option value="200">200 DPI (มาตรฐาน)</option>
                <option value="300" selected>300 DPI (แนะนำ / OCR)</option>
                <option value="400">400 DPI (คุณภาพสูง)</option>
                <option value="600">600 DPI (สูงสุด)</option>
              </select>
            </div>
            <div class="form-group" style="margin:0;">
              <label style="font-size:12.5px; color:var(--text-muted);">โหมดสี</label>
              <select id="scanColorMode" class="form-control" style="font-size:13.5px; padding:9px 12px;">
                <option value="color">สี (Color)</option>
                <option value="gray">ขาวดำ (Grayscale)</option>
                <option value="bw">ขาวดำชัด (B&amp;W) ไฟล์เล็กสุด</option>
              </select>
            </div>
            <div class="form-group" style="margin:0;">
              <label style="font-size:12.5px; color:var(--text-muted);">แหล่งกระดาษ</label>
              <select id="scanSource" class="form-control" style="font-size:13.5px; padding:9px 12px;">
                <option value="feeder">ถาดป้อน (ADF) หน้าเดียว</option>
                <option value="duplex">ถาดป้อน 2 หน้า (Duplex)</option>
                <option value="glass">กระจก (Flatbed)</option>
              </select>
            </div>
            <div class="form-group" style="margin:0;">
              <label style="font-size:12.5px; color:var(--text-muted);">รูปแบบไฟล์ / ขนาด</label>
              <div style="display:flex; gap:6px;">
                <select id="scanFormat" class="form-control" style="font-size:13.5px; padding:9px 8px;">
                  <option value="pdf" selected>PDF</option>
                  <option value="png">PNG</option>
                  <option value="jpg">JPEG</option>
                  <option value="tiff">TIFF</option>
                </select>
                <select id="scanPageSize" class="form-control" style="font-size:13.5px; padding:9px 8px;">
                  <option value="a4" selected>A4</option>
                  <option value="a3">A3</option>
                  <option value="letter">Letter</option>
                  <option value="legal">Legal</option>
                </select>
              </div>
            </div>
          </div>

          <div style="border:1.5px solid var(--border); border-radius:12px; padding:6px 14px; margin:12px 0 16px;">
            <label class="check-row"><input type="checkbox" id="scanDeskew" checked><div>ปรับภาพเอียงให้ตรงอัตโนมัติ</div></label>
            <label class="check-row"><input type="checkbox" id="scanSkipBlank" checked><div>ข้ามหน้าว่าง<small>เช่น ด้านหลังที่ไม่มีข้อความเมื่อสแกน 2 หน้า</small></div></label>
            <label class="check-row"><input type="checkbox" id="scanOcr"><div>ทำ OCR ภาษาไทย/อังกฤษ (PDF ค้นหาข้อความได้)<small>ต้องติดตั้งชุดภาษา OCR ใน NAPS2 ก่อน และใช้เวลานานขึ้น</small></div></label>
            <label class="check-row"><input type="checkbox" id="scanPatchT"><div>แยกเอกสารด้วยแผ่นคั่น Patch-T<small>ใส่แผ่นคั่นระหว่างเอกสาร ระบบจะแยกเป็นหลายไฟล์และบันทึกเป็นเอกสารแยกกันได้</small></div></label>
            <label class="check-row"><input type="checkbox" id="scanPdfa"><div>บันทึกเป็น PDF/A-2b<small>มาตรฐานสำหรับจัดเก็บเอกสารระยะยาว</small></div></label>
          </div>

          <button type="button" id="btn-start-scan" class="btn" style="width:100%; justify-content:center; padding:16px; font-size:1.05rem; font-weight:700; background:linear-gradient(135deg, #059669 0%, #10b981 100%); color:white; border-radius:var(--radius-md); box-shadow: 0 4px 14px -2px rgba(16, 185, 129, 0.4);" onclick="App.Scanner.startScan()">
            <span class="material-icons-round" style="font-size:24px;">document_scanner</span> สั่งสแกนเอกสาร
          </button>

          <div id="scan-progress" style="display:none; margin-top:16px; padding:16px; background:#f8fafc; border-radius:var(--radius-md); border:1.5px solid var(--border);">
            <div style="display:flex; align-items:center; gap:12px;">
              <div style="width:26px; height:26px; border:3px solid #e2e8f0; border-top:3px solid #10b981; border-radius:50%; animation:spin 0.8s linear infinite; flex-shrink:0;"></div>
              <div style="flex:1;">
                <div style="font-weight:600; font-size:14.5px;" id="scan-progress-text">กำลังสแกนเอกสาร...</div>
                <div style="color:var(--text-muted); font-size:12.5px;" id="scan-progress-sub">ทำงานเบื้องหลัง สามารถกรอกข้อมูลเอกสารระหว่างรอได้</div>
              </div>
              <button class="btn btn-outline" id="btn-cancel-scan" style="padding:5px 10px; font-size:12.5px; display:none;" onclick="App.Scanner.cancel()">ยกเลิก</button>
            </div>
          </div>

          <div style="margin-top:18px;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
              <strong style="font-size:14px;">ไฟล์ที่สแกนแล้ว</strong>
            </div>
            <div id="scan-staged"></div>
          </div>

          <button type="button" class="btn btn-primary" style="width:100%; justify-content:center; padding:15px; margin-top:20px; font-size:1.05rem; background:linear-gradient(135deg,#059669,#10b981);" onclick="App.Scanner.submit()">
            <span class="material-icons-round">qr_code_2</span> บันทึกและจัดเก็บเอกสารสแกน
          </button>
        </div>
      </div>
    </div>
  </template>

  <!-- ==================== VIEW: อัปโหลดเอกสาร ==================== -->
  <template id="view-upload">
    <div class="upload-wrapper">
      <div style="margin-bottom:24px;">
        <h1 style="display:flex; align-items:center; gap:10px;">
          <span class="material-icons-round" style="color:var(--primary); font-size:32px;">cloud_upload</span>
          อัปโหลดเอกสาร
        </h1>
        <p class="subtitle">อัปโหลดไฟล์ PDF หรือรูปภาพ จัดหน้า แล้วจัดเก็บพร้อมประทับ QR Code สำหรับตรวจสอบ</p>
      </div>
      <div class="grid-2">
        <div class="card" style="border-top: 4px solid var(--primary);">
          <h3 style="margin-bottom:20px; font-family:var(--font-heading); display:flex; align-items:center; gap:8px;">
            <span class="material-icons-round" style="color:var(--primary);">assignment</span> 1. ข้อมูลเอกสารและดัชนี
          </h3>
          <div data-docform="upload"></div>
        </div>

        <div class="card" style="border-top: 4px solid var(--primary);">
          <h3 style="margin-bottom:20px; font-family:var(--font-heading); display:flex; align-items:center; gap:8px;">
            <span class="material-icons-round" style="color:var(--primary);">attach_file</span> 2. เลือกไฟล์เอกสาร
          </h3>
          <div id="upload-drop" style="border: 2px dashed #cbd5e1; border-radius: var(--radius-md); padding: 26px 20px; text-align: center; background: #f8fafc; transition: all 0.2s;">
            <span class="material-icons-round" style="font-size: 44px; color: var(--primary); margin-bottom: 6px;">cloud_upload</span>
            <div style="font-weight: 600; margin-bottom: 4px;">ลากไฟล์มาวางที่นี่ หรือเลือกไฟล์</div>
            <p style="color: var(--text-muted); font-size: 13px; margin: 0 0 14px 0;">PDF, JPG, PNG, TIFF · เลือกหลายไฟล์พร้อมกันได้ · ไฟล์ละไม่เกิน <span id="upload-limit">40 MB</span> · รูปภาพแปลงเป็น PDF อัตโนมัติ</p>
            <input type="file" id="fileInput" class="form-control" accept=".pdf,image/*,.tif,.tiff" multiple onchange="App.Upload.staged.addFiles(this)">
          </div>
          <div id="upload-staged" style="margin-top:16px;"></div>
          <button type="button" class="btn btn-primary" style="width:100%; justify-content:center; padding:15px; margin-top:20px; font-size:1.05rem;" onclick="App.Upload.submit()">
            <span class="material-icons-round">qr_code_2</span> บันทึกและจัดเก็บเอกสาร
          </button>
        </div>
      </div>
    </div>
  </template>

  <!-- ==================== VIEW: ตั้งค่า ==================== -->
  <template id="view-settings">
    <div class="settings-wrapper">
      <div style="margin-bottom: 20px;">
        <h1>การตั้งค่าระบบ</h1>
        <p class="subtitle" style="margin:0;">แฟ้มจัดเก็บ รหัสและอายุการเก็บ ผู้ใช้และสิทธิ์ พื้นที่จัดเก็บ และการสำรองข้อมูล</p>
      </div>
      <div class="tabs" id="settings-tabs">
        <button data-tab="folders" class="active" onclick="App.Settings.tab('folders')">แฟ้มจัดเก็บ</button>
        <button data-tab="prefixes" onclick="App.Settings.tab('prefixes')">รหัสและอายุการเก็บ</button>
        <button data-tab="fields" onclick="App.Settings.tab('fields')">ดัชนีเพิ่มเติม</button>
        <button data-tab="users" onclick="App.Settings.tab('users')">ผู้ใช้และสิทธิ์</button>
        <button data-tab="system" onclick="App.Settings.tab('system')">ค่าระบบ</button>
        <button data-tab="storage" onclick="App.Settings.tab('storage')">พื้นที่และสำรองข้อมูล</button>
      </div>

      <div data-pane="folders" class="card" style="padding:0; overflow:hidden;">
        <div class="section-head">
          <div style="display: flex; gap:12px; align-items: flex-end; flex-wrap:wrap;">
            <div style="flex: 1; min-width:200px;"><label style="font-size:13px; color:var(--text-muted); font-weight:600;">ชื่อแฟ้ม *</label><input type="text" id="newFolderName" class="form-control" placeholder="ระบุชื่อแฟ้มเอกสาร"></div>
            <div style="flex: 1.5; min-width:240px;"><label style="font-size:13px; color:var(--text-muted); font-weight:600;">คำอธิบาย</label><input type="text" id="newFolderDesc" class="form-control" placeholder="รายละเอียดของแฟ้ม (ถ้ามี)"></div>
            <button class="btn btn-primary" onclick="App.Settings.addFolder()" style="padding: 11px 20px;"><span class="material-icons-round" style="font-size:18px;">add_circle</span> เพิ่มแฟ้ม</button>
          </div>
        </div>
        <div style="padding:12px 18px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <input type="text" id="folder-filter" class="form-control" placeholder="กรองชื่อแฟ้ม..." style="padding:8px 12px; max-width:320px;" oninput="App.Settings.renderFolders(this.value)">
          <span id="folder-selected-count" style="font-size:13px; color:var(--text-muted);"></span>
          <button class="btn btn-outline" id="btn-clear-folder-selection" style="padding:7px 12px; font-size:13px; display:none;" onclick="App.Settings.clearFolderSelection()">ยกเลิกการเลือก</button>
          <button class="btn btn-outline" id="btn-delete-folders" style="padding:7px 12px; font-size:13px; color:var(--danger); border-color:#fecaca; margin-left:auto;" disabled onclick="App.Settings.deleteSelectedFolders()"><span class="material-icons-round" style="font-size:17px;">delete_sweep</span> ลบแฟ้มที่เลือก</button>
        </div>
        <div class="table-responsive" style="border:none; border-radius:0;">
          <table class="data-table"><thead><tr><th style="width:44px; padding-left:20px;"><input type="checkbox" id="folder-select-all" title="เลือกทั้งหมดที่แสดงอยู่" style="width:16px; height:16px; accent-color:var(--primary);" onchange="App.Settings.selectAllFolders(this.checked)"></th><th>ชื่อแฟ้ม</th><th>รายละเอียด</th><th style="text-align:right;">เอกสาร</th><th style="text-align:right; width:150px;">จัดการ</th></tr></thead><tbody id="admin-folders-list"></tbody></table>
        </div>
      </div>

      <div data-pane="prefixes" class="card" style="padding:0; overflow:hidden; display:none;">
        <div class="section-head">
          <p style="margin:0 0 14px;">ตัวอักษรนำหน้ารหัสใช้แยกประเภทเอกสาร และกำหนดอายุการเก็บของแต่ละประเภทได้ (เว้นว่าง = ใช้ค่าเริ่มต้นของระบบ) วันครบอายุ = วันที่จ่ายเงิน + อายุการเก็บ</p>
          <div style="display: flex; gap:12px; align-items: flex-end; flex-wrap:wrap;">
            <div style="width:160px;"><label style="font-size:13px; color:var(--text-muted); font-weight:600;">ตัวอักษรย่อ *</label><input type="text" id="newPrefixValue" class="form-control" placeholder="เช่น FIN"></div>
            <div style="flex: 1.5; min-width:200px;"><label style="font-size:13px; color:var(--text-muted); font-weight:600;">ประเภทเอกสาร</label><input type="text" id="newPrefixName" class="form-control" placeholder="เช่น เอกสารการเงิน"></div>
            <div style="width:140px;"><label style="font-size:13px; color:var(--text-muted); font-weight:600;">อายุการเก็บ (ปี)</label><input type="number" min="1" id="newPrefixYears" class="form-control" placeholder="ค่าเริ่มต้น"></div>
            <button class="btn btn-primary" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);" onclick="App.Settings.addPrefix()"><span class="material-icons-round" style="font-size:18px;">add_circle</span> เพิ่มรหัส</button>
          </div>
        </div>
        <table class="data-table"><thead><tr><th style="padding-left:24px;">ตัวอักษรย่อ</th><th>ประเภทเอกสาร</th><th>อายุการเก็บ (ปี)</th><th style="text-align:right; width:150px;">จัดการ</th></tr></thead><tbody id="admin-prefixes-list"></tbody></table>
      </div>

      <div data-pane="fields" class="card" style="padding:0; overflow:hidden; display:none;">
        <div class="section-head">
          <p style="margin:0 0 14px;">ช่องข้อมูลดัชนีเพิ่มเติมที่แสดงในฟอร์มบันทึกเอกสาร เช่น เลขที่ฎีกา, จำนวนเงิน, หน่วยงาน (ค้นหาได้จากช่องค้นหา)</p>
          <div style="display: flex; gap:12px; align-items: flex-end; flex-wrap:wrap;">
            <div style="flex:1; min-width:200px;"><label style="font-size:13px; color:var(--text-muted); font-weight:600;">ชื่อช่องข้อมูล *</label><input type="text" id="newFieldName" class="form-control" placeholder="เช่น เลขที่ฎีกา"></div>
            <div style="width:160px;"><label style="font-size:13px; color:var(--text-muted); font-weight:600;">ชนิด</label><select id="newFieldType" class="form-control"><option value="text">ข้อความ</option><option value="number">ตัวเลข</option><option value="date">วันที่</option></select></div>
            <label class="check-row" style="padding-bottom:12px;"><input type="checkbox" id="newFieldRequired"> <div>บังคับกรอก</div></label>
            <button class="btn btn-primary" onclick="App.Settings.addField()"><span class="material-icons-round" style="font-size:18px;">add_circle</span> เพิ่มช่อง</button>
          </div>
        </div>
        <table class="data-table"><thead><tr><th style="padding-left:24px;">ชื่อช่องข้อมูล</th><th>ชนิด</th><th>บังคับกรอก</th><th style="text-align:right; width:120px;">จัดการ</th></tr></thead><tbody id="admin-fields-list"></tbody></table>
      </div>

      <div data-pane="users" class="card" style="padding:0; overflow:hidden; display:none;">
        <div class="section-head">
          <p style="margin:0 0 14px;"><b>ผู้ดูแลระบบ</b>: ทุกอย่าง · <b>เจ้าหน้าที่</b>: สแกน อัปโหลด แก้ไข ขอทำลาย · <b>ผู้ดูเอกสาร</b>: ค้นหาและเปิดดูเท่านั้น · จำกัดแฟ้มที่แต่ละคนเข้าถึงได้ด้วยปุ่ม <span class="material-icons-round" style="font-size:15px; vertical-align:middle;">folder_shared</span></p>
          <div style="display: flex; gap:12px; align-items: flex-end; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 160px;"><label style="font-size:13px; color:var(--text-muted); font-weight:600;">ชื่อผู้ใช้ *</label><input type="text" id="newUserName" class="form-control" placeholder="เช่น somchai" autocomplete="off"></div>
            <div style="flex: 1; min-width: 160px;"><label style="font-size:13px; color:var(--text-muted); font-weight:600;">รหัสผ่านเริ่มต้น * (8 ตัวขึ้นไป)</label><input type="password" id="newUserPassword" class="form-control" autocomplete="new-password"></div>
            <div style="width: 160px;"><label style="font-size:13px; color:var(--text-muted); font-weight:600;">สิทธิ์</label>
              <select id="newUserRole" class="form-control"><option value="staff">เจ้าหน้าที่</option><option value="viewer">ผู้ดูเอกสาร</option><option value="admin">ผู้ดูแลระบบ</option></select>
            </div>
            <button class="btn btn-primary" style="background: linear-gradient(135deg, #d97706 0%, #f59e0b 100%);" onclick="App.Users.create()"><span class="material-icons-round" style="font-size:18px;">person_add</span> เพิ่มผู้ใช้</button>
          </div>
        </div>
        <div class="table-responsive" style="border:none; border-radius:0;">
          <table class="data-table"><thead><tr><th style="padding-left:24px;">ชื่อผู้ใช้</th><th>สิทธิ์</th><th>แฟ้มที่เข้าถึง</th><th>สถานะ</th><th class="hide-sm">สร้างเมื่อ</th><th style="text-align:right; width: 190px;">จัดการ</th></tr></thead><tbody id="admin-users-list"></tbody></table>
        </div>
      </div>

      <div data-pane="system" style="display:none;">
        <div class="card">
          <h3 style="font-family:var(--font-heading); margin-bottom:16px; display:flex; gap:8px; align-items:center;"><span class="material-icons-round" style="color:var(--primary);">settings</span> ค่าระบบ</h3>
          <div id="env-status" style="margin-bottom:16px;"></div>
          <div class="form-group"><label>URL สาธารณะของระบบ (ใช้สร้าง QR Code)</label><input type="text" id="set-base-url" class="form-control" placeholder="เช่น https://edms.example.ac.th"><small style="color:var(--text-muted);" id="set-base-url-hint"></small></div>
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
            <div class="form-group"><label>อายุการเก็บเริ่มต้น (ปี)</label><input type="number" min="1" id="set-retention" class="form-control"></div>
            <div class="form-group"><label>เตือนก่อนครบอายุ (วัน)</label><input type="number" min="1" id="set-warn-days" class="form-control"></div>
            <div class="form-group"><label>เก็บในถังขยะ (วัน, 0 = ไม่ลบอัตโนมัติ)</label><input type="number" min="0" id="set-trash-days" class="form-control"></div>
          </div>
        </div>
        <div style="text-align:right;"><button class="btn btn-primary" onclick="App.Settings.saveSystem()"><span class="material-icons-round" style="font-size:18px;">save</span> บันทึกการตั้งค่า</button></div>
      </div>

      <div data-pane="storage" style="display:none;">
        <div class="card" id="storage-report"><div style="color:var(--text-muted);">กำลังคำนวณพื้นที่...</div></div>
        <div class="card">
          <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
            <div>
              <h3 style="font-family:var(--font-heading); display:flex; gap:8px; align-items:center;"><span class="material-icons-round" style="color:var(--success);">backup</span> สำรองข้อมูล</h3>
              <p style="font-size:13.5px; color:var(--text-muted); margin-top:4px;" id="backup-hint"></p>
            </div>
            <div style="display:flex; gap:8px;">
              <button class="btn btn-outline" onclick="App.Settings.loadBackups()"><span class="material-icons-round" style="font-size:18px;">refresh</span></button>
              <button class="btn btn-primary" onclick="App.Settings.runBackup()"><span class="material-icons-round" style="font-size:18px;">backup</span> สำรองข้อมูลตอนนี้</button>
            </div>
          </div>
          <table class="data-table"><thead><tr><th>ชุดสำรอง</th><th>สถานะ</th><th>ไฟล์</th><th>ขนาด</th></tr></thead><tbody id="backup-list"></tbody></table>
        </div>
      </div>
    </div>
  </template>

  <!-- ==================== VIEW: แดชบอร์ดผู้บริหาร ==================== -->
  <template id="view-analytics">
    <div class="analytics-wrapper">
      <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom: 24px; flex-wrap:wrap; gap:12px;">
        <div>
          <h1>แดชบอร์ดผู้บริหาร</h1>
          <p class="subtitle" style="margin:0;">ภาพรวมเอกสาร งานที่รอดำเนินการ และความเคลื่อนไหวในระบบ</p>
        </div>
        <button class="btn btn-outline" style="color:var(--success); border-color:var(--success);" onclick="window.open('api/export.php', '_blank')">
          <span class="material-icons-round" style="font-size:18px;">download</span> ส่งออกรายงานทั้งหมด (CSV)
        </button>
      </div>

      <div id="stat-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;"></div>

      <div class="grid-2" style="margin-bottom: 24px;">
        <div class="card">
          <h3 style="margin-bottom:16px; font-family:var(--font-heading); font-size:1.1rem; display:flex; align-items:center; gap:8px;">
            <span class="material-icons-round" style="color:var(--primary); font-size:20px;">bar_chart</span> เอกสารที่นำเข้า 7 วันล่าสุด
          </h3>
          <div style="position: relative; height: 220px; width: 100%;"><canvas id="uploadChart"></canvas></div>
        </div>
        <div class="card">
          <h3 style="margin-bottom:16px; font-family:var(--font-heading); font-size:1.1rem; display:flex; align-items:center; gap:8px;">
            <span class="material-icons-round" style="color:var(--success); font-size:20px;">pie_chart</span> สัดส่วนเอกสารรายแฟ้ม
          </h3>
          <div style="position: relative; height: 220px; width: 100%;"><canvas id="folderChart"></canvas></div>
        </div>
      </div>

      <div class="card table-responsive" style="padding:0;">
        <div style="padding: 18px 24px; border-bottom: 1px solid var(--border); background: #f8fafc; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
          <h3 style="margin:0; display:flex; align-items:center; gap:8px; font-family:var(--font-heading); font-size:1.1rem;">
            <span class="material-icons-round" style="color:var(--text-muted);">history</span> ประวัติการใช้งาน (Audit Log)
          </h3>
          <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <input type="text" id="auditLogSearch" class="form-control" placeholder="ค้นหา ผู้ใช้ รายการ รหัสเอกสาร IP..." style="padding:8px 14px; font-size:13.5px; width:260px; border-radius:999px;" oninput="App.Analytics.filterLogs()">
            <select id="auditLogActionFilter" class="form-control" style="padding:8px 14px; font-size:13.5px; width:160px; border-radius:999px;" onchange="App.Analytics.filterLogs()">
              <option value="">ทุกรายการ</option>
              <option value="login">Login</option>
              <option value="view">เปิดดู / ดาวน์โหลด</option>
              <option value="upload">Upload</option>
              <option value="scan">Scan</option>
              <option value="create">Create</option>
              <option value="update">Update</option>
              <option value="workflow">Workflow</option>
              <option value="destruction">ทำลายเอกสาร</option>
              <option value="delete">Delete / Purge</option>
              <option value="user">User</option>
              <option value="backup">Backup</option>
            </select>
          </div>
        </div>
        <table class="data-table">
          <thead><tr><th style="padding-left:24px;">วัน-เวลา</th><th>ผู้ใช้งาน</th><th>รายการ</th><th>รายละเอียด</th><th class="hide-sm">IP Address</th></tr></thead>
          <tbody id="audit-log-body"></tbody>
        </table>
      </div>
    </div>
  </template>

  <script src="<?= $v('assets/app.js') ?>"></script>
</body>
</html>
