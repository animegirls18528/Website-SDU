/* ระบบจัดเก็บเอกสาร — front-end (single page) */
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.14.305/pdf.worker.min.js';

const WORKFLOW = {
  Draft: { label: 'ฉบับร่าง', cls: 'muted', icon: 'edit_note' },
  PendingReview: { label: 'รอตรวจสอบ', cls: 'purple', icon: 'pending_actions' },
  Approved: { label: 'อนุมัติแล้ว', cls: 'success', icon: 'task_alt' },
};
const ROLE_LABELS = { admin: 'ผู้ดูแลระบบ', staff: 'เจ้าหน้าที่', viewer: 'ผู้ดูเอกสาร' };

const App = {
  data: { folders: [], customFields: [], prefixes: [], settings: {} },
  lists: {},
  currentView: null,
  isAdmin: () => window.CURRENT_USER && window.CURRENT_USER.role === 'admin',
  canEdit: () => window.CURRENT_USER && window.CURRENT_USER.canEdit,
  navItem: (viewId) => document.querySelector(`.nav-links li[data-view="${viewId}"]`),

  // Files are only reachable through the authenticated endpoint; fileId looks like "uploads/2026/10/doc_x.pdf"
  fileUrl: function(fileId, download) {
    const rel = String(fileId).trim().replace(/^uploads\//, '');
    return 'api/file.php?f=' + encodeURIComponent(rel) + (download ? '&download=1' : '');
  },

  init: function() {
    this.showLoader("กำลังเชื่อมต่อเซิร์ฟเวอร์...");
    this.reloadMeta()
      .then(() => {
        this.hideLoader();
        this.navigate('dashboard', document.querySelector('.nav-links li.active'));
      })
      .catch(() => {
        this.hideLoader();
        this.toast("เชื่อมต่อเซิร์ฟเวอร์ล้มเหลว", "error");
      });
  },

  reloadMeta: function() {
    return fetch('api/init.php').then(r => r.json()).then(data => {
      if (data.error) throw new Error(data.error);
      Object.assign(this.data, data);
      return data;
    });
  },

  navigate: function(viewId, elContext) {
    if ((viewId === 'analytics' || viewId === 'settings') && !this.isAdmin()) viewId = 'dashboard';
    if ((viewId === 'scan' || viewId === 'upload') && !this.canEdit()) viewId = 'dashboard';
    elContext = elContext || this.navItem(viewId);
    if (elContext) {
      document.querySelectorAll('.nav-links li').forEach(li => li.classList.remove('active'));
      elContext.classList.add('active');
    }
    const template = document.getElementById('view-' + viewId);
    if (!template) return;
    document.getElementById('app-view').innerHTML = template.innerHTML;
    if (!this.canEdit()) document.querySelectorAll('#app-view .edit-only').forEach(el => el.remove());
    this.currentView = viewId;
    document.querySelector('.sidebar').classList.remove('open'); // collapse the phone menu after choosing

    if (viewId === 'analytics') this.Analytics.init();
    if (viewId === 'dashboard') this.Docs.init();
    if (viewId === 'scan') this.Scanner.init();
    if (viewId === 'upload') this.Upload.init();
    if (viewId === 'settings') this.Settings.init();
  },

  showLoader: function(msg) {
    const l = document.getElementById('loader'); const t = document.getElementById('loader-text');
    if (l) l.style.display = 'flex'; if (t) t.textContent = msg || 'กำลังประมวลผล...';
  },
  hideLoader: function() { const l = document.getElementById('loader'); if (l) l.style.display = 'none'; },

  toast: function(msg, type = 'error') {
    let container = document.getElementById('toast-container');
    if (!container) { container = document.createElement('div'); container.id = 'toast-container'; document.body.appendChild(container); }
    const t = document.createElement('div'); t.className = `toast-msg ${type}`;
    const iconName = type === 'success' ? 'check_circle' : (type === 'info' ? 'info' : 'error');
    const color = type === 'success' ? 'var(--success)' : (type === 'info' ? 'var(--primary)' : 'var(--danger)');
    t.innerHTML = `<span class="material-icons-round" style="font-size:22px; color:${color};">${iconName}</span><span></span>`;
    t.lastChild.textContent = msg;
    container.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(100%)'; t.style.transition = 'all 0.3s ease'; setTimeout(() => t.remove(), 300); }, type === 'error' ? 6000 : 3500);
  },

  /** POST JSON; resolves with the response body, rejects with a readable Error. */
  api: async function(url, body) {
    let res;
    try {
      res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
    } catch (e) {
      throw new Error('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
    }
    let data = null;
    try { data = await res.json(); } catch (e) { /* not JSON */ }
    if (!res.ok || !data || data.success === false) {
      throw new Error((data && (data.message || data.error)) || `เกิดข้อผิดพลาด (HTTP ${res.status})`);
    }
    return data;
  },


  uploadFile: function(file, onProgress, replaces, context) {
    const limit = (App.data.settings && App.data.settings.uploadLimitBytes) || 0;
    if (limit && file.size > limit) {
      return Promise.reject(new Error(`ไฟล์ใหญ่ ${App.fmtBytes(file.size)} เกินขนาดสูงสุดที่เซิร์ฟเวอร์รับได้ (${App.fmtBytes(limit)})`));
    }
    return new Promise((resolve, reject) => {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('context', context || 'upload');
      if (replaces) formData.append('replaces', replaces);
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'api/upload.php', true);
      xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN);
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable && onProgress) onProgress(Math.round((e.loaded / e.total) * 100));
      };
      xhr.onload = () => {
        if (xhr.status === 401) { location.href = 'login.php'; return; }
        let result = null;
        // PHP may print a size warning before the JSON when the request is too large; take the JSON part
        try { result = JSON.parse(xhr.responseText.slice(xhr.responseText.indexOf('{'))); } catch (e) { /* ignore */ }
        if (xhr.status === 200 && result && result.success) resolve(result);
        else if (result && result.message) reject(new Error(result.message));
        else if (xhr.status === 413) reject(new Error('ไฟล์มีขนาดใหญ่เกินที่เซิร์ฟเวอร์รับได้'));
        else reject(new Error(`อัปโหลดไม่สำเร็จ (HTTP ${xhr.status})`));
      };
      xhr.onerror = () => reject(new Error("เครือข่ายขาดการเชื่อมต่อ"));
      xhr.send(formData);
    });
  },

  loadScript: function(src) {
    this._scripts = this._scripts || {};
    if (!this._scripts[src]) {
      this._scripts[src] = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = src; s.onload = resolve; s.onerror = () => reject(new Error('โหลดไลบรารีไม่สำเร็จ: ' + src));
        document.head.appendChild(s);
      });
    }
    return this._scripts[src];
  },

  /** Every page of a TIFF as PNG bytes (browsers cannot show TIFF, so it is converted in the browser). */
  tiffToPngs: async function(bytes) {
    await this.loadScript('https://cdn.jsdelivr.net/npm/utif@3.1.0/UTIF.js');
    const ifds = UTIF.decode(bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength));
    const pages = [];
    for (const ifd of ifds) {
      UTIF.decodeImage(bytes.buffer, ifd, ifds);
      if (!ifd.width || !ifd.height) continue;
      const rgba = UTIF.toRGBA8(ifd);
      const canvas = document.createElement('canvas');
      canvas.width = ifd.width; canvas.height = ifd.height;
      canvas.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(rgba.buffer), ifd.width, ifd.height), 0, 0);
      const blob = await new Promise(r => canvas.toBlob(r, 'image/png'));
      pages.push(new Uint8Array(await blob.arrayBuffer()));
    }
    if (!pages.length) throw new Error('อ่านไฟล์ TIFF ไม่ได้');
    return pages;
  },

  /** Put images (each {bytes, type:'png'|'jpg'}) on A4 pages in their own orientation. */
  imagesToPdf: async function(images) {
    const doc = await PDFLib.PDFDocument.create();
    for (const im of images) {
      const img = im.type === 'png' ? await doc.embedPng(im.bytes) : await doc.embedJpg(im.bytes);
      const [W, H] = img.width > img.height ? [841.89, 595.28] : [595.28, 841.89];
      const scale = Math.min(W / img.width, H / img.height);
      doc.addPage([W, H]).drawImage(img, { x: (W - img.width * scale) / 2, y: (H - img.height * scale) / 2, width: img.width * scale, height: img.height * scale });
    }
    return doc.save({ useObjectStreams: false });
  },

  /**
   * Prepare a file before upload so it can be QR-stamped and viewed in the browser:
   * - images (JPG/PNG/TIFF) become a PDF
   * - PDFs from modern tools use compressed cross-reference streams that the server-side stamper (FPDI) cannot
   *   read, so they are re-saved with a classic xref table; pages and content are unchanged
   */
  prepareFile: async function(file) {
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    const pdfName = file.name.replace(/\.[^.]+$/, '') + '.pdf';
    if (file.size > 80 * 1024 * 1024) return file;
    try {
      if (ext === 'pdf' || file.type === 'application/pdf') {
        const doc = await PDFLib.PDFDocument.load(await file.arrayBuffer());
        return new File([await doc.save({ useObjectStreams: false })], file.name, { type: 'application/pdf' });
      }
      const bytes = new Uint8Array(await file.arrayBuffer());
      let images;
      if (ext === 'tif' || ext === 'tiff') images = (await this.tiffToPngs(bytes)).map(b => ({ bytes: b, type: 'png' }));
      else if (ext === 'png') images = [{ bytes, type: 'png' }];
      else if (ext === 'jpg' || ext === 'jpeg') images = [{ bytes, type: 'jpg' }];
      else return file;
      return new File([await this.imagesToPdf(images)], pdfName, { type: 'application/pdf' });
    } catch (e) {
      console.warn('File preparation skipped:', e);
      return file; // encrypted PDF or unreadable image: upload as is
    }
  },

  discardStaged: function(fileId) {
    const fd = new FormData();
    fd.append('action', 'discard');
    fd.append('fileId', fileId);
    return fetch('api/upload.php', { method: 'POST', body: fd }).catch(() => {});
  },

  escapeHtml: function(unsafe) {
    if (unsafe === null || unsafe === undefined) return '';
    return unsafe.toString()
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
  },
  // For values placed inside onclick="...('value')" attributes
  jsArg: function(v) { return App.escapeHtml(JSON.stringify(String(v))); },

  fmtBytes: function(n) {
    n = Number(n) || 0;
    if (n >= 1073741824) return (n / 1073741824).toFixed(2) + ' GB';
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (n >= 1024) return (n / 1024).toFixed(0) + ' KB';
    return n + ' B';
  },
  // Thai date (Buddhist year) from 'YYYY-MM-DD' or 'YYYY-MM-DD HH:MM:SS'
  fmtDate: function(s, withTime) {
    if (!s) return '-';
    const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!m) return String(s);
    let out = `${parseInt(m[3], 10)}/${parseInt(m[2], 10)}/${parseInt(m[1], 10) + 543}`;
    if (withTime && m[4]) out += ` ${m[4]}:${m[5]}`;
    return out;
  },
  today: function() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  },
  folderOptions: function(selected, includeEmpty) {
    let html = includeEmpty ? `<option value="">— ไม่มีแฟ้ม —</option>` : '';
    html += this.data.folders.map(f =>
      `<option value="${f.ID}" ${String(selected) === String(f.ID) ? 'selected' : ''}>${App.escapeHtml(f.FolderName)}</option>`).join('');
    return html;
  },
  prefixOptions: function(selected) {
    if (!this.data.prefixes.length) return '<option value="DOC">DOC (ทั่วไป)</option>';
    return this.data.prefixes.map(p =>
      `<option value="${App.escapeHtml(p.PrefixValue)}" ${p.PrefixValue === selected ? 'selected' : ''}>${App.escapeHtml(p.PrefixValue)} (${App.escapeHtml(p.PrefixName)})</option>`).join('');
  },
  /** Same rule as the server: IDs go into CODE128 barcodes, so ASCII only. */
  validDocId: function(id) {
    if (/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,99}$/.test(id)) return true;
    App.toast('รหัสเอกสารใช้ได้เฉพาะ A-Z, 0-9 และ . _ / - (ไม่เกิน 100 ตัว) เพื่อให้พิมพ์บาร์โค้ดได้');
    return false;
  },
  debounce: function(fn, ms) {
    let t;
    return function(...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), ms); };
  },
};

/* =====================================================================
   Dialogs: styled replacements for the browser's confirm() / prompt()
   ===================================================================== */
App.Dialog = {
  TONES: {
    primary: { icon: 'help_outline', btn: 'btn-primary' },
    warning: { icon: 'warning_amber', btn: 'dlg-btn-warning' },
    danger: { icon: 'delete_outline', btn: 'dlg-btn-danger' },
  },

  /** Resolves true/false. opts: title, message, items[], note, icon, tone, confirmText, cancelText */
  confirm: function(opts) {
    return this.open(Object.assign({}, opts, { fields: [] })).then(r => r !== null);
  },

  /** Resolves {name: value} or null when cancelled. opts.fields: [{name, label, value, placeholder, required, multiline}] */
  form: function(opts) {
    return this.open(opts);
  },

  open: function(opts) {
    const tone = this.TONES[opts.tone] ? opts.tone : 'primary';
    const t = this.TONES[tone];
    const esc = App.escapeHtml;
    const fields = opts.fields || [];
    const prevFocus = document.activeElement;

    const wrap = document.createElement('div');
    wrap.className = 'dlg-backdrop';
    wrap.innerHTML = `
      <div class="dlg tone-${tone}" role="dialog" aria-modal="true" aria-labelledby="dlg-title">
        <div class="dlg-head">
          <div class="dlg-icon"><span class="material-icons-round">${esc(opts.icon || t.icon)}</span></div>
          <div class="dlg-titles">
            <h3 id="dlg-title">${esc(opts.title || 'ยืนยัน')}</h3>
            ${opts.message ? `<p>${esc(opts.message)}</p>` : ''}
          </div>
        </div>
        ${opts.items && opts.items.length ? `<ul class="dlg-items">${opts.items.map(i => `<li>${esc(i)}</li>`).join('')}</ul>` : ''}
        ${fields.map((f, i) => `
          <label class="dlg-field">
            <span>${esc(f.label || '')}${f.required ? ' <b>*</b>' : ''}</span>
            ${f.multiline
              ? `<textarea class="form-control" rows="3" data-i="${i}" placeholder="${esc(f.placeholder || '')}">${esc(f.value || '')}</textarea>`
              : `<input class="form-control" data-i="${i}" value="${esc(f.value || '')}" placeholder="${esc(f.placeholder || '')}" autocomplete="off">`}
            <small class="dlg-error"></small>
          </label>`).join('')}
        ${opts.note ? `<div class="dlg-note"><span class="material-icons-round">info</span><span>${esc(opts.note)}</span></div>` : ''}
        <div class="dlg-actions">
          <button type="button" class="btn btn-outline" data-act="cancel">${esc(opts.cancelText || 'ยกเลิก')}</button>
          <button type="button" class="btn ${t.btn}" data-act="ok">${esc(opts.confirmText || 'ตกลง')}</button>
        </div>
      </div>`;
    document.body.appendChild(wrap);
    requestAnimationFrame(() => wrap.classList.add('show'));

    return new Promise(resolve => {
      const inputs = Array.from(wrap.querySelectorAll('[data-i]'));
      const finish = (value) => {
        wrap.classList.remove('show');
        setTimeout(() => wrap.remove(), 160);
        if (prevFocus && prevFocus.focus) prevFocus.focus();
        resolve(value);
      };
      const submit = () => {
        const out = {};
        let ok = true;
        inputs.forEach(el => {
          const f = fields[el.dataset.i];
          const v = el.value.trim();
          const err = el.parentElement.querySelector('.dlg-error');
          err.textContent = '';
          el.classList.remove('invalid');
          if (f.required && !v) {
            err.textContent = `กรุณากรอก${f.label ? ' ' + f.label : 'ข้อมูล'}`;
            el.classList.add('invalid');
            if (ok) el.focus();
            ok = false;
          }
          out[f.name] = v;
        });
        if (ok) finish(out);
      };
      wrap.querySelector('[data-act="ok"]').onclick = submit;
      wrap.querySelector('[data-act="cancel"]').onclick = () => finish(null);
      wrap.addEventListener('mousedown', e => { if (e.target === wrap) finish(null); });
      wrap.addEventListener('keydown', e => {
        e.stopPropagation(); // keep the page's Escape handler from closing the modal underneath
        if (e.key === 'Escape') { e.preventDefault(); finish(null); }
        else if (e.key === 'Enter' && (e.target.tagName !== 'TEXTAREA' || e.ctrlKey) && e.target.dataset.act !== 'cancel') {
          e.preventDefault(); submit();
        } else if (e.key === 'Tab') {
          // Keep focus inside the dialog
          const items = Array.from(wrap.querySelectorAll('input, textarea, button'));
          const i = items.indexOf(document.activeElement);
          if (e.shiftKey && i <= 0) { e.preventDefault(); items[items.length - 1].focus(); }
          else if (!e.shiftKey && i === items.length - 1) { e.preventDefault(); items[0].focus(); }
        }
      });
      // Text fields get focus; for destructive confirmations focus "cancel" so a stray Enter does nothing harmful
      const first = inputs[0] || wrap.querySelector(`[data-act="${tone === 'danger' ? 'cancel' : 'ok'}"]`);
      setTimeout(() => { first.focus(); if (first.select && first.tagName === 'INPUT') first.select(); }, 30);
    });
  },
};

