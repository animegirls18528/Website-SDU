/* Batch scanning with cover sheets, and the scan station. */

/** Run one background scan job; resolves with the scanned files (already staged for this session). */
App.ScanJob = {
  run: function(options, onStatus) {
    return new Promise(async (resolve, reject) => {
      let job;
      try {
        job = await App.api('api/scan.php', Object.assign({ action: 'start' }, options));
      } catch (e) { reject(e); return; }
      const poll = async () => {
        try {
          const r = await App.api('api/scan.php', { action: 'jobStatus', jobId: job.jobId });
          if (r.status === 'done') { resolve(r.files); return; }
          if (r.status === 'failed') { reject(new Error(r.message || 'สแกนไม่สำเร็จ')); return; }
          if (onStatus) onStatus(r.status === 'queued' && r.ahead ? `รอคิว (มีงานก่อนหน้า ${r.ahead} งาน)` : 'กำลังสแกน...');
        } catch (e) { /* transient network error: keep polling */ }
        setTimeout(poll, 1500);
      };
      poll();
    });
  },

  /** Options from a scan profile (or sensible defaults for paper documents). */
  optionsFromProfile: function(p) {
    const o = Object.assign({ dpi: 300, colorMode: 'bw', source: 'duplex', format: 'pdf', pageSize: 'a4', deskew: true, skipBlank: true, ocr: false, splitPatchT: false, pdfa: false }, (p && p.Options) || {});
    o.format = 'pdf';
    o.splitPatchT = false;
    return o;
  },
};

