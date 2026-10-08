<?php
require_once 'config/auth.php';
startSecureSession();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$isAdmin = isAdmin();
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <base target="_top">
  <title>ระบบจัดเก็บเอกสาร</title>
  <meta charset="UTF-8">

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
    window.CURRENT_USER = <?= json_encode(['id' => (int)$_SESSION['user_id'], 'username' => $_SESSION['username'], 'role' => $_SESSION['role']]) ?>;

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
  
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.14.305/pdf.min.js"></script>
  <script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.5/JsBarcode.all.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
  :root {
    --primary: #2563eb;
    --primary-hover: #1d4ed8;
    --primary-light: #eff6ff;
    --primary-gradient: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
    --background: #f8fafc;
    --surface: #ffffff;
    --surface-glass: rgba(255, 255, 255, 0.88);
    --text-main: #0f172a;
    --text-muted: #64748b;
    --border: #e2e8f0;
    --border-light: #f1f5f9;
    --danger: #ef4444;
    --danger-light: #fef2f2;
    --success: #10b981;
    --success-light: #ecfdf5;
    --warning: #f59e0b;
    --warning-light: #fffbeb;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --radius-xl: 20px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
    --shadow: 0 4px 12px -2px rgba(15, 23, 42, 0.08);
    --shadow-lg: 0 20px 25px -5px rgba(15, 23, 42, 0.1), 0 8px 10px -6px rgba(15, 23, 42, 0.05);
    --font: 'Sarabun', -apple-system, BlinkMacSystemFont, sans-serif;
    --font-heading: 'Plus Jakarta Sans', 'Sarabun', sans-serif;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: var(--font);
    background-color: var(--background);
    color: var(--text-main);
    display: flex;
    height: 100vh;
    overflow: hidden;
    letter-spacing: -0.01em;
  }
  
  /* Sidebar */
  .sidebar {
    width: 270px;
    background-color: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    z-index: 20;
    transition: all 0.3s ease;
  }
  .sidebar .brand {
    padding: 24px 22px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid var(--border-light);
  }
  .brand-badge-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: var(--primary-gradient);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    box-shadow: 0 6px 14px -3px rgba(37, 99, 235, 0.4);
  }
  .brand-badge-icon .material-icons-round { font-size: 24px; }
  .sidebar .brand h2 {
    font-family: var(--font-heading);
    font-size: 1.15rem;
    font-weight: 700;
    line-height: 1.25;
    color: var(--text-main);
    letter-spacing: -0.02em;
  }
  .sidebar .brand h2 span {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--primary);
    display: block;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }
  .nav-links {
    list-style: none;
    padding: 20px 14px;
    flex-grow: 1;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .nav-links li {
    padding: 12px 16px;
    border-radius: var(--radius-md);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 14px;
    font-weight: 500;
    color: var(--text-muted);
    font-size: 0.95rem;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  }
  .nav-links li .material-icons-round {
    font-size: 22px;
    transition: transform 0.2s;
  }
  .nav-links li:hover {
    background-color: #f1f5f9;
    color: var(--text-main);
    transform: translateX(3px);
  }
  .nav-links li.active {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    color: var(--primary);
    font-weight: 600;
  }
  .nav-links li.active .material-icons-round {
    color: var(--primary);
  }

  .user-info {
    padding: 16px 18px;
    border-top: 1px solid var(--border-light);
    display: flex;
    align-items: center;
    gap: 12px;
    background: #fafbfc;
  }
  .user-avatar-circle {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
    color: #4338ca;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.95rem;
  }

  /* Main Content Area */
  .main-content {
    flex-grow: 1;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    background: var(--background);
  }
  .top-bar {
    min-height: 72px;
    height: 72px;
    background-color: var(--surface-glass);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 0 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 15;
    box-sizing: border-box;
    gap: 20px;
  }
  .search-box {
    display: flex;
    align-items: center;
    background-color: #f1f5f9;
    padding: 10px 18px;
    border-radius: 999px;
    width: 480px;
    max-width: 100%;
    border: 1.5px solid transparent;
    transition: all 0.25s ease;
    margin: 0;
  }
  .search-box:focus-within {
    background-color: #ffffff;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
  }
  .search-box input {
    border: none;
    background: transparent;
    outline: none;
    margin-left: 10px;
    width: 100%;
    font-family: var(--font);
    font-size: 14.5px;
    color: var(--text-main);
  }
  .search-box input::placeholder {
    color: #94a3b8;
  }

  #app-view {
    padding: 32px;
    flex-grow: 1;
    animation: fadeIn 0.3s ease-out;
  }
  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
  }

  h1 {
    font-family: var(--font-heading);
    font-size: 1.75rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--text-main);
    margin-bottom: 6px;
  }
  p.subtitle {
    color: var(--text-muted);
    margin-bottom: 24px;
    font-size: 0.95rem;
  }

  .card {
    background: var(--surface);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 24px;
    box-shadow: var(--shadow);
    margin-bottom: 24px;
    transition: box-shadow 0.2s;
  }
  .card:hover {
    box-shadow: 0 8px 20px -4px rgba(15, 23, 42, 0.08);
  }

  /* Data Table */
  .table-responsive {
    overflow-x: auto;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    background: var(--surface);
  }
  .data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
  }
  .data-table th {
    padding: 14px 18px;
    text-align: left;
    font-weight: 600;
    color: #475569;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    background: #f8fafc;
    border-bottom: 1px solid var(--border);
  }
  .data-table td {
    padding: 14px 18px;
    text-align: left;
    border-bottom: 1px solid var(--border-light);
    color: var(--text-main);
    vertical-align: middle;
  }
  .data-table tr.doc-item {
    transition: background-color 0.15s ease;
  }
  .data-table tr.doc-item:hover {
    background-color: #f8fafc;
  }
  .data-table tr:last-child td {
    border-bottom: none;
  }

  /* Badges */
  .badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 600;
    letter-spacing: 0.02em;
  }
  .badge.success { background-color: var(--success-light); color: #047857; border: 1px solid #a7f3d0; }
  .badge.danger { background-color: var(--danger-light); color: #b91c1c; border: 1px solid #fecaca; }

  /* Forms & Buttons */
  .form-group { margin-bottom: 18px; }
  .form-group label {
    display: block;
    margin-bottom: 7px;
    font-weight: 600;
    font-size: 0.9rem;
    color: #334155;
  }
  .form-control {
    width: 100%;
    padding: 11px 15px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-md);
    font-family: var(--font);
    font-size: 0.95rem;
    background: #ffffff;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    outline: none;
    color: var(--text-main);
  }
  .form-control:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
  }
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: var(--radius-md);
    font-family: var(--font);
    font-weight: 600;
    font-size: 0.92rem;
    cursor: pointer;
    border: none;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  }
  .btn-primary {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    color: white;
    box-shadow: 0 4px 12px -2px rgba(37, 99, 235, 0.35);
  }
  .btn-primary:hover {
    background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px -2px rgba(37, 99, 235, 0.45);
  }
  .btn-primary:active { transform: translateY(0); }
  .btn-outline {
    background-color: #ffffff;
    border: 1.5px solid var(--border);
    color: #334155;
  }
  .btn-outline:hover {
    background-color: #f8fafc;
    border-color: #cbd5e1;
    color: var(--text-main);
  }
  .btn-icon {
    padding: 7px 9px;
    border-radius: var(--radius-sm);
  }
  .btn-icon:hover {
    background-color: #f1f5f9;
  }

  /* Modals */
  .modal {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(6px);
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  .modal-content {
    background: white;
    padding: 32px;
    border-radius: var(--radius-xl);
    max-width: 600px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
    animation: modalScale 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  }
  @keyframes modalScale {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
  }
  .modal-content h2 {
    font-family: var(--font-heading);
    margin-bottom: 20px;
    color: var(--text-main);
    font-size: 1.4rem;
  }
  .modal-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 28px;
  }
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }

  /* PDF pages grid */
  .pdf-page-item {
    position: relative;
    border: 2px solid transparent;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: var(--shadow-sm);
    background: white;
    overflow: hidden;
  }
  .pdf-page-item:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
  }
  .pdf-page-item.deleted {
    border-color: var(--danger);
    opacity: 0.5;
  }
  .pdf-page-item canvas { width: 100%; height: auto; display: block; }
  .pdf-page-number {
    position: absolute;
    bottom: 0; left: 0; right: 0;
    background: rgba(15, 23, 42, 0.75);
    backdrop-filter: blur(2px);
    color: white;
    text-align: center;
    font-size: 11px;
    padding: 4px;
    font-weight: 500;
  }
  .delete-overlay {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(239, 68, 68, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s;
  }
  .pdf-page-item.deleted .delete-overlay { opacity: 1; }
  .delete-overlay .material-icons-round {
    font-size: 38px;
    color: var(--danger);
    background: white;
    border-radius: 50%;
    padding: 4px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
  }

  /* Toast & Loader */
  #toast-container {
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 12px;
    pointer-events: none;
  }
  .toast-msg {
    background-color: var(--surface);
    border-left: 4px solid var(--danger);
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.12);
    border-radius: var(--radius-md);
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: slideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    pointer-events: auto;
    max-width: 380px;
    line-height: 1.4;
    font-size: 0.95rem;
    border: 1px solid var(--border);
    border-left-width: 4px;
  }
  .toast-msg.success { border-left-color: var(--success); }
  .toast-msg.info { border-left-color: var(--primary); }
  @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

  .loader-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.4);
    backdrop-filter: blur(6px);
    z-index: 1000;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
  }
  .spinner-box {
    background: white;
    padding: 30px 40px;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-lg);
    display: flex;
    flex-direction: column;
    align-items: center;
  }
  .spinner {
    border: 3.5px solid #e2e8f0;
    border-top: 3.5px solid var(--primary);
    border-radius: 50%;
    width: 46px;
    height: 46px;
    animation: spin 0.8s linear infinite;
    margin-bottom: 16px;
  }
  @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

  /* Mobile Responsive */
  @media (max-width: 860px) {
    body { flex-direction: column; height: auto; overflow: auto; }
    .sidebar { width: 100%; height: auto; }
    .grid-2 { grid-template-columns: 1fr; }
    .search-box { width: 100%; }
    .top-bar { padding: 0 16px; }
    #app-view { padding: 20px 16px; }
  }
  </style>
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
        <span>แฟ้มเอกสารทั้งหมด</span>
      </li>
      <li data-view="scan" onclick="App.navigate('scan', this)">
        <span class="material-icons-round">scanner</span>
        <span>สแกนเอกสาร</span>
      </li>
      <li data-view="upload" onclick="App.navigate('upload', this)">
        <span class="material-icons-round">cloud_upload</span>
        <span>อัปโหลดเอกสาร</span>
      </li>
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
        <input type="text" id="globalSearch" placeholder="ค้นหาเอกสาร... (ชื่อเรื่อง, รหัสบาร์โค้ด, แฟ้ม)" onkeyup="App.Dashboard.search()">
      </div>

      <div class="top-user-profile" style="display:flex; align-items:center; gap:16px;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div class="user-avatar-circle">
            <?= strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)) ?>
          </div>
          <div style="line-height:1.25;">
            <div id="currentUserEmail" style="color:var(--text-main); font-weight:700; font-size: 0.95rem;">
              <?= htmlspecialchars($_SESSION['username'] ?? '') ?>
            </div>
            <div style="font-size:12px; color:var(--text-muted); display:flex; align-items:center; gap:4px; margin-top:2px;">
              <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:<?= $isAdmin ? '#f59e0b' : '#10b981' ?>;"></span>
              <?= $isAdmin ? 'ผู้ดูแลระบบ' : 'เจ้าหน้าที่' ?>
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

  <div class="modal" id="viewer-modal" style="display:none;">
    <div class="modal-content" style="max-width:900px; width:95%; height:90vh; display:flex; flex-direction:column; padding: 20px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <h2 style="margin:0; color:var(--primary); display:flex; align-items:center; gap:8px;">
          <span class="material-icons-outlined">description</span> เปิดดูเอกสาร
        </h2>
        <button class="btn btn-outline btn-icon" onclick="document.getElementById('viewer-modal').style.display='none'">
          <span class="material-icons-round" style="font-size: 20px;">close</span>
        </button>
      </div>
      <div id="viewer-tabs" style="display:flex; gap:8px; margin-bottom:12px; overflow-x:auto;"></div>
      <div id="viewer-frame-container" style="flex-grow:1; background:#f8fafc; border-radius:12px; overflow:hidden; border:1px solid var(--border);"></div>
    </div>
  </div>

  <!-- Modal: แก้ไขเอกสาร -->
  <div class="modal" id="edit-modal" style="display:none;">
    <div class="modal-content">
      <h2><span class="material-icons-round" style="vertical-align: middle; color:var(--primary);">edit_note</span> แก้ไขข้อมูลเอกสาร</h2>
      <div class="form-group">
        <label>รหัสเอกสาร (Barcode) *</label>
        <div style="display: flex; gap: 8px;">
          <select id="editDocPrefix" class="form-control" style="width: 180px;" required></select>
          <input type="text" id="editDocIdInput" class="form-control" required placeholder="ระบุตัวเลข เช่น 690001">
        </div>
      </div>
      <div class="form-group"><label>ชื่อเอกสาร / เรื่อง *</label><input type="text" id="edit-filename" class="form-control" required></div>
      <div class="form-group">
        <label>วันที่จ่ายเงิน</label>
        <input type="date" id="edit-payment-date" class="form-control">
      </div>
      
      <div class="form-group" style="padding-top: 14px; border-top: 1px solid var(--border); margin-top: 16px;">
        <label style="color:var(--primary); font-size: 14.5px; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
          <span class="material-icons-round" style="font-size:20px;">attach_file</span> จัดการไฟล์เอกสารแนบ
        </label>
        <div style="background: #f8fafc; padding: 14px; border-radius: 12px; border: 1.5px solid var(--border); margin-bottom: 14px;">
          <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; font-size: 14px; margin-bottom: 10px;">
            <input type="radio" name="edit-upload-mode" value="append" checked style="margin-top: 3px;"> 
            <div>
              <b style="color:var(--text-main);">➕ แนบไฟล์เพิ่มเติม (Append)</b><br>
              <span style="color:var(--text-muted); font-size: 12.5px;">ไฟล์เก่าจะยังอยู่ และไฟล์ใหม่จะถูกต่อท้าย</span>
            </div>
          </label>
          <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; font-size: 14px;">
            <input type="radio" name="edit-upload-mode" value="overwrite" style="margin-top: 3px;"> 
            <div>
              <b style="color:var(--danger);">🔄 อัปโหลดไฟล์ใหม่ทับของเดิม (Overwrite)</b><br>
              <span style="color:var(--text-muted); font-size: 12.5px;">ลบไฟล์เก่าทิ้งทั้งหมด และใช้ชุดไฟล์ใหม่นี้แทนที่</span>
            </div>
          </label>
        </div>
        <input type="file" id="edit-file-input" class="form-control" multiple accept=".pdf,image/*,.tif,.tiff">
        <small style="color:var(--warning); display:block; margin-top:6px;">* ปล่อยว่างไว้หากต้องการแค่แก้ไขข้อความ</small>
      </div>

      <div class="modal-actions">
        <button class="btn btn-outline" onclick="App.Dashboard.closeEditModal()">ยกเลิก</button>
        <button class="btn btn-primary" onclick="App.Dashboard.saveEdit()"><span class="material-icons-round" style="font-size:18px;">save</span> บันทึก</button>
      </div>
    </div>
  </div>

  <div class="modal" id="barcode-modal" style="display:none;">
    <div class="modal-content" style="text-align:center; max-width:440px;">
      <h2 style="margin-bottom:8px; color:var(--text-main);">บาร์โค้ดเอกสาร</h2>
      <p style="color:var(--text-muted); font-size:14px; margin-bottom:18px;">ใช้สำหรับสแกนและติดหน้าแฟ้มหรือซองเอกสาร</p>
      <div style="padding:24px; background:#fff; border:2px dashed var(--border); border-radius:14px; margin-bottom:24px; display:inline-block;"><svg id="barcode-svg"></svg></div>
      <div style="display:flex; gap:12px; justify-content:center;">
        <button class="btn btn-outline" onclick="App.Dashboard.closeBarcode()">ปิด</button>
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
        <input type="text" id="quickFolderName" class="form-control" required placeholder="เช่น แฟ้มบัญชี 2567">
      </div>
      <div class="form-group">
        <label>รายละเอียดเพิ่มเติม</label>
        <input type="text" id="quickFolderDesc" class="form-control" placeholder="เช่น เก็บเอกสารใบแจ้งหนี้">
      </div>
      <div class="modal-actions" style="margin-top:24px;">
        <button class="btn btn-outline" onclick="App.Dashboard.closeFolderModal()">ยกเลิก</button>
        <button class="btn btn-primary" onclick="App.Dashboard.saveNewFolder()"><span class="material-icons-round" style="font-size:18px;">save</span> สร้างแฟ้ม</button>
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

  <template id="view-dashboard">
    <div class="dashboard-wrapper">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 24px; flex-wrap:wrap; gap:16px;">
        <div><h1>คลังเอกสารอิเล็กทรอนิกส์</h1><p class="subtitle">สืบค้น เรียกดูไฟล์ภาพเอกสาร สั่งพิมพ์ และออกรายงาน</p></div>
        <div style="display:flex; gap:12px;">
          <button class="btn btn-outline" onclick="App.Dashboard.showFolderModal()"><span class="material-icons-round" style="font-size:18px;">create_new_folder</span> สร้างแฟ้มใหม่</button>
          <button class="btn btn-primary" onclick="App.navigate('upload', App.navItem('upload'))"><span class="material-icons-round" style="font-size:18px;">add</span> เพิ่มเอกสารใหม่</button>
        </div>
      </div>
      <div class="card table-responsive" style="padding:0;">
        <table class="data-table">
          <thead><tr><th>รหัส / บาร์โค้ด</th><th>ชื่อเอกสาร</th><th>แฟ้มจัดเก็บ</th><th>วันที่จ่ายเงิน</th><th>วันหมดอายุ (10 ปี)</th><th>สถานะ</th><th style="text-align:center;">จัดการ</th></tr></thead>
          <tbody id="doc-table-body"></tbody>
        </table>
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
        <p class="subtitle">สั่งสแกนเอกสารโดยตรงจากเครื่องสแกนเนอร์ Canon imageFORMULA DR-G2110 พร้อมบันทึกดัชนีและแฟ้มจัดเก็บ</p>
      </div>
      <div class="grid-2">
        <!-- ========== Card 1: ข้อมูลเอกสาร ========== -->
        <div class="card" style="border-top: 4px solid #059669;">
          <h3 style="margin-bottom:20px; color:var(--text-main); font-family:var(--font-heading); display:flex; align-items:center; gap:8px;">
            <span class="material-icons-round" style="color:#059669;">assignment</span> 1. ข้อมูลเอกสารและดัชนี
          </h3>
          <form id="scan-form">
            <div class="form-group">
              <label>รหัสเอกสาร (Barcode) *</label>
              <div style="display: flex; gap: 8px;">
                <select id="scanDocPrefix" class="form-control" style="width: 180px;" required onchange="App.Scanner.fetchNextId()"></select>
                <input type="text" id="scanDocIdInput" class="form-control" required placeholder="กำลังสร้างรหัส...">
              </div>
            </div>
            <div class="form-group"><label>ชื่อเอกสาร / เรื่อง *</label><input type="text" id="scanDocName" class="form-control" required placeholder="เช่น เอกสารใบเสร็จ, สัญญาจ้าง, รายงาน"></div>
            <div class="form-group"><label>จัดเก็บลงแฟ้ม / กล่อง *</label><select id="scanUploadFolder" class="form-control" required></select></div>
            <div class="form-group">
              <label>วันที่จ่ายเงิน (คำนวณอายุจัดเก็บ 10 ปีอัตโนมัติ)</label>
              <input type="date" id="scanPaymentDate" class="form-control">
            </div>
          </form>
        </div>

        <!-- ========== Card 2: สั่งสแกนเอกสาร ========== -->
        <div class="card" style="border-top: 4px solid #10b981;">
          <h3 style="margin-bottom:20px; color:var(--text-main); font-family:var(--font-heading); display:flex; align-items:center; gap:8px;">
            <span class="material-icons-round" style="color:#10b981;">scanner</span> 2. ตั้งค่าและสั่งสแกน
          </h3>

          <!-- Scanner Status -->
          <div id="scanner-status-bar" style="display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:var(--radius-md); background:#f0fdf4; border:1.5px solid #a7f3d0; margin-bottom:18px;">
            <span class="material-icons-round" style="font-size:22px; color:#059669;">cast_connected</span>
            <div style="flex:1;">
              <div style="font-size:13.5px; font-weight:600; color:#065f46;">Canon imageFORMULA DR-G2110</div>
              <div style="font-size:12px; color:#047857;" id="scanner-status-text">กำลังตรวจสอบเครื่องสแกน...</div>
            </div>
            <button class="btn btn-outline" style="padding:4px 10px; font-size:12px; border-radius:8px; border-color:#a7f3d0; color:#059669;" onclick="App.Scanner.checkStatus()" title="ตรวจสอบเครื่องสแกน">
              <span class="material-icons-round" style="font-size:16px;">refresh</span>
            </button>
          </div>

          <!-- Scan Settings Grid -->
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
            <div class="form-group" style="margin:0;">
              <label style="font-size:12.5px; color:var(--text-muted); display:flex; align-items:center; gap:4px;">
                <span class="material-icons-round" style="font-size:15px;">high_quality</span> ความละเอียด (DPI)
              </label>
              <select id="scanDpi" class="form-control" style="font-size:13.5px; padding:9px 12px;">
                <option value="150">150 DPI (เร็ว)</option>
                <option value="200">200 DPI (มาตรฐาน)</option>
                <option value="300" selected>300 DPI (แนะนำ)</option>
                <option value="400">400 DPI (คุณภาพสูง)</option>
                <option value="600">600 DPI (สูงสุด)</option>
              </select>
            </div>
            <div class="form-group" style="margin:0;">
              <label style="font-size:12.5px; color:var(--text-muted); display:flex; align-items:center; gap:4px;">
                <span class="material-icons-round" style="font-size:15px;">palette</span> โหมดสี
              </label>
              <select id="scanColorMode" class="form-control" style="font-size:13.5px; padding:9px 12px;">
                <option value="color">สี (Color)</option>
                <option value="gray">ขาวดำ (Grayscale)</option>
                <option value="bw">ขาวดำชัด (B&W)</option>
              </select>
            </div>
            <div class="form-group" style="margin:0;">
              <label style="font-size:12.5px; color:var(--text-muted); display:flex; align-items:center; gap:4px;">
                <span class="material-icons-round" style="font-size:15px;">content_copy</span> แหล่งกระดาษ
              </label>
              <select id="scanSource" class="form-control" style="font-size:13.5px; padding:9px 12px;">
                <option value="feeder">ถาดป้อน (ADF)</option>
                <option value="duplex">สแกน 2 หน้า (Duplex)</option>
                <option value="glass">กระจก (Flatbed)</option>
              </select>
            </div>
            <div class="form-group" style="margin:0;">
              <label style="font-size:12.5px; color:var(--text-muted); display:flex; align-items:center; gap:4px;">
                <span class="material-icons-round" style="font-size:15px;">description</span> รูปแบบไฟล์
              </label>
              <select id="scanFormat" class="form-control" style="font-size:13.5px; padding:9px 12px;">
                <option value="pdf" selected>PDF</option>
                <option value="png">PNG</option>
                <option value="jpg">JPEG</option>
                <option value="tiff">TIFF</option>
              </select>
            </div>
          </div>

          <!-- Scan Button -->
          <button type="button" id="btn-start-scan" class="btn" style="width:100%; justify-content:center; padding:16px; font-size:1.05rem; font-weight:700; background:linear-gradient(135deg, #059669 0%, #10b981 100%); color:white; border:none; border-radius:var(--radius-md); box-shadow: 0 4px 14px -2px rgba(16, 185, 129, 0.4); transition: all 0.3s; cursor:pointer; gap:8px;" onclick="App.Scanner.startScan()">
            <span class="material-icons-round" style="font-size:24px;">print</span> สั่งสแกนเอกสารทันที
          </button>

          <!-- Scan Progress (hidden by default) -->
          <div id="scan-progress" style="display:none; margin-top:16px; padding:18px; background:#f8fafc; border-radius:var(--radius-md); border:1.5px solid var(--border); text-align:center;">
            <div style="display:inline-flex; align-items:center; gap:12px; margin-bottom:10px;">
              <div style="width:28px; height:28px; border:3px solid #e2e8f0; border-top:3px solid #10b981; border-radius:50%; animation:spin 0.8s linear infinite;"></div>
              <span style="font-weight:600; color:var(--text-main); font-size:15px;" id="scan-progress-text">กำลังสแกนเอกสาร...</span>
            </div>
            <p style="color:var(--text-muted); font-size:13px; margin:0;">กรุณารอสักครู่ เครื่องกำลังดึงกระดาษและประมวลผล</p>
          </div>

          <!-- Scanned Files List -->
          <div id="scanned-files-list" style="display:none; margin-top:16px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
              <span class="material-icons-round" style="color:#059669; font-size:20px;">check_circle</span>
              <strong style="color:var(--text-main); font-size:14px;">ไฟล์ที่สแกนแล้ว</strong>
              <span id="scanned-files-count" style="background:#d1fae5; color:#065f46; font-size:12px; font-weight:700; padding:2px 10px; border-radius:999px;">0 ไฟล์</span>
            </div>
            <div id="scanned-files-container" style="display:flex; flex-direction:column; gap:8px; max-height:200px; overflow-y:auto;"></div>
            <button type="button" class="btn" style="margin-top:10px; padding:8px 16px; font-size:13px; background:#f0fdf4; color:#059669; border:1.5px solid #a7f3d0; border-radius:var(--radius-md); font-weight:600; gap:4px;" onclick="App.Scanner.startScan()">
              <span class="material-icons-round" style="font-size:16px;">add</span> สแกนชุดถัดไป
            </button>
          </div>

          <button type="button" class="btn btn-primary" style="width:100%; justify-content:center; padding:15px; margin-top:24px; font-size:1.05rem; background:linear-gradient(135deg,#059669,#10b981); border:none;" onclick="App.Scanner.submit()">
            <span class="material-icons-round">qr_code_2</span> บันทึกข้อมูลและจัดเก็บเอกสารสแกน
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
        <p class="subtitle">อัปโหลดไฟล์ดิจิทัล (PDF, รูปภาพ) พร้อมจัดหมวดหมู่แฟ้ม และประทับ QR Code ตรวจสอบ</p>
      </div>
      <div class="grid-2">
        <!-- ========== Card 1: ข้อมูลเอกสาร ========== -->
        <div class="card" style="border-top: 4px solid var(--primary);">
          <h3 style="margin-bottom:20px; color:var(--text-main); font-family:var(--font-heading); display:flex; align-items:center; gap:8px;">
            <span class="material-icons-round" style="color:var(--primary);">assignment</span> 1. ข้อมูลเอกสารและดัชนี
          </h3>
          <form id="upload-form">
            <div class="form-group">
              <label>รหัสเอกสาร (Barcode) *</label>
              <div style="display: flex; gap: 8px;">
                <select id="docPrefix" class="form-control" style="width: 180px;" required onchange="App.Upload.fetchNextId()"></select>
                <input type="text" id="docIdInput" class="form-control" required placeholder="กำลังสร้างรหัส...">
              </div>
            </div>
            <div class="form-group"><label>ชื่อเอกสาร / เรื่อง *</label><input type="text" id="docName" class="form-control" required placeholder="เช่น รายงานการประชุมประจำเดือน ม.ค."></div>
            <div class="form-group"><label>จัดเก็บลงแฟ้ม / กล่อง *</label><select id="uploadFolder" class="form-control" required></select></div>
            <div class="form-group">
              <label>วันที่จ่ายเงิน (คำนวณอายุจัดเก็บ 10 ปีอัตโนมัติ)</label>
              <input type="date" id="paymentDate" class="form-control">
            </div>
          </form>
        </div>

        <!-- ========== Card 2: อัปโหลดไฟล์ ========== -->
        <div class="card" style="border-top: 4px solid var(--primary);">
          <h3 style="margin-bottom:20px; color:var(--text-main); font-family:var(--font-heading); display:flex; align-items:center; gap:8px;">
            <span class="material-icons-round" style="color:var(--primary);">attach_file</span> 2. เลือกไฟล์เอกสารแนบ
          </h3>

          <div id="file-panel">
            <div style="border: 2px dashed #cbd5e1; border-radius: var(--radius-md); padding: 30px 20px; text-align: center; background: #f8fafc; transition: all 0.2s;" ondragover="event.preventDefault(); this.style.borderColor='var(--primary)';" ondragleave="this.style.borderColor='#cbd5e1';">
              <span class="material-icons-round" style="font-size: 48px; color: var(--primary); margin-bottom: 8px;">cloud_upload</span>
              <div style="font-weight: 600; color: var(--text-main); margin-bottom: 4px;">ลากไฟล์มาวางที่นี่ หรือคลิกปุ่มด้านล่าง</div>
              <p style="color: var(--text-muted); font-size: 13px; margin: 0 0 16px 0;">รองรับไฟล์ PDF, JPG, PNG, TIFF (สามารถเลือกหลายไฟล์พร้อมกันได้)</p>
              <input type="file" id="fileInput" class="form-control" accept=".pdf,image/*,.tif,.tiff" multiple onchange="App.Upload.handleFileSelect(this)">
            </div>
          </div>

          <!-- 🔥 กล่อง Visual PDF Editor 🔥 -->
          <div id="pdf-editor-container" style="display:none; margin-top: 18px; border: 1.5px solid var(--border); border-radius: var(--radius-md); padding: 18px; background-color: #f8fafc;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
              <strong style="color:var(--primary); display:flex; align-items:center; gap:6px; font-size:14.5px;">
                <span class="material-icons-round">view_carousel</span> พรีวิวหน้าเอกสาร (คลิกหน้าเพื่อลบทิ้ง)
              </strong>
              <div id="editor-status" style="font-size:13px; color:var(--text-muted); font-weight:500;"></div>
            </div>
            
            <div id="pdf-pages-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 12px; max-height: 350px; overflow-y: auto; padding: 12px; border: 1px dashed var(--border); border-radius: 12px; background: white;">
            </div>
          </div>

          <button type="button" class="btn btn-primary" style="width:100%; justify-content:center; padding:15px; margin-top:24px; font-size:1.05rem;" onclick="App.Upload.submit()">
            <span class="material-icons-round">qr_code_2</span> บันทึกข้อมูลและจัดทำเอกสาร
          </button>
        </div>
      </div>
    </div>
  </template>

  <template id="view-settings">
    <div class="settings-wrapper">
      <div style="margin-bottom: 24px;">
        <h1>การตั้งค่าระบบ (System Configuration)</h1>
        <p class="subtitle">จัดการโครงสร้างแฟ้มจัดเก็บ รหัสอ้างอิง และบัญชีผู้ใช้งานระบบ</p>
      </div>

      <div class="grid-2" style="margin-bottom: 24px;">
        <!-- Card: Folder Management -->
        <div class="card" style="padding: 0; grid-column: span 2; border-top: 4px solid var(--primary); overflow:hidden;">
          <div style="padding: 24px; border-bottom: 1px solid var(--border); background: #f8fafc;">
            <div style="display:flex; align-items:center; gap:12px; margin-bottom: 20px;">
              <span class="material-icons-round" style="font-size: 28px; color: var(--primary);">folder_shared</span>
              <div>
                <h3 style="margin: 0; color: var(--text-main); font-family:var(--font-heading);">การจัดการแฟ้มจัดเก็บ (Folder Management)</h3>
                <p style="margin: 4px 0 0 0; font-size: 13.5px; color: var(--text-muted);">สร้างและบริหารจัดการโครงสร้างแฟ้มเพื่อความเป็นระเบียบในการจัดเก็บ</p>
              </div>
            </div>
            
            <div style="display: flex; gap:16px; align-items: flex-start; flex-wrap:wrap;">
              <div class="form-group" style="flex: 1; min-width:200px; margin:0;">
                <label style="font-size:13px; color:var(--text-muted);">ชื่อแฟ้ม / หมวดหมู่หลัก *</label>
                <input type="text" id="newFolderName" class="form-control" placeholder="ระบุชื่อแฟ้มเอกสาร">
              </div>
              <div class="form-group" style="flex: 1.5; min-width:240px; margin:0;">
                <label style="font-size:13px; color:var(--text-muted);">คำอธิบายเพิ่มเติม</label>
                <input type="text" id="newFolderDesc" class="form-control" placeholder="ระบุรายละเอียดของแฟ้มจัดเก็บ (ถ้ามี)">
              </div>
              <div style="margin-top: 24px;">
                <button class="btn btn-primary" onclick="App.Settings.addFolder()" style="padding: 11px 24px;">
                  <span class="material-icons-round" style="font-size:18px;">add_circle</span> เพิ่มแฟ้ม
                </button>
              </div>
            </div>
          </div>
          <div style="padding: 0;">
            <table class="data-table" style="margin: 0;">
              <thead>
                <tr>
                  <th style="padding-left:24px;">ชื่อแฟ้มเอกสาร</th>
                  <th>รายละเอียด</th>
                  <th style="text-align:center; width: 120px;">การจัดการ</th>
                </tr>
              </thead>
              <tbody id="admin-folders-list"></tbody>
            </table>
          </div>
        </div>

        <!-- Card: Prefix Management -->
        <div class="card" style="padding: 0; grid-column: span 2; border-top: 4px solid var(--success); margin-top: 8px; overflow:hidden;">
          <div style="padding: 24px; border-bottom: 1px solid var(--border); background: #f8fafc;">
            <div style="display:flex; align-items:center; gap:12px; margin-bottom: 20px;">
              <span class="material-icons-round" style="font-size: 28px; color: var(--success);">qr_code_scanner</span>
              <div>
                <h3 style="margin: 0; color: var(--text-main); font-family:var(--font-heading);">รหัสอ้างอิงเอกสาร (Barcode Prefixes)</h3>
                <p style="margin: 4px 0 0 0; font-size: 13.5px; color: var(--text-muted);">กำหนดตัวอักษรย่อนำหน้ารหัสเพื่อแย่งหมวดหมู่ของบาร์โค้ด</p>
              </div>
            </div>
            
            <div style="display: flex; gap:16px; align-items: flex-start; flex-wrap:wrap;">
              <div class="form-group" style="flex: 1; min-width:180px; margin:0;">
                <label style="font-size:13px; color:var(--text-muted);">ตัวอักษรย่อ (Prefix) *</label>
                <input type="text" id="newPrefixValue" class="form-control" placeholder="เช่น FIN, HR, DOC">
              </div>
              <div class="form-group" style="flex: 1.5; min-width:240px; margin:0;">
                <label style="font-size:13px; color:var(--text-muted);">ชื่อเรียกหมวดหมู่</label>
                <input type="text" id="newPrefixName" class="form-control" placeholder="เช่น เอกสารการเงิน (ระบุหรือไม่ก็ได้)">
              </div>
              <div style="margin-top: 24px;">
                <button class="btn btn-primary" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);" onclick="App.Settings.addPrefix()">
                  <span class="material-icons-round" style="font-size:18px;">add_circle</span> เพิ่มรหัส
                </button>
              </div>
            </div>
          </div>
          <div style="padding: 0;">
            <table class="data-table" style="margin: 0;">
              <thead>
                <tr>
                  <th style="padding-left:24px;">ตัวอักษรย่ออ้างอิง (Prefix)</th>
                  <th>ความหมายหมวดหมู่</th>
                  <th style="text-align:center; width: 120px;">การจัดการ</th>
                </tr>
              </thead>
              <tbody id="admin-prefixes-list"></tbody>
            </table>
          </div>
        </div>

        <!-- Card: User Management -->
        <div class="card" style="padding: 0; grid-column: span 2; border-top: 4px solid var(--warning); margin-top: 8px; overflow:hidden;">
          <div style="padding: 24px; border-bottom: 1px solid var(--border); background: #f8fafc;">
            <div style="display:flex; align-items:center; gap:12px; margin-bottom: 20px;">
              <span class="material-icons-round" style="font-size: 28px; color: var(--warning);">manage_accounts</span>
              <div>
                <h3 style="margin: 0; color: var(--text-main); font-family:var(--font-heading);">ผู้ใช้งานระบบ (User Management)</h3>
                <p style="margin: 4px 0 0 0; font-size: 13.5px; color: var(--text-muted);">ผู้ดูแลระบบ: จัดการได้ทุกอย่าง · เจ้าหน้าที่: ดู อัปโหลด และแก้ไขเอกสารได้ แต่ลบเอกสารและเปลี่ยนการตั้งค่าไม่ได้</p>
              </div>
            </div>

            <div style="display: flex; gap:16px; align-items: flex-start; flex-wrap: wrap;">
              <div class="form-group" style="flex: 1; min-width: 160px; margin:0;">
                <label style="font-size:13px; color:var(--text-muted);">ชื่อผู้ใช้ *</label>
                <input type="text" id="newUserName" class="form-control" placeholder="เช่น somchai" autocomplete="off">
              </div>
              <div class="form-group" style="flex: 1; min-width: 160px; margin:0;">
                <label style="font-size:13px; color:var(--text-muted);">รหัสผ่านเริ่มต้น * (8 ตัวขึ้นไป)</label>
                <input type="password" id="newUserPassword" class="form-control" autocomplete="new-password">
              </div>
              <div class="form-group" style="width: 160px; margin:0;">
                <label style="font-size:13px; color:var(--text-muted);">สิทธิ์</label>
                <select id="newUserRole" class="form-control">
                  <option value="staff">เจ้าหน้าที่</option>
                  <option value="admin">ผู้ดูแลระบบ</option>
                </select>
              </div>
              <div style="margin-top: 24px;">
                <button class="btn btn-primary" style="background: linear-gradient(135deg, #d97706 0%, #f59e0b 100%);" onclick="App.Users.create()">
                  <span class="material-icons-round" style="font-size:18px;">person_add</span> เพิ่มผู้ใช้
                </button>
              </div>
            </div>
          </div>
          <div style="padding: 0;">
            <table class="data-table" style="margin: 0;">
              <thead>
                <tr>
                  <th style="padding-left:24px;">ชื่อผู้ใช้</th>
                  <th>สิทธิ์</th>
                  <th>สถานะ</th>
                  <th>สร้างเมื่อ</th>
                  <th style="text-align:center; width: 160px;">การจัดการ</th>
                </tr>
              </thead>
              <tbody id="admin-users-list"></tbody>
            </table>
          </div>
        </div>

      </div>
    </div>
  </template>

  <template id="view-analytics">
    <div class="analytics-wrapper">
      <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom: 24px;">
        <div>
          <h1>แดชบอร์ดผู้บริหาร (Executive Dashboard)</h1>
          <p class="subtitle">ภาพรวมสถิติการอัปโหลดเอกสารและความเคลื่อนไหวในระบบ</p>
        </div>
        <button class="btn btn-outline" style="color:var(--success); border-color:var(--success);" onclick="window.open('api/export.php', '_blank')">
          <span class="material-icons-outlined">download</span> ส่งออกรายงาน (CSV)
        </button>
      </div>

      <div class="grid-3" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 24px;">
        <div class="card" style="margin-bottom:0; display:flex; align-items:center; gap:20px; padding:24px; border-left: 4px solid var(--primary);">
          <div style="width:56px; height:56px; border-radius:16px; background:#eff6ff; display:flex; align-items:center; justify-content:center; color:var(--primary);">
            <span class="material-icons-round" style="font-size:32px;">description</span>
          </div>
          <div>
            <div style="font-size: 2.2rem; font-weight:700; color:var(--text-main); line-height:1.2; font-family:var(--font-heading);" id="stat-total-docs">0</div>
            <div style="color:var(--text-muted); font-size:0.9rem; font-weight:500;">เอกสารทั้งหมดในระบบ</div>
          </div>
        </div>
        <div class="card" style="margin-bottom:0; display:flex; align-items:center; gap:20px; padding:24px; border-left: 4px solid var(--success);">
          <div style="width:56px; height:56px; border-radius:16px; background:#ecfdf5; display:flex; align-items:center; justify-content:center; color:var(--success);">
            <span class="material-icons-round" style="font-size:32px;">cloud_done</span>
          </div>
          <div>
            <div style="font-size: 2.2rem; font-weight:700; color:var(--text-main); line-height:1.2; font-family:var(--font-heading);" id="stat-week-docs">0</div>
            <div style="color:var(--text-muted); font-size:0.9rem; font-weight:500;">อัปโหลดในสัปดาห์นี้</div>
          </div>
        </div>
        <div class="card" style="margin-bottom:0; display:flex; align-items:center; gap:20px; padding:24px; border-left: 4px solid var(--warning);">
          <div style="width:56px; height:56px; border-radius:16px; background:#fffbeb; display:flex; align-items:center; justify-content:center; color:var(--warning);">
            <span class="material-icons-round" style="font-size:32px;">group</span>
          </div>
          <div>
            <div style="font-size: 2.2rem; font-weight:700; color:var(--text-main); line-height:1.2; font-family:var(--font-heading);" id="stat-active-users">0</div>
            <div style="color:var(--text-muted); font-size:0.9rem; font-weight:500;">ผู้ใช้งานแอคทีฟ (30 วัน)</div>
          </div>
        </div>
      </div>

      <div class="grid-2" style="margin-bottom: 24px;">
        <div class="card">
          <h3 style="margin-bottom:16px; font-family:var(--font-heading); font-size:1.1rem; display:flex; align-items:center; gap:8px;">
            <span class="material-icons-round" style="color:var(--primary); font-size:20px;">bar_chart</span>
            สถิติอัปโหลด 7 วันล่าสุด
          </h3>
          <div style="position: relative; height: 200px; width: 100%;">
            <canvas id="uploadChart"></canvas>
          </div>
        </div>
        <div class="card">
          <h3 style="margin-bottom:16px; font-family:var(--font-heading); font-size:1.1rem; display:flex; align-items:center; gap:8px;">
            <span class="material-icons-round" style="color:var(--success); font-size:20px;">pie_chart</span>
            สัดส่วนเอกสารรายแฟ้มจัดเก็บ
          </h3>
          <div style="position: relative; height: 200px; width: 100%;">
            <canvas id="folderChart"></canvas>
          </div>
        </div>
      </div>

      <div class="card table-responsive" style="padding:0;">
        <div style="padding: 18px 24px; border-bottom: 1px solid var(--border); background: #f8fafc; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
          <h3 style="margin:0; display:flex; align-items:center; gap:8px; font-family:var(--font-heading); font-size:1.1rem;">
            <span class="material-icons-round" style="color:var(--text-muted);">history</span> ประวัติการใช้งาน (Audit Log & Security)
          </h3>
          <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <div style="position:relative; display:flex; align-items:center;">
              <span class="material-icons-round" style="position:absolute; left:12px; font-size:18px; color:#94a3b8; pointer-events:none;">search</span>
              <input type="text" id="auditLogSearch" class="form-control" placeholder="ค้นหาประวัติ (ผู้ใช้, รายการ, IP)..." style="padding:8px 14px 8px 36px; font-size:13.5px; width:260px; border-radius:999px; background:#ffffff;" onkeyup="App.Analytics.filterLogs()">
            </div>
            <select id="auditLogActionFilter" class="form-control" style="padding:8px 14px; font-size:13.5px; width:150px; border-radius:999px; background:#ffffff;" onchange="App.Analytics.filterLogs()">
              <option value="">ทุกรายการ (All)</option>
              <option value="Login">Login</option>
              <option value="Upload">Upload</option>
              <option value="Scan">Scan</option>
              <option value="Create">Create</option>
              <option value="Update">Update</option>
              <option value="Delete">Delete</option>
              <option value="User">User</option>
            </select>
          </div>
        </div>
        <table class="data-table">
          <thead><tr><th style="padding-left:24px;">วัน-เวลา</th><th>ผู้ใช้งาน</th><th>รายการ</th><th>รายละเอียด</th><th>IP Address</th></tr></thead>
          <tbody id="audit-log-body"></tbody>
        </table>
      </div>
    </div>
  </template>

  <script>
