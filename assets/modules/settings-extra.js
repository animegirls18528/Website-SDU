/* Settings tabs added in v2, plus the scan page's profiles and inbox. */
App.SettingsExtra = {
  // ---------- physical storage locations ----------
  locations: async function() {
    const body = document.getElementById('admin-locations-list');
    if (!body) return;
    const r = await App.api('api/locations.php', { action: 'list' });
    this.locs = r.locations;
    body.innerHTML = r.locations.length ? r.locations.map(l => `<tr>
        <td style="padding-left:24px;"><b>${App.escapeHtml(l.Room)}</b></td><td>${App.escapeHtml(l.Cabinet || '-')}</td><td>${App.escapeHtml(l.Shelf || '-')}</td><td>${App.escapeHtml(l.Box || '-')}</td>
        <td style="font-size:13px; color:var(--text-muted);">${App.escapeHtml(l.Note || '')}</td><td style="text-align:right;">${(+l.DocCount).toLocaleString()}</td>
        <td style="text-align:right; white-space:nowrap;">
          <button class="btn btn-outline btn-icon" title="แก้ไข" onclick="App.SettingsExtra.editLocation(${l.ID})"><span class="material-icons-round" style="font-size:17px;">edit</span></button>
          <button class="btn btn-outline btn-icon" title="ลบ" style="color:var(--danger);" onclick="App.SettingsExtra.deleteLocation(${l.ID})"><span class="material-icons-round" style="font-size:17px;">delete</span></button>
        </td></tr>`).join('')
      : `<tr><td colspan="7" style="text-align:center; color:var(--text-muted); padding:24px;">ยังไม่มีที่เก็บ — เพิ่มห้อง/ตู้/ชั้น/กล่องที่เก็บต้นฉบับกระดาษ</td></tr>`;
  },

  editLocation: async function(id) {
    const l = (this.locs || []).find(x => x.ID == id) || {};
    const r = await App.Dialog.form({
      title: id ? 'แก้ไขที่เก็บ' : 'เพิ่มที่เก็บตัวจริง', icon: 'inventory_2',
      fields: [
        { name: 'Room', label: 'ห้อง / อาคาร', value: l.Room || '', required: true, placeholder: 'เช่น ห้องเก็บเอกสาร กองคลัง ชั้น 3' },
        { name: 'Cabinet', label: 'ตู้', value: l.Cabinet || '', placeholder: 'เช่น 5' },
        { name: 'Shelf', label: 'ชั้น', value: l.Shelf || '', placeholder: 'เช่น 2' },
        { name: 'Box', label: 'กล่อง / แฟ้ม', value: l.Box || '', placeholder: 'เช่น ฎีกา 2568-01' },
        { name: 'Note', label: 'หมายเหตุ', value: l.Note || '' },
      ],
      confirmText: 'บันทึก',
    });
    if (!r) return;
    App.api('api/locations.php', Object.assign({ action: 'save', ID: id || '' }, r)).then(() => { App.toast('บันทึกแล้ว', 'success'); this.locations(); App.ListTools.locations = null; }).catch(e => App.toast(e.message));
  },

  deleteLocation: async function(id) {
    const l = this.locs.find(x => x.ID == id);
    if (!await App.Dialog.confirm({ title: 'ลบที่เก็บนี้?', icon: 'delete', tone: 'danger', message: l.Label,
      note: +l.DocCount ? `เอกสาร ${l.DocCount} ฉบับจะกลายเป็น "ยังไม่ระบุที่เก็บ"` : '', confirmText: 'ลบ' })) return;
    App.api('api/locations.php', { action: 'delete', ID: id }).then(() => { this.locations(); App.ListTools.locations = null; }).catch(e => App.toast(e.message));
  },

  // ---------- scan profiles ----------
  profiles: async function() {
    const body = document.getElementById('admin-profiles-list');
    if (!body) return;
    const r = await App.api('api/profiles.php', { action: 'list' });
    this.profs = r.profiles;
    const desc = o => [o.dpi && o.dpi + ' DPI', { color: 'สี', gray: 'เทา', bw: 'ขาวดำ' }[o.colorMode], { feeder: 'ADF หน้าเดียว', duplex: 'สองหน้า', glass: 'กระจก' }[o.source],
      o.deskew && 'ปรับเอียง', o.skipBlank && 'ข้ามหน้าว่าง', o.ocr && 'OCR', o.pdfa && 'PDF/A', o.jpegQuality && 'JPEG ' + o.jpegQuality + '%'].filter(Boolean).join(' · ');
    body.innerHTML = r.profiles.length ? r.profiles.map(p => `<tr>
        <td style="padding-left:24px;"><b>${App.escapeHtml(p.Name)}</b></td><td style="font-size:13px;">${App.escapeHtml(desc(p.Options))}</td>
        <td>${App.escapeHtml(p.Prefix || '-')}</td><td style="font-size:13px;">${App.escapeHtml(p.FolderName || '-')}</td><td>${p.ExpectedPages || '-'}</td>
        <td style="text-align:right; white-space:nowrap;">
          <button class="btn btn-outline btn-icon" onclick="App.SettingsExtra.editProfile(${p.ID})"><span class="material-icons-round" style="font-size:17px;">edit</span></button>
          <button class="btn btn-outline btn-icon" style="color:var(--danger);" onclick="App.SettingsExtra.deleteProfile(${p.ID})"><span class="material-icons-round" style="font-size:17px;">delete</span></button>
        </td></tr>`).join('')
      : `<tr><td colspan="6" style="text-align:center; color:var(--text-muted); padding:24px;">ยังไม่มีโปรไฟล์</td></tr>`;
  },

  editProfile: async function(id) {
    const p = (this.profs || []).find(x => x.ID == id) || { Options: { dpi: 300, colorMode: 'bw', source: 'duplex', deskew: true, skipBlank: true } };
    const o = p.Options;
    const yn = v => v ? '1' : '';
    const r = await App.Dialog.form({
      title: id ? 'แก้ไขโปรไฟล์สแกน' : 'เพิ่มโปรไฟล์สแกน', icon: 'tune',
      fields: [
        { name: 'Name', label: 'ชื่อโปรไฟล์', value: p.Name || '', required: true, placeholder: 'เช่น ฎีกา (ขาวดำ 2 หน้า)' },
        { name: 'dpi', label: 'ความละเอียด', type: 'select', value: o.dpi || 300, options: [150, 200, 300, 400, 600].map(v => ({ value: v, label: v + ' DPI' })) },
        { name: 'colorMode', label: 'โหมดสี', type: 'select', value: o.colorMode || 'bw', options: [{ value: 'bw', label: 'ขาวดำ (ไฟล์เล็กที่สุด)' }, { value: 'gray', label: 'เทา' }, { value: 'color', label: 'สี' }] },
        { name: 'source', label: 'แหล่งกระดาษ', type: 'select', value: o.source || 'duplex', options: [{ value: 'feeder', label: 'ถาดป้อน หน้าเดียว' }, { value: 'duplex', label: 'ถาดป้อน สองหน้า' }, { value: 'glass', label: 'กระจก' }] },
        { name: 'jpegQuality', label: 'คุณภาพ JPEG (สี/เทา)', type: 'select', value: o.jpegQuality || '', options: [{ value: '', label: 'ค่าเริ่มต้น (75%)' }, { value: 60, label: '60% เล็กลง' }, { value: 45, label: '45% เล็กมาก' }, { value: 90, label: '90% คมชัด' }] },
        { name: 'deskew', label: 'ตัวเลือก', type: 'checkbox', value: yn(o.deskew), checkLabel: 'ปรับภาพเอียง' },
        { name: 'skipBlank', label: '', type: 'checkbox', value: yn(o.skipBlank), checkLabel: 'ข้ามหน้าว่าง' },
        { name: 'ocr', label: '', type: 'checkbox', value: yn(o.ocr), checkLabel: 'ทำ OCR ไทย/อังกฤษ' },
        { name: 'pdfa', label: '', type: 'checkbox', value: yn(o.pdfa), checkLabel: 'บันทึกเป็น PDF/A-2b' },
        { name: 'Prefix', label: 'รหัสนำหน้าเริ่มต้น', type: 'select', value: p.Prefix || '', options: [{ value: '', label: '-' }].concat(App.data.prefixes.map(x => ({ value: x.PrefixValue, label: x.PrefixValue + ' ' + x.PrefixName }))) },
        { name: 'FolderId', label: 'แฟ้มเริ่มต้น', type: 'select', value: p.FolderId || '', options: [{ value: '', label: '-' }].concat(App.data.folders.map(f => ({ value: f.ID, label: f.FolderName }))) },
        { name: 'ExpectedPages', label: 'จำนวนหน้าที่ควรได้ (ถ้าคงที่)', type: 'number', value: p.ExpectedPages || '' },
      ],
      confirmText: 'บันทึก',
    });
    if (!r) return;
    const Options = { dpi: +r.dpi, colorMode: r.colorMode, source: r.source, deskew: !!r.deskew, skipBlank: !!r.skipBlank, ocr: !!r.ocr, pdfa: !!r.pdfa, jpegQuality: r.jpegQuality ? +r.jpegQuality : '' };
    App.api('api/profiles.php', { action: 'save', ID: id || '', Name: r.Name, Options, Prefix: r.Prefix, FolderId: r.FolderId, ExpectedPages: r.ExpectedPages })
      .then(() => { App.toast('บันทึกโปรไฟล์แล้ว', 'success'); this.profiles(); }).catch(e => App.toast(e.message));
  },

  deleteProfile: async function(id) {
    const p = this.profs.find(x => x.ID == id);
    if (!await App.Dialog.confirm({ title: 'ลบโปรไฟล์นี้?', icon: 'delete', tone: 'danger', message: p.Name, confirmText: 'ลบ' })) return;
    App.api('api/profiles.php', { action: 'delete', ID: id }).then(() => this.profiles());
  },

  // ---------- policies: downloads, watermark, PDPA, 2FA, signatures ----------
  policy: async function() {
    const el = document.getElementById('policy-form');
    if (!el) return;
    const r = await App.api('api/settings.php', { action: 'getPolicy' });
    const p = r.policy;
    const roles = (key, list) => ['staff', 'viewer'].map(role => `<label class="check-row" style="padding:4px 0;"><input type="checkbox" data-role="${key}" value="${role}" ${list.split(',').includes(role) ? 'checked' : ''}><div>${ROLE_LABELS[role]}</div></label>`).join('');
    const c = r.certificate;
    el.innerHTML = `
      <div class="grid-2" style="gap:20px;">
        <div class="card" style="margin:0;"><h4 class="sec-title"><span class="material-icons-round">download</span> สิทธิ์ดาวน์โหลด</h4>
          <p class="doc-sub" style="margin-bottom:6px;">ผู้ดูแลระบบดาวน์โหลดได้เสมอ · บทบาทที่ไม่ได้เลือกจะเปิดดูได้อย่างเดียว</p>${roles('download_roles', p.download_roles)}
          <h4 class="sec-title" style="margin-top:14px;"><span class="material-icons-round">branding_watermark</span> ลายน้ำ</h4>
          <select id="pol-watermark" class="form-control">
            <option value="off" ${p.watermark_mode === 'off' ? 'selected' : ''}>ไม่ใส่ลายน้ำ</option>
            <option value="download" ${p.watermark_mode === 'download' ? 'selected' : ''}>ใส่เมื่อดาวน์โหลด</option>
            <option value="all" ${p.watermark_mode === 'all' ? 'selected' : ''}>ใส่ทุกครั้งที่เปิดดู/พิมพ์/ดาวน์โหลด</option></select>
          <p class="doc-sub" style="margin-top:6px;">ลายน้ำระบุชื่อผู้ใช้ วันเวลา และ IP · การพิมพ์จากหน้าเปิดดูจะมีลายน้ำเฉพาะโหมด "ทุกครั้ง"</p></div>
        <div class="card" style="margin:0;"><h4 class="sec-title"><span class="material-icons-round">privacy_tip</span> PDPA</h4>
          <p class="doc-sub" style="margin-bottom:6px;">บทบาทที่เห็นข้อมูลส่วนบุคคลและเปิดไฟล์ที่มีข้อมูลส่วนบุคคลได้ (ผู้ดูแลระบบเห็นเสมอ)</p>${roles('pii_roles', p.pii_roles)}
          <h4 class="sec-title" style="margin-top:14px;"><span class="material-icons-round">phonelink_lock</span> ความปลอดภัย</h4>
          <label class="check-row"><input type="checkbox" id="pol-2fa" ${p.require_admin_2fa === '1' ? 'checked' : ''}><div>บังคับผู้ดูแลระบบใช้การยืนยันตัวตนสองขั้น<small>ผู้ดูแลที่ยังไม่เปิดจะได้รับการแจ้งเตือนจนกว่าจะเปิด</small></div></label></div>
        <div class="card" style="margin:0;"><h4 class="sec-title"><span class="material-icons-round">manage_search</span> ช่องข้อมูลสำหรับค้นหาขั้นสูง</h4>
          <label class="mini-label">ชื่อช่อง "จำนวนเงิน"</label><input id="pol-amount" class="form-control" value="${App.escapeHtml(p.amount_field)}">
          <label class="mini-label" style="margin-top:8px;">ชื่อช่อง "หน่วยงาน"</label><input id="pol-dept" class="form-control" value="${App.escapeHtml(p.department_field)}"></div>
        <div class="card" style="margin:0;"><h4 class="sec-title"><span class="material-icons-round">draw</span> ลายมือชื่อดิจิทัลบน PDF</h4>
          <label class="mini-label">ชื่อหน่วยงาน (ภาษาไทย)</label><input id="pol-org" class="form-control" value="${App.escapeHtml(p.org_name)}">
          <label class="mini-label" style="margin-top:8px;">ชื่อหน่วยงาน (อังกฤษ ใช้ในใบรับรอง)</label><input id="pol-org-en" class="form-control" value="${App.escapeHtml(p.org_name_en)}" placeholder="e.g. Finance Division, SDU">
          <label class="check-row"><input type="checkbox" id="pol-sign" ${p.sign_enabled === '1' ? 'checked' : ''}><div>ลงลายมือชื่อดิจิทัลทุกไฟล์ที่จัดเก็บใหม่<small>เปิด PDF ใน Adobe Reader จะเห็นลายมือชื่อ และรู้ทันทีถ้าไฟล์ถูกแก้</small></div></label>
          <div class="notice ${c ? (c.selfSigned ? 'warn' : 'ok') : 'info'}" style="margin-top:6px;"><span class="material-icons-round">badge</span><div>${c ? `ใบรับรอง: <b>${App.escapeHtml(c.subject)}</b> · ออกโดย ${App.escapeHtml(c.issuer)} · หมดอายุ ${App.fmtDate(c.validTo)}${c.selfSigned ? '<br>เป็นใบรับรองที่ระบบสร้างเอง โปรแกรมอ่าน PDF จะแสดงว่า "ไม่ได้รับความเชื่อถือ" — ติดตั้งใบรับรองจากผู้ให้บริการออกใบรับรอง (CA) ได้ด้านล่าง' : ''}` : 'ยังไม่มีใบรับรอง ระบบจะสร้างให้เมื่อเปิดใช้งาน'}</div></div>
          <div style="display:flex; gap:6px; margin-top:8px; flex-wrap:wrap;"><input type="file" id="pol-cert" accept=".p12,.pfx" class="form-control" style="flex:1; min-width:180px;"><input type="password" id="pol-cert-pw" class="form-control" placeholder="รหัสผ่านไฟล์" style="width:150px;"><button class="btn btn-outline" onclick="App.SettingsExtra.uploadCert()">ติดตั้งใบรับรอง</button></div></div>
      </div>
      <div style="text-align:right; margin-top:16px;"><button class="btn btn-primary" onclick="App.SettingsExtra.savePolicy()"><span class="material-icons-round" style="font-size:18px;">save</span> บันทึกนโยบาย</button></div>`;
  },

  savePolicy: function() {
    const roles = key => Array.from(document.querySelectorAll(`[data-role="${key}"]:checked`)).map(c => c.value);
    App.api('api/settings.php', {
      action: 'savePolicy', download_roles: roles('download_roles'), pii_roles: roles('pii_roles'),
      watermark_mode: document.getElementById('pol-watermark').value, require_admin_2fa: document.getElementById('pol-2fa').checked,
      amount_field: document.getElementById('pol-amount').value, department_field: document.getElementById('pol-dept').value,
      org_name: document.getElementById('pol-org').value, org_name_en: document.getElementById('pol-org-en').value, sign_enabled: document.getElementById('pol-sign').checked,
    }).then(() => { App.toast('บันทึกนโยบายแล้ว', 'success'); this.policy(); }).catch(e => App.toast(e.message));
  },

  uploadCert: function() {
    const f = document.getElementById('pol-cert').files[0];
    if (!f) { App.toast('เลือกไฟล์ .p12 / .pfx ก่อน', 'info'); return; }
    const fd = new FormData();
    fd.append('action', 'uploadCert');
    fd.append('cert', f);
    fd.append('password', document.getElementById('pol-cert-pw').value);
    fetch('api/settings.php', { method: 'POST', body: fd }).then(r => r.json()).then(r => {
      if (!r.success) throw new Error(r.message);
      App.toast('ติดตั้งใบรับรองแล้ว', 'success');
      this.policy();
    }).catch(e => App.toast(e.message));
  },

  // ---------- reviewer links ----------
  shares: async function() {
    const body = document.getElementById('admin-shares-list');
    if (!body) return;
    const r = await App.api('api/share.php', { action: 'list' });
    body.innerHTML = r.links.length ? r.links.map(l => `<tr>
        <td style="padding-left:24px;"><b>${App.escapeHtml(l.Recipient)}</b>${l.Note ? `<div class="doc-sub">${App.escapeHtml(l.Note)}</div>` : ''}<div class="doc-sub">#${l.ID} · สร้างโดย ${App.escapeHtml(l.CreatedByName || '-')} ${App.fmtDate(l.CreatedAt)}</div></td>
        <td style="text-align:right;">${l.DocCount}</td><td>${App.fmtDate(l.ExpiresAt)}</td>
        <td>${l.Active ? '<span class="badge success">ใช้งานได้</span>' : (l.RevokedAt ? '<span class="badge muted">ยกเลิกแล้ว</span>' : '<span class="badge warning">หมดอายุ</span>')}${+l.AllowDownload ? ' <span class="badge info">ดาวน์โหลดได้</span>' : ''}</td>
        <td style="font-size:13px;">${l.AccessCount} ครั้ง${l.LastAccessAt ? `<div class="doc-sub">ล่าสุด ${App.fmtDate(l.LastAccessAt, true)}</div>` : ''}</td>
        <td style="text-align:right; white-space:nowrap;"><button class="btn btn-outline" style="padding:5px 10px; font-size:12.5px;" onclick="App.SettingsExtra.shareDetail(${l.ID})">รายละเอียด</button>
          ${l.Active ? `<button class="btn btn-outline" style="padding:5px 10px; font-size:12.5px; color:var(--danger);" onclick="App.SettingsExtra.revokeShare(${l.ID})">ยกเลิก</button>` : ''}</td></tr>`).join('')
      : `<tr><td colspan="6" style="text-align:center; color:var(--text-muted); padding:24px;">ยังไม่มีลิงก์ — เลือกเอกสารในคลังเอกสารแล้วกด "ลิงก์ผู้ตรวจ"</td></tr>`;
  },

  shareDetail: async function(id) {
    const r = await App.api('api/share.php', { action: 'detail', ID: id });
    App.Dialog.confirm({ title: `ลิงก์ #${id}`, icon: 'share',
      message: `${r.documents.length} เอกสาร · เปิดใช้ ${r.access.length} ครั้งล่าสุดแสดงด้านล่าง`,
      items: r.documents.map(d => `${d.ID} ${d.Filename}`).concat(r.access.slice(0, 30).map(a => `🕑 ${App.fmtDate(a.CreatedAt, true)} · ${a.Details} · ${a.IPAddress}`)),
      confirmText: 'ปิด', cancelText: 'ปิด' });
  },

  revokeShare: async function(id) {
    if (!await App.Dialog.confirm({ title: 'ยกเลิกลิงก์นี้?', icon: 'link_off', tone: 'danger', message: 'ผู้รับจะเปิดลิงก์ไม่ได้อีก', confirmText: 'ยกเลิกลิงก์' })) return;
    App.api('api/share.php', { action: 'revoke', ID: id }).then(() => this.shares());
  },

  // ---------- integrity, restore test, off-site backup (in the storage tab) ----------
  loadIntegrity: async function() {
    const el = document.getElementById('integrity-card');
    if (!el) return;
    const r = await App.api('api/integrity.php', { action: 'status' });
    const run = (x, kind) => {
      if (!x) return `<div class="doc-sub">ยังไม่เคย${kind}</div>`;
      if (x.State === 'running') return `<div class="notice info"><span class="spin-dot"></span><div>กำลัง${kind}... เริ่ม ${App.fmtDate(x.StartedAt, true)}</div></div>`;
      const good = x.State === 'ok';
      const details = Array.isArray(x.Details) ? x.Details : [];
      return `<div class="notice ${good ? 'ok' : 'danger'}"><span class="material-icons-round">${good ? 'verified' : 'gpp_bad'}</span><div style="flex:1;">
        <b>${good ? 'ผ่าน' : (x.State === 'problems' ? `พบปัญหา ${x.Problems} รายการ` : 'ไม่ผ่าน')}</b> · ${App.fmtDate(x.FinishedAt, true)}${x.TriggeredByName ? ' · ' + App.escapeHtml(x.TriggeredByName) : ''}
        ${x.Kind === 'integrity' ? ` · ตรวจ ${(+x.Checked).toLocaleString()} ไฟล์` : ''}
        ${details.length ? `<details style="margin-top:4px;"><summary style="cursor:pointer;">รายละเอียด</summary><div style="margin-top:4px; font-size:12.5px;">${details.slice(0, 50).map(d => d.step
            ? `${d.ok ? '✓' : '✗'} ${App.escapeHtml(d.step)}${d.detail ? ' — ' + App.escapeHtml(d.detail) : ''}`
            : `${App.escapeHtml(d.doc || '')} ${App.escapeHtml(d.what)} <span class="mono">${App.escapeHtml(d.path || '')}</span>`).join('<br>')}</div></details>` : ''}</div></div>`;
    };
    el.innerHTML = `
      <h3 style="font-family:var(--font-heading); display:flex; gap:8px; align-items:center; margin-bottom:12px;"><span class="material-icons-round" style="color:var(--primary);">fact_check</span> ความสมบูรณ์ของไฟล์และการกู้คืน</h3>
      <div class="grid-2" style="gap:16px;">
        <div><div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:8px;"><b>ตรวจ SHA-256 ทุกไฟล์</b>
          <button class="btn btn-outline" style="padding:6px 12px; font-size:13px;" onclick="App.SettingsExtra.runIntegrity()">ตรวจตอนนี้</button></div>${run(r.integrity, 'ตรวจ')}
          <p class="doc-sub" style="margin-top:6px;">ตั้งเวลาตรวจทุกเดือน: <span class="mono">php tools\\integrity_check.php</span></p></div>
        <div><div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:8px;"><b>ทดลองกู้คืนจากชุดสำรองล่าสุด</b>
          <button class="btn btn-outline" style="padding:6px 12px; font-size:13px;" onclick="App.SettingsExtra.runRestore()">ทดลองกู้คืน</button></div>${run(r.restore, 'ทดลองกู้คืน')}
          <p class="doc-sub" style="margin-top:6px;">นำเข้าลงฐานข้อมูลชั่วคราว นับข้อมูล สุ่มตรวจไฟล์ แล้วลบทิ้ง ระบบจริงไม่ได้รับผลกระทบ</p></div>
      </div>
      <h4 class="sec-title" style="margin-top:18px;"><span class="material-icons-round">lan</span> สำรองข้อมูลไปเก็บอีกที่ (NAS / ฮาร์ดดิสก์ภายนอก)</h4>
      <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <input id="offsite-path" class="form-control" style="flex:1; min-width:240px;" value="${App.escapeHtml(r.offsite.path)}" placeholder="เช่น \\\\NAS01\\edms-backup หรือ E:\\edms-backup">
        <button class="btn btn-outline" onclick="App.SettingsExtra.saveOffsite()">บันทึกและทดสอบ</button>
        ${r.offsite.path ? `<span class="badge ${r.offsite.reachable ? 'success' : 'danger'}">${r.offsite.reachable ? 'เข้าถึงได้' : 'เข้าถึงไม่ได้'}</span>` : ''}
      </div>
      <p class="doc-sub" style="margin-top:6px;">ทุกครั้งที่สำรองข้อมูล ระบบจะคัดลอกชุดสำรองไปที่นี่และตรวจ hash ว่าครบถ้วน · เว้นว่าง = ไม่ใช้</p>`;
    if ((r.integrity && r.integrity.State === 'running') || (r.restore && r.restore.State === 'running')) {
      setTimeout(() => { if (document.getElementById('integrity-card')) this.loadIntegrity(); }, 4000);
    }
  },

  runIntegrity: function() { App.api('api/integrity.php', { action: 'runIntegrity' }).then(() => { App.toast('เริ่มตรวจแล้ว', 'info'); setTimeout(() => this.loadIntegrity(), 1500); }).catch(e => App.toast(e.message)); },
  runRestore: function() { App.api('api/integrity.php', { action: 'runRestoreTest' }).then(() => { App.toast('เริ่มทดลองกู้คืนแล้ว', 'info'); setTimeout(() => this.loadIntegrity(), 1500); }).catch(e => App.toast(e.message)); },
  saveOffsite: function() {
    App.api('api/integrity.php', { action: 'saveOffsite', path: document.getElementById('offsite-path').value })
      .then(() => { App.toast('บันทึกแล้ว', 'success'); this.loadIntegrity(); }).catch(e => App.toast(e.message));
  },
};