/* =====================================================================
   Staged files (uploaded/scanned but not yet saved into a document)
   ===================================================================== */
class StagedList {
  constructor(name, containerId, context) {
    this.name = name;
    this.context = context || 'upload';
    this.containerId = containerId;
    this.items = [];      // {fileId, name, size, format}
    this.uploading = [];  // {name, percent}
    App.lists[name] = this;
  }
  get el() { return document.getElementById(this.containerId); }
  fileIds() { return this.items.map(i => i.fileId); }

  async addFiles(input) {
    const files = Array.from(input.files || []);
    if (input.value !== undefined) input.value = '';
    for (const file of files) {
      const job = { name: file.name, percent: 0 };
      this.uploading.push(job);
      this.render();
      try {
        const res = await App.uploadFile(await App.prepareFile(file), p => { job.percent = p; this.render(); }, null, this.context);
        this.items.push({ fileId: res.fileId, name: res.name, size: res.size, format: res.format });
      } catch (e) {
        App.toast(`${file.name}: ${e.message}`);
      }
      this.uploading.splice(this.uploading.indexOf(job), 1);
      this.render();
    }
  }
  add(item) { this.items.push(item); this.render(); }
  remove(idx) {
    const item = this.items[idx];
    if (!item) return;
    App.discardStaged(item.fileId);
    this.items.splice(idx, 1);
    this.render();
  }
  /** Replace the items at `indices` with one new item at the first position. */
  replace(indices, newItem) {
    const first = Math.min(...indices);
    this.items = this.items.filter((_, i) => !indices.includes(i));
    this.items.splice(Math.min(first, this.items.length), 0, newItem);
    this.render();
  }
  move(idx, delta) {
    const j = idx + delta;
    if (j < 0 || j >= this.items.length) return;
    [this.items[idx], this.items[j]] = [this.items[j], this.items[idx]];
    this.render();
  }
  clear(discard) {
    if (discard) this.items.forEach(i => App.discardStaged(i.fileId));
    this.items = [];
    this.render();
  }
  editable(item) { return ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff'].includes(item.format); }
  /** Bring back files staged before a page reload. */
  restore(files) {
    const known = new Set(this.items.map(i => i.fileId));
    files.filter(f => !known.has(f.fileId)).forEach(f => this.items.push(f));
    this.render();
  }

  render() {
    const el = this.el;
    if (!el) return;
    const ref = `App.lists.${this.name}`;
    let html = this.uploading.map(u => `
      <div class="file-row">
        <span class="material-icons-round" style="color:var(--primary);">upload_file</span>
        <div class="meta"><div class="name">${App.escapeHtml(u.name)}</div>
          <div class="bar-track" style="margin-top:6px;"><div class="bar-fill" style="width:${u.percent}%"></div></div></div>
        <span style="font-size:12px; color:var(--text-muted);">${u.percent}%</span>
      </div>`).join('');

    html += this.items.map((f, i) => `
      <div class="file-row">
        <span class="material-icons-round" style="font-size:26px; color:${f.format === 'pdf' ? '#dc2626' : '#2563eb'};">${f.format === 'pdf' ? 'picture_as_pdf' : 'image'}</span>
        <div class="meta">
          <div class="name" title="${App.escapeHtml(f.name)}">${i + 1}. ${App.escapeHtml(f.name)}</div>
          <div class="sub">${App.fmtBytes(f.size)} · ${App.escapeHtml((f.format || '').toUpperCase())}${f.dpi ? ' · ' + f.dpi + ' DPI' : ''}</div>
        </div>
        <button class="btn btn-outline btn-icon" title="เลื่อนขึ้น" onclick="${ref}.move(${i}, -1)" ${i === 0 ? 'disabled' : ''}><span class="material-icons-round" style="font-size:18px;">arrow_upward</span></button>
        <button class="btn btn-outline btn-icon" title="ดูตัวอย่าง" style="color:var(--primary);" onclick="App.Viewer.openStaged(${ref}, ${i})"><span class="material-icons-round" style="font-size:18px;">visibility</span></button>
        ${this.editable(f) ? `<button class="btn btn-outline btn-icon" title="จัดหน้า: หมุน ลบ เรียงหน้า" onclick="App.PageEditor.open(${ref}, [${i}])"><span class="material-icons-round" style="font-size:18px;">auto_fix_high</span></button>` : ''}
        <button class="btn btn-outline btn-icon" title="นำไฟล์นี้ออก" style="color:var(--danger);" onclick="${ref}.remove(${i})"><span class="material-icons-round" style="font-size:18px;">close</span></button>
      </div>`).join('');

    if (this.items.length > 1 && this.items.every(f => this.editable(f))) {
      html += `<button class="btn btn-outline" style="width:100%; margin-top:4px; font-size:13px;" onclick="App.PageEditor.open(${ref}, ${JSON.stringify(this.items.map((_, i) => i))})">
        <span class="material-icons-round" style="font-size:18px;">merge_type</span> รวมทุกไฟล์เป็น PDF เดียวและจัดหน้า</button>`;
    }
    if (!html) html = `<div style="text-align:center; color:var(--text-muted); font-size:13px; padding:14px; border:1.5px dashed var(--border); border-radius:10px;">ยังไม่มีไฟล์</div>`;
    el.innerHTML = html;
  }
}

/* =====================================================================
   Document metadata form (shared by Upload and Scan views)
   ===================================================================== */
App.DocForm = {
  suggested: {},

  render: function(p) {
    const host = document.querySelector(`[data-docform="${p}"]`);
    if (!host) return;
    const cf = App.data.customFields.map((f, i) => `
      <div class="form-group"><label>${App.escapeHtml(f.FieldName)}${f.IsRequired === 'Yes' ? ' *' : ''}</label>
        <input type="${f.FieldType === 'number' ? 'number' : (f.FieldType === 'date' ? 'date' : 'text')}" class="form-control" id="${p}-cf-${i}"></div>`).join('');
    host.innerHTML = `
      <div class="form-group">
        <label>รหัสเอกสาร (Barcode) *</label>
        <div style="display:flex; gap:8px;">
          <select id="${p}Prefix" class="form-control" style="width:190px;" onchange="App.DocForm.fetchNextId('${p}')">${App.prefixOptions()}</select>
          <input type="text" id="${p}Number" class="form-control" placeholder="กำลังสร้างรหัส...">
        </div>
        <small style="color:var(--text-muted); font-size:12px;">ถ้ามีผู้อื่นใช้เลขนี้ไปก่อน ระบบจะออกเลขถัดไปให้อัตโนมัติ</small>
      </div>
      <div class="form-group"><label>ชื่อเอกสาร / เรื่อง *</label><input type="text" id="${p}Title" class="form-control" placeholder="เช่น ใบสำคัญจ่าย, สัญญาจ้าง, รายงานการประชุม"></div>
      <div class="form-group">
        <label>จัดเก็บลงแฟ้ม / กล่อง *</label>
        <select id="${p}Folder" class="form-control">${App.folderOptions(this.lastFolder)}</select>
      </div>
      <div class="form-group"><label>วันที่จ่ายเงิน (ใช้คำนวณวันครบอายุการเก็บ)</label><input type="date" id="${p}Payment" class="form-control" value="${App.today()}"></div>
      <div class="form-group"><label>แท็ก (คั่นด้วยจุลภาค)</label><input type="text" id="${p}Tags" class="form-control" placeholder="เช่น ใบเสร็จ, งบรายได้"></div>
      ${cf}
      <div style="border-top:1px solid var(--border); padding-top:8px;">
        <label class="check-row"><input type="checkbox" id="${p}Draft"><div>บันทึกเป็นฉบับร่าง<small>ยังไม่ส่งตรวจ แก้ไขต่อได้ภายหลัง</small></div></label>
        <label class="check-row"><input type="checkbox" id="${p}Split"><div>บันทึกแต่ละไฟล์เป็นเอกสารแยกกัน<small>ใช้กับการสแกนแบบแผ่นคั่น หรืออัปโหลดหลายเอกสารพร้อมกัน ระบบออกเลขให้ทีละฉบับ</small></div></label>
      </div>`;
    this.fetchNextId(p);
  },

  fetchNextId: function(p) {
    const prefix = document.getElementById(`${p}Prefix`)?.value;
    const input = document.getElementById(`${p}Number`);
    if (!prefix || !input) return;
    input.value = '';
    App.api('api/document.php', { action: 'getNextId', Prefix: prefix })
      .then(res => { input.value = res.nextNumber; this.suggested[p] = res.nextNumber; })
      .catch(() => App.toast("ไม่สามารถสร้างรหัสอัตโนมัติได้"));
  },

  collect: function(p) {
    const val = id => (document.getElementById(p + id)?.value || '').trim();
    const data = {
      Prefix: val('Prefix'),
      number: val('Number'),
      Filename: val('Title'),
      FolderId: val('Folder'),
      PaymentDate: val('Payment'),
      Tags: val('Tags'),
      draft: document.getElementById(`${p}Draft`)?.checked,
      split: document.getElementById(`${p}Split`)?.checked,
      CustomData: {},
    };
    if (!data.number || !data.Filename || !data.FolderId) { App.toast("กรุณากรอกรหัส ชื่อเอกสาร และแฟ้มจัดเก็บให้ครบ"); return null; }
    for (const [i, f] of App.data.customFields.entries()) {
      const v = (document.getElementById(`${p}-cf-${i}`)?.value || '').trim();
      if (f.IsRequired === 'Yes' && !v) { App.toast(`กรุณากรอก "${f.FieldName}"`); return null; }
      if (v) data.CustomData[f.FieldName] = v;
    }
    data.autoId = data.number === this.suggested[p];
    if (!data.autoId && !App.validDocId(data.Prefix + data.number)) return null;
    data.WorkflowStatus = data.draft ? 'Draft' : (App.isAdmin() ? 'Approved' : 'PendingReview');
    this.lastFolder = data.FolderId;
    return data;
  },

  /** Save one document with all staged files, or one document per file when "split" is ticked. */
  saveWithFiles: async function(p, list) {
    const form = this.collect(p);
    if (!form) return;
    if (list.uploading.length) { App.toast("กรุณารอให้อัปโหลดไฟล์เสร็จก่อน", "info"); return; }
    if (!list.items.length) { App.toast("กรุณาเพิ่มไฟล์อย่างน้อย 1 ไฟล์"); return; }

    const base = {
      action: 'createDocument', Prefix: form.Prefix, Filename: form.Filename, FolderId: form.FolderId,
      PaymentDate: form.PaymentDate, Tags: form.Tags, CustomData: form.CustomData, WorkflowStatus: form.WorkflowStatus,
    };
    const batches = form.split && list.items.length > 1 ? list.items.map(i => [i]) : [list.items];
    const created = [];
    try {
      for (const [n, files] of batches.entries()) {
        App.showLoader(batches.length > 1 ? `กำลังบันทึกเอกสาร ${n + 1}/${batches.length}...` : 'กำลังบันทึกและประทับ QR Code...');
        const body = Object.assign({}, base, {
          ID: form.Prefix + form.number,
          autoId: batches.length > 1 ? true : form.autoId,
          newFileId: files.map(f => f.fileId),
        });
        if (batches.length > 1) body.Filename = `${form.Filename} (${n + 1}/${batches.length})`;
        const res = await App.api('api/document.php', body);
        created.push(res.updatedDoc.ID);
        files.forEach(f => list.items.splice(list.items.indexOf(f), 1));
        if (res.idChanged && batches.length === 1) App.toast(`เลข ${body.ID} ถูกใช้ไปแล้ว ระบบออกเลข ${res.updatedDoc.ID} ให้แทน`, 'info');
        if (res.stampFailed && res.stampFailed.length) App.toast(`ประทับ QR ไม่ได้ (รูปแบบ PDF ไม่รองรับ): ${res.stampFailed.join(', ')} — จัดเก็บไฟล์ต้นฉบับแทน`, 'info');
      }
    } catch (e) {
      App.hideLoader();
      list.render();
      App.toast("บันทึกไม่สำเร็จ: " + e.message);
      return;
    }
    App.hideLoader();
    list.render();
    App.reloadMeta();
    App.toast(created.length > 1 ? `บันทึกแล้ว ${created.length} เอกสาร (${created[0]} – ${created[created.length - 1]})` : `บันทึกเอกสาร ${created[0]} สำเร็จ`, 'success');
    App.Docs.state = Object.assign(App.Docs.defaultState(), { sort: '' });
    App.navigate('dashboard');
    if (created.length === 1) App.Detail.open(created[0]);
  },
};

/* =====================================================================
   Document list (server-side search, filters, paging)
   ===================================================================== */
App.Docs = {
  defaultState: () => ({ q: '', folderId: '', status: '', dateField: 'payment', dateFrom: '', dateTo: '', sort: '', page: 1, pageSize: 50 }),
  state: null,
  seq: 0,

  statuses: function() {
    const s = [
      { v: '', label: 'ทั้งหมด', icon: 'inventory_2' },
      { v: 'expiring', label: 'ใกล้ครบอายุ', icon: 'schedule' },
      { v: 'expired', label: 'ครบอายุแล้ว', icon: 'history' },
      { v: 'pending', label: 'รอตรวจสอบ', icon: 'pending_actions' },
      { v: 'draft', label: 'ฉบับร่าง', icon: 'edit_note' },
      { v: 'destruction', label: 'ขอทำลาย', icon: 'delete_sweep' },
      { v: 'nofile', label: 'ยังไม่มีไฟล์', icon: 'link_off' },
    ];
    if (App.isAdmin()) s.push({ v: 'trash', label: 'ถังขยะ', icon: 'delete' });
    return s;
  },

  init: function() {
    if (!this.state) this.state = this.defaultState();
    const s = this.state;
    document.getElementById('f-folder').innerHTML = `<option value="">ทุกแฟ้ม</option>` +
      App.data.folders.map(f => `<option value="${f.ID}">${App.escapeHtml(f.FolderName)} (${Number(f.DocCount).toLocaleString()})</option>`).join('') +
      `<option value="none">— ไม่มีแฟ้ม —</option>`;
    document.getElementById('f-q').value = s.q;
    document.getElementById('globalSearch').value = s.q;
    document.getElementById('f-folder').value = s.folderId;
    document.getElementById('f-datefield').value = s.dateField;
    document.getElementById('f-from').value = s.dateFrom;
    document.getElementById('f-to').value = s.dateTo;
    document.getElementById('f-sort').value = s.sort;
    this.renderChips();
    this.load();
  },

  renderChips: function() {
    const el = document.getElementById('status-chips');
    if (!el) return;
    el.innerHTML = this.statuses().map(c => `
      <button class="chip ${this.state.status === c.v ? 'active' : ''}" onclick="App.Docs.setStatus('${c.v}')">
        <span class="material-icons-round" style="font-size:16px;">${c.icon}</span>${c.label}</button>`).join('');
  },
  setStatus: function(v) { this.state.status = v; this.state.page = 1; this.renderChips(); this.load(); },

  applyFilters: function() {
    const s = this.state;
    s.q = document.getElementById('f-q').value.trim();
    s.folderId = document.getElementById('f-folder').value;
    s.dateField = document.getElementById('f-datefield').value;
    s.dateFrom = document.getElementById('f-from').value;
    s.dateTo = document.getElementById('f-to').value;
    s.sort = document.getElementById('f-sort').value;
    s.page = 1;
    document.getElementById('globalSearch').value = s.q;
    this.load();
  },
  onFilterInput: App.debounce(function() { App.Docs.applyFilters(); }, 350),
  resetFilters: function() { this.state = this.defaultState(); this.init(); },

  searchFromTopBar: App.debounce(function() {
    const q = document.getElementById('globalSearch').value.trim();
    if (!App.Docs.state) App.Docs.state = App.Docs.defaultState();
    App.Docs.state.q = q;
    App.Docs.state.page = 1;
    if (App.currentView !== 'dashboard') { App.navigate('dashboard'); return; }
    document.getElementById('f-q').value = q;
    App.Docs.load();
  }, 350),

  load: function() {
    const seq = ++this.seq;
    const tbody = document.getElementById('doc-table-body');
    if (!tbody) return;
    tbody.style.opacity = '0.5';
    App.api('api/search.php', this.state).then(res => {
      if (seq !== this.seq) return; // a newer search is already on its way
      tbody.style.opacity = '1';
      this.render(res);
    }).catch(e => {
      tbody.style.opacity = '1';
      App.toast(e.message);
    });
  },

  statusBadges: function(d) {
    const out = [];
    const warnDays = App.data.settings.expiryWarningDays || 90;
    if (d.DeletedAt) out.push(`<span class="badge danger"><span class="material-icons-round" style="font-size:13px;">delete</span> ถังขยะ</span>`);
    else if (d.ExpiryDate && d.ExpiryDate < App.today()) out.push(`<span class="badge danger"><span class="material-icons-round" style="font-size:13px;">history</span> ครบอายุ</span>`);
    else if (d.ExpiryDate && (new Date(d.ExpiryDate) - new Date()) / 86400000 <= warnDays) out.push(`<span class="badge warning"><span class="material-icons-round" style="font-size:13px;">schedule</span> ใกล้ครบอายุ</span>`);
    else out.push(`<span class="badge success"><span class="material-icons-round" style="font-size:13px;">check_circle</span> ปกติ</span>`);
    if (d.WorkflowStatus && d.WorkflowStatus !== 'Approved') {
      const w = WORKFLOW[d.WorkflowStatus] || { label: d.WorkflowStatus, cls: 'muted', icon: 'help' };
      out.push(`<span class="badge ${w.cls}"><span class="material-icons-round" style="font-size:13px;">${w.icon}</span> ${w.label}</span>`);
    }
    if (d.DestructionRequestedAt && !d.DeletedAt) out.push(`<span class="badge danger" title="${App.escapeHtml(d.DestructionReason)}"><span class="material-icons-round" style="font-size:13px;">delete_sweep</span> ขอทำลาย</span>`);
    return `<div style="display:flex; flex-wrap:wrap; gap:4px;">${out.join('')}</div>`;
  },

  customSummary: function(cd, max) {
    const entries = Object.entries(cd || {}).filter(([k, v]) => k !== 'LegacyID' && v !== '' && v !== null && typeof v !== 'object');
    return entries.slice(0, max || 4).map(([k, v]) => `${App.escapeHtml(k)}: <b style="color:#334155;">${App.escapeHtml(v)}</b>`).join(' · ');
  },

  highlight: function(text, q) {
    let html = App.escapeHtml(text);
    q.split(/\s+/).filter(Boolean).forEach(term => {
      const esc = App.escapeHtml(term).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      html = html.replace(new RegExp(esc, 'gi'), m => `<mark>${m}</mark>`);
    });
    return html;
  },

  render: function(res) {
    const tbody = document.getElementById('doc-table-body');
    const s = this.state;
    const trash = s.status === 'trash';
    const from = res.total ? (res.page - 1) * res.pageSize + 1 : 0;
    const to = Math.min(res.total, res.page * res.pageSize);
    document.getElementById('result-count').innerHTML = res.total
      ? `พบ <b style="color:var(--text-main);">${res.total.toLocaleString()}</b> เอกสาร · แสดง ${from.toLocaleString()}–${to.toLocaleString()}`
      : 'ไม่พบเอกสาร';
    document.getElementById('result-hint').textContent = trash
      ? `เอกสารในถังขยะจะถูกลบถาวรอัตโนมัติหลัง ${App.data.settings.trashRetentionDays || 30} วัน` : '';

    if (!res.documents.length) {
      tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:48px; color:var(--text-muted);">
        <span class="material-icons-round" style="font-size:48px; color:#cbd5e1;">search_off</span>
        <div>ไม่พบเอกสารที่ตรงกับเงื่อนไข</div></td></tr>`;
    } else {
      tbody.innerHTML = res.documents.map(d => {
        const id = App.jsArg(d.ID);
        const tags = (d.Tags || '').split(',').map(t => t.trim()).filter(Boolean).map(t => `<span class="tag">${App.escapeHtml(t)}</span>`).join('');
        const summary = this.customSummary(d.CustomData);
        const actions = trash ? `
            <button class="btn btn-outline btn-icon" title="กู้คืน" style="color:var(--success);" onclick="App.Detail.restore(${id})"><span class="material-icons-round" style="font-size:18px;">restore_from_trash</span></button>
            <button class="btn btn-outline btn-icon" title="ลบถาวร" style="color:var(--danger);" onclick="App.Detail.purge(${id})"><span class="material-icons-round" style="font-size:18px;">delete_forever</span></button>`
          : `
            ${d.HasFiles ? `<button class="btn btn-outline btn-icon" title="เปิดดูไฟล์" style="color:var(--primary);" onclick="App.Detail.viewFiles(${id})"><span class="material-icons-round" style="font-size:18px;">visibility</span></button>` : ''}
            <button class="btn btn-outline btn-icon" title="รายละเอียด" onclick="App.Detail.open(${id})"><span class="material-icons-round" style="font-size:18px;">info</span></button>
            <button class="btn btn-outline btn-icon" title="บาร์โค้ด" onclick="App.Docs.showBarcode(${id})"><span class="material-icons-round" style="font-size:18px;">qr_code</span></button>`;
        return `<tr class="doc-row">
          <td><span class="doc-id" onclick="App.Detail.open(${id})">${App.escapeHtml(d.ID)}</span>
            ${d.HasFiles ? '' : '<div class="doc-sub" title="ยังไม่มีไฟล์แนบ"><span class="material-icons-round" style="font-size:14px; vertical-align:-2px;">link_off</span> ไม่มีไฟล์</div>'}</td>
          <td style="min-width:220px;">
            <div class="doc-title">${App.escapeHtml(d.Filename)}</div>
            ${summary ? `<div class="doc-sub">${summary}</div>` : ''}
            ${tags ? `<div>${tags}</div>` : ''}
            ${d.Snippet ? `<div class="doc-snippet">…${this.highlight(d.Snippet, s.q)}…</div>` : ''}
            ${trash && d.DeleteReason ? `<div class="doc-sub" style="color:var(--danger);">เหตุผล: ${App.escapeHtml(d.DeleteReason)}</div>` : ''}
          </td>
          <td class="hide-sm" style="color:var(--text-muted); font-size:13.5px;">${App.escapeHtml(d.FolderName) || '-'}</td>
          <td class="hide-sm" style="color:var(--text-muted); white-space:nowrap;">${App.fmtDate(d.PaymentDate)}</td>
          <td class="hide-sm" style="color:var(--text-muted); white-space:nowrap;">${App.fmtDate(d.ExpiryDate)}</td>
          <td>${trash ? `<span class="badge danger">ลบเมื่อ ${App.fmtDate(d.DeletedAt)}</span>` : this.statusBadges(d)}</td>
          <td class="actions"><div style="display:inline-flex; gap:4px;">${actions}</div></td>
        </tr>`;
      }).join('');
    }
    this.renderPager(res);
  },

  renderPager: function(res) {
    const pager = document.getElementById('pager');
    const pages = Math.max(1, Math.ceil(res.total / res.pageSize));
    const p = res.page;
    pager.innerHTML = `
      <select class="form-control" style="width:auto; padding:6px 10px; font-size:13px;" onchange="App.Docs.state.pageSize=+this.value; App.Docs.state.page=1; App.Docs.load();">
        ${[25, 50, 100, 200].map(n => `<option value="${n}" ${n === res.pageSize ? 'selected' : ''}>${n} รายการ/หน้า</option>`).join('')}
      </select>
      <button class="btn btn-outline" ${p <= 1 ? 'disabled' : ''} onclick="App.Docs.goPage(1)"><span class="material-icons-round" style="font-size:18px;">first_page</span></button>
      <button class="btn btn-outline" ${p <= 1 ? 'disabled' : ''} onclick="App.Docs.goPage(${p - 1})"><span class="material-icons-round" style="font-size:18px;">chevron_left</span></button>
      <span style="font-size:13px; color:var(--text-muted); padding:0 6px;">หน้า
        <input type="number" min="1" max="${pages}" value="${p}" style="width:70px; padding:5px 8px; border:1.5px solid var(--border); border-radius:8px; font-family:inherit;" onchange="App.Docs.goPage(+this.value)">
        / ${pages.toLocaleString()}</span>
      <button class="btn btn-outline" ${p >= pages ? 'disabled' : ''} onclick="App.Docs.goPage(${p + 1})"><span class="material-icons-round" style="font-size:18px;">chevron_right</span></button>
      <button class="btn btn-outline" ${p >= pages ? 'disabled' : ''} onclick="App.Docs.goPage(${pages})"><span class="material-icons-round" style="font-size:18px;">last_page</span></button>`;
    this.pages = pages;
  },
  goPage: function(n) {
    n = Math.max(1, Math.min(this.pages || 1, n || 1));
    this.state.page = n;
    this.load();
    document.querySelector('.main-content').scrollTo({ top: 0, behavior: 'smooth' });
  },

  exportCsv: function() {
    const s = this.state;
    const qs = new URLSearchParams({ q: s.q, folderId: s.folderId, status: s.status, dateField: s.dateField, dateFrom: s.dateFrom, dateTo: s.dateTo, sort: s.sort });
    window.open('api/export.php?' + qs.toString(), '_blank');
  },

  showFolderModal: function() {
    document.getElementById('quick-folder-modal').style.display = 'flex';
    document.getElementById('quickFolderName').value = '';
    document.getElementById('quickFolderDesc').value = '';
  },
  closeFolderModal: function() { document.getElementById('quick-folder-modal').style.display = 'none'; },
  saveNewFolder: function() {
    const name = document.getElementById('quickFolderName').value.trim();
    const desc = document.getElementById('quickFolderDesc').value.trim();
    if (!name) { App.toast("กรุณาระบุชื่อแฟ้ม"); return; }
    App.api('api/settings.php', { action: 'addFolder', FolderName: name, Description: desc })
      .then(() => App.reloadMeta())
      .then(() => {
        App.toast("สร้างแฟ้มสำเร็จ", "success");
        this.closeFolderModal();
        if (App.currentView === 'dashboard') this.init();
      })
      .catch(e => App.toast(e.message));
  },

  showBarcode: function(code) {
    try {
      JsBarcode("#barcode-svg", code, { format: "CODE128", width: 2, height: 70, displayValue: true });
    } catch (e) {
      App.toast(`รหัส "${code}" มีอักขระที่บาร์โค้ด CODE128 ไม่รองรับ (ใช้ได้เฉพาะ A-Z, 0-9 และสัญลักษณ์) — แก้ไขรหัสเอกสารก่อนพิมพ์บาร์โค้ด`);
      return;
    }
    document.getElementById('barcode-modal').style.display = 'flex';
  },
  closeBarcode: function() { document.getElementById('barcode-modal').style.display = 'none'; },
};

/* =====================================================================
   Document detail: metadata, files, versions, history, workflow
   ===================================================================== */
App.Detail = {
  doc: null,
  tabName: 'info',

  open: async function(id) {
    try {
      App.showLoader('กำลังโหลดเอกสาร...');
      const res = await App.api('api/document.php', { action: 'get', ID: id });
      App.hideLoader();
      this.doc = res.document;
      this.tabName = 'info';
      this.render();
      document.getElementById('detail-modal').style.display = 'flex';
    } catch (e) {
      App.hideLoader();
      App.toast(e.message);
    }
  },
  close: function() { document.getElementById('detail-modal').style.display = 'none'; this.doc = null; },
  refresh: function() { if (this.doc) this.open(this.doc.ID); if (App.currentView === 'dashboard') App.Docs.load(); },

  viewFiles: async function(id) {
    try {
      const res = await App.api('api/document.php', { action: 'get', ID: id });
      const files = res.document.Files;
      if (!files.length) { App.toast("ไม่มีไฟล์แนบ", "info"); return; }
      App.Viewer.open(files.map(f => ({ fileId: f.StoredPath, name: f.OriginalName })), res.document.ID + ' · ' + res.document.Filename);
    } catch (e) { App.toast(e.message); }
  },

  tab: function(name) { this.tabName = name; this.render(); },

  render: function() {
    const d = this.doc;
    const id = App.jsArg(d.ID);
    const admin = App.isAdmin();
    const edit = App.canEdit();
    const deleted = !!d.DeletedAt;
    const expired = d.ExpiryDate && d.ExpiryDate < App.today();
    const w = WORKFLOW[d.WorkflowStatus] || WORKFLOW.Approved;

    const btn = (icon, label, onclick, style) => `<button class="btn btn-outline" style="padding:7px 12px; font-size:13px; ${style || ''}" onclick="${onclick}"><span class="material-icons-round" style="font-size:17px;">${icon}</span> ${label}</button>`;
    const actions = [];
    if (!deleted) {
      if (d.Files.length) actions.push(btn('visibility', 'เปิดดูไฟล์', `App.Detail.viewFiles(${id})`, 'color:var(--primary);'));
      if (edit) actions.push(btn('edit', 'แก้ไข', `App.Editor.open()`));
      if (edit && d.WorkflowStatus === 'Draft') actions.push(btn('send', 'ส่งตรวจสอบ', `App.Detail.setWorkflow('PendingReview')`));
      if (admin && d.WorkflowStatus === 'PendingReview') {
        actions.push(btn('task_alt', 'อนุมัติ', `App.Detail.setWorkflow('Approved')`, 'color:var(--success); border-color:#a7f3d0;'));
        actions.push(btn('undo', 'ตีกลับเป็นร่าง', `App.Detail.setWorkflow('Draft', true)`));
      }
      if (edit && expired && !d.DestructionRequestedAt) actions.push(btn('delete_sweep', 'ขออนุมัติทำลาย', `App.Detail.requestDestruction()`, 'color:#b45309;'));
      if (admin && d.DestructionRequestedAt) {
        actions.push(btn('check', 'อนุมัติทำลาย', `App.Detail.decideDestruction(true)`, 'color:var(--danger); border-color:#fecaca;'));
        actions.push(btn('close', 'ไม่อนุมัติทำลาย', `App.Detail.decideDestruction(false)`));
      }
      actions.push(btn('qr_code', 'บาร์โค้ด', `App.Docs.showBarcode(${id})`));
      if (d.VerifyToken) actions.push(btn('verified', 'หน้าตรวจสอบ QR', `window.open('verify.php?t=${d.VerifyToken}', '_blank')`));
      if (admin) actions.push(btn('delete_outline', 'ย้ายไปถังขยะ', `App.Detail.trash(${id})`, 'color:var(--danger);'));
    } else if (admin) {
      actions.push(btn('restore_from_trash', 'กู้คืน', `App.Detail.restore(${id})`, 'color:var(--success);'));
      actions.push(btn('delete_forever', 'ลบถาวร', `App.Detail.purge(${id})`, 'color:var(--danger);'));
    }

    const notices = [];
    if (deleted) notices.push(`<div class="notice danger"><span class="material-icons-round">delete</span><div>อยู่ในถังขยะตั้งแต่ ${App.fmtDate(d.DeletedAt, true)}${d.DeleteReason ? ' · ' + App.escapeHtml(d.DeleteReason) : ''}</div></div>`);
    if (d.DestructionRequestedAt && !deleted) notices.push(`<div class="notice warn"><span class="material-icons-round">delete_sweep</span><div>มีคำขอทำลายโดย ${App.escapeHtml(d.DestructionRequestedByName || '-')} เมื่อ ${App.fmtDate(d.DestructionRequestedAt, true)}<br>เหตุผล: ${App.escapeHtml(d.DestructionReason)}</div></div>`);

    const tabs = [['info', 'ข้อมูลเอกสาร'], ['files', `ไฟล์ (${d.Files.length})`], ['versions', `เวอร์ชันเก่า (${d.Versions.length})`], ['history', 'ประวัติ']];
    let body = '';
    if (this.tabName === 'info') {
      const custom = Object.entries(d.CustomData || {}).filter(([k]) => k !== 'LegacyID');
      const tags = (d.Tags || '').split(',').map(t => t.trim()).filter(Boolean).map(t => `<span class="tag">${App.escapeHtml(t)}</span>`).join('') || '-';
      body = `<dl class="kv">
        <dt>แฟ้มจัดเก็บ</dt><dd>${App.escapeHtml(d.FolderName) || '-'}</dd>
        <dt>วันที่จ่ายเงิน</dt><dd>${App.fmtDate(d.PaymentDate)}</dd>
        <dt>วันครบอายุการเก็บ</dt><dd>${App.fmtDate(d.ExpiryDate)} ${expired ? '<span class="badge danger">ครบอายุแล้ว</span>' : ''}</dd>
        <dt>ขั้นตอน</dt><dd><span class="badge ${w.cls}">${w.label}</span></dd>
        <dt>แท็ก</dt><dd>${tags}</dd>
        ${custom.map(([k, v]) => `<dt>${App.escapeHtml(k)}</dt><dd>${App.escapeHtml(typeof v === 'object' ? JSON.stringify(v) : v)}</dd>`).join('')}
        <dt>นำเข้าระบบ</dt><dd>${App.fmtDate(d.CreatedAt, true)}${d.CreatedByName ? ' · ' + App.escapeHtml(d.CreatedByName) : ''}</dd>
        <dt>แก้ไขล่าสุด</dt><dd>${App.fmtDate(d.UpdatedAt, true)}</dd>
        <dt>ข้อความสำหรับค้นหา</dt><dd>${d.HasText ? `จัดทำดัชนีแล้ว (${Number(d.TextLength).toLocaleString()} ตัวอักษร)` : (d.Files.length ? 'ยังไม่มีข้อความ (ไฟล์เป็นภาพสแกนที่ไม่ได้ทำ OCR)' : '-')}
          ${edit && d.Files.length && !deleted ? ` <button class="btn btn-outline" style="padding:3px 10px; font-size:12px; margin-left:6px;" onclick="App.Detail.reindex()">อ่านข้อความใหม่</button>` : ''}</dd>
      </dl>`;
    } else if (this.tabName === 'files') {
      body = d.Files.length ? d.Files.map(f => this.fileRow(f, edit && !deleted)).join('') : `<div class="notice info"><span class="material-icons-round">info</span><div>ยังไม่มีไฟล์แนบ${edit && !deleted ? ' — กด "แก้ไข" เพื่อแนบไฟล์' : ''}</div></div>`;
    } else if (this.tabName === 'versions') {
      body = d.Versions.length
        ? `<p style="font-size:13px; color:var(--text-muted); margin-bottom:10px;">ไฟล์ที่ถูกแทนที่หรือนำออก ระบบเก็บไว้ให้ตรวจย้อนหลัง</p>` + d.Versions.map(f => this.fileRow(f, false, true)).join('')
        : `<div style="color:var(--text-muted); font-size:14px;">ยังไม่มีเวอร์ชันเก่า</div>`;
    } else {
      body = d.History.length ? `<table class="data-table"><thead><tr><th>วัน-เวลา</th><th>ผู้ใช้</th><th>รายการ</th><th>รายละเอียด</th></tr></thead><tbody>${d.History.map(h => `
        <tr><td style="white-space:nowrap; font-size:13px; color:var(--text-muted);">${App.fmtDate(h.CreatedAt, true)}</td><td>${App.escapeHtml(h.Username)}</td>
        <td><span class="badge muted">${App.escapeHtml(h.Action)}</span></td><td style="font-size:13px;">${App.escapeHtml(h.Details)}</td></tr>`).join('')}</tbody></table>`
        : `<div style="color:var(--text-muted); font-size:14px;">ยังไม่มีประวัติ (ประวัติเริ่มบันทึกรายเอกสารตั้งแต่อัปเดตระบบนี้)</div>`;
    }

    document.getElementById('detail-body').innerHTML = `
      <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
        <div>
          <div style="font-family:var(--font-heading); font-weight:700; color:var(--primary); font-size:15px;">${App.escapeHtml(d.ID)}</div>
          <h2 style="margin:2px 0 8px; font-size:1.35rem;">${App.escapeHtml(d.Filename)}</h2>
          ${deleted ? '' : App.Docs.statusBadges(d)}
        </div>
        <button class="btn btn-outline btn-icon" onclick="App.Detail.close()"><span class="material-icons-round" style="font-size:20px;">close</span></button>
      </div>
      <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:16px;">${actions.join('')}</div>
      ${notices.length ? `<div style="display:flex; flex-direction:column; gap:8px; margin-top:14px;">${notices.join('')}</div>` : ''}
      <div class="tabs">${tabs.map(([k, l]) => `<button class="${this.tabName === k ? 'active' : ''}" onclick="App.Detail.tab('${k}')">${l}</button>`).join('')}</div>
      ${body}`;
  },

  fileRow: function(f, canRemove, isOld) {
    const fid = App.jsArg(f.StoredPath);
    return `<div class="file-row">
      <span class="material-icons-round" style="font-size:26px; color:${/pdf/.test(f.MimeType) ? '#dc2626' : '#2563eb'};">${/pdf/.test(f.MimeType) ? 'picture_as_pdf' : 'image'}</span>
      <div class="meta">
        <div class="name">${App.escapeHtml(f.OriginalName)} <span class="badge muted" style="padding:1px 8px;">v${f.Version}</span></div>
        <div class="sub">${f.PageCount ? f.PageCount + ' หน้า · ' : ''}${App.fmtBytes(f.SizeBytes)} · ${App.fmtDate(f.CreatedAt, true)}${f.UploadedBy ? ' · ' + App.escapeHtml(f.UploadedBy) : ''}${isOld && f.ReplacedAt ? ' · ถูกแทนที่ ' + App.fmtDate(f.ReplacedAt, true) : ''}</div>
        ${f.Sha256 ? `<div class="sub mono" title="SHA-256 ใช้ตรวจว่าไฟล์ไม่ถูกแก้ไข">SHA-256 ${f.Sha256.slice(0, 16)}…</div>` : ''}
      </div>
      <button class="btn btn-outline btn-icon" title="เปิดดู" style="color:var(--primary);" onclick="App.Viewer.open([{fileId:${fid}, name:${App.jsArg(f.OriginalName)}}], ${App.jsArg(f.OriginalName)})"><span class="material-icons-round" style="font-size:18px;">visibility</span></button>
      <a class="btn btn-outline btn-icon" title="ดาวน์โหลด" href="${App.fileUrl(f.StoredPath, true)}"><span class="material-icons-round" style="font-size:18px;">download</span></a>
      ${f.OriginalPath ? `<a class="btn btn-outline btn-icon" title="ดาวน์โหลดต้นฉบับ (ก่อนประทับ QR)" href="${App.fileUrl(f.OriginalPath, true)}"><span class="material-icons-round" style="font-size:18px;">description</span></a>` : ''}
      ${canRemove ? `<button class="btn btn-outline btn-icon" title="นำออก (เก็บไว้ในเวอร์ชันเก่า)" style="color:var(--danger);" onclick="App.Detail.removeFile(${f.ID})"><span class="material-icons-round" style="font-size:18px;">link_off</span></button>` : ''}
    </div>`;
  },

  act: async function(body, okMsg, closeAfter) {
    try {
      App.showLoader();
      await App.api('api/document.php', body);
      App.hideLoader();
      App.toast(okMsg, 'success');
      if (closeAfter) { this.close(); if (App.currentView === 'dashboard') App.Docs.load(); }
      else this.refresh();
    } catch (e) { App.hideLoader(); App.toast(e.message); }
  },
  setWorkflow: async function(to, askNote) {
    let note = '';
    if (askNote) {
      const r = await App.Dialog.form({
        title: 'ตีกลับเป็นฉบับร่าง', icon: 'undo', tone: 'warning',
        message: `เอกสาร ${this.doc.ID} จะกลับไปเป็นฉบับร่างให้ผู้จัดทำแก้ไข`,
        fields: [{ name: 'note', label: 'เหตุผลที่ตีกลับ (ถ้ามี)', multiline: true, placeholder: 'เช่น เลขที่ฎีกาไม่ตรงกับเอกสาร' }],
        confirmText: 'ตีกลับ',
      });
      if (!r) return;
      note = r.note;
    }
    this.act({ action: 'setWorkflow', ID: this.doc.ID, WorkflowStatus: to, Note: note }, 'เปลี่ยนสถานะเป็น "' + WORKFLOW[to].label + '" แล้ว');
  },
  requestDestruction: async function() {
    const r = await App.Dialog.form({
      title: 'ขออนุมัติทำลายเอกสาร', icon: 'delete_sweep', tone: 'warning',
      message: `ส่งคำขอทำลายเอกสาร ${this.doc.ID} ให้ผู้ดูแลระบบพิจารณา`,
      fields: [{ name: 'reason', label: 'เหตุผล', value: 'ครบอายุการเก็บรักษาตามระเบียบ', required: true, multiline: true }],
      confirmText: 'ส่งคำขอ',
    });
    if (!r) return;
    this.act({ action: 'requestDestruction', ID: this.doc.ID, Reason: r.reason }, 'ส่งคำขอทำลายแล้ว รอผู้ดูแลระบบอนุมัติ');
  },
  decideDestruction: async function(approve) {
    if (approve && !await App.Dialog.confirm({
      title: 'อนุมัติทำลายเอกสาร?', icon: 'delete_sweep', tone: 'danger',
      message: `เอกสาร ${this.doc.ID} จะถูกย้ายไปถังขยะ และถูกลบถาวรเมื่อครบกำหนดเก็บในถังขยะ`,
      confirmText: 'อนุมัติทำลาย',
    })) return;
    this.act({ action: 'decideDestruction', ID: this.doc.ID, approve }, approve ? 'อนุมัติทำลายแล้ว (ย้ายไปถังขยะ)' : 'ไม่อนุมัติคำขอทำลาย', approve);
  },
  trash: async function(id) {
    const r = await App.Dialog.form({
      title: 'ย้ายไปถังขยะ', icon: 'delete_outline', tone: 'danger',
      message: `เอกสาร ${id} จะถูกย้ายไปถังขยะ กู้คืนได้จากตัวกรอง "ถังขยะ" จนกว่าจะครบกำหนดลบถาวร`,
      fields: [{ name: 'reason', label: 'เหตุผล (ถ้ามี)', placeholder: 'เช่น บันทึกซ้ำ' }],
      confirmText: 'ย้ายไปถังขยะ',
    });
    if (!r) return;
    this.act({ action: 'deleteDocument', ID: id, Reason: r.reason }, 'ย้ายไปถังขยะแล้ว กู้คืนได้จากตัวกรอง "ถังขยะ"', true);
  },
  restore: function(id) {
    this.act({ action: 'restoreDocument', ID: id }, 'กู้คืนเอกสารแล้ว', true);
  },
  purge: async function(id) {
    if (!await App.Dialog.confirm({
      title: 'ลบถาวร?', icon: 'delete_forever', tone: 'danger',
      message: `เอกสาร ${id} และไฟล์ทุกเวอร์ชันจะถูกลบอย่างถาวร`,
      note: 'การลบนี้ย้อนกลับไม่ได้',
      confirmText: 'ลบถาวร',
    })) return;
    this.act({ action: 'purgeDocument', ID: id }, 'ลบถาวรแล้ว', true);
  },
  removeFile: async function(rowId) {
    if (!await App.Dialog.confirm({
      title: 'นำไฟล์ออกจากเอกสาร?', icon: 'link_off', tone: 'warning',
      message: 'ไฟล์จะไม่หายไป ยังเปิดดูได้ในแท็บ "เวอร์ชันเก่า"',
      confirmText: 'นำออก',
    })) return;
    this.act({ action: 'removeFile', ID: this.doc.ID, FileRowId: rowId }, 'นำไฟล์ออกแล้ว');
  },
  reindex: function() {
    this.act({ action: 'reindexText', ID: this.doc.ID }, 'กำลังอ่านข้อความในไฟล์อยู่เบื้องหลัง');
  },
};

/* =====================================================================
   Edit document
   ===================================================================== */
App.Editor = {
  doc: null,
  staged: new StagedList('edit', 'edit-staged', 'edit'),

  open: function() {
    const doc = App.Detail.doc;
    if (!doc) return;
    this.doc = doc;
    const prefixSelect = document.getElementById('editDocPrefix');
    prefixSelect.innerHTML = `<option value="">(ไม่ใช้ตัวนำหน้า)</option>` + App.prefixOptions();
    let matched = '';
    App.data.prefixes.forEach(p => { if (doc.ID.startsWith(p.PrefixValue) && p.PrefixValue.length > matched.length) matched = p.PrefixValue; });
    prefixSelect.value = matched;
    document.getElementById('editDocIdInput').value = doc.ID.substring(matched.length);
    document.getElementById('edit-filename').value = doc.Filename;
    document.getElementById('edit-folder').innerHTML = App.folderOptions(doc.FolderId, true);
    document.getElementById('edit-payment-date').value = doc.PaymentDate || '';
    document.getElementById('edit-tags').value = doc.Tags || '';
    document.querySelector('input[name="edit-upload-mode"][value="append"]').checked = true;
    document.getElementById('edit-file-input').value = '';

    // Index fields: configured custom fields plus whatever the document already has (e.g. imported legacy fields)
    const names = App.data.customFields.map(f => f.FieldName);
    Object.keys(doc.CustomData || {}).forEach(k => { if (k !== 'LegacyID' && !names.includes(k)) names.push(k); });
    document.getElementById('edit-custom-fields').innerHTML = names.length ? `<div style="display:grid; grid-template-columns:1fr 1fr; gap:0 16px;">` + names.map((n, i) => `
      <div class="form-group"><label>${App.escapeHtml(n)}</label><input type="text" class="form-control" data-cf="${App.escapeHtml(n)}" value="${App.escapeHtml((doc.CustomData || {})[n] ?? '')}"></div>`).join('') + `</div>` : '';

    this.staged.clear(true);
    document.getElementById('edit-modal').style.display = 'flex';
  },
  close: function() {
    this.staged.clear(true);
    document.getElementById('edit-modal').style.display = 'none';
  },
  save: async function() {
    const d = this.doc;
    const number = document.getElementById('editDocIdInput').value.trim();
    const filename = document.getElementById('edit-filename').value.trim();
    if (!number || !filename) { App.toast("กรุณากรอกรหัสและชื่อเอกสาร"); return; }
    if (this.staged.uploading.length) { App.toast("กรุณารอให้อัปโหลดไฟล์เสร็จก่อน", "info"); return; }
    const custom = Object.assign({}, d.CustomData || {});
    document.querySelectorAll('#edit-custom-fields [data-cf]').forEach(inp => {
      const v = inp.value.trim();
      if (v) custom[inp.dataset.cf] = v; else delete custom[inp.dataset.cf];
    });
    const mode = document.querySelector('input[name="edit-upload-mode"]:checked').value;
    if (mode === 'replace' && this.staged.items.length && !await App.Dialog.confirm({
      title: 'แทนที่ไฟล์ทั้งหมด?', icon: 'published_with_changes', tone: 'warning',
      message: `ไฟล์ปัจจุบันจะถูกแทนที่ด้วยไฟล์ใหม่ ${this.staged.items.length} ไฟล์`,
      note: 'ไฟล์เดิมยังเก็บไว้ในแท็บ "เวอร์ชันเก่า"',
      confirmText: 'แทนที่ไฟล์',
    })) return;
    const newId = document.getElementById('editDocPrefix').value + number;
    if (newId !== d.ID && !App.validDocId(newId)) return;
    if (d.WorkflowStatus === 'Approved' && !App.isAdmin() && !await App.Dialog.confirm({
      title: 'เอกสารนี้อนุมัติแล้ว', icon: 'pending_actions', tone: 'warning',
      message: 'การแก้ไขจะส่งเอกสารกลับไป "รอตรวจสอบ" ให้ผู้ดูแลระบบอนุมัติอีกครั้ง',
      confirmText: 'บันทึกและส่งตรวจ',
    })) return;

    try {
      App.showLoader(this.staged.items.length ? 'กำลังบันทึกและประทับ QR Code...' : 'กำลังบันทึก...');
      const res = await App.api('api/document.php', {
        action: 'updateDocumentMetadata', oldId: d.ID,
        newId,
        RowVersion: d.RowVersion,
        Filename: filename,
        FolderId: document.getElementById('edit-folder').value,
        PaymentDate: document.getElementById('edit-payment-date').value,
        Tags: document.getElementById('edit-tags').value,
        CustomData: custom,
        newFileId: this.staged.fileIds(),
        appendFiles: mode === 'append',
      });
      App.hideLoader();
      this.staged.items = [];
      this.close();
      App.toast(res.backToReview ? 'บันทึกแล้ว — ส่งกลับไปรอตรวจสอบ' : "บันทึกการแก้ไขสำเร็จ", "success");
      if (res.stampFailed && res.stampFailed.length) App.toast(`ประทับ QR ไม่ได้: ${res.stampFailed.join(', ')} — จัดเก็บไฟล์ต้นฉบับแทน`, 'info');
      App.Detail.open(res.updatedDoc.ID);
      if (App.currentView === 'dashboard') App.Docs.load();
    } catch (e) {
      App.hideLoader();
      App.toast("ข้อผิดพลาด: " + e.message);
    }
  },
};

/* =====================================================================
   File viewer
   ===================================================================== */
App.Viewer = {
  open: function(files, title) {
    const tabs = document.getElementById('viewer-tabs');
    document.getElementById('viewer-title').textContent = title || 'เปิดดูเอกสาร';
    document.getElementById('viewer-modal').style.display = 'flex';
    tabs.replaceChildren();
    tabs.style.display = files.length > 1 ? 'flex' : 'none';
    files.forEach((f, i) => {
      const b = document.createElement('button');
      b.className = 'btn btn-outline';
      b.style.cssText = 'padding:6px 12px; font-size:13px; white-space:nowrap;';
      b.textContent = `${i + 1}. ${f.name || 'ไฟล์'}`;
      b.onclick = () => this.show(f, b);
      tabs.appendChild(b);
    });
    this.show(files[0], tabs.firstChild);
  },
  openStaged: function(list, idx) {
    const f = list.items[idx];
    this.open([{ fileId: f.fileId, name: f.name }], f.name);
  },
  show: function(f, button) {
    document.querySelectorAll('#viewer-tabs button').forEach(b => { b.style.borderColor = ''; b.style.color = ''; });
    if (button) { button.style.borderColor = 'var(--primary)'; button.style.color = 'var(--primary)'; }
    const url = App.fileUrl(f.fileId);
    document.getElementById('viewer-download').href = App.fileUrl(f.fileId, true);
    const box = document.getElementById('viewer-frame-container');
    const ext = String(f.fileId).split('.').pop().toLowerCase();
    if (ext === 'pdf') {
      box.innerHTML = `<iframe src="${url}" style="width:100%; height:100%; border:0;"></iframe>`;
    } else if (ext === 'tif' || ext === 'tiff') {
      box.innerHTML = `<div style="padding:40px; text-align:center; color:var(--text-muted);"><span class="material-icons-round" style="font-size:48px;">image_not_supported</span>
        <div style="margin-top:8px;">เบราว์เซอร์แสดงไฟล์ TIFF ไม่ได้ กรุณากดดาวน์โหลด (แนะนำให้สแกนเป็น PDF)</div></div>`;
    } else {
      box.innerHTML = `<div style="text-align:center; padding:12px;"><img src="${url}" style="max-width:100%; box-shadow:var(--shadow);"></div>`;
    }
  },
  close: function() {
    document.getElementById('viewer-modal').style.display = 'none';
    document.getElementById('viewer-frame-container').innerHTML = '';
  },
};

/* =====================================================================
   Page editor: rotate / delete / reorder pages and merge files (pdf-lib in the browser)
   ===================================================================== */
App.PageEditor = {
  list: null, indices: [], sources: [], pages: [],

  open: async function(list, indices) {
    this.list = list;
    this.indices = indices;
    this.sources = [];
    this.pages = [];
    document.getElementById('page-editor-modal').style.display = 'flex';
    const grid = document.getElementById('pe-grid');
    grid.innerHTML = '';
    const status = document.getElementById('pe-status');
    document.getElementById('pe-save').disabled = true;
    try {
      for (const idx of indices) {
        const item = list.items[idx];
        status.textContent = `กำลังโหลด ${item.name}...`;
        const bytes = new Uint8Array(await (await fetch(App.fileUrl(item.fileId))).arrayBuffer());
        if (item.format === 'tif' || item.format === 'tiff') {
          // Browsers cannot draw TIFF: decode every page to PNG and treat each page as an image
          status.textContent = `กำลังแปลง TIFF ${item.name}...`;
          for (const png of await App.tiffToPngs(bytes)) {
            const sIdx = this.sources.push({ item, bytes: png, kind: 'image', imgType: 'png' }) - 1;
            this.pages.push({ src: sIdx, index: 0, rotate: 0, thumb: URL.createObjectURL(new Blob([png], { type: 'image/png' })) });
            this.render();
          }
          continue;
        }
        const src = { item, bytes, kind: item.format === 'pdf' ? 'pdf' : 'image', imgType: item.format === 'png' ? 'png' : 'jpg' };
        const sIdx = this.sources.push(src) - 1;
        if (src.kind === 'pdf') {
          const pdf = await pdfjsLib.getDocument({ data: bytes.slice(0) }).promise;
          for (let i = 1; i <= pdf.numPages; i++) {
            status.textContent = `กำลังสร้างภาพตัวอย่าง ${item.name} หน้า ${i}/${pdf.numPages}`;
            const page = await pdf.getPage(i);
            const vp1 = page.getViewport({ scale: 1 });
            const vp = page.getViewport({ scale: 220 / Math.max(vp1.width, vp1.height) });
            const canvas = document.createElement('canvas');
            canvas.width = vp.width; canvas.height = vp.height;
            await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
            this.pages.push({ src: sIdx, index: i - 1, rotate: 0, thumb: canvas.toDataURL('image/jpeg', 0.7) });
            this.render();
          }
        } else {
          const url = URL.createObjectURL(new Blob([bytes], { type: item.format === 'png' ? 'image/png' : 'image/jpeg' }));
          this.pages.push({ src: sIdx, index: 0, rotate: 0, thumb: url });
          this.render();
        }
      }
      status.textContent = `${this.pages.length} หน้า`;
      document.getElementById('pe-save').disabled = false;
    } catch (e) {
      console.error(e);
      status.textContent = 'โหลดไฟล์ไม่สำเร็จ: ' + e.message;
    }
  },

  render: function() {
    const grid = document.getElementById('pe-grid');
    grid.innerHTML = this.pages.map((p, i) => `
      <div class="pe-card">
        <div class="pe-thumb"><img src="${p.thumb}" style="transform: rotate(${p.rotate}deg);"></div>
        <div class="pe-bar">
          <button title="ย้ายไปก่อนหน้า" onclick="App.PageEditor.move(${i}, -1)"><span class="material-icons-round">chevron_left</span></button>
          <button title="หมุนซ้าย" onclick="App.PageEditor.rotate(${i}, -90)"><span class="material-icons-round">rotate_left</span></button>
          <span>หน้า ${i + 1}</span>
          <button title="หมุนขวา" onclick="App.PageEditor.rotate(${i}, 90)"><span class="material-icons-round">rotate_right</span></button>
          <button title="ย้ายไปถัดไป" onclick="App.PageEditor.move(${i}, 1)"><span class="material-icons-round">chevron_right</span></button>
        </div>
        <button onclick="App.PageEditor.remove(${i})" style="border:none; background:#fef2f2; color:var(--danger); font-family:inherit; font-size:12px; font-weight:600; padding:5px; cursor:pointer;">ลบหน้านี้</button>
      </div>`).join('');
    document.getElementById('pe-status').textContent = `${this.pages.length} หน้า`;
  },
  rotate: function(i, deg) { this.pages[i].rotate = (this.pages[i].rotate + deg + 360) % 360; this.render(); },
  rotateAll: function(deg) { this.pages.forEach(p => p.rotate = (p.rotate + deg + 360) % 360); this.render(); },
  reverse: function() { this.pages.reverse(); this.render(); },
  move: function(i, d) {
    const j = i + d;
    if (j < 0 || j >= this.pages.length) return;
    [this.pages[i], this.pages[j]] = [this.pages[j], this.pages[i]];
    this.render();
  },
  remove: function(i) { this.pages.splice(i, 1); this.render(); },
  close: function() { document.getElementById('page-editor-modal').style.display = 'none'; },

  save: async function() {
    if (!this.pages.length) { App.toast('ต้องมีอย่างน้อย 1 หน้า'); return; }
    const { PDFDocument, degrees } = PDFLib;
    App.showLoader('กำลังสร้างไฟล์ PDF...');
    try {
      const out = await PDFDocument.create();
      const loaded = {};
      for (const p of this.pages) {
        const src = this.sources[p.src];
        if (src.kind === 'pdf') {
          if (!loaded[p.src]) loaded[p.src] = await PDFDocument.load(src.bytes, { ignoreEncryption: true });
          const [page] = await out.copyPages(loaded[p.src], [p.index]);
          page.setRotation(degrees((page.getRotation().angle + p.rotate) % 360));
          out.addPage(page);
        } else {
          if (!loaded[p.src]) loaded[p.src] = src.imgType === 'png' ? await out.embedPng(src.bytes) : await out.embedJpg(src.bytes);
          const img = loaded[p.src];
          // Fit the image on an A4 page in the image's orientation
          const landscape = img.width > img.height;
          const [W, H] = landscape ? [841.89, 595.28] : [595.28, 841.89];
          const scale = Math.min(W / img.width, H / img.height);
          const page = out.addPage([W, H]);
          page.drawImage(img, { x: (W - img.width * scale) / 2, y: (H - img.height * scale) / 2, width: img.width * scale, height: img.height * scale });
          if (p.rotate) page.setRotation(degrees(p.rotate));
        }
      }
      // Classic xref (no object streams) so the server can stamp the QR code
      const bytes = await out.save({ useObjectStreams: false });
      const first = this.list.items[this.indices[0]];
      const name = first.name.replace(/\.[^.]+$/, '') + (this.indices.length > 1 ? '_รวม' : '_แก้ไข') + '.pdf';
      const file = new File([bytes], name, { type: 'application/pdf' });
      App.showLoader('กำลังอัปโหลดไฟล์ที่จัดหน้าแล้ว...');
      const single = this.indices.length === 1;
      const res = await App.uploadFile(file, p => App.showLoader(`กำลังอัปโหลด... ${p}%`), single ? first.fileId : null);
      if (!single) this.indices.forEach(i => App.discardStaged(this.list.items[i].fileId));
      this.list.replace(this.indices, { fileId: res.fileId, name: res.name, size: res.size, format: 'pdf' });
      App.hideLoader();
      this.close();
      App.toast(`บันทึกแล้ว ${this.pages.length} หน้า`, 'success');
    } catch (e) {
      console.error(e);
      App.hideLoader();
      App.toast('สร้าง PDF ไม่สำเร็จ: ' + e.message);
    }
  },
};

/* =====================================================================
   Upload view
   ===================================================================== */
App.Upload = {
  staged: new StagedList('upload', 'upload-staged'),
  init: function() {
    App.DocForm.render('upload');
    this.staged.render();
    const limit = document.getElementById('upload-limit');
    if (limit) limit.textContent = App.fmtBytes(App.data.settings.uploadLimitBytes);
    // Files uploaded before a page reload are still on the server
    const fd = new FormData();
    fd.append('action', 'listStaged');
    fd.append('context', 'upload');
    fetch('api/upload.php', { method: 'POST', body: fd }).then(r => r.json())
      .then(r => { if (r.success && r.files.length) { this.staged.restore(r.files); App.toast(`กู้คืนไฟล์ที่ยังไม่ได้บันทึก ${r.files.length} ไฟล์`, 'info'); } })
      .catch(() => {});
    const drop = document.getElementById('upload-drop');
    drop.ondragover = e => { e.preventDefault(); drop.style.borderColor = 'var(--primary)'; drop.style.background = '#eff6ff'; };
    drop.ondragleave = () => { drop.style.borderColor = '#cbd5e1'; drop.style.background = '#f8fafc'; };
    drop.ondrop = e => {
      e.preventDefault();
      drop.ondragleave();
      this.staged.addFiles({ files: e.dataTransfer.files });
    };
  },
  submit: function() { App.DocForm.saveWithFiles('upload', this.staged); },
};

/* =====================================================================
   Scanner view: queued background scan jobs
   ===================================================================== */
App.Scanner = {
  staged: new StagedList('scan', 'scan-staged', 'scan'),
  jobId: null,
  pollTimer: null,

  init: function() {
    App.DocForm.render('scan');
    this.staged.render();
    this.checkStatus();
    // After a reload: pick up a running job and scanned files that were not saved yet
    App.api('api/scan.php', { action: 'resume' }).then(r => {
      const before = this.staged.items.length;
      this.staged.restore(r.files);
      if (this.staged.items.length > before) App.toast(`กู้คืนไฟล์สแกนที่ยังไม่ได้บันทึก ${this.staged.items.length - before} ไฟล์`, 'info');
      if (r.activeJobId && !this.jobId) this.jobId = r.activeJobId;
      if (this.jobId) { this.showProgress(true, 'กำลังติดตามงานสแกน...'); this.poll(); }
    }).catch(() => {});
  },

  checkStatus: function() {
    const text = document.getElementById('scanner-status-text');
    const bar = document.getElementById('scanner-status-bar');
    const icon = document.getElementById('scanner-status-icon');
    if (!text) return;
    const set = (bg, border, ic, color, msg) => {
      bar.style.background = bg; bar.style.borderColor = border; icon.textContent = ic; icon.style.color = color; text.textContent = msg; text.style.color = color;
    };
    set('#f8fafc', 'var(--border)', 'hourglass_top', '#64748b', 'กำลังตรวจสอบเครื่องสแกน...');
    App.api('api/scan.php', { action: 'checkStatus' }).then(d => {
      const queue = d.queue ? ` · มีงานในคิว ${d.queue} งาน` : '';
      if (!d.naps2Installed) set('#fef2f2', '#fecaca', 'error_outline', '#dc2626', 'ไม่พบ NAPS2 บนเซิร์ฟเวอร์ — ติดตั้งจาก naps2.com/download');
      else if (d.detectedDevices && d.detectedDevices.length) set('#f0fdf4', '#a7f3d0', 'cast_connected', '#047857', `พร้อมสแกน — พบ ${d.detectedDevices.length} เครื่อง${queue}`);
      else set('#fffbeb', '#fcd34d', 'warning', '#92400e', 'ติดตั้ง NAPS2 แล้ว แต่ไม่พบเครื่องสแกน — ตรวจสอบสาย USB และเปิดเครื่อง' + queue);
    }).catch(() => set('#fef2f2', '#fecaca', 'cloud_off', '#dc2626', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้'));
  },

  listDevices: function() {
    App.showLoader('กำลังค้นหาเครื่องสแกน (TWAIN, WIA, เครือข่าย)...');
    App.api('api/scan.php', { action: 'listDevices' }).then(d => {
      App.hideLoader();
      const sel = document.getElementById('scanDevice');
      sel.innerHTML = `<option value="">ค่าเริ่มต้น (CANON DR-G2110 · TWAIN)</option>` + d.devices.map(x =>
        `<option value="${App.escapeHtml(JSON.stringify(x))}">${App.escapeHtml(x.name)} · ${x.driver.toUpperCase()}</option>`).join('');
      App.toast(d.devices.length ? `พบ ${d.devices.length} เครื่อง` : 'ไม่พบเครื่องสแกน', d.devices.length ? 'success' : 'info');
    }).catch(e => { App.hideLoader(); App.toast(e.message); });
  },

  showProgress: function(on, msg, sub, cancellable) {
    const box = document.getElementById('scan-progress');
    if (!box) return;
    box.style.display = on ? 'block' : 'none';
    const btn = document.getElementById('btn-start-scan');
    btn.disabled = on; btn.style.opacity = on ? '0.5' : '1';
    if (msg) document.getElementById('scan-progress-text').textContent = msg;
    if (sub !== undefined) document.getElementById('scan-progress-sub').textContent = sub;
    document.getElementById('btn-cancel-scan').style.display = cancellable ? 'inline-flex' : 'none';
  },

  startScan: function() {
    if (this.jobId) { App.toast('มีงานสแกนที่กำลังทำงานอยู่', 'info'); return; }
    const device = document.getElementById('scanDevice').value;
    const dev = device ? JSON.parse(device) : null;
    const body = {
      action: 'start',
      device: dev ? dev.name : '',
      driver: dev ? dev.driver : 'twain',
      dpi: parseInt(document.getElementById('scanDpi').value, 10),
      colorMode: document.getElementById('scanColorMode').value,
      source: document.getElementById('scanSource').value,
      format: document.getElementById('scanFormat').value,
      pageSize: document.getElementById('scanPageSize').value,
      deskew: document.getElementById('scanDeskew').checked,
      skipBlank: document.getElementById('scanSkipBlank').checked,
      ocr: document.getElementById('scanOcr').checked,
      splitPatchT: document.getElementById('scanPatchT').checked,
      pdfa: document.getElementById('scanPdfa').checked,
    };
    App.api('api/scan.php', body).then(res => {
      this.jobId = res.jobId;
      this.showProgress(true, res.ahead ? `รอคิว (มีงานก่อนหน้า ${res.ahead} งาน)` : 'กำลังเริ่มสแกน...', 'ทำงานเบื้องหลัง สามารถกรอกข้อมูลเอกสารระหว่างรอได้', !!res.ahead);
      this.poll();
    }).catch(e => App.toast('สั่งสแกนไม่สำเร็จ: ' + e.message));
  },

  poll: function() {
    clearTimeout(this.pollTimer);
    if (!this.jobId) return;
    App.api('api/scan.php', { action: 'jobStatus', jobId: this.jobId }).then(res => {
      if (res.status === 'queued' && !res.ahead) {
        this.showProgress(true, 'กำลังเริ่มสแกน...', 'ทำงานเบื้องหลัง สามารถกรอกข้อมูลเอกสารระหว่างรอได้', true);
      } else if (res.status === 'queued') {
        this.showProgress(true, `รอคิว (มีงานก่อนหน้า ${res.ahead} งาน)`, 'เครื่องสแกนกำลังทำงานให้ผู้ใช้อื่น', true);
      } else if (res.status === 'running') {
        this.showProgress(true, 'กำลังสแกนเอกสาร...', 'เครื่องกำลังดึงกระดาษและประมวลผล', false);
      } else {
        this.jobId = null;
        this.showProgress(false);
        if (res.status === 'done') {
          res.files.forEach(f => this.staged.add(f));
          App.toast(`สแกนสำเร็จ ${res.files.length} ไฟล์`, 'success');
          if (res.split && res.files.length > 1) {
            const cb = document.getElementById('scanSplit');
            if (cb) cb.checked = true;
            App.toast('แยกเอกสารตามแผ่นคั่นแล้ว — ติ๊ก "บันทึกแต่ละไฟล์เป็นเอกสารแยกกัน" ให้อัตโนมัติ', 'info');
          }
        } else {
          App.toast('สแกนไม่สำเร็จ: ' + (res.message || 'ไม่ทราบสาเหตุ'));
        }
        return;
      }
      this.pollTimer = setTimeout(() => this.poll(), 1500);
    }).catch(() => { this.pollTimer = setTimeout(() => this.poll(), 4000); });
  },

  cancel: function() {
    if (!this.jobId) return;
    App.api('api/scan.php', { action: 'cancel', jobId: this.jobId })
      .then(r => App.toast(r.message, 'info'))
      .catch(e => App.toast(e.message));
  },

  submit: function() { App.DocForm.saveWithFiles('scan', this.staged); },
};

/* =====================================================================
   Settings (admin)
   ===================================================================== */
App.Settings = {
  init: function() {
    this.tab('folders');
  },
  tab: function(name) {
    document.querySelectorAll('#settings-tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
    document.querySelectorAll('[data-pane]').forEach(p => p.style.display = p.dataset.pane === name ? '' : 'none');
    if (name === 'folders') this.renderFolders();
    if (name === 'prefixes') this.renderPrefixes();
    if (name === 'fields') this.renderFields();
    if (name === 'users') App.Users.load();
    if (name === 'system') this.loadSystem();
    if (name === 'storage') { this.loadStorage(); this.loadBackups(); }
  },
  call: function(body, okMsg) {
    return App.api('api/settings.php', body).then(res => { if (okMsg) App.toast(okMsg, 'success'); return res; })
      .catch(e => { App.toast(e.message); throw e; });
  },

  selectedFolders: new Set(),

  visibleFolders: function() {
    const f = ((document.getElementById('folder-filter') || {}).value || '').toLowerCase();
    return App.data.folders.filter(x => !f || x.FolderName.toLowerCase().includes(f) || (x.Description || '').toLowerCase().includes(f));
  },

  renderFolders: function() {
    const tbody = document.getElementById('admin-folders-list');
    if (!tbody) return;
    // Forget selections of folders that no longer exist
    const existing = new Set(App.data.folders.map(x => Number(x.ID)));
    this.selectedFolders.forEach(id => { if (!existing.has(id)) this.selectedFolders.delete(id); });
    const rows = this.visibleFolders();
    tbody.innerHTML = rows.map(x => `
      <tr style="${this.selectedFolders.has(Number(x.ID)) ? 'background:#eff6ff;' : ''}">
        <td style="padding-left:20px;"><input type="checkbox" style="width:16px; height:16px; accent-color:var(--primary);" ${this.selectedFolders.has(Number(x.ID)) ? 'checked' : ''} onchange="App.Settings.toggleFolder(${x.ID}, this)"></td>
        <td style="font-weight:600;">${App.escapeHtml(x.FolderName)}</td>
        <td style="color:var(--text-muted); font-size:13px;">${App.escapeHtml(x.Description) || '-'}</td>
        <td style="text-align:right;">${Number(x.DocCount).toLocaleString()}</td>
        <td style="text-align:right; white-space:nowrap;">
          <button class="btn btn-outline btn-icon" title="เปลี่ยนชื่อ" onclick="App.Settings.renameFolder(${x.ID})"><span class="material-icons-round" style="font-size:17px;">edit</span></button>
          <button class="btn btn-outline btn-icon" title="บาร์โค้ดแฟ้ม" onclick="App.Docs.showBarcode('FL-${x.ID}')"><span class="material-icons-round" style="font-size:17px;">qr_code</span></button>
          <button class="btn btn-outline btn-icon" title="ลบแฟ้ม" style="color:var(--danger);" onclick="App.Settings.deleteFolder(${x.ID})"><span class="material-icons-round" style="font-size:17px;">delete</span></button>
        </td>
      </tr>`).join('') || `<tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:24px;">ไม่พบแฟ้ม</td></tr>`;
    this.updateFolderSelectionUi();
  },

  toggleFolder: function(id, box) {
    // Update just this row so scrolling and focus stay where they are
    if (box.checked) this.selectedFolders.add(Number(id)); else this.selectedFolders.delete(Number(id));
    box.closest('tr').style.background = box.checked ? '#eff6ff' : '';
    this.updateFolderSelectionUi();
  },
  selectAllFolders: function(on) {
    this.visibleFolders().forEach(x => on ? this.selectedFolders.add(Number(x.ID)) : this.selectedFolders.delete(Number(x.ID)));
    this.renderFolders();
  },
  clearFolderSelection: function() {
    this.selectedFolders.clear();
    this.renderFolders();
  },
  updateFolderSelectionUi: function() {
    const n = this.selectedFolders.size;
    const visible = this.visibleFolders();
    const all = document.getElementById('folder-select-all');
    if (all) {
      const sel = visible.filter(x => this.selectedFolders.has(Number(x.ID))).length;
      all.checked = visible.length > 0 && sel === visible.length;
      all.indeterminate = sel > 0 && sel < visible.length;
    }
    const btn = document.getElementById('btn-delete-folders');
    if (btn) {
      btn.disabled = n === 0;
      btn.style.opacity = n ? '1' : '0.5';
      btn.lastChild.textContent = n ? ` ลบแฟ้มที่เลือก (${n})` : ' ลบแฟ้มที่เลือก';
    }
    const count = document.getElementById('folder-selected-count');
    if (count) count.textContent = n ? `เลือกแล้ว ${n} แฟ้ม` : `ทั้งหมด ${App.data.folders.length} แฟ้ม`;
    const clear = document.getElementById('btn-clear-folder-selection');
    if (clear) clear.style.display = n ? '' : 'none';
  },
  deleteSelectedFolders: async function() {
    const chosen = App.data.folders.filter(x => this.selectedFolders.has(Number(x.ID)));
    if (!chosen.length) return;
    const docs = chosen.reduce((s, x) => s + Number(x.DocCount), 0);
    if (!await App.Dialog.confirm({
      title: `ลบ ${chosen.length} แฟ้ม?`, icon: 'delete_sweep', tone: 'danger',
      items: chosen.map(x => `${x.FolderName} · ${Number(x.DocCount).toLocaleString()} เอกสาร`),
      note: docs ? `เอกสาร ${docs.toLocaleString()} ฉบับในแฟ้มเหล่านี้จะไม่ถูกลบ แต่จะกลายเป็น "ไม่มีแฟ้ม"` : 'แฟ้มเหล่านี้ไม่มีเอกสาร',
      confirmText: `ลบ ${chosen.length} แฟ้ม`,
    })) return;
    this.call({ action: 'deleteFolders', IDs: chosen.map(x => Number(x.ID)) })
      .then(r => { App.toast(`ลบแล้ว ${r.deleted} แฟ้ม`, 'success'); this.selectedFolders.clear(); return App.reloadMeta(); })
      .then(() => this.renderFolders())
      .catch(() => {});
  },
  addFolder: function() {
    const name = document.getElementById('newFolderName').value.trim();
    const desc = document.getElementById('newFolderDesc').value.trim();
    if (!name) return App.toast("กรอกชื่อแฟ้ม");
    this.call({ action: 'addFolder', FolderName: name, Description: desc }, 'เพิ่มแฟ้มแล้ว').then(() => App.reloadMeta()).then(() => {
      document.getElementById('newFolderName').value = '';
      document.getElementById('newFolderDesc').value = '';
      this.renderFolders();
    });
  },
  renameFolder: async function(id) {
    const f = App.data.folders.find(x => x.ID == id);
    const r = await App.Dialog.form({
      title: 'แก้ไขแฟ้มจัดเก็บ', icon: 'drive_file_rename_outline',
      fields: [
        { name: 'name', label: 'ชื่อแฟ้ม', value: f.FolderName, required: true },
        { name: 'desc', label: 'คำอธิบาย', value: f.Description || '', placeholder: 'รายละเอียดของแฟ้ม (ถ้ามี)' },
      ],
      confirmText: 'บันทึก',
    });
    if (!r) return;
    this.call({ action: 'updateFolder', ID: id, FolderName: r.name, Description: r.desc }, 'บันทึกแล้ว').then(() => App.reloadMeta()).then(() => this.renderFolders());
  },
  deleteFolder: async function(id) {
    const f = App.data.folders.find(x => x.ID == id);
    if (!await App.Dialog.confirm({
      title: 'ลบแฟ้มนี้?', icon: 'folder_delete', tone: 'danger',
      message: `"${f.FolderName}"`,
      note: Number(f.DocCount) ? `เอกสาร ${Number(f.DocCount).toLocaleString()} ฉบับในแฟ้มนี้จะไม่ถูกลบ แต่จะกลายเป็น "ไม่มีแฟ้ม"` : 'แฟ้มนี้ไม่มีเอกสาร',
      confirmText: 'ลบแฟ้ม',
    })) return;
    this.call({ action: 'deleteFolder', ID: id }, 'ลบแฟ้มแล้ว').then(() => App.reloadMeta()).then(() => this.renderFolders());
  },

  renderPrefixes: function() {
    const def = App.data.settings.retentionYearsDefault || 10;
    document.getElementById('admin-prefixes-list').innerHTML = App.data.prefixes.map((p, i) => `
      <tr>
        <td style="padding-left:24px; font-weight:700; color:var(--primary);">${App.escapeHtml(p.PrefixValue)}</td>
        <td><input class="form-control inline-input" id="pf-name-${i}" value="${App.escapeHtml(p.PrefixName)}"></td>
        <td><input class="form-control inline-input" type="number" min="1" style="width:110px;" id="pf-years-${i}" value="${p.RetentionYears ?? ''}" placeholder="${def} (ค่าเริ่มต้น)"></td>
        <td style="text-align:right; white-space:nowrap;">
          <button class="btn btn-outline btn-icon" title="บันทึก" style="color:var(--primary);" onclick="App.Settings.savePrefix(${i})"><span class="material-icons-round" style="font-size:17px;">save</span></button>
          <button class="btn btn-outline btn-icon" title="ลบ" style="color:var(--danger);" onclick="App.Settings.deletePrefix(${i})"><span class="material-icons-round" style="font-size:17px;">delete</span></button>
        </td>
      </tr>`).join('');
  },
  addPrefix: function() {
    const pv = document.getElementById('newPrefixValue').value.trim();
    if (!pv) return App.toast("กรุณากรอกตัวอักษรย่อ");
    this.call({ action: 'addPrefix', PrefixValue: pv, PrefixName: document.getElementById('newPrefixName').value.trim(), RetentionYears: document.getElementById('newPrefixYears').value },
      'เพิ่มรหัสแล้ว').then(() => App.reloadMeta()).then(() => {
      ['newPrefixValue', 'newPrefixName', 'newPrefixYears'].forEach(id => document.getElementById(id).value = '');
      this.renderPrefixes();
    });
  },
  savePrefix: function(i) {
    const p = App.data.prefixes[i];
    this.call({ action: 'updatePrefix', PrefixValue: p.PrefixValue, PrefixName: document.getElementById(`pf-name-${i}`).value, RetentionYears: document.getElementById(`pf-years-${i}`).value },
      'บันทึกแล้ว (มีผลกับเอกสารที่บันทึกหรือแก้ไขหลังจากนี้)').then(() => App.reloadMeta());
  },
  deletePrefix: async function(i) {
    const p = App.data.prefixes[i];
    if (!await App.Dialog.confirm({
      title: `ลบรหัส ${p.PrefixValue}?`, icon: 'qr_code_scanner', tone: 'danger',
      message: p.PrefixName || '', note: 'เอกสารที่ใช้รหัสนี้อยู่แล้วไม่ได้รับผลกระทบ', confirmText: 'ลบรหัส',
    })) return;
    this.call({ action: 'deletePrefix', PrefixValue: p.PrefixValue }, 'ลบแล้ว').then(() => App.reloadMeta()).then(() => this.renderPrefixes());
  },

  renderFields: function() {
    const types = { text: 'ข้อความ', number: 'ตัวเลข', date: 'วันที่' };
    const rows = App.data.customFields.map((f, i) => `
      <tr><td style="padding-left:24px; font-weight:600;">${App.escapeHtml(f.FieldName)}</td><td>${types[f.FieldType] || App.escapeHtml(f.FieldType)}</td>
      <td>${f.IsRequired === 'Yes' ? 'ใช่' : '-'}</td>
      <td style="text-align:right;"><button class="btn btn-outline btn-icon" style="color:var(--danger);" onclick="App.Settings.deleteField(${i})"><span class="material-icons-round" style="font-size:17px;">delete</span></button></td></tr>`).join('');
    document.getElementById('admin-fields-list').innerHTML = rows || `<tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:24px;">ยังไม่มีช่องข้อมูลเพิ่มเติม</td></tr>`;
  },
  addField: function() {
    const name = document.getElementById('newFieldName').value.trim();
    if (!name) return App.toast('กรุณาระบุชื่อช่องข้อมูล');
    this.call({ action: 'addField', FieldName: name, FieldType: document.getElementById('newFieldType').value, IsRequired: document.getElementById('newFieldRequired').checked ? 'Yes' : 'No' }, 'เพิ่มช่องข้อมูลแล้ว')
      .then(() => App.reloadMeta()).then(() => { document.getElementById('newFieldName').value = ''; this.renderFields(); });
  },
  deleteField: async function(i) {
    const f = App.data.customFields[i];
    if (!await App.Dialog.confirm({
      title: 'ลบช่องข้อมูลนี้?', icon: 'playlist_remove', tone: 'danger',
      message: `"${f.FieldName}"`, note: 'ข้อมูลที่บันทึกไว้แล้วในเอกสารยังอยู่ครบ', confirmText: 'ลบช่องข้อมูล',
    })) return;
    this.call({ action: 'deleteField', FieldName: f.FieldName }, 'ลบแล้ว').then(() => App.reloadMeta()).then(() => this.renderFields());
  },

  loadSystem: function() {
    this.call({ action: 'getSystemSettings' }).then(res => {
      const s = res.settings, env = res.environment;
      document.getElementById('set-base-url').value = s.public_base_url;
      document.getElementById('set-base-url-hint').textContent = `เว้นว่าง = ใช้ที่อยู่ปัจจุบัน (${env.detectedBaseUrl}) · ควรเป็น https:// และเป็นที่อยู่ที่บุคคลภายนอกเปิดได้`;
      document.getElementById('set-retention').value = s.retention_years_default;
      document.getElementById('set-warn-days').value = s.expiry_warning_days;
      document.getElementById('set-trash-days').value = s.trash_retention_days;
      const item = (ok, label, hint) => `<div class="notice ${ok ? 'ok' : 'warn'}" style="padding:8px 12px;"><span class="material-icons-round" style="font-size:18px;">${ok ? 'check_circle' : 'warning'}</span><div><b>${label}</b>${hint ? ' · ' + hint : ''}</div></div>`;
      document.getElementById('env-status').innerHTML = `<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:8px;">
        ${item(env.https, 'HTTPS', env.https ? 'เชื่อมต่อแบบเข้ารหัส' : 'ยังเป็น http — ควรติดตั้งใบรับรอง SSL ก่อนเปิดใช้งานจริง')}
        ${item(env.naps2, 'NAPS2 (สแกนเอกสาร)', env.naps2 ? 'พร้อมใช้งาน' : 'ยังไม่ได้ติดตั้งบนเซิร์ฟเวอร์')}
        ${item(env.tesseract, 'Tesseract OCR (รูปภาพ)', env.tesseract ? 'พร้อมใช้งาน' : 'ไม่บังคับ · ตั้งค่าใน config/app.php')}
        ${item(env.phpCli, 'PHP CLI (งานเบื้องหลัง)', env.phpCli ? 'พร้อมใช้งาน' : 'ตรวจ PHP_CLI_PATH ใน config/app.php')}
      </div>`;
    });
  },
  saveSystem: function() {
    this.call({
      action: 'saveSystemSettings',
      public_base_url: document.getElementById('set-base-url').value.trim(),
      retention_years_default: document.getElementById('set-retention').value,
      expiry_warning_days: document.getElementById('set-warn-days').value,
      trash_retention_days: document.getElementById('set-trash-days').value,
    }, 'บันทึกการตั้งค่าแล้ว').then(() => App.reloadMeta()).then(() => this.loadSystem());
  },

  loadStorage: function() {
    const box = document.getElementById('storage-report');
    this.call({ action: 'storageReport' }).then(res => {
      const r = res.report;
      this.orphans = r.orphans;
      const used = r.diskTotalBytes - r.diskFreeBytes;
      const pct = r.diskTotalBytes ? Math.round(used / r.diskTotalBytes * 100) : 0;
      const years = Object.entries(r.byYear).map(([y, b]) => `<span class="badge muted">${App.escapeHtml(y)}: ${App.fmtBytes(b)}</span>`).join(' ');
      box.innerHTML = `
        <h3 style="font-family:var(--font-heading); display:flex; gap:8px; align-items:center; margin-bottom:14px;"><span class="material-icons-round" style="color:var(--primary);">storage</span> พื้นที่จัดเก็บ</h3>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:12px; margin-bottom:16px;">
          <div><div style="font-size:12.5px; color:var(--text-muted);">ไฟล์เอกสารทั้งหมด</div><div style="font-size:1.4rem; font-weight:700;">${App.fmtBytes(r.totalBytes)}</div><div style="font-size:12.5px; color:var(--text-muted);">${r.fileCount.toLocaleString()} ไฟล์ · ${r.documentsWithFiles.toLocaleString()} เอกสาร</div></div>
          <div><div style="font-size:12.5px; color:var(--text-muted);">เวอร์ชันเก่าที่เก็บไว้</div><div style="font-size:1.4rem; font-weight:700;">${r.oldVersions.toLocaleString()}</div></div>
          <div><div style="font-size:12.5px; color:var(--text-muted);">ดิสก์เซิร์ฟเวอร์</div><div style="font-size:1.4rem; font-weight:700;">ว่าง ${App.fmtBytes(r.diskFreeBytes)}</div>
            <div class="bar-track" style="margin-top:6px;"><div class="bar-fill" style="width:${pct}%; background:${pct > 90 ? 'var(--danger)' : (pct > 75 ? 'var(--warning)' : 'var(--primary)')};"></div></div>
            <div style="font-size:12px; color:var(--text-muted); margin-top:3px;">ใช้ไป ${pct}% จาก ${App.fmtBytes(r.diskTotalBytes)}</div></div>
        </div>
        ${years ? `<div style="margin-bottom:16px; display:flex; flex-wrap:wrap; gap:6px;">${years}</div>` : ''}
        <div class="notice ${r.documentsWithoutText ? 'info' : 'ok'}" style="margin-bottom:10px;"><span class="material-icons-round">manage_search</span>
          <div style="flex:1;">เอกสารที่มีไฟล์แต่ยังไม่มีข้อความสำหรับค้นหา: <b>${r.documentsWithoutText.toLocaleString()}</b></div>
          ${r.documentsWithoutText ? `<button class="btn btn-outline" style="padding:4px 12px; font-size:12.5px;" onclick="App.Settings.reindexAll()">อ่านข้อความทั้งหมด</button>` : ''}</div>
        <div class="notice ${r.orphanCount ? 'warn' : 'ok'}"><span class="material-icons-round">${r.orphanCount ? 'cleaning_services' : 'check_circle'}</span>
          <div style="flex:1;">ไฟล์ขยะ (ไม่ได้ผูกกับเอกสารใด และเก่ากว่า 1 วัน): <b>${r.orphanCount.toLocaleString()} ไฟล์ · ${App.fmtBytes(r.orphanBytes)}</b>
          ${r.orphanCount ? `<details style="margin-top:6px;"><summary style="cursor:pointer;">ดูรายการ</summary><div class="mono" style="max-height:160px; overflow:auto; margin-top:6px;">${r.orphans.map(o => `${App.escapeHtml(o.path)} (${App.fmtBytes(o.size)}, ${o.modified})`).join('<br>')}</div></details>` : ''}</div>
          ${r.orphanCount ? `<button class="btn btn-outline" style="padding:4px 12px; font-size:12.5px; color:var(--danger);" onclick="App.Settings.deleteOrphans()">ลบไฟล์ขยะ</button>` : ''}</div>`;
    }).catch(() => { box.innerHTML = '<div style="color:var(--danger);">โหลดข้อมูลพื้นที่ไม่สำเร็จ</div>'; });
  },
  reindexAll: function() {
    this.call({ action: 'reindexAll' }, 'เริ่มอ่านข้อความในไฟล์เบื้องหลังแล้ว อาจใช้เวลาสักครู่');
  },
  deleteOrphans: async function() {
    if (!this.orphans || !this.orphans.length) return;
    if (!await App.Dialog.confirm({
      title: `ลบไฟล์ขยะ ${this.orphans.length} ไฟล์?`, icon: 'cleaning_services', tone: 'danger',
      message: 'ไฟล์เหล่านี้ไม่ได้ผูกกับเอกสารใดเลย', items: this.orphans.map(o => `${o.path} · ${App.fmtBytes(o.size)}`),
      note: 'ลบถาวร ย้อนกลับไม่ได้', confirmText: 'ลบไฟล์ขยะ',
    })) return;
    this.call({ action: 'deleteOrphans', paths: this.orphans.map(o => o.path) }).then(r => {
      App.toast(`ลบแล้ว ${r.deleted} ไฟล์`, 'success');
      this.loadStorage();
    });
  },
  loadBackups: function() {
    this.call({ action: 'listBackups' }).then(res => {
      document.getElementById('backup-hint').textContent = `เก็บไว้ที่ ${res.dir} (นอกเว็บไซต์) · เก็บชุดล่าสุด ${res.keep} ชุด · ตั้งเวลาอัตโนมัติด้วย Windows Task Scheduler (ดู tools/backup.php)`;
      const states = { done: ['success', 'สำเร็จ'], running: ['info', 'กำลังทำงาน'], failed: ['danger', 'ล้มเหลว'] };
      document.getElementById('backup-list').innerHTML = res.backups.length ? res.backups.map(b => {
        const [cls, label] = states[b.state] || ['muted', b.state];
        const m = b.name.match(/edms_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})/);
        const when = m ? App.fmtDate(`${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}`, true) : b.name;
        return `<tr><td><b>${when}</b><div class="mono" style="color:var(--text-muted);">${App.escapeHtml(b.name)}</div></td>
          <td><span class="badge ${cls}">${label}</span>${b.error ? `<div style="font-size:12px; color:var(--danger);">${App.escapeHtml(b.error)}</div>` : ''}</td>
          <td>${b.files !== undefined ? b.files.toLocaleString() + ' ไฟล์' : '-'}</td><td>${App.fmtBytes(b.totalBytes)}</td></tr>`;
      }).join('') : `<tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:20px;">ยังไม่มีชุดสำรองข้อมูล</td></tr>`;
      if (res.backups.some(b => b.state === 'running')) setTimeout(() => { if (document.getElementById('backup-list')) this.loadBackups(); }, 5000);
    });
  },
  runBackup: function() {
    this.call({ action: 'runBackup' }, 'เริ่มสำรองข้อมูลเบื้องหลังแล้ว').then(() => setTimeout(() => this.loadBackups(), 2000));
  },
};