const App = {
  data: { userEmail: '', folders: [], customFields: [], prefixes: [], documents: [] },
  isAdmin: function() { return window.CURRENT_USER && window.CURRENT_USER.role === 'admin'; },
  navItem: function(viewId) { return document.querySelector(`.nav-links li[data-view="${viewId}"]`); },
  // FileId is stored as "uploads/<name>"; files are only reachable through the authenticated endpoint.
  fileUrl: function(fileId) {
    const name = String(fileId).trim().split('/').pop();
    return 'api/file.php?f=' + encodeURIComponent(name);
  },
  
  init: function() {
    this.showLoader("กำลังเชื่อมต่อเซิร์ฟเวอร์...");
    fetch('api/init.php')
      .then(res => res.json())
      .then(data => {
        this.data = data;
        document.getElementById('currentUserEmail').textContent = this.data.userEmail || 'admin';
        this.hideLoader();
        this.navigate('dashboard', document.querySelector('.nav-links li.active'));
      })
      .catch(err => { 
        this.hideLoader(); 
        this.toast("เชื่อมต่อเซิร์ฟเวอร์ล้มเหลว", "error");
      });
  },
  
  navigate: function(viewId, elContext) {
    if ((viewId === 'analytics' || viewId === 'settings') && !this.isAdmin()) viewId = 'dashboard';
    if(elContext) {
      document.querySelectorAll('.nav-links li').forEach(li => li.classList.remove('active'));
      elContext.classList.add('active');
    }
    const template = document.getElementById('view-' + viewId);
    if(template) {
      document.getElementById('app-view').innerHTML = template.innerHTML;
      
      // Ensure top bar is always visible and nicely centered
      const topBar = document.querySelector('.top-bar');
      if (topBar) {
        topBar.style.display = 'flex';
      }

      if(viewId === 'analytics') this.Analytics.init();
      if(viewId === 'dashboard') this.Dashboard.renderTable(this.data.documents);
      if(viewId === 'scan') this.Scanner.init();
      if(viewId === 'upload') this.Upload.init();
      if(viewId === 'settings') this.Settings.init();
    }
  },
  
  showLoader: function(msg) { 
    const l = document.getElementById('loader'); const t = document.getElementById('loader-text');
    if(l) l.style.display = 'flex'; if(t) t.innerHTML = msg || 'กำลังประมวลผล...'; 
  },
  hideLoader: function() { const l = document.getElementById('loader'); if(l) l.style.display = 'none'; },
  
  toast: function(msg, type='error') {
    let container = document.getElementById('toast-container');
    if(!container) { container = document.createElement('div'); container.id = 'toast-container'; document.body.appendChild(container); }
    const t = document.createElement('div'); t.className = `toast-msg ${type}`;
    let iconName = type === 'success' ? 'check_circle' : (type === 'info' ? 'info' : 'error');
    t.innerHTML = `<span class="material-icons-round" style="font-size:22px; color:${type === 'success' ? 'var(--success)' : (type === 'info' ? 'var(--primary)' : 'var(--danger)')};">${iconName}</span><span>${msg}</span>`;
    container.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(100%)'; t.style.transition = 'all 0.3s ease'; setTimeout(() => t.remove(), 300); }, 3500);
  },

  uploadFile: async function(file, pagesToDelete, onProgress) {
    return new Promise((resolve, reject) => {
      const formData = new FormData();
      formData.append('file', file);
      if (pagesToDelete) formData.append('pagesToDelete', pagesToDelete);
      
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'api/upload.php', true);
      xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN);
      xhr.upload.onprogress = function(e) {
        if (e.lengthComputable && onProgress) {
          const percent = Math.round((e.loaded / e.total) * 100);
          onProgress(percent);
        }
      };

      xhr.onload = function() {
        if (xhr.status === 200) {
          try {
            const result = JSON.parse(xhr.responseText);
            if(result.success) {
              resolve({ success: true, fileId: result.fileId });
            } else { reject(new Error(result.message)); }
          } catch(e) { reject(new Error("Invalid JSON response")); }
        } else if (xhr.status === 401) {
          location.href = 'login.php';
        } else {
          let msg = "อัปโหลดไม่สำเร็จ";
          try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
          reject(new Error(msg));
        }
      };

      xhr.onerror = function() { reject(new Error("เครือข่ายอินเทอร์เน็ตขาดหาย")); };
      xhr.send(formData);
    });
  },
  escapeHtml: function(unsafe) {
    if (!unsafe) return '';
    return unsafe.toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
  }
};