App.Batch = {
  profiles: [],
  locations: [],
  segments: [],

  init: async function() {
    try {
      const [p, l] = await Promise.all([App.api('api/profiles.php', { action: 'list' }), App.api('api/locations.php', { action: 'list' })]);
      this.profiles = p.profiles;
      this.locations = l.locations;
    } catch (e) { /* forms still work without them */ }
    document.getElementById('cv-prefix').innerHTML = App.prefixOptions();
    document.getElementById('cv-folder').innerHTML = App.folderOptions(App.DocForm.lastFolder);
    document.getElementById('cv-location').innerHTML = `<option value="">— ยังไม่ระบุ —</option>` + this.locations.map(l => `<option value="${l.ID}">${App.escapeHtml(l.Label)}</option>`).join('');
    document.getElementById('cv-payment').value = App.today();
    document.getElementById('bt-profile').innerHTML = `<option value="">ค่าเริ่มต้น: ขาวดำ 300 DPI สองหน้า</option>` + this.profiles.map(p => `<option value="${p.ID}">${App.escapeHtml(p.Name)}</option>`).join('');
  },

  // ---------- step 1: cover sheets ----------
  createCovers: async function() {
    const titles = document.getElementById('cv-titles').value.split('\n').map(t => t.trim()).filter(Boolean);
    const copies = parseInt(document.getElementById('cv-copies').value, 10) || 0;
    const list = titles.length ? titles : (copies > 0 ? Array(copies).fill(document.getElementById('cv-title-one').value.trim() || 'เอกสารรอสแกน') : []);
    if (!list.length) { App.toast('ใส่ชื่อเรื่องอย่างน้อย 1 บรรทัด หรือระบุจำนวนใบ'); return; }
    if (list.length > 200) { App.toast('สร้างได้ครั้งละไม่เกิน 200 ใบ'); return; }
    try {
      App.showLoader(`กำลังสร้างเอกสาร ${list.length} รายการ...`);
      const r = await App.api('api/cover.php', {
        action: 'create', Prefix: document.getElementById('cv-prefix').value, FolderId: document.getElementById('cv-folder').value,
        PaymentDate: document.getElementById('cv-payment').value, ExpectedPages: document.getElementById('cv-pages').value,
        LocationId: document.getElementById('cv-location').value, Tags: document.getElementById('cv-tags').value, Titles: list,
      });
      App.hideLoader();
      App.toast(`สร้าง ${r.ids.length} เอกสาร (${r.ids[0]} – ${r.ids[r.ids.length - 1]}) เปิดหน้าพิมพ์ใบปะหน้าแล้ว`, 'success');
      window.open('cover_print.php?ids=' + encodeURIComponent(r.ids.join(',')), '_blank');
      document.getElementById('cv-titles').value = '';
    } catch (e) { App.hideLoader(); App.toast(e.message); }
  },

  printExisting: function() {
    const ids = document.getElementById('cv-existing').value.split(/[\s,]+/).map(s => s.trim()).filter(Boolean);
    if (!ids.length) { App.toast('ใส่รหัสเอกสาร'); return; }
    window.open('cover_print.php?ids=' + encodeURIComponent(ids.join(',')), '_blank');
  },

  // ---------- step 2: the stack ----------
  scanStack: async function() {
    const pid = document.getElementById('bt-profile').value;
    const opts = App.ScanJob.optionsFromProfile(this.profiles.find(p => String(p.ID) === pid));
    const st = document.getElementById('bt-status');
    st.innerHTML = '<span class="spin-dot"></span> กำลังสั่งสแกน...';
    try {
      const files = await App.ScanJob.run(opts, s => st.innerHTML = `<span class="spin-dot"></span> ${s}`);
      if (!files.length) throw new Error('ไม่ได้ไฟล์จากเครื่องสแกน');
      const f = files[0];
      this.stackSource = { fileId: f.fileId, name: f.name };
      const bytes = new Uint8Array(await (await fetch(App.fileUrl(f.fileId))).arrayBuffer());
      await this.analyse(bytes, f.name);
    } catch (e) {
      st.innerHTML = `<span style="color:var(--danger);">${App.escapeHtml(e.message)}</span> — ถ้าไม่มีเครื่องสแกนที่เซิร์ฟเวอร์ ให้สแกนด้วยวิธีอื่นแล้วเลือกไฟล์ PDF ของทั้งปึกแทน`;
    }
  },

  pickStack: async function(input) {
    const file = input.files[0];
    input.value = '';
    if (!file) return;
    this.stackSource = null;
    let bytes = new Uint8Array(await file.arrayBuffer());
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    if (ext !== 'pdf') {
      // A multi-page TIFF from the scanner software: make it a PDF first
      const prepared = await App.prepareFile(file);
      bytes = new Uint8Array(await prepared.arrayBuffer());
    }
    await this.analyse(bytes, file.name);
  },

  /** Find cover sheets (QR "EDMSCOVER:<ID>") and split the stack into documents. */
  analyse: async function(bytes, name) {
    const st = document.getElementById('bt-status');
    await App.loadScript('https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js');
    this.stackBytes = bytes;
    const pdf = await pdfjsLib.getDocument({ data: bytes.slice(0) }).promise;
    const n = pdf.numPages;
    const covers = []; // [{page, id}]
    for (let i = 1; i <= n; i++) {
      st.innerHTML = `<span class="spin-dot"></span> กำลังอ่านใบปะหน้า หน้า ${i}/${n}...`;
      const id = await this.findCover(await pdf.getPage(i));
      if (id) covers.push({ page: i, id });
    }
    this.pageCount = n;
    this.segments = [];
    const before = covers.length ? covers[0].page - 1 : n;
    covers.forEach((c, k) => {
      const end = k + 1 < covers.length ? covers[k + 1].page - 1 : n;
      const pages = [];
      for (let p = c.page + 1; p <= end; p++) pages.push(p);
      this.segments.push({ id: c.id, coverPage: c.page, pages, status: '' });
    });
    let docs = {};
    if (this.segments.length) {
      try { docs = (await App.api('api/cover.php', { action: 'resolve', IDs: this.segments.map(s => s.id) })).documents; } catch (e) { App.toast(e.message); }
    }
    this.segments.forEach(s => { s.doc = docs[s.id] || null; });
    this.orphanPages = before;
    st.innerHTML = `${App.escapeHtml(name)} · ${n} หน้า · พบใบปะหน้า ${covers.length} ใบ`;
    this.renderSegments();
  },

  findCover: async function(page) {
    const vp1 = page.getViewport({ scale: 1 });
    const scale = 1400 / Math.max(vp1.width, vp1.height);
    const vp = page.getViewport({ scale });
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(vp.width); canvas.height = Math.round(vp.height);
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
    await page.render({ canvasContext: ctx, viewport: vp }).promise;
    // The cover QR sits in the upper part of the sheet; try that first, then the whole page (rotated scans)
    for (const [y, h] of [[0, Math.round(canvas.height * 0.65)], [0, canvas.height]]) {
      const img = ctx.getImageData(0, y, canvas.width, h);
      const code = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
      if (code && /^EDMSCOVER:/.test(code.data)) return code.data.slice(10).trim();
    }
    return null;
  },

  renderSegments: function() {
    const el = document.getElementById('bt-result');
    if (!this.segments.length) {
      el.innerHTML = `<div class="notice warn"><span class="material-icons-round">qr_code_2</span><div>ไม่พบใบปะหน้าในไฟล์นี้ ตรวจว่าวางใบปะหน้าไว้หน้าแรกของแต่ละชุด และสแกนด้านที่มี QR Code</div></div>`;
      return;
    }
    const ok = this.segments.filter(s => s.doc && !s.doc.Deleted && s.pages.length).length;
    el.innerHTML = `
      ${this.orphanPages ? `<div class="notice warn" style="margin-bottom:10px;"><span class="material-icons-round">warning</span><div>${this.orphanPages} หน้าแรกไม่มีใบปะหน้านำหน้า ระบบจะไม่บันทึกหน้าเหล่านี้</div></div>` : ''}
      <div class="table-responsive"><table class="data-table"><thead><tr><th>ใบปะหน้า (หน้า)</th><th>เอกสาร</th><th style="text-align:right;">จำนวนหน้า</th><th>ตรวจสอบ</th><th>ผล</th></tr></thead><tbody>
      ${this.segments.map((s, i) => {
        const warn = [];
        if (!s.doc) warn.push('<span class="badge danger">ไม่พบรหัสนี้/ไม่มีสิทธิ์</span>');
        else if (s.doc.Deleted) warn.push('<span class="badge danger">อยู่ในถังขยะ</span>');
        if (!s.pages.length) warn.push('<span class="badge danger">ไม่มีหน้าเอกสาร</span>');
        if (s.doc && s.doc.ExpectedPages && s.pages.length !== s.doc.ExpectedPages) warn.push(`<span class="badge warning">ควรได้ ${s.doc.ExpectedPages} หน้า</span>`);
        if (s.doc && s.doc.HasFiles) warn.push('<span class="badge info">มีไฟล์อยู่แล้ว จะต่อท้าย</span>');
        return `<tr><td>${s.coverPage}</td><td><b>${App.escapeHtml(s.id)}</b>${s.doc ? `<div class="doc-sub">${App.escapeHtml(s.doc.Filename)}</div>` : ''}</td>
          <td style="text-align:right;">${s.pages.length}${s.pages.length ? `<div class="doc-sub">หน้า ${s.pages[0]}–${s.pages[s.pages.length - 1]}</div>` : ''}</td>
          <td>${warn.join(' ') || '<span class="badge success">พร้อมบันทึก</span>'}</td><td id="bt-row-${i}">${s.status}</td></tr>`;
      }).join('')}</tbody></table></div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:12px;">
        <button class="btn btn-primary" ${ok ? '' : 'disabled'} onclick="App.Batch.saveAll()"><span class="material-icons-round" style="font-size:18px;">save</span> บันทึกเข้าเอกสาร ${ok} รายการ</button>
      </div>`;
  },

  saveAll: async function() {
    const { PDFDocument } = PDFLib;
    const src = await PDFDocument.load(this.stackBytes, { ignoreEncryption: true });
    let done = 0;
    for (const [i, s] of this.segments.entries()) {
      const cell = document.getElementById('bt-row-' + i);
      if (!s.doc || s.doc.Deleted || !s.pages.length || s.saved) continue;
      cell.innerHTML = '<span class="spin-dot"></span> กำลังบันทึก';
      try {
        const out = await PDFDocument.create();
        (await out.copyPages(src, s.pages.map(p => p - 1))).forEach(p => out.addPage(p));
        const file = new File([await out.save({ useObjectStreams: false })], `${s.id}.pdf`, { type: 'application/pdf' });
        const up = await App.uploadFile(file, null, null, 'scan');
        await App.api('api/document.php', { action: 'updateDocumentMetadata', oldId: s.id, newFileId: [up.fileId], appendFiles: true });
        s.saved = true;
        s.status = cell.innerHTML = '<span class="badge success">✓ บันทึกแล้ว</span>';
        done++;
      } catch (e) {
        s.status = cell.innerHTML = `<span class="badge danger" title="${App.escapeHtml(e.message)}">✗ ${App.escapeHtml(e.message).slice(0, 60)}</span>`;
      }
    }
    if (this.stackSource) App.discardStaged(this.stackSource.fileId);
    App.toast(`บันทึกเข้าเอกสารแล้ว ${done} รายการ`, done ? 'success' : 'info');
    if (App.Bell) App.Bell.refresh();
  },
};

