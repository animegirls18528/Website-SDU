<?php
// First-run setup: create the first admin account. Disabled as soon as any user exists.
require_once 'config/auth.php';
startSecureSession();
require_once 'config/database.php';
require_once 'config/helpers.php';

if ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0) {
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'โทเค็นความปลอดภัยไม่ถูกต้อง กรุณาลองใหม่';
    } elseif (!preg_match('/^[A-Za-z0-9._@-]{3,50}$/', $username)) {
        $error = 'ชื่อผู้ใช้ต้องยาว 3-50 ตัว ใช้ได้เฉพาะ a-z, 0-9 และ . _ @ -';
    } elseif ($err = validatePassword($password)) {
        $error = $err;
    } elseif ($password !== ($_POST['password2'] ?? '')) {
        $error = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
    } else {
        $pdo->prepare("INSERT INTO users (Username, Password, Role) VALUES (?, ?, 'admin')")
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int)$pdo->lastInsertId();
        loginUser(['ID' => $id, 'Username' => $username, 'Role' => 'admin']);
        addAuditLog($pdo, $id, 'Setup', 'Created first admin account');
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <title>ตั้งค่าเริ่มต้น - ระบบจัดเก็บเอกสารอิเล็กทรอนิกส์</title>
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
      --surface: rgba(255, 255, 255, 0.98);
      --text-main: #0f172a;
      --text-muted: #64748b;
      --border: #e2e8f0;
      --font-body: 'Sarabun', sans-serif;
      --font-heading: 'Plus Jakarta Sans', 'Sarabun', sans-serif;
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
        radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.2) 0px, transparent 50%),
        radial-gradient(at 50% 50%, rgba(14, 165, 233, 0.1) 0px, transparent 50%);
    }
    .setup-container {
      width: 100%;
      max-width: 460px;
    }
    .setup-card {
      background: var(--surface);
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
    }
    .brand-icon-wrap .material-icons-round {
      font-size: 38px;
      color: #2563eb;
    }
    .setup-card h2 {
      font-family: var(--font-heading);
      color: var(--text-main);
      font-size: 1.55rem;
      font-weight: 700;
      margin-bottom: 6px;
    }
    .setup-card p {
      color: var(--text-muted);
      font-size: 0.95rem;
      margin-bottom: 24px;
      line-height: 1.5;
    }
    .form-group {
      text-align: left;
      margin-bottom: 18px;
    }
    .form-group label {
      display: block;
      font-weight: 600;
      margin-bottom: 8px;
      font-size: 0.88rem;
      color: #334155;
    }
    .form-group input {
      width: 100%;
      padding: 13px 16px;
      background: #f8fafc;
      border: 1.5px solid var(--border);
      border-radius: 12px;
      font-family: var(--font-body);
      font-size: 0.97rem;
      outline: none;
      transition: all 0.2s;
    }
    .form-group input:focus {
      background: #fff;
      border-color: #3b82f6;
      box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
    }
    .btn-primary {
      width: 100%;
      background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
      color: white;
      border: none;
      padding: 14px;
      border-radius: 12px;
      font-family: var(--font-body);
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 8px 20px -4px rgba(37, 99, 235, 0.4);
      margin-top: 10px;
      transition: all 0.2s;
    }
    .btn-primary:hover {
      background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%);
      transform: translateY(-1.5px);
    }
    .error-message {
      color: #dc2626;
      background: #fef2f2;
      border: 1px solid #fecaca;
      padding: 12px;
      border-radius: 12px;
      margin-bottom: 20px;
      font-size: 0.9rem;
      text-align: left;
    }
  </style>
</head>
<body>
<div class="setup-container">
  <div class="setup-card">
    <div class="brand-icon-wrap">
      <span class="material-icons-round">admin_panel_settings</span>
    </div>
    <h2>ตั้งค่าเริ่มต้นระบบ</h2>
    <p>ยังไม่มีผู้ใช้งานในระบบ กรุณาสร้างบัญชีผู้ดูแลระบบ (Admin) คนแรก</p>
    <?php if ($error): ?><div class="error-message"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <div class="form-group">
        <label>ชื่อผู้ใช้งาน (Username)</label>
        <input type="text" name="username" required autocomplete="username" placeholder="เช่น admin" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)</label>
        <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="กำหนดรหัสผ่าน">
      </div>
      <div class="form-group">
        <label>ยืนยันรหัสผ่าน</label>
        <input type="password" name="password2" required minlength="8" autocomplete="new-password" placeholder="พิมพ์รหัสผ่านอีกครั้ง">
      </div>
      <button type="submit" class="btn-primary">สร้างบัญชีผู้ดูแลระบบ</button>
    </form>
  </div>
</div>
</body>
</html>