App.Dashboard = {
  searchTimer: null, currentEditDoc: null,
  showFolderModal: function() {
    document.getElementById('quick-folder-modal').style.display = 'flex';
    document.getElementById('quickFolderName').value = '';
    document.getElementById('quickFolderDesc').value = '';
  },
  closeFolderModal: function() {
    document.getElementById('quick-folder-modal').style.display = 'none';
  },
  saveNewFolder: function() {
    const folderName = document.getElementById('quickFolderName').value.trim();
    const description = document.getElementById('quickFolderDesc').value.trim();
    if (!folderName) { App.toast("กรุณาระบุชื่อแฟ้ม"); return; }
    
    App.showLoader("กำลังสร้างแฟ้ม...");
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'addFolder', FolderName: folderName, Description: description })
    })
    .then(res => res.json())
    .then(res => {
        App.hideLoader();
        if (res.success) {
            App.toast("สร้างแฟ้มสำเร็จ", "success");
            fetch('api/init.php').then(r => r.json()).then(data => {
                App.data.folders = data.folders;
                if(App.Upload) App.Upload.init();
                if(App.Settings) App.Settings.renderTables();
                App.Dashboard.renderTable(App.data.documents);
            });
            this.closeFolderModal();
        } else {
            App.toast("ข้อผิดพลาด: " + res.message);
        }
    });
  },
  renderTable: function(docs) {
    const activeDocs = docs.filter(d => d.Status !== 'Deleted');
    const tbody = document.getElementById('doc-table-body');
    if(!tbody) return;
    
    const searchInput = document.getElementById('globalSearch');
    const isSearching = searchInput && searchInput.value.trim().length > 0;
    
    const grouped = {};
    docs.forEach(doc => {
        const fId = doc.FolderId || 'uncat';
        if (!grouped[fId]) grouped[fId] = [];
        grouped[fId].push(doc);
    });

    let html = '';
    let hasOutput = false;
    
    App.data.folders.forEach(f => {
        const folderDocs = grouped[f.ID] || [];
        if (isSearching && folderDocs.length === 0) return;
        
        hasOutput = true;

        html += `
          <tr class="folder-row" style="cursor:pointer; background:#f8fafc; border-left: 4px solid var(--primary); transition:background 0.2s;" onclick="App.Dashboard.toggleFolder('${f.ID}')">
            <td colspan="7" style="padding: 14px 18px;">
              <div style="display:flex; align-items:center; gap:12px; font-weight:600; color:var(--text-main); font-size:15px;">
                <span class="material-icons-round" id="icon-folder-${f.ID}" style="color:var(--primary); font-size:22px;">folder</span> 
                <span>${App.escapeHtml(f.FolderName)}</span>
                <span style="color:#475569; font-size:12px; font-weight:600; background:#e2e8f0; padding:2px 10px; border-radius:999px;">${folderDocs.length} รายการ</span>
                <span style="margin-left:auto; display:flex; align-items:center; gap:10px;">
                  <button class="btn btn-outline" style="padding:5px 10px; font-size:12.5px; border-radius:8px;" onclick="App.Dashboard.showBarcode('FL-${f.ID}'); event.stopPropagation();" title="พิมพ์บาร์โค้ดแฟ้ม"><span class="material-icons-round" style="font-size:16px;">qr_code_2</span> บาร์โค้ด</button>
                  <span class="material-icons-round" style="transform: rotate(0deg); transition: transform 0.25s cubic-bezier(0.4,0,0.2,1); color:var(--text-muted); font-size:22px;" id="chevron-folder-${f.ID}">expand_more</span>
                </span>
              </div>
            </td>
          </tr>
        `;

        folderDocs.forEach(doc => {
            let badge = doc.Status === 'Active' 
              ? '<span class="badge success"><span class="material-icons-round" style="font-size:13px;">check_circle</span> ปกติ</span>' 
              : '<span class="badge danger"><span class="material-icons-round" style="font-size:13px;">warning</span> หมดอายุ</span>';
            html += `<tr class="doc-item doc-folder-${f.ID}" style="display:none; background:#ffffff;">
              <td style="padding-left:44px; font-family:var(--font-heading); font-weight:600; color:var(--primary);">
                ${App.escapeHtml(doc.ID)}
              </td>
              <td style="font-weight:500;">${App.escapeHtml(doc.Filename)}</td>
              <td style="color:var(--text-muted);">${App.escapeHtml(f.FolderName)}</td>
              <td style="color:var(--text-muted);">${App.escapeHtml(doc.PaymentDate) || '-'}</td>
              <td style="color:var(--text-muted);">${App.escapeHtml(doc.ExpiryDate) || '-'}</td>
              <td>${badge}</td>
              <td style="text-align:center; white-space:nowrap;">
                <div style="display:inline-flex; gap:4px;">
                  <button class="btn btn-outline btn-icon" title="แก้ไขเอกสาร" onclick="App.Dashboard.editDoc('${App.escapeHtml(doc.ID)}'); event.stopPropagation();"><span class="material-icons-round" style="font-size:18px;">edit</span></button>
                  <button class="btn btn-outline btn-icon" title="เปิดดูเอกสาร" style="color:var(--primary);" onclick="App.Dashboard.viewDoc('${App.escapeHtml(doc.FileId)}'); event.stopPropagation();"><span class="material-icons-round" style="font-size:18px;">visibility</span></button>
                  <button class="btn btn-outline btn-icon" title="บาร์โค้ด" onclick="App.Dashboard.showBarcode('${App.escapeHtml(doc.ID)}'); event.stopPropagation();"><span class="material-icons-round" style="font-size:18px;">qr_code</span></button>
                  ${App.isAdmin() ? `<button class="btn btn-outline btn-icon" title="ลบเอกสาร" style="color:var(--danger);" onclick="App.Dashboard.deleteDoc('${App.escapeHtml(doc.ID)}'); event.stopPropagation();"><span class="material-icons-round" style="font-size:18px;">delete_outline</span></button>` : ''}
                </div>
              </td></tr>`;
        });
    });
    
    const uncategorized = grouped['uncat'] || [];
    if (uncategorized.length > 0) {
        hasOutput = true;
        html += `
          <tr class="folder-row" style="cursor:pointer; background:#f8fafc; border-left: 4px solid var(--text-muted); transition:background 0.2s;" onclick="App.Dashboard.toggleFolder('uncat')">
            <td colspan="7" style="padding: 14px 18px;">
              <div style="display:flex; align-items:center; gap:12px; font-weight:600; color:var(--text-main); font-size:15px;">
                <span class="material-icons-round" id="icon-folder-uncat" style="color:var(--text-muted); font-size:22px;">folder_off</span> 
                <span>ไม่มีแฟ้มจัดเก็บ</span>
                <span style="color:#475569; font-size:12px; font-weight:600; background:#e2e8f0; padding:2px 10px; border-radius:999px;">${uncategorized.length} รายการ</span>
                <span class="material-icons-round" style="margin-left:auto; transform: rotate(0deg); transition: transform 0.25s cubic-bezier(0.4,0,0.2,1); color:var(--text-muted); font-size:22px;" id="chevron-folder-uncat">expand_more</span>
              </div>
            </td>
          </tr>
        `;
        uncategorized.forEach(doc => {
            let badge = doc.Status === 'Active' 
              ? '<span class="badge success"><span class="material-icons-round" style="font-size:13px;">check_circle</span> ปกติ</span>' 
              : '<span class="badge danger"><span class="material-icons-round" style="font-size:13px;">warning</span> หมดอายุ</span>';
            html += `<tr class="doc-item doc-folder-uncat" style="display:none; background:#ffffff;">
              <td style="padding-left:44px; font-family:var(--font-heading); font-weight:600; color:var(--primary);">
                ${App.escapeHtml(doc.ID)}
              </td>
              <td style="font-weight:500;">${App.escapeHtml(doc.Filename)}</td>
              <td style="color:var(--text-muted);">-</td>
              <td style="color:var(--text-muted);">${App.escapeHtml(doc.PaymentDate) || '-'}</td>
              <td style="color:var(--text-muted);">${App.escapeHtml(doc.ExpiryDate) || '-'}</td>
              <td>${badge}</td>
              <td style="text-align:center; white-space:nowrap;">
                <div style="display:inline-flex; gap:4px;">
                  <button class="btn btn-outline btn-icon" title="แก้ไขเอกสาร" onclick="App.Dashboard.editDoc('${App.escapeHtml(doc.ID)}'); event.stopPropagation();"><span class="material-icons-round" style="font-size:18px;">edit</span></button>
                  <button class="btn btn-outline btn-icon" title="เปิดดูเอกสาร" style="color:var(--primary);" onclick="App.Dashboard.viewDoc('${App.escapeHtml(doc.FileId)}'); event.stopPropagation();"><span class="material-icons-round" style="font-size:18px;">visibility</span></button>
                  <button class="btn btn-outline btn-icon" title="บาร์โค้ด" onclick="App.Dashboard.showBarcode('${App.escapeHtml(doc.ID)}'); event.stopPropagation();"><span class="material-icons-round" style="font-size:18px;">qr_code</span></button>
                  ${App.isAdmin() ? `<button class="btn btn-outline btn-icon" title="ลบเอกสาร" style="color:var(--danger);" onclick="App.Dashboard.deleteDoc('${App.escapeHtml(doc.ID)}'); event.stopPropagation();"><span class="material-icons-round" style="font-size:18px;">delete_outline</span></button>` : ''}
                </div>
              </td></tr>`;
        });
    }

    if (!hasOutput) {
        html = `<tr><td colspan="7" style="text-align:center; padding:40px; color:var(--text-muted);">
          <div style="display:flex; flex-direction:column; align-items:center; gap:8px;">
            <span class="material-icons-round" style="font-size:48px; color:#cbd5e1;">inventory_2</span>
            <div>ไม่พบเอกสารหรือแฟ้มจัดเก็บที่ตรงกับคำค้นหา</div>
          </div>
        </td></tr>`;
    }

    tbody.innerHTML = html;
  },
  toggleFolder: function(folderId) {
      const rows = document.querySelectorAll('.doc-folder-' + folderId);
      const icon = document.getElementById('icon-folder-' + folderId);
      const chevron = document.getElementById('chevron-folder-' + folderId);
      if(rows.length === 0) return;
      
      const isExpanding = rows[0].style.display === 'none';
      rows.forEach(row => { row.style.display = isExpanding ? 'table-row' : 'none'; });
      
      if (isExpanding) {
          if(icon) icon.innerText = 'folder_open';
          if(chevron) chevron.style.transform = 'rotate(180deg)';
      } else {
          if(icon) icon.innerText = folderId === 'uncat' ? 'folder_off' : 'folder';
          if(chevron) chevron.style.transform = 'rotate(0deg)';
      }
  },
  search: function() {
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => {
      const q = document.getElementById('globalSearch').value.toLowerCase();
      // If user searches while not on the dashboard, navigate to dashboard automatically
      const currentActive = document.querySelector('.nav-links li.active');
      if (currentActive && currentActive.dataset.view !== 'dashboard') {
        const dashNav = document.querySelector('.nav-links li[data-view="dashboard"]');
        App.navigate('dashboard', dashNav);
      }
      const filtered = App.data.documents.filter(d => 
        (d.Filename && d.Filename.toLowerCase().includes(q)) || 
        (d.ID && d.ID.toLowerCase().includes(q))
      );
      this.renderTable(filtered);
    }, 300);
  },
  viewDoc: function(fileIdStr) { 
    if(!fileIdStr) { App.toast("ไม่มีไฟล์แนบ", "info"); return; }
    const urls = fileIdStr.split(',').filter(id => id.trim()).map(id => App.fileUrl(id));
    
    const tabsContainer = document.getElementById('viewer-tabs');
    const frameContainer = document.getElementById('viewer-frame-container');
    document.getElementById('viewer-modal').style.display = 'flex';

    const show = (url) => {
      const frame = document.createElement('iframe');
      frame.src = url; frame.width = '100%'; frame.height = '100%'; frame.style.border = '0';
      frameContainer.replaceChildren(frame);
    };

    tabsContainer.replaceChildren();
    tabsContainer.style.display = urls.length > 1 ? 'flex' : 'none';
    urls.forEach((url, index) => {
      const btn = document.createElement('button');
      btn.className = 'btn btn-outline';
      btn.style.cssText = 'padding:6px 12px; font-size:13px;';
      btn.textContent = `ไฟล์ส่วนที่ ${index + 1}`;
      btn.onclick = () => show(url);
      tabsContainer.appendChild(btn);
    });
    show(urls[0]);
  },
  editDoc: function(docId) {
    const doc = App.data.documents.find(d => d.ID === docId);
    if (!doc) return;
    this.currentEditDoc = doc;
    
    const prefixSelect = document.getElementById('editDocPrefix');
    if (App.data.prefixes.length > 0) { 
        prefixSelect.innerHTML = App.data.prefixes.map(p => `<option value="${p.PrefixValue}">${p.PrefixValue} (${p.PrefixName})</option>`).join(''); 
    } else { 
        prefixSelect.innerHTML = '<option value="DOC">DOC (ทั่วไป)</option>'; 
    }

    let matchedPrefix = "";
    Array.from(prefixSelect.options).forEach(opt => { 
        if (doc.ID.startsWith(opt.value)) { 
            if (opt.value.length > matchedPrefix.length) { matchedPrefix = opt.value; } 
        } 
    });
    
    if (matchedPrefix) { 
        document.getElementById('editDocPrefix').value = matchedPrefix; 
        document.getElementById('editDocIdInput').value = doc.ID.substring(matchedPrefix.length); 
    } else { 
        document.getElementById('editDocIdInput').value = doc.ID; 
    }

    document.getElementById('edit-filename').value = doc.Filename;
    document.getElementById('edit-payment-date').value = doc.PaymentDate || '';
    if(document.getElementById('edit-file-input')) document.getElementById('edit-file-input').value = ""; 
    
    document.getElementById('edit-modal').style.display = 'flex';
  },
  closeEditModal: function() { 
      document.getElementById('edit-modal').style.display = 'none'; 
      this.currentEditDoc = null; 
  },
  saveEdit: async function() {
    if (!this.currentEditDoc) return;
    const finalNewId = document.getElementById('editDocPrefix').value + document.getElementById('editDocIdInput').value.trim();
    const updatedData = { 
        newId: finalNewId, 
        Filename: document.getElementById('edit-filename').value, 
        PaymentDate: document.getElementById('edit-payment-date').value 
    };
    
    if (!document.getElementById('editDocIdInput').value.trim() || !updatedData.Filename) { 
        App.toast("กรุณากรอกข้อมูลสำคัญให้ครบถ้วน"); return; 
    }
    
    const fileInput = document.getElementById('edit-file-input');
    const uploadMode = document.querySelector('input[name="edit-upload-mode"]:checked')?.value || 'append';
    updatedData.appendFiles = (uploadMode === 'append');
    updatedData.oldFileId = this.currentEditDoc.FileId;

    let uploadedFileIds = [];
    if (fileInput && fileInput.files.length > 0) {
        App.showLoader("กำลังอัปโหลดไฟล์ใหม่...");
        for(let i=0; i<fileInput.files.length; i++) {
            try {
                const res = await App.uploadFile(fileInput.files[i], null, () => {});
                if(res.success) uploadedFileIds.push(res.fileId);
            } catch(e) {
                App.hideLoader(); App.toast(e.message); return;
            }
        }
        updatedData.newFileId = uploadedFileIds.join(',');
    } else {
        App.showLoader("กำลังอัปเดตฐานข้อมูล...");
    }

    fetch('api/document.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'updateDocumentMetadata', ...updatedData, oldId: this.currentEditDoc.ID })
    })
    .then(res => res.json())
    .then(res => {
      App.hideLoader();
      if(res.success) {
        const idx = App.data.documents.findIndex(d => d.ID === this.currentEditDoc.ID);
        if (idx !== -1) { App.data.documents[idx] = res.updatedDoc; }
        this.renderTable(App.data.documents);
        this.closeEditModal();
        App.toast("บันทึกการแก้ไขสำเร็จ", "success");
      } else { App.toast("ข้อผิดพลาด: " + res.message); }
    });
  },
  showBarcode: function(docId) { 
    document.getElementById('barcode-modal').style.display = 'flex'; 
    JsBarcode("#barcode-svg", docId, { format: "CODE128", width: 2, height: 70, displayValue: true }); 
  },
  closeBarcode: function() { document.getElementById('barcode-modal').style.display = 'none'; },
  deleteDoc: function(docId) {
    if(!confirm("ยืนยันการลบเอกสารนี้?")) return;
    App.showLoader("กำลังลบ...");
    fetch('api/document.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'deleteDocument', ID: docId })
    })
    .then(res => res.json())
    .then(res => {
      App.hideLoader();
      if(res.success) {
        App.data.documents = App.data.documents.filter(d => d.ID !== docId);
        this.renderTable(App.data.documents);
        App.toast("ลบเอกสารแล้ว", "success");
      } else { App.toast("ข้อผิดพลาด: " + res.message); }
    });
  }
};