/* Scan station: barcode gun → scan → filed, repeat. Large controls, keyboard driven. */
App.Station = {
  profiles: [],
  doc: null,
  log: [],

  init: async function() {
    try { this.profiles = (await App.api('api/profiles.php', { action: 'list' })).profiles; } catch (e) { this.profiles = []; }
    const sel = document.getElementById('st-profile');
    sel.innerHTML = `<option value="">ค่าเริ่มต้น: ขาวดำ 300 DPI สองหน้า</option>` + this.profiles.map(p => `<option value="${p.ID}">${App.escapeHtml(p.Name)}</option>`).join('');
    const remembered = (() => { try { return localStorage.getItem('edms.station.profile'); } catch (e) { return null; } })();
    if (remembered) sel.value = remembered;
    sel.onchange = () => { try { localStorage.setItem('edms.station.profile', sel.value); } catch (e) {} };
    this.renderLog();
    this.reset();
  },

  reset: function() {
    this.doc = null;
    document.getElementById('st-doc').innerHTML = `<div class="st-empty"><span class="material-icons-round">qr_code_scanner</span><div>ยิงบาร์โค้ดบนใบปะหน้า หรือพิมพ์รหัสเอกสารแล้วกด Enter</div></div>`;
    document.getElementById('st-scan').disabled = true;
    const input = document.getElementById('st-code');
    input.value = '';
    input.focus();
  },

  onKey: function(e) {
    if (e.key === 'Enter') { e.preventDefault(); this.lookup(); }
  },

  lookup: async function() {
    const code = document.getElementById('st-code').value.trim().replace(/^EDMSCOVER:/, '');
    if (!code) return;
    try {
      const r = await App.api('api/cover.php', { action: 'resolve', IDs: [code] });
      const d = r.documents[code];
      if (!d || d.Deleted) { this.beep(false); App.toast(`ไม่พบเอกสาร ${code}`); document.getElementById('st-code').select(); return; }
      this.doc = d;
      document.getElementById('st-doc').innerHTML = `
        <div class="st-id">${App.escapeHtml(d.ID)}</div>
        <div class="st-title">${App.escapeHtml(d.Filename)}</div>
        <div class="st-meta">${d.ExpectedPages ? `ควรได้ ${d.ExpectedPages} หน้า · ` : ''}${d.HasFiles ? 'มีไฟล์อยู่แล้ว — ไฟล์ใหม่จะต่อท้าย' : 'ยังไม่มีไฟล์'}</div>
        <div class="st-hint">วางเอกสารในถาดป้อนแล้วกด <kbd>Enter</kbd> หรือปุ่ม "สแกน" · <kbd>Esc</kbd> ยกเลิก</div>`;
      const btn = document.getElementById('st-scan');
      btn.disabled = false;
      btn.focus();
    } catch (e) { App.toast(e.message); }
  },

  scan: async function() {
    if (!this.doc) return;
    const btn = document.getElementById('st-scan');
    const pid = document.getElementById('st-profile').value;
    const opts = App.ScanJob.optionsFromProfile(this.profiles.find(p => String(p.ID) === pid));
    btn.disabled = true;
    const status = document.getElementById('st-status');
    status.innerHTML = '<span class="spin-dot"></span> กำลังสแกน...';
    try {
      const files = await App.ScanJob.run(opts, s => status.innerHTML = `<span class="spin-dot"></span> ${s}`);
      await this.attach(files.map(f => f.fileId));
    } catch (e) {
      status.innerHTML = `<span style="color:var(--danger);">${App.escapeHtml(e.message)}</span>`;
      this.beep(false);
      btn.disabled = false;
    }
  },

  /** Fallback without a scanner on the server: attach a file picked here. */
  pickFile: async function(input) {
    if (!this.doc) { App.toast('ยิงบาร์โค้ดเอกสารก่อน', 'info'); input.value = ''; return; }
    const files = Array.from(input.files);
    input.value = '';
    const status = document.getElementById('st-status');
    try {
      const ids = [];
      for (const f of files) {
        status.innerHTML = `<span class="spin-dot"></span> กำลังอัปโหลด ${App.escapeHtml(f.name)}`;
        ids.push((await App.uploadFile(await App.prepareFile(f), null, null, 'scan', { sourceSha256: await App.sha256Hex(f) })).fileId);
      }
      await this.attach(ids);
    } catch (e) { status.innerHTML = `<span style="color:var(--danger);">${App.escapeHtml(e.message)}</span>`; this.beep(false); }
  },

  attach: async function(fileIds) {
    const status = document.getElementById('st-status');
    status.innerHTML = '<span class="spin-dot"></span> กำลังบันทึกและประทับ QR Code...';
    const r = await App.api('api/document.php', { action: 'updateDocumentMetadata', oldId: this.doc.ID, newFileId: fileIds, appendFiles: true });
    const pages = r.updatedDoc.Files.reduce((s, f) => s + (+f.PageCount || 0), 0);
    this.log.unshift({ id: this.doc.ID, title: this.doc.Filename, pages, expected: this.doc.ExpectedPages, time: new Date().toTimeString().slice(0, 5) });
    status.innerHTML = `<span style="color:var(--success);">✓ บันทึก ${App.escapeHtml(this.doc.ID)} แล้ว (${pages} หน้า)</span>`;
    this.beep(true);
    this.renderLog();
    this.reset();
  },

  renderLog: function() {
    const el = document.getElementById('st-log');
    document.getElementById('st-count').textContent = this.log.length;
    el.innerHTML = this.log.length ? this.log.map(l => `<div class="st-log-row ${l.expected && l.pages !== l.expected ? 'warn' : ''}">
        <span class="t">${l.time}</span><b class="doc-id" onclick="App.Detail.open(${App.jsArg(l.id)})">${App.escapeHtml(l.id)}</b>
        <span class="n">${App.escapeHtml(l.title)}</span><span>${l.pages} หน้า${l.expected && l.pages !== l.expected ? ` (ควร ${l.expected})` : ''}</span></div>`).join('')
      : '<div style="color:var(--text-muted); padding:12px;">ยังไม่มีเอกสารที่สแกนในรอบนี้</div>';
  },

  beep: function(ok) {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const o = ctx.createOscillator();
      const g = ctx.createGain();
      o.frequency.value = ok ? 880 : 220;
      g.gain.value = 0.08;
      o.connect(g); g.connect(ctx.destination);
      o.start(); o.stop(ctx.currentTime + (ok ? 0.12 : 0.35));
    } catch (e) { /* no audio */ }
  },
};

document.addEventListener('keydown', e => {
  if (App.currentView !== 'station' || document.querySelector('.dlg-backdrop')) return;
  if (e.key === 'Escape') { App.Station.reset(); }
  if (e.key === 'F2' && App.Station.doc) { e.preventDefault(); App.Station.scan(); }
});
