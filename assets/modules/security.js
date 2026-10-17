/* My account security: two-factor authentication, active logins, scan-agent tokens. */
App.Security = {
  init: function() {
    this.loadStatus();
    this.loadSessions();
    if (App.canEdit()) this.loadTokens(); else document.getElementById('sec-tokens-card')?.remove();
  },

  loadStatus: async function() {
    const el = document.getElementById('sec-2fa');
    try {
      const s = await App.api('api/security.php', { action: 'status' });
      el.innerHTML = s.totpEnabled ? `
        <div class="notice ok"><span class="material-icons-round">verified_user</span><div style="flex:1;"><b>เปิดใช้การยืนยันตัวตนสองขั้นแล้ว</b><br>เข้าสู่ระบบต้องใช้รหัส 6 หลักจากแอปในโทรศัพท์ · เหลือรหัสกู้คืน ${s.recoveryLeft} รหัส</div></div>
        <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
          <button class="btn btn-outline" onclick="App.Security.newCodes()"><span class="material-icons-round" style="font-size:18px;">password</span> สร้างรหัสกู้คืนชุดใหม่</button>
          <button class="btn btn-outline" style="color:var(--danger);" onclick="App.Security.disable()"><span class="material-icons-round" style="font-size:18px;">no_encryption</span> ปิดการยืนยันสองขั้น</button>
        </div>`
        : `<div class="notice ${App.isAdmin() && s.requireAdmin2fa ? 'danger' : 'warn'}"><span class="material-icons-round">phonelink_lock</span><div>ยังไม่ได้เปิดการยืนยันตัวตนสองขั้น${App.isAdmin() && s.requireAdmin2fa ? ' — <b>ระบบกำหนดให้ผู้ดูแลระบบต้องเปิดใช้</b>' : ''}<br>ถ้ารหัสผ่านรั่ว คนอื่นจะยังเข้าบัญชีไม่ได้หากไม่มีโทรศัพท์ของคุณ</div></div>
          <button class="btn btn-primary" style="margin-top:12px;" onclick="App.Security.start()"><span class="material-icons-round" style="font-size:18px;">qr_code_scanner</span> เปิดการยืนยันตัวตนสองขั้น</button>`;
    } catch (e) { el.textContent = e.message; }
  },

  start: async function() {
    try {
      const r = await App.api('api/security.php', { action: 'totpStart' });
      document.getElementById('sec-2fa').innerHTML = `
        <ol class="steps">
          <li>ติดตั้งแอป <b>Google Authenticator</b> หรือ <b>Microsoft Authenticator</b> ในโทรศัพท์</li>
          <li>ในแอปกด "เพิ่มบัญชี" แล้วสแกน QR Code นี้ (หรือพิมพ์รหัสด้านล่าง)</li>
          <li>กรอกรหัส 6 หลักที่แอปแสดง แล้วกดยืนยัน</li>
        </ol>
        <div style="display:flex; gap:20px; align-items:center; flex-wrap:wrap; margin:12px 0;">
          <img src="${r.qr}" alt="QR" style="width:180px; height:180px; border:1px solid var(--border); border-radius:12px; image-rendering:pixelated;">
          <div><div class="mini-label">รหัสสำหรับพิมพ์เอง</div><div class="mono" style="font-size:15px; letter-spacing:1px; user-select:all;">${App.escapeHtml(r.secret)}</div></div>
        </div>
        <div style="display:flex; gap:8px; max-width:360px;">
          <input id="totp-code" class="form-control" inputmode="numeric" maxlength="6" placeholder="รหัส 6 หลัก" onkeydown="if(event.key==='Enter') App.Security.confirm()">
          <button class="btn btn-primary" onclick="App.Security.confirm()">ยืนยัน</button>
        </div>`;
      document.getElementById('totp-code').focus();
    } catch (e) { App.toast(e.message); }
  },

  confirm: async function() {
    try {
      const r = await App.api('api/security.php', { action: 'totpConfirm', code: document.getElementById('totp-code').value });
      this.showCodes(r.recoveryCodes, 'เปิดการยืนยันสองขั้นแล้ว');
      this.loadStatus();
      if (App.Bell) App.Bell.refresh();
    } catch (e) { App.toast(e.message); }
  },

  showCodes: function(codes, title) {
    App.Dialog.confirm({
      title, icon: 'key', items: codes,
      message: 'เก็บรหัสกู้คืนเหล่านี้ไว้ในที่ปลอดภัย (พิมพ์หรือจดไว้) ใช้เข้าระบบได้รหัสละ 1 ครั้งเมื่อไม่มีโทรศัพท์',
      note: 'รหัสจะแสดงครั้งนี้ครั้งเดียว', confirmText: 'บันทึกเก็บแล้ว', cancelText: 'ปิด',
    });
  },

  newCodes: async function() {
    const r = await App.Dialog.form({ title: 'สร้างรหัสกู้คืนชุดใหม่', icon: 'password', message: 'รหัสชุดเดิมจะใช้ไม่ได้อีก',
      fields: [{ name: 'password', label: 'รหัสผ่านของคุณ', type: 'password', required: true }], confirmText: 'สร้างใหม่' });
    if (!r) return;
    App.api('api/security.php', { action: 'newRecoveryCodes', password: r.password })
      .then(x => { this.showCodes(x.recoveryCodes, 'รหัสกู้คืนชุดใหม่'); this.loadStatus(); }).catch(e => App.toast(e.message));
  },

  disable: async function() {
    const r = await App.Dialog.form({ title: 'ปิดการยืนยันตัวตนสองขั้น?', icon: 'no_encryption', tone: 'danger', message: 'บัญชีจะป้องกันด้วยรหัสผ่านอย่างเดียว',
      fields: [{ name: 'password', label: 'รหัสผ่านของคุณ', type: 'password', required: true }], confirmText: 'ปิดการยืนยันสองขั้น' });
    if (!r) return;
    App.api('api/security.php', { action: 'totpDisable', password: r.password }).then(() => { App.toast('ปิดแล้ว', 'success'); this.loadStatus(); }).catch(e => App.toast(e.message));
  },

  // ---------- active logins ----------
  loadSessions: async function() {
    const body = document.getElementById('sec-sessions');
    const all = App.isAdmin() && document.getElementById('sec-all')?.checked;
    try {
      const r = await App.api('api/security.php', { action: 'sessions', all });
      body.innerHTML = r.sessions.map(s => `<tr>
          <td><b>${App.escapeHtml(s.Device)}</b>${s.Current ? ' <span class="badge success">อุปกรณ์นี้</span>' : ''}<div class="doc-sub mono">${App.escapeHtml((s.UserAgent || '').slice(0, 70))}</div></td>
          ${all ? `<td>${App.escapeHtml(s.Username)}</td>` : ''}
          <td class="mono">${App.escapeHtml(s.IP)}</td>
          <td style="white-space:nowrap;">${App.fmtDate(s.Since, true)}</td>
          <td style="white-space:nowrap;">${App.fmtDate(s.LastSeen, true)}</td>
          <td style="text-align:right;">${s.Current ? '' : `<button class="btn btn-outline" style="padding:5px 10px; font-size:12.5px; color:var(--danger);" onclick="App.Security.revoke('${s.Handle}')">ออกจากระบบ</button>`}</td>
        </tr>`).join('');
      document.getElementById('sec-user-col').style.display = all ? '' : 'none';
    } catch (e) { body.innerHTML = `<tr><td>${App.escapeHtml(e.message)}</td></tr>`; }
  },

  revoke: async function(handle) {
    if (!await App.Dialog.confirm({ title: 'สั่งออกจากระบบอุปกรณ์นี้?', icon: 'logout', tone: 'warning', message: 'อุปกรณ์นั้นต้องเข้าสู่ระบบใหม่', confirmText: 'ออกจากระบบ' })) return;
    App.api('api/security.php', { action: 'revokeSession', handle }).then(() => { App.toast('ออกจากระบบอุปกรณ์นั้นแล้ว', 'success'); this.loadSessions(); }).catch(e => App.toast(e.message));
  },

  revokeOthers: async function() {
    if (!await App.Dialog.confirm({ title: 'ออกจากระบบอุปกรณ์อื่นทั้งหมด?', icon: 'devices_other', tone: 'warning', message: 'อุปกรณ์นี้ยังใช้งานต่อได้ตามปกติ', confirmText: 'ออกจากระบบอุปกรณ์อื่น' })) return;
    App.api('api/security.php', { action: 'revokeOthers' }).then(r => { App.toast(`ออกจากระบบแล้ว ${r.count} อุปกรณ์`, 'success'); this.loadSessions(); }).catch(e => App.toast(e.message));
  },

  // ---------- scan agent tokens ----------
  loadTokens: async function() {
    const body = document.getElementById('sec-tokens');
    try {
      const r = await App.api('api/security.php', { action: 'tokens' });
      body.innerHTML = r.tokens.length ? r.tokens.map(t => `<tr>
          <td><b>${App.escapeHtml(t.Name)}</b></td><td>${App.fmtDate(t.CreatedAt)}</td><td>${t.LastUsedAt ? App.fmtDate(t.LastUsedAt, true) : '-'}</td>
          <td>${t.RevokedAt ? '<span class="badge muted">ยกเลิกแล้ว</span>' : '<span class="badge success">ใช้งานได้</span>'}</td>
          <td style="text-align:right;">${t.RevokedAt ? '' : `<button class="btn btn-outline" style="padding:5px 10px; font-size:12.5px; color:var(--danger);" onclick="App.Security.revokeToken(${t.ID})">ยกเลิก</button>`}</td></tr>`).join('')
        : `<tr><td colspan="5" style="color:var(--text-muted); padding:16px;">ยังไม่มี token</td></tr>`;
      document.getElementById('agent-server').textContent = location.origin + location.pathname.replace(/\/[^/]*$/, '');
    } catch (e) { body.innerHTML = `<tr><td>${App.escapeHtml(e.message)}</td></tr>`; }
  },

  createToken: async function() {
    const r = await App.Dialog.form({ title: 'สร้าง token เครื่องสแกน', icon: 'key',
      fields: [{ name: 'name', label: 'ชื่อเครื่อง', required: true, placeholder: 'เช่น เครื่องสแกนห้องการเงิน ชั้น 2' }], confirmText: 'สร้าง' });
    if (!r) return;
    try {
      const x = await App.api('api/security.php', { action: 'createToken', Name: r.name });
      await App.Dialog.form({ title: 'Token ใหม่', icon: 'key', message: 'คัดลอก token นี้ไปใส่ในโปรแกรมสแกนที่เครื่องนั้น (แสดงครั้งเดียว)',
        fields: [{ name: 't', label: 'Token', value: x.token }], note: 'ไฟล์จากเครื่องนั้นจะเข้ากล่องรับไฟล์ของบัญชีคุณ', confirmText: 'คัดลอกและปิด', cancelText: 'ปิด' })
        .then(ok => { if (ok && navigator.clipboard) navigator.clipboard.writeText(x.token).catch(() => {}); });
      this.loadTokens();
    } catch (e) { App.toast(e.message); }
  },

  revokeToken: async function(id) {
    if (!await App.Dialog.confirm({ title: 'ยกเลิก token นี้?', icon: 'key_off', tone: 'danger', message: 'เครื่องที่ใช้ token นี้จะส่งไฟล์เข้าระบบไม่ได้อีก', confirmText: 'ยกเลิก token' })) return;
    App.api('api/security.php', { action: 'revokeToken', ID: id }).then(() => this.loadTokens());
  },
};