App.Upload = {
  deletedPages: new Set(),
  pdfFile: null,
  init: function() {
    this.deletedPages.clear();
    this.pdfFile = null;
    const prefSelect = document.getElementById('docPrefix');
    if (prefSelect) {
        prefSelect.innerHTML = App.data.prefixes.map(p => `<option value="${p.PrefixValue}">${p.PrefixValue} (${p.PrefixName})</option>`).join('');
    }
    const folderSelect = document.getElementById('uploadFolder');
    if (folderSelect) {
        folderSelect.innerHTML = App.data.folders.map(f => `<option value="${f.ID}">${f.FolderName}</option>`).join('');
    }
    const pDate = document.getElementById('paymentDate');
    if (pDate) {
        pDate.value = new Date().toISOString().split('T')[0];
    }
    this.fetchNextId();
  },
  fetchNextId: function() {
    const prefix = document.getElementById('docPrefix')?.value;
    const input = document.getElementById('docIdInput');
    if (!prefix || !input) return;
    
    input.value = 'กำลังสร้างรหัส...';
    fetch('api/document.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'getNextId', Prefix: prefix })
    })
    .then(res => res.json())
    .then(res => {
      if(res.success) {
        input.value = res.nextNumber;
      } else {
        input.value = '';
        App.toast("ไม่สามารถสร้างรหัสอัตโนมัติได้");
      }
    });
  },
  handleFileSelect: async function(input) {
    this.deletedPages.clear();
    const container = document.getElementById('pdf-editor-container');
    const grid = document.getElementById('pdf-pages-grid');
    const statusDiv = document.getElementById('editor-status');
    if(statusDiv) statusDiv.innerText = '';
    
    if (input.files.length === 0) {
        if (container) container.style.display = 'none';
        this.pdfFile = null;
        return;
    }

    if (container) container.style.display = 'block';
    if (grid) grid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding: 20px;">กำลังโหลดพรีวิว...</div>';
    
    // CASE 1: Single PDF File (Page level deletion)
    if (input.files.length === 1 && input.files[0].type === 'application/pdf') {
      this.pdfFile = input.files[0];
      try {
        const url = URL.createObjectURL(this.pdfFile);
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.14.305/pdf.worker.min.js';
        
        const pdf = await pdfjsLib.getDocument({ url: url, cMapUrl: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.14.305/cmaps/', cMapPacked: true }).promise;
        const numPages = pdf.numPages;
        if (grid) grid.innerHTML = '';
        
        for (let i = 1; i <= numPages; i++) {
            const page = await pdf.getPage(i);
            const viewport = page.getViewport({scale: 0.5});
            
            const div = document.createElement('div');
            div.className = 'pdf-page-item';
            div.dataset.page = i;
            div.onclick = function() {
              if (this.classList.contains('deleted')) {
                this.classList.remove('deleted');
                App.Upload.deletedPages.delete(i);
              } else {
                this.classList.add('deleted');
                App.Upload.deletedPages.add(i);
              }
              if (statusDiv) statusDiv.innerText = `หน้าทั้งหมด: ${numPages} | ลบทิ้ง: ${App.Upload.deletedPages.size} หน้า`;
            };
            
            const canvas = document.createElement('canvas');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            const ctx = canvas.getContext('2d');
            
            await page.render({canvasContext: ctx, viewport: viewport}).promise;
            
            const numberDiv = document.createElement('div');
            numberDiv.className = 'pdf-page-number';
            numberDiv.innerText = `หน้า ${i}`;
            
            const overlayDiv = document.createElement('div');
            overlayDiv.className = 'delete-overlay';
            overlayDiv.innerHTML = `<span class="material-icons-outlined">delete_outline</span>`;
            
            div.appendChild(canvas);
            div.appendChild(numberDiv);
            div.appendChild(overlayDiv);
            
            if (grid) grid.appendChild(div);
        }
        if (statusDiv) statusDiv.innerText = `หน้าทั้งหมด: ${numPages} | ลบทิ้ง: 0 หน้า`;
      } catch (err) {
        console.error(err);
        if (grid) grid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; color:red;">ไม่สามารถพรีวิว PDF ได้</div>';
      }
    } 
    // CASE 2: Image Files or Multiple Files (File level deletion)
    else {
      this.pdfFile = null;
      if (grid) grid.innerHTML = '';
      
      Array.from(input.files).forEach((file, index) => {
          const div = document.createElement('div');
          div.className = 'pdf-page-item';
          div.onclick = function() {
            if (this.classList.contains('deleted')) {
              this.classList.remove('deleted');
              App.Upload.deletedPages.delete(index);
            } else {
              this.classList.add('deleted');
              App.Upload.deletedPages.add(index);
            }
            if (statusDiv) statusDiv.innerText = `ไฟล์ทั้งหมด: ${input.files.length} | ยกเลิกอัปโหลด: ${App.Upload.deletedPages.size} ไฟล์`;
          };
          
          if (file.type.startsWith('image/')) {
              const img = document.createElement('img');
              img.src = URL.createObjectURL(file);
              img.style.width = '100%';
              img.style.height = '150px';
              img.style.objectFit = 'cover';
              img.style.display = 'block';
              div.appendChild(img);
          } else {
              div.innerHTML = `<div style="height:150px; display:flex; align-items:center; justify-content:center; background:#f3f4f6;"><span class="material-icons-outlined" style="font-size:48px; color:var(--text-muted)">description</span></div>`;
          }
          
          div.innerHTML += `<div class="pdf-page-number">ไฟล์ที่ ${index + 1}</div><div class="delete-overlay"><span class="material-icons-outlined">delete_outline</span></div>`;
          if (grid) grid.appendChild(div);
      });
      if (statusDiv) statusDiv.innerText = `ไฟล์ทั้งหมด: ${input.files.length} | ยกเลิกอัปโหลด: 0 ไฟล์`;
    }
  },
  submit: async function() {
    const prefix = document.getElementById('docPrefix').value;
    const docId = document.getElementById('docIdInput').value;
    const filename = document.getElementById('docName').value;
    const folderId = document.getElementById('uploadFolder').value;
    const paymentDate = document.getElementById('paymentDate').value;
    const fileInput = document.getElementById('fileInput');

    if(!docId || !filename || !folderId) { App.toast("กรุณากรอกข้อมูลให้ครบถ้วน"); return; }
    if(!fileInput || fileInput.files.length === 0) { App.toast("กรุณาเลือกไฟล์อย่างน้อย 1 ไฟล์"); return; }
    
    let uploadedFileIds = [];
    let finalPagesToDelete = '';
    
    // Check if Single PDF mode
    if (this.pdfFile && fileInput.files.length === 1 && fileInput.files[0] === this.pdfFile) {
        finalPagesToDelete = Array.from(this.deletedPages).join(',');
    }
    
    App.showLoader("กำลังอัปโหลดไฟล์...");
    for(let i=0; i<fileInput.files.length; i++) {
        // If in multiple files mode (not single PDF) and this file index is deleted, skip it!
        if (!this.pdfFile && this.deletedPages.has(i)) {
            continue; 
        }
        
        try {
          const pagesToDel = (this.pdfFile && i === 0) ? finalPagesToDelete : null;
          const res = await App.uploadFile(fileInput.files[i], pagesToDel, (percent) => {
              document.getElementById('loader-text').innerHTML = `อัปโหลดไฟล์ที่ ${i+1}/${fileInput.files.length}... ${percent}%`;
          });
          if(res.success) uploadedFileIds.push(res.fileId);
        } catch(e) {
          App.hideLoader(); App.toast(e.message); return;
        }
    }

    if (uploadedFileIds.length === 0) {
        App.hideLoader();
        App.toast("ไม่มีไฟล์ที่เลือกหรือถูกยกเลิกไปทั้งหมด");
        return;
    }

    App.showLoader("กำลังบันทึกข้อมูล...");
    
    fetch('api/document.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'createDocument',
        ID: prefix + docId,
        Filename: filename,
        FolderId: folderId,
        PaymentDate: paymentDate,
        newFileId: uploadedFileIds.join(',')
      })
    })
    .then(res => res.json())
    .then(res => {
      App.hideLoader();
      if(res.success) {
        App.data.documents.push(res.updatedDoc);
        App.toast("บันทึกเอกสารสำเร็จ", "success");
        App.navigate('dashboard', App.navItem('dashboard'));
      } else { App.toast("ข้อผิดพลาด: " + res.message); }
    });
  }
};