/* =====================================================================
   Users (admin)
   ===================================================================== */
App.Users = {
  list: [],
  me: null,
  editing: null,
  load: function() {
    if (!document.getElementById('admin-users-list')) return;
    App.api('api/users.php', { action: 'list' }).then(res => {
      this.list = res.users; this.me = res.me;
      this.render();
    }).catch(e => App.toast(e.message));
  },
  render: function() {
    const tbody = document.getElementById('admin-users-list');
    if (!tbody) return;
    tbody.innerHTML = this.list.map(u => {
      const isMe = u.ID === this.me;
      const roleOptions = Object.keys(ROLE_LABELS).map(r => `<option value="${r}" ${u.Role === r ? 'selected' : ''}>${ROLE_LABELS[r]}</option>`).join('');
      const access = u.Role === 'admin' ? '<span style="color:var(--text-muted);">ทุกแฟ้ม (ผู้ดูแล)</span>'
        : (u.FolderScope === 'restricted' ? `<span class="badge warning">${u.FolderIds.length} แฟ้ม</span>` : '<span class="badge muted">ทุกแฟ้ม</span>');
      return `
        <tr>
          <td style="padding-left:24px;"><strong>${App.escapeHtml(u.Username)}</strong>${isMe ? ' <span class="badge info">คุณ</span>' : ''}</td>
          <td><select class="form-control inline-input" style="width:150px;" onchange="App.Users.updateRole(${u.ID}, this.value)">${roleOptions}</select></td>
          <td>${access}</td>
          <td>${u.Locked ? '<span class="badge danger">ถูกล็อก</span>' : '<span class="badge success">ปกติ</span>'}</td>
          <td class="hide-sm" style="font-size:13px; color:var(--text-muted);">${App.fmtDate(u.CreatedAt, true)}</td>
          <td style="text-align:right; white-space:nowrap;">
            ${u.Role !== 'admin' ? `<button class="btn btn-outline btn-icon" onclick="App.Users.openFolderAccess(${u.ID})" title="กำหนดแฟ้มที่เข้าถึงได้"><span class="material-icons-round" style="font-size:17px;">folder_shared</span></button>` : ''}
            ${u.Locked ? `<button class="btn btn-outline btn-icon" onclick="App.Users.unlock(${u.ID})" title="ปลดล็อก"><span class="material-icons-round" style="font-size:17px;">lock_open</span></button>` : ''}
            <button class="btn btn-outline btn-icon" onclick="App.Account.openPasswordModal(${u.ID})" title="ตั้งรหัสผ่านใหม่"><span class="material-icons-round" style="font-size:17px;">password</span></button>
            ${isMe ? '' : `<button class="btn btn-outline btn-icon" style="color:var(--danger);" onclick="App.Users.remove(${u.ID})" title="ลบผู้ใช้"><span class="material-icons-round" style="font-size:17px;">delete</span></button>`}
          </td>
        </tr>`;
    }).join('');
  },
  create: function() {
    const username = document.getElementById('newUserName').value.trim();
    const password = document.getElementById('newUserPassword').value;
    const role = document.getElementById('newUserRole').value;
    if (!username || !password) return App.toast("กรุณากรอกชื่อผู้ใช้และรหัสผ่าน");
    App.api('api/users.php', { action: 'create', Username: username, Password: password, Role: role }).then(() => {
      document.getElementById('newUserName').value = '';
      document.getElementById('newUserPassword').value = '';
      App.toast("เพิ่มผู้ใช้แล้ว", "success");
      this.load();
    }).catch(e => App.toast(e.message));
  },
  updateRole: function(id, role) {
    App.api('api/users.php', { action: 'updateRole', ID: id, Role: role }).then(() => {
      if (id === this.me && role !== 'admin') { location.reload(); return; }
      App.toast("เปลี่ยนสิทธิ์แล้ว", "success");
      this.load();
    }).catch(e => { App.toast(e.message); this.render(); });
  },
  unlock: function(id) {
    App.api('api/users.php', { action: 'unlock', ID: id }).then(() => { App.toast("ปลดล็อกบัญชีแล้ว", "success"); this.load(); }).catch(e => App.toast(e.message));
  },
  remove: async function(id) {
    const u = this.list.find(x => x.ID === id);
    if (!u || !await App.Dialog.confirm({
      title: 'ลบผู้ใช้นี้?', icon: 'person_remove', tone: 'danger',
      message: `"${u.Username}" · ${ROLE_LABELS[u.Role] || u.Role}`,
      note: 'ผู้ใช้จะเข้าระบบไม่ได้ทันที ประวัติการใช้งานยังเก็บไว้', confirmText: 'ลบผู้ใช้',
    })) return;
    App.api('api/users.php', { action: 'delete', ID: id }).then(() => { App.toast("ลบผู้ใช้แล้ว", "success"); this.load(); }).catch(e => App.toast(e.message));
  },

  openFolderAccess: function(id) {
    const u = this.list.find(x => x.ID === id);
    this.editing = u;
    document.getElementById('fa-title').textContent = `แฟ้มที่ ${u.Username} เข้าถึงได้`;
    document.querySelector(`input[name="fa-scope"][value="${u.FolderScope === 'restricted' ? 'restricted' : 'all'}"]`).checked = true;
    document.getElementById('fa-list').innerHTML = App.data.folders.map(f => `
      <label class="check-row" data-name="${App.escapeHtml(f.FolderName.toLowerCase())}" style="padding:5px 0;">
        <input type="checkbox" value="${f.ID}" ${u.FolderIds.includes(Number(f.ID)) ? 'checked' : ''}><div>${App.escapeHtml(f.FolderName)} <small style="display:inline;">(${Number(f.DocCount).toLocaleString()})</small></div></label>`).join('');
    this.toggleFolderList();
    document.getElementById('folder-access-modal').style.display = 'flex';
  },
  toggleFolderList: function() {
    const restricted = document.querySelector('input[name="fa-scope"]:checked').value === 'restricted';
    document.getElementById('fa-list-wrap').style.opacity = restricted ? '1' : '0.4';
    document.getElementById('fa-list-wrap').style.pointerEvents = restricted ? '' : 'none';
  },
  filterFolderList: function(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#fa-list label').forEach(l => l.style.display = !q || l.dataset.name.includes(q) ? '' : 'none');
  },
  saveFolderAccess: async function() {
    const scope = document.querySelector('input[name="fa-scope"]:checked').value;
    const ids = Array.from(document.querySelectorAll('#fa-list input:checked')).map(i => Number(i.value));
    if (scope === 'restricted' && !ids.length && !await App.Dialog.confirm({
      title: 'ยังไม่ได้เลือกแฟ้ม', icon: 'folder_off', tone: 'warning',
      message: 'ผู้ใช้นี้จะไม่เห็นเอกสารใด ๆ เลย', confirmText: 'บันทึกต่อ',
    })) return;
    App.api('api/users.php', { action: 'setFolderScope', ID: this.editing.ID, FolderScope: scope, FolderIds: ids }).then(() => {
      document.getElementById('folder-access-modal').style.display = 'none';
      App.toast('บันทึกสิทธิ์แล้ว', 'success');
      this.load();
    }).catch(e => App.toast(e.message));
  },
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
      ? { action: 'resetPassword', ID: this.targetUserId, Password: pw }
      : { action: 'changeOwnPassword', CurrentPassword: current, NewPassword: pw };
    App.api('api/users.php', body).then(() => {
      this.closePasswordModal();
      App.toast("บันทึกรหัสผ่านใหม่แล้ว", "success");
    }).catch(e => App.toast(e.message));
  },
};