/* Scan page extras: profile buttons and the inbox of files from other office scanners. */
App.ScanExtra = {
  profiles: [],
  init: async function() {
    try { this.profiles = (await App.api('api/profiles.php', { action: 'list' })).profiles; } catch (e) { this.profiles = []; }
    const bar = document.getElementById('profile-bar');
    if (bar) bar.innerHTML = this.profiles.length
      ? this.profiles.map(p => `<button class="chip" data-pid="${p.ID}" onclick="App.ScanExtra.apply(${p.ID})"><span class="material-icons-round" style="font-size:16px;">tune</span>${App.escapeHtml(p.Name)}</button>`).join('')
      : `<span class="doc-sub">ยังไม่มีโปรไฟล์ — ผู้ดูแลระบบเพิ่มได้ที่ ตั้งค่า › โปรไฟล์สแกน</span>`;
    this.loadInbox();
  },

  apply: function(id) {
    const p = this.profiles.find(x => x.ID == id);
    if (!p) return;
    const o = p.Options;
    const set = (elId, v) => { const el = document.getElementById(elId); if (el && v !== undefined && v !== '') el.value = String(v); };
    const chk = (elId, v) => { const el = document.getElementById(elId); if (el && v !== undefined) el.checked = !!v; };
    set('scanDpi', o.dpi); set('scanColorMode', o.colorMode); set('scanSource', o.source); set('scanFormat', o.format || 'pdf'); set('scanPageSize', o.pageSize);
    set('scanJpegQuality', o.jpegQuality || '');
    chk('scanDeskew', o.deskew); chk('scanSkipBlank', o.skipBlank); chk('scanOcr', o.ocr); chk('scanPdfa', o.pdfa);
    if (p.Prefix) { set('scanPrefix', p.Prefix); App.DocForm.fetchNextId('scan'); }
    if (p.FolderId) set('scanFolder', p.FolderId);
    if (p.ExpectedPages) set('scanExpected', p.ExpectedPages);
    document.querySelectorAll('#profile-bar .chip').forEach(c => c.classList.toggle('active', c.dataset.pid == id));
    App.toast(`ใช้โปรไฟล์ "${p.Name}"`, 'info');
  },

  loadInbox: async function() {
    const box = document.getElementById('inbox-box');
    if (!box) return;
    try {
      const r = await App.api('api/inbox.php', { action: 'list' });
      box.style.display = r.files.length ? 'block' : 'none';
      document.getElementById('inbox-list').innerHTML = r.files.map(f => `<div class="file-row">
          <span class="material-icons-round" style="color:#0891b2;">move_to_inbox</span>
          <div class="meta"><div class="name">${App.escapeHtml(f.OriginalName)}</div><div class="sub">${App.fmtBytes(f.SizeBytes)} · จาก ${App.escapeHtml(f.Source || '-')} · ${App.fmtDate(f.CreatedAt, true)}${f.TargetDocumentID ? ' · สำหรับ ' + App.escapeHtml(f.TargetDocumentID) : ''}</div></div>
          <button class="btn btn-outline" style="padding:5px 10px; font-size:12.5px;" onclick="App.ScanExtra.take(${f.ID})">ใช้ไฟล์นี้</button>
          <button class="btn btn-outline btn-icon" style="color:var(--danger);" title="ทิ้ง" onclick="App.ScanExtra.discard(${f.ID})"><span class="material-icons-round" style="font-size:18px;">delete</span></button></div>`).join('');
    } catch (e) { box.style.display = 'none'; }
  },

  take: async function(id) {
    try {
      const r = await App.api('api/inbox.php', { action: 'take', ID: id });
      // Agent files arrive raw: normalise them like a browser upload so QR stamping and watermarks can read them
      let file = r.file;
      try {
        App.showLoader('กำลังเตรียมไฟล์...');
        const blob = await (await fetch(App.fileUrl(file.fileId) + '&edit=1')).blob();
        const raw = new File([blob], file.name, { type: blob.type });
        const ready = await App.prepareFile(raw);
        if (ready !== raw) {
          const up = await App.uploadFile(ready, null, '', 'scan', { sourceSha256: await App.sha256Hex(raw) });
          App.discardStaged(file.fileId);
          file = Object.assign({}, up, { target: file.target });
        }
      } catch (e) { console.warn('Inbox file kept as is:', e); } finally { App.hideLoader(); }
      App.Scanner.staged.add(file);
      if (r.file.target) App.toast(`ไฟล์นี้ระบุว่าเป็นของเอกสาร ${r.file.target} — ใช้หน้า "สถานีสแกน" หรือแก้ไขเอกสารนั้นเพื่อแนบ`, 'info');
      this.loadInbox();
      if (App.Bell) App.Bell.refresh();
    } catch (e) { App.toast(e.message); }
  },

  discard: async function(id) {
    if (!await App.Dialog.confirm({ title: 'ทิ้งไฟล์นี้?', icon: 'delete', tone: 'danger', message: 'ไฟล์จะถูกลบออกจากกล่องรับไฟล์', confirmText: 'ทิ้งไฟล์' })) return;
    App.api('api/inbox.php', { action: 'discard', ID: id }).then(() => this.loadInbox());
  },
};