// ========================================
// App.Scanner - สแกนเอกสารจากเครื่อง Canon DR-G2110
// ========================================
App.Scanner = {
  isReady: false,
  isScanning: false,
  scannedFiles: [],

  init: function() {
    this.scannedFiles = [];
    const prefSelect = document.getElementById('scanDocPrefix');
    if (prefSelect) {
        prefSelect.innerHTML = App.data.prefixes.map(p => `<option value="${p.PrefixValue}">${p.PrefixValue} (${p.PrefixName})</option>`).join('');
    }
    const folderSelect = document.getElementById('scanUploadFolder');
    if (folderSelect) {
        folderSelect.innerHTML = App.data.folders.map(f => `<option value="${f.ID}">${f.FolderName}</option>`).join('');
    }
    const pDate = document.getElementById('scanPaymentDate');
    if (pDate) {
        pDate.value = new Date().toISOString().split('T')[0];
    }
    this.fetchNextId();
    this.checkStatus();
  },

  fetchNextId: function() {
    const prefix = document.getElementById('scanDocPrefix')?.value;
    const input = document.getElementById('scanDocIdInput');
    if (!prefix || !input) return;
    
    input.value = 'กำลังสร้างรหัส...';
    fetch('api/document.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'getNextId', Prefix: prefix })
    })
    .then(res => res.json())
    .then(res => {
      if(res.success) {
        input.value = res.nextNumber;
      } else {
        input.value = '';
        App.toast("ไม่สามารถสร้างรหัสอัตโนมัติได้");
      }
    });
  },

  checkStatus: function() {
    const statusText = document.getElementById('scanner-status-text');
    const statusBar = document.getElementById('scanner-status-bar');
    if (!statusText || !statusBar) return;

    statusText.textContent = 'กำลังตรวจสอบเครื่องสแกน...';
    statusBar.style.background = '#f8fafc';
    statusBar.style.borderColor = 'var(--border)';

    fetch('api/scan.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'checkStatus' })
    })
    .then(res => res.json())
    .then(data => {
      if (data.success && data.naps2Installed) {
        this.isReady = true;
        statusBar.style.background = '#f0fdf4';
        statusBar.style.borderColor = '#a7f3d0';
        statusBar.querySelector('.material-icons-round').textContent = 'cast_connected';
        statusBar.querySelector('.material-icons-round').style.color = '#059669';

        if (data.detectedDevices && data.detectedDevices.length > 0) {
          statusText.textContent = 'พร้อมสแกน — พบเครื่องสแกน ' + data.detectedDevices.length + ' เครื่อง';
          statusText.style.color = '#047857';
        } else {
          statusText.textContent = 'ติดตั้ง NAPS2 แล้ว — ตรวจสอบการเชื่อมต่อเครื่องสแกน';
          statusText.style.color = '#92400e';
          statusBar.style.background = '#fffbeb';
          statusBar.style.borderColor = '#fcd34d';
          statusBar.querySelector('.material-icons-round').textContent = 'warning';
          statusBar.querySelector('.material-icons-round').style.color = '#d97706';
        }
      } else {
        this.isReady = false;
        statusBar.style.background = '#fef2f2';
        statusBar.style.borderColor = '#fecaca';
        statusBar.querySelector('.material-icons-round').textContent = 'error_outline';
        statusBar.querySelector('.material-icons-round').style.color = '#dc2626';
        statusText.textContent = 'ไม่พบ NAPS2 — กรุณาติดตั้งจาก naps2.com/download';
        statusText.style.color = '#dc2626';
      }
    })
    .catch(err => {
      this.isReady = false;
      statusText.textContent = 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้';
      statusText.style.color = '#dc2626';
      statusBar.style.background = '#fef2f2';
      statusBar.style.borderColor = '#fecaca';
    });
  },

  startScan: function() {
    if (this.isScanning) {
      App.toast('กำลังสแกนอยู่ กรุณารอสักครู่', 'info');
      return;
    }

    const dpi = document.getElementById('scanDpi')?.value || '300';
    const colorMode = document.getElementById('scanColorMode')?.value || 'color';
    const source = document.getElementById('scanSource')?.value || 'feeder';
    const format = document.getElementById('scanFormat')?.value || 'pdf';

    this.isScanning = true;
    const progressDiv = document.getElementById('scan-progress');
    const scanBtn = document.getElementById('btn-start-scan');
    const progressText = document.getElementById('scan-progress-text');

    if (progressDiv) progressDiv.style.display = 'block';
    if (scanBtn) { scanBtn.disabled = true; scanBtn.style.opacity = '0.5'; scanBtn.style.cursor = 'not-allowed'; }
    if (progressText) progressText.textContent = 'กำลังสั่งสแกนจากเครื่อง Canon DR-G2110...';

    fetch('api/scan.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'scan',
        dpi: parseInt(dpi),
        colorMode: colorMode,
        source: source,
        format: format
      })
    })
    .then(res => res.json())
    .then(data => {
      this.isScanning = false;
      if (progressDiv) progressDiv.style.display = 'none';
      if (scanBtn) { scanBtn.disabled = false; scanBtn.style.opacity = '1'; scanBtn.style.cursor = 'pointer'; }

      if (data.success) {
        App.toast(`สแกนสำเร็จ: ${data.filename} (${data.fileSize})`, 'success');
        this.scannedFiles.push(data);
        this.renderScannedFiles();
      } else {
        App.toast('สแกนไม่สำเร็จ: ' + data.message, 'error');
      }
    })
    .catch(err => {
      this.isScanning = false;
      if (progressDiv) progressDiv.style.display = 'none';
      if (scanBtn) { scanBtn.disabled = false; scanBtn.style.opacity = '1'; scanBtn.style.cursor = 'pointer'; }
      App.toast('เกิดข้อผิดพลาดในการสแกน: ' + err.message, 'error');
    });
  },

  renderScannedFiles: function() {
    const listDiv = document.getElementById('scanned-files-list');
    const container = document.getElementById('scanned-files-container');
    const countBadge = document.getElementById('scanned-files-count');
    if (!listDiv || !container) return;

    if (this.scannedFiles.length === 0) {
      listDiv.style.display = 'none';
      return;
    }

    listDiv.style.display = 'block';
    if (countBadge) countBadge.textContent = this.scannedFiles.length + ' ไฟล์';
    container.innerHTML = '';

    this.scannedFiles.forEach((file, idx) => {
      const item = document.createElement('div');
      item.id = 'scanned-file-' + idx;
      item.style.cssText = 'display:flex; align-items:center; gap:10px; padding:10px 14px; background:#ffffff; border:1.5px solid #e2e8f0; border-radius:10px; transition:all 0.2s;';
      
      const formatIcon = file.format === 'pdf' ? 'picture_as_pdf' : 'image';
      const formatColor = file.format === 'pdf' ? '#dc2626' : '#2563eb';
      
      item.innerHTML = `
        <span class="material-icons-round" style="font-size:28px; color:${formatColor};">${formatIcon}</span>
        <div style="flex:1; min-width:0;">
          <div style="font-size:13.5px; font-weight:600; color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${App.escapeHtml(file.filename)}</div>
          <div style="font-size:12px; color:var(--text-muted); display:flex; gap:8px; margin-top:2px;">
            <span>${file.fileSize}</span>
            <span>·</span>
            <span>${file.dpi} DPI</span>
            <span>·</span>
            <span>${file.colorMode === 'color' ? 'สี' : file.colorMode === 'gray' ? 'ขาวดำ' : 'B&W'}</span>
          </div>
        </div>
        <button class="btn btn-outline btn-icon" style="padding:4px; color:var(--primary); border-color:var(--border);" onclick="App.Scanner.previewFile('${App.escapeHtml(file.fileId)}')" title="ดูตัวอย่าง">
          <span class="material-icons-round" style="font-size:18px;">visibility</span>
        </button>
        <button class="btn btn-outline btn-icon" style="padding:4px; color:var(--danger); border-color:#fecaca;" onclick="App.Scanner.removeScannedFile(${idx})" title="ลบไฟล์นี้">
          <span class="material-icons-round" style="font-size:18px;">close</span>
        </button>
      `;
      container.appendChild(item);
    });
  },

  removeScannedFile: function(index) {
    this.scannedFiles.splice(index, 1);
    this.renderScannedFiles();
    App.toast('ลบไฟล์สแกนออกแล้ว', 'info');
  },

  previewFile: function(fileId) {
    App.Dashboard.viewDoc(fileId);
  },

  submit: async function() {
    const prefix = document.getElementById('scanDocPrefix')?.value;
    const docId = document.getElementById('scanDocIdInput')?.value;
    const filename = document.getElementById('scanDocName')?.value;
    const folderId = document.getElementById('scanUploadFolder')?.value;
    const paymentDate = document.getElementById('scanPaymentDate')?.value;

    if(!docId || !filename || !folderId) { App.toast("กรุณากรอกข้อมูลดัชนีเอกสารให้ครบถ้วน"); return; }
    if(this.scannedFiles.length === 0) { App.toast("กรุณาสั่งสแกนเอกสารอย่างน้อย 1 ครั้ง"); return; }

    const fileIds = this.scannedFiles.map(f => f.fileId).join(',');

    App.showLoader("กำลังบันทึกข้อมูลเอกสารสแกน...");
    fetch('api/document.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'createDocument',
        ID: prefix + docId,
        Filename: filename,
        FolderId: folderId,
        PaymentDate: paymentDate,
        newFileId: fileIds
      })
    })
    .then(res => res.json())
    .then(res => {
      App.hideLoader();
      if(res.success) {
        App.data.documents.push(res.updatedDoc);
        App.toast("บันทึกเอกสารสแกนสำเร็จ", "success");
        App.navigate('dashboard', App.navItem('dashboard'));
      } else { App.toast("ข้อผิดพลาด: " + res.message); }
    })
    .catch(err => {
      App.hideLoader();
      App.toast("เกิดข้อผิดพลาดในการบันทึก: " + err.message);
    });
  }
};