/* =====================================================================
   Executive dashboard (admin)
   ===================================================================== */
App.Analytics = {
  chartUpload: null,
  chartFolder: null,
  allLogs: [],
  init: function() {
    App.showLoader("กำลังโหลดข้อมูลสถิติ...");
    fetch('api/dashboard_stats.php').then(res => res.json()).then(res => {
      App.hideLoader();
      if (!res.success) { App.toast("ไม่สามารถโหลดข้อมูลสถิติได้"); return; }
      this.allLogs = res.data.recentLogs || [];
      this.renderStats(res.data);
    }).catch(() => { App.hideLoader(); App.toast("ไม่สามารถโหลดข้อมูลสถิติได้"); });
  },

  goDocs: function(status) {
    App.Docs.state = Object.assign(App.Docs.defaultState(), { status });
    App.navigate('dashboard');
  },

  renderStats: function(d) {
    const week = d.docsThisWeek.reduce((s, x) => s + x.Count, 0);
    const card = (num, label, icon, color, bg, status) => `
      <div class="card stat-card ${status !== undefined ? 'link' : ''}" style="border-left-color:${color};" ${status !== undefined ? `onclick="App.Analytics.goDocs('${status}')"` : ''}>
        <div class="ico" style="background:${bg}; color:${color};"><span class="material-icons-round" style="font-size:28px;">${icon}</span></div>
        <div><div class="num">${num}</div><div class="lbl">${label}</div></div>
      </div>`;
    document.getElementById('stat-cards').innerHTML = [
      card(d.totalDocs.toLocaleString(), 'เอกสารทั้งหมด', 'description', '#2563eb', '#eff6ff', ''),
      card(week.toLocaleString(), 'นำเข้าใน 7 วัน', 'cloud_done', '#10b981', '#ecfdf5'),
      card(d.pendingReview.toLocaleString(), 'รอตรวจสอบ/อนุมัติ', 'pending_actions', '#7c3aed', '#f5f3ff', 'pending'),
      card(d.expiringSoon.toLocaleString(), `ครบอายุใน ${d.expiryWarningDays} วัน`, 'schedule', '#f59e0b', '#fffbeb', 'expiring'),
      card(d.expired.toLocaleString(), 'ครบอายุการเก็บแล้ว', 'history', '#ef4444', '#fef2f2', 'expired'),
      card(d.destructionRequests.toLocaleString(), 'คำขอทำลายรออนุมัติ', 'delete_sweep', '#b91c1c', '#fef2f2', 'destruction'),
      card(d.inTrash.toLocaleString(), 'อยู่ในถังขยะ', 'delete', '#64748b', '#f1f5f9', 'trash'),
      card(App.fmtBytes(d.storageBytes), `ไฟล์เอกสาร · ผู้ใช้แอคทีฟ ${d.activeUsers} คน`, 'storage', '#0ea5e9', '#f0f9ff'),
    ].join('');

    this.renderLogTable(this.allLogs);

    if (this.chartUpload) this.chartUpload.destroy();
    if (this.chartFolder) this.chartFolder.destroy();
    this.chartUpload = new Chart(document.getElementById('uploadChart'), {
      type: 'bar',
      data: {
        labels: d.docsThisWeek.map(x => App.fmtDate(x.Date).replace(/\/\d+$/, '')),
        datasets: [{ label: 'จำนวนเอกสาร', data: d.docsThisWeek.map(x => x.Count), backgroundColor: '#3b82f6', hoverBackgroundColor: '#1d4ed8', borderRadius: 8, borderSkipped: false }],
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { grid: { color: '#f1f5f9' }, ticks: { precision: 0 } }, x: { grid: { display: false } } } },
    });
    this.chartFolder = new Chart(document.getElementById('folderChart'), {
      type: 'doughnut',
      data: {
        labels: d.docsByFolder.map(x => x.FolderName),
        datasets: [{ data: d.docsByFolder.map(x => parseInt(x.Count, 10)), backgroundColor: ['#2563eb', '#38bdf8', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#14b8a6', '#94a3b8'], borderWidth: 2, borderColor: '#ffffff' }],
      },
      options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'right', labels: { boxWidth: 12, padding: 8, font: { size: 11 } } } } },
    });
  },

  filterLogs: function() {
    const q = (document.getElementById('auditLogSearch')?.value || '').trim().toLowerCase();
    const a = (document.getElementById('auditLogActionFilter')?.value || '').toLowerCase();
    const groups = { view: ['view', 'download'], delete: ['delete', 'purge'], destruction: ['destruction'] };
    this.renderLogTable(this.allLogs.filter(log => {
      const act = (log.Action || '').toLowerCase();
      const matchQ = !q || [log.Username, log.Action, log.Details, log.IPAddress, log.DocumentID].some(v => v && String(v).toLowerCase().includes(q));
      const matchA = !a || (groups[a] || [a]).some(k => act.includes(k));
      return matchQ && matchA;
    }));
  },

  renderLogTable: function(logs) {
    const body = document.getElementById('audit-log-body');
    if (!body) return;
    if (!logs.length) {
      body.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:30px; color:var(--text-muted);">ไม่พบประวัติที่ตรงกับเงื่อนไข</td></tr>`;
      return;
    }
    body.innerHTML = logs.map(log => {
      const act = (log.Action || '').toLowerCase();
      let cls = 'muted';
      if (act.includes('login')) cls = 'info';
      else if (act.includes('upload') || act.includes('create') || act.includes('scan complete') || act.includes('restore')) cls = 'success';
      else if (act.includes('delete') || act.includes('purge') || act.includes('fail') || act.includes('destruction')) cls = 'danger';
      else if (act.includes('update') || act.includes('workflow')) cls = 'warning';
      return `<tr>
        <td style="padding-left:24px; color:var(--text-muted); font-size:13px; white-space:nowrap;">${App.fmtDate(log.CreatedAt, true)}</td>
        <td style="font-weight:600;">${App.escapeHtml(log.Username)}</td>
        <td><span class="badge ${cls}">${App.escapeHtml(log.Action)}</span></td>
        <td style="color:#334155; font-size:13.5px;">${log.DocumentID ? `<span class="doc-id" onclick="App.Detail.open(${App.jsArg(log.DocumentID)})">${App.escapeHtml(log.DocumentID)}</span> · ` : ''}${App.escapeHtml(log.Details)}</td>
        <td class="hide-sm mono" style="color:var(--text-muted);">${App.escapeHtml(log.IPAddress)}</td>
      </tr>`;
    }).join('');
  },
};

// Close modals with Escape
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  const open = Array.from(document.querySelectorAll('.modal')).filter(m => m.style.display === 'flex').pop();
  if (!open) return;
  if (open.id === 'edit-modal') App.Editor.close();
  else if (open.id === 'viewer-modal') App.Viewer.close();
  else if (open.id === 'detail-modal') App.Detail.close();
  else open.style.display = 'none';
});

window.onload = function() { App.init(); };
