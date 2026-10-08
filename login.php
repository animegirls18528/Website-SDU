<?php
require_once 'config/auth.php';
startSecureSession();
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
require_once 'config/database.php';
if ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0) {
    header('Location: setup.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <title>เข้าสู่ระบบ - ระบบจัดเก็บเอกสารอิเล็กทรอนิกส์</title>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
  <style>
    :root {
      --primary: #2563eb;
      --primary-hover: #1d4ed8;
      --primary-gradient: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #60a5fa 100%);
      --bg-gradient: radial-gradient(circle at 15% 15%, #1e293b 0%, #0f172a 100%);
      --surface: rgba(255, 255, 255, 0.98);
      --text-main: #0f172a;
      --text-muted: #64748b;
      --border: #e2e8f0;
      --font-body: 'Sarabun', sans-serif;
      --font-heading: 'Plus Jakarta Sans', 'Sarabun', sans-serif;
      --shadow-card: 0 25px 50px -12px rgba(15, 23, 42, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.1);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: var(--font-body);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
      background: #0b1329;
      background-image: 
        radial-gradient(at 0% 0%, rgba(37, 99, 235, 0.28) 0px, transparent 50%),
        radial-gradient(at 100% 100%, rgba(79, 70, 229, 0.22) 0px, transparent 50%),
        radial-gradient(at 50% 50%, rgba(14, 165, 233, 0.1) 0px, transparent 50%);
      position: relative;
      overflow-x: hidden;
    }
    body::before {
      content: '';
      position: absolute;
      top: -10%; left: -10%;
      width: 400px; height: 400px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(59, 130, 246, 0.25), transparent 70%);
      filter: blur(50px);
      z-index: 0;
      animation: float 12s ease-in-out infinite alternate;
    }
    @keyframes float {
      0% { transform: translateY(0) scale(1); }
      100% { transform: translateY(60px) scale(1.15); }
    }
    .login-container {
      width: 100%;
      max-width: 440px;
      position: relative;
      z-index: 1;
      animation: popIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    @keyframes popIn {
      from { opacity: 0; transform: translateY(20px) scale(0.97); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .login-card {
      background: var(--surface);
      backdrop-filter: blur(16px);
      padding: 44px 38px;
      border-radius: 24px;
      box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.35);
      border: 1px solid rgba(255, 255, 255, 0.8);
      text-align: center;
    }
    .brand-icon-wrap {
      width: 72px;
      height: 72px;
      margin: 0 auto 20px;
      background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
      border: 2px solid #bfdbfe;
      border-radius: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 10px 20px -5px rgba(37, 99, 235, 0.2);
    }
    .brand-icon-wrap .material-icons-round {
      font-size: 38px;
      color: #2563eb;
    }
    .login-card h2 {
      font-family: var(--font-heading);
      color: var(--text-main);
      font-size: 1.55rem;
      font-weight: 700;
      letter-spacing: -0.02em;
      margin-bottom: 6px;
    }
    .login-card p {
      color: var(--text-muted);
      font-size: 0.95rem;
      margin-bottom: 28px;
    }
    .form-group {
      text-align: left;
      margin-bottom: 20px;
    }
    .form-group label {
      display: block;
      font-weight: 600;
      margin-bottom: 8px;
      font-size: 0.88rem;
      color: #334155;
    }
    .input-wrap {
      position: relative;
      display: flex;
      align-items: center;
    }
    .input-wrap .material-icons-round {
      position: absolute;
      left: 14px;
      font-size: 20px;
      color: #94a3b8;
      pointer-events: none;
      transition: color 0.2s;
    }
    .input-wrap input {
      width: 100%;
      padding: 13px 16px 13px 44px;
      background: #f8fafc;
      border: 1.5px solid var(--border);
      border-radius: 12px;
      font-family: var(--font-body);
      font-size: 0.97rem;
      color: var(--text-main);
      outline: none;
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .input-wrap input:focus {
      background: #ffffff;
      border-color: #3b82f6;
      box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
    }
    .input-wrap input:focus + .material-icons-round,
    .input-wrap:focus-within .material-icons-round {
      color: #2563eb;
    }
    .btn-primary {
      width: 100%;
      background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
      color: white;
      border: none;
      padding: 14px;
      border-radius: 12px;
      font-family: var(--font-body);
      font-size: 1.02rem;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 8px 20px -4px rgba(37, 99, 235, 0.4);
      transition: all 0.25s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-top: 10px;
    }
    .btn-primary:hover {
      background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%);
      transform: translateY(-1.5px);
      box-shadow: 0 12px 24px -4px rgba(37, 99, 235, 0.5);
    }
    .btn-primary:active {
      transform: translateY(0);
    }
    .btn-primary:disabled {
      opacity: 0.7;
      cursor: not-allowed;
      transform: none;
    }
    .error-message {
      color: #dc2626;
      background: #fef2f2;
      border: 1px solid #fecaca;
      padding: 12px 16px;
      border-radius: 12px;
      margin-bottom: 20px;
      font-size: 0.9rem;
      text-align: left;
      display: none;
      align-items: center;
      gap: 10px;
    }
    .login-footer {
      margin-top: 24px;
      padding-top: 18px;
      border-top: 1px solid #f1f5f9;
      font-size: 0.85rem;
      color: #94a3b8;
    }
  </style>
</head>
<body>

<div class="login-container">
  <div class="login-card">
    <div class="brand-icon-wrap">
      <span class="material-icons-round">folder_shared</span>
    </div>
    <h2>ระบบจัดเก็บเอกสาร</h2>
    <p>ระบบบริหารจัดการและจัดเก็บเอกสารอิเล็กทรอนิกส์</p>

    <div id="errorBox" class="error-message"></div>

    <form id="loginForm" onsubmit="handleLogin(event)">
      <div class="form-group">
        <label for="username">ชื่อผู้ใช้งาน (Username)</label>
        <div class="input-wrap">
          <input type="text" id="username" required autocomplete="username" placeholder="กรอกชื่อผู้ใช้งาน">
          <span class="material-icons-round">person</span>
        </div>
      </div>
      <div class="form-group">
        <label for="password">รหัสผ่าน (Password)</label>
        <div class="input-wrap">
          <input type="password" id="password" required autocomplete="current-password" placeholder="กรอกรหัสผ่าน">
          <span class="material-icons-round">lock</span>
        </div>
      </div>
      <button type="submit" class="btn-primary" id="loginBtn">
        <span>เข้าสู่ระบบ</span>
        <span class="material-icons-round" style="font-size: 20px;">arrow_forward</span>
      </button>
    </form>

    <div class="login-footer">
      ปลอดภัยด้วยระบบยืนยันตัวตนระดับองค์กร
    </div>
  </div>
</div>

<script>
function handleLogin(e) {
  e.preventDefault();
  const btn = document.getElementById('loginBtn');
  const errorBox = document.getElementById('errorBox');
  const u = document.getElementById('username').value.trim();
  const p = document.getElementById('password').value;

  btn.disabled = true;
  btn.innerText = 'กำลังตรวจสอบ...';
  errorBox.style.display = 'none';

  fetch('api/auth.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: u, password: p })
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      window.location.href = 'index.php';
    } else {
      errorBox.innerText = data.message || 'เกิดข้อผิดพลาด';
      errorBox.style.display = 'block';
      btn.disabled = false;
      btn.innerText = 'เข้าสู่ระบบ';
    }
  })
  .catch(err => {
    errorBox.innerText = 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้';
    errorBox.style.display = 'block';
    btn.disabled = false;
    btn.innerText = 'เข้าสู่ระบบ';
  });
}
</script>

</body>
</html>