App.Settings = {
  init: function() {
      this.renderTables();
      App.Users.load();
  },
  renderTables: function() {
      const pTbody = document.getElementById('admin-prefixes-list');
      if (pTbody) {
          pTbody.innerHTML = App.data.prefixes.map(p => `
              <tr>
                  <td>${App.escapeHtml(p.PrefixValue)}</td>
                  <td>${App.escapeHtml(p.PrefixName)}</td>
                  <td style="text-align:center;">
                      <button class="btn btn-outline" style="padding: 4px 8px; color:var(--danger);" onclick="App.Settings.deletePrefix('${App.escapeHtml(p.PrefixValue)}')"><span class="material-icons-outlined" style="font-size:16px;">delete</span></button>
                  </td>
              </tr>
          `).join('');
      }
      
      const fTbody = document.getElementById('admin-folders-list');
      if (fTbody) {
          fTbody.innerHTML = App.data.folders.map(f => `
              <tr>
                  <td>${App.escapeHtml(f.FolderName)}</td>
                  <td>${App.escapeHtml(f.Description) || '-'}</td>
                  <td style="text-align:center; display:flex; gap:6px; justify-content:center;">
                      <button class="btn btn-outline" style="padding: 4px 8px; color:var(--primary);" onclick="App.Dashboard.showBarcode('FL-${f.ID}')" title="พิมพ์บาร์โค้ดแฟ้ม"><span class="material-icons-outlined" style="font-size:16px;">qr_code</span></button>
                      <button class="btn btn-outline" style="padding: 4px 8px; color:var(--danger);" onclick="App.Settings.deleteFolder(${f.ID})" title="ลบแฟ้ม"><span class="material-icons-outlined" style="font-size:16px;">delete</span></button>
                  </td>
              </tr>
          `).join('');
      }
  },
  addPrefix: function() {
    const pv = document.getElementById('newPrefixValue').value.trim();
    const pn = document.getElementById('newPrefixName').value.trim();
    if(!pv) return App.toast("กรุณากรอกตัวอักษรย่อ (Prefix)");
    
    fetch('api/settings.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action: 'addPrefix', PrefixValue: pv, PrefixName: pn})
    })
    .then(res => res.json())
    .then(res => {
      if(res.success) {
        App.data.prefixes.push({PrefixValue: pv, PrefixName: pn});
        App.toast("เพิ่มหมวดหมู่รหัสแล้ว", "success");
        document.getElementById('newPrefixValue').value = '';
        document.getElementById('newPrefixName').value = '';
        this.renderTables();
      }
    });
  },
  deletePrefix: function(prefixVal) {
      if(!confirm("ยืนยันการลบหมวดหมู่นี้?")) return;
      fetch('api/settings.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'deletePrefix', PrefixValue: prefixVal})
      }).then(res => res.json()).then(res => {
          if (res.success) {
              App.data.prefixes = App.data.prefixes.filter(p => p.PrefixValue !== prefixVal);
              this.renderTables();
          }
      });
  },
  addFolder: function() {
    const fn = document.getElementById('newFolderName').value;
    const fd = document.getElementById('newFolderDesc').value;
    if(!fn) return App.toast("กรอกชื่อแฟ้ม");
    
    fetch('api/settings.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action: 'addFolder', FolderName: fn, Description: fd})
    })
    .then(res => res.json())
    .then(res => {
      if(res.success) {
        App.data.folders.push({ID: res.ID, FolderName: fn, Description: fd});
        App.toast("เพิ่มแฟ้มจัดเก็บแล้ว", "success");
        document.getElementById('newFolderName').value = '';
        document.getElementById('newFolderDesc').value = '';
        this.renderTables();
      }
    });
  },
  deleteFolder: function(folderId) {
      if(!confirm("ยืนยันการลบแฟ้มจัดเก็บนี้? เอกสารในแฟ้มนี้จะไม่ถูกลบ แต่จะกลายเป็นไม่มีแฟ้ม")) return;
      fetch('api/settings.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'deleteFolder', ID: folderId})
      }).then(res => res.json()).then(res => {
          if (res.success) {
              App.data.folders = App.data.folders.filter(f => f.ID != folderId);
              this.renderTables();
          }
      });
  }
};

App.postJson = function(url, body) {
  return fetch(url, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(body)
  }).then(res => res.json());
};

App.Users = {
  list: [],
  me: null,
  roleLabels: { admin: 'ผู้ดูแลระบบ', staff: 'เจ้าหน้าที่' },
  load: function() {
    const tbody = document.getElementById('admin-users-list');
    if (!tbody) return;
    App.postJson('api/users.php', {action: 'list'}).then(res => {
      if (!res.success) { App.toast(App.escapeHtml(res.message)); return; }
      this.list = res.users; this.me = res.me;
      this.render();
    }).catch(() => App.toast("โหลดรายชื่อผู้ใช้ไม่สำเร็จ"));
  },
  render: function() {
    const tbody = document.getElementById('admin-users-list');
    if (!tbody) return;
    tbody.innerHTML = this.list.map(u => {
      const isMe = u.ID === this.me;
      const roleOptions = Object.keys(this.roleLabels).map(r =>
        `<option value="${r}" ${u.Role === r ? 'selected' : ''}>${this.roleLabels[r]}</option>`).join('');
      return `
        <tr>
          <td style="padding-left:24px;"><strong>${App.escapeHtml(u.Username)}</strong>${isMe ? ' <span class="badge" style="background:#DBEAFE; color:#1E3A8A;">คุณ</span>' : ''}</td>
          <td><select class="form-control" style="padding:6px 10px; width:150px;" onchange="App.Users.updateRole(${u.ID}, this.value)">${roleOptions}</select></td>
          <td>${u.Locked ? '<span class="badge danger">ถูกล็อก</span>' : '<span class="badge success">ปกติ</span>'}</td>
          <td>${App.escapeHtml(u.CreatedAt)}</td>
          <td style="text-align:center; display:flex; gap:6px; justify-content:center;">
            ${u.Locked ? `<button class="btn btn-outline" style="padding: 4px 8px;" onclick="App.Users.unlock(${u.ID})" title="ปลดล็อก"><span class="material-icons-outlined" style="font-size:16px;">lock_open</span></button>` : ''}
            <button class="btn btn-outline" style="padding: 4px 8px;" onclick="App.Account.openPasswordModal(${u.ID})" title="ตั้งรหัสผ่านใหม่"><span class="material-icons-outlined" style="font-size:16px;">password</span></button>
            ${isMe ? '' : `<button class="btn btn-outline" style="padding: 4px 8px; color:var(--danger);" onclick="App.Users.remove(${u.ID})" title="ลบผู้ใช้"><span class="material-icons-outlined" style="font-size:16px;">delete</span></button>`}
          </td>
        </tr>`;
    }).join('');
  },
  create: function() {
    const username = document.getElementById('newUserName').value.trim();
    const password = document.getElementById('newUserPassword').value;
    const role = document.getElementById('newUserRole').value;
    if (!username || !password) return App.toast("กรุณากรอกชื่อผู้ใช้และรหัสผ่าน");
    App.postJson('api/users.php', {action: 'create', Username: username, Password: password, Role: role}).then(res => {
      if (!res.success) { App.toast(App.escapeHtml(res.message)); return; }
      document.getElementById('newUserName').value = '';
      document.getElementById('newUserPassword').value = '';
      App.toast("เพิ่มผู้ใช้แล้ว", "success");
      this.load();
    });
  },
  updateRole: function(id, role) {
    App.postJson('api/users.php', {action: 'updateRole', ID: id, Role: role}).then(res => {
      if (!res.success) { App.toast(App.escapeHtml(res.message)); this.render(); return; }
      if (id === this.me && role !== 'admin') { location.reload(); return; }
      App.toast("เปลี่ยนสิทธิ์แล้ว", "success");
      this.load();
    });
  },
  unlock: function(id) {
    App.postJson('api/users.php', {action: 'unlock', ID: id}).then(res => {
      if (!res.success) { App.toast(App.escapeHtml(res.message)); return; }
      App.toast("ปลดล็อกบัญชีแล้ว", "success");
      this.load();
    });
  },
  remove: function(id) {
    const u = this.list.find(x => x.ID === id);
    if (!u || !confirm(`ยืนยันการลบผู้ใช้ "${u.Username}"?`)) return;
    App.postJson('api/users.php', {action: 'delete', ID: id}).then(res => {
      if (!res.success) { App.toast(App.escapeHtml(res.message)); return; }
      App.toast("ลบผู้ใช้แล้ว", "success");
      this.load();
    });
  }
};

App.Account = {
  targetUserId: null, // null = change my own password
  openPasswordModal: function(userId) {
    this.targetUserId = userId || null;
    const target = this.targetUserId ? App.Users.list.find(u => u.ID === this.targetUserId) : null;
    document.getElementById('password-modal-title').textContent = target ? `ตั้งรหัสผ่านใหม่ให้ ${target.Username}` : 'เปลี่ยนรหัสผ่านของฉัน';
    document.getElementById('pw-current-group').style.display = target ? 'none' : 'block';
    ['pwCurrent', 'pwNew', 'pwConfirm'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('password-modal').style.display = 'flex';
  },
  closePasswordModal: function() { document.getElementById('password-modal').style.display = 'none'; },
  savePassword: function() {
    const current = document.getElementById('pwCurrent').value;
    const pw = document.getElementById('pwNew').value;
    if (pw.length < 8) return App.toast("รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร");
    if (pw !== document.getElementById('pwConfirm').value) return App.toast("รหัสผ่านใหม่ทั้งสองช่องไม่ตรงกัน");
    const body = this.targetUserId
      ? {action: 'resetPassword', ID: this.targetUserId, Password: pw}
      : {action: 'changeOwnPassword', CurrentPassword: current, NewPassword: pw};
    App.postJson('api/users.php', body).then(res => {
      if (!res.success) { App.toast(App.escapeHtml(res.message)); return; }
      this.closePasswordModal();
      App.toast("บันทึกรหัสผ่านใหม่แล้ว", "success");
    });
  }
};

window.onload = function() {
  App.init();
};

App.Analytics = {
  chartUpload: null,
  chartFolder: null,
  allLogs: [],
  init: function() {
      App.showLoader("กำลังโหลดข้อมูลสถิติ...");
      fetch('api/dashboard_stats.php')
      .then(res => res.json())
      .then(res => {
          App.hideLoader();
          if (res.success) {
              this.allLogs = res.data.recentLogs || [];
              this.renderStats(res.data);
          } else {
              App.toast("ไม่สามารถโหลดข้อมูลสถิติได้");
          }
      });
  },
  filterLogs: function() {
      const searchInput = document.getElementById('auditLogSearch');
      const actionSelect = document.getElementById('auditLogActionFilter');
      const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
      const actionFilter = actionSelect ? actionSelect.value.trim().toLowerCase() : '';

      const filtered = this.allLogs.filter(log => {
          const matchQuery = !query || 
              (log.Username && log.Username.toLowerCase().includes(query)) ||
              (log.Action && log.Action.toLowerCase().includes(query)) ||
              (log.Details && log.Details.toLowerCase().includes(query)) ||
              (log.IPAddress && log.IPAddress.toLowerCase().includes(query));
          
          const matchAction = !actionFilter || 
              (log.Action && log.Action.toLowerCase().includes(actionFilter));

          return matchQuery && matchAction;
      });

      this.renderLogTable(filtered);
  },
  renderLogTable: function(logs) {
      const logBody = document.getElementById('audit-log-body');
      if (!logBody) return;
      
      if (logs.length === 0) {
          logBody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:30px; color:var(--text-muted);">
            <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
              <span class="material-icons-round" style="font-size:36px; color:#cbd5e1;">manage_search</span>
              <div>ไม่พบประวัติการใช้งานที่ตรงกับเงื่อนไขการค้นหา</div>
            </div>
          </td></tr>`;
          return;
      }

      logBody.innerHTML = logs.map(log => {
          let badgeStyle = 'background:#f1f5f9; color:#475569; border: 1px solid #e2e8f0;';
          const act = (log.Action || '').toLowerCase();
          if (act.includes('login')) {
              badgeStyle = 'background:#eff6ff; color:#1d4ed8; border: 1px solid #bfdbfe;';
          } else if (act.includes('upload') || act.includes('create')) {
              badgeStyle = 'background:#ecfdf5; color:#047857; border: 1px solid #a7f3d0;';
          } else if (act.includes('delete')) {
              badgeStyle = 'background:#fef2f2; color:#b91c1c; border: 1px solid #fecaca;';
          } else if (act.includes('update')) {
              badgeStyle = 'background:#fffbeb; color:#b45309; border: 1px solid #fde68a;';
          }

          return `
              <tr>
                  <td style="padding-left:24px; color:var(--text-muted); font-size:13.5px;">${App.escapeHtml(log.CreatedAt)}</td>
                  <td style="font-weight:600; color:var(--text-main);">${App.escapeHtml(log.Username)}</td>
                  <td><span class="badge" style="${badgeStyle}">${App.escapeHtml(log.Action)}</span></td>
                  <td style="color:#334155;">${App.escapeHtml(log.Details)}</td>
                  <td style="font-family:monospace; color:var(--text-muted); font-size:13px;">${App.escapeHtml(log.IPAddress)}</td>
              </tr>
          `;
      }).join('');
  },
  renderStats: function(data) {
      document.getElementById('stat-total-docs').innerText = data.totalDocs || 0;
      
      const thisWeekDocs = data.docsThisWeek.reduce((sum, item) => sum + parseInt(item.Count), 0);
      document.getElementById('stat-week-docs').innerText = thisWeekDocs;
      document.getElementById('stat-active-users').innerText = data.activeUsers || 0;

      // Render Audit Log Table with all logs initially
      this.renderLogTable(this.allLogs);

      // Render Charts
      if (this.chartUpload) this.chartUpload.destroy();
      if (this.chartFolder) this.chartFolder.destroy();

      const uploadCtx = document.getElementById('uploadChart').getContext('2d');
      this.chartUpload = new Chart(uploadCtx, {
          type: 'bar',
          data: {
              labels: data.docsThisWeek.map(d => d.Date),
              datasets: [{
                  label: 'จำนวนเอกสาร',
                  data: data.docsThisWeek.map(d => parseInt(d.Count)),
                  backgroundColor: '#3b82f6',
                  hoverBackgroundColor: '#1d4ed8',
                  borderRadius: 8,
                  borderSkipped: false
              }]
          },
          options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                  legend: { display: false }
              },
              scales: {
                  y: { grid: { color: '#f1f5f9' }, ticks: { precision: 0 } },
                  x: { grid: { display: false } }
              }
          }
      });

      const folderCtx = document.getElementById('folderChart').getContext('2d');
      this.chartFolder = new Chart(folderCtx, {
          type: 'doughnut',
          data: {
              labels: data.docsByFolder.map(d => d.FolderName),
              datasets: [{
                  data: data.docsByFolder.map(d => parseInt(d.Count)),
                  backgroundColor: ['#2563eb', '#38bdf8', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#64748b'],
                  borderWidth: 2,
                  borderColor: '#ffffff'
              }]
          },
          options: {
              responsive: true,
              maintainAspectRatio: false,
              cutout: '70%',
              plugins: {
                  legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } }
              }
          }
      });
  }
};
</script>
</body>
</html>
