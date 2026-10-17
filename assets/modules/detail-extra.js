/* Document detail extras: physical copy & loans, related documents, PDPA flag, editing pages of a saved file. */
App.DetailExtra = {
  addDays: function(n) {
    const d = new Date(Date.now() + n * 86400000);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  },

  // ---------- physical original & loans ----------
  physicalTab: function(d) {
    setTimeout(() => this.loadPhysical(d), 0);
    return `<div id="phys-body" style="color:var(--text-muted);">กำลังโหลด...</div>`;
  },

  loadPhysical: async function(d) {
    const el = document.getElementById('phys-body');
    if (!el) return;
    const edit = App.canEdit() && !d.DeletedAt;
    let locs = [], loans = [];
    try {
      [locs, loans] = await Promise.all([
        App.api('api/locations.php', { action: 'list' }).then(r => r.locations),
        App.api('api/locations.php', { action: 'loans', filter: 'all', DocumentID: d.ID }).then(r => r.loans),
      ]);
    } catch (e) { el.innerHTML = `<div class="notice danger">${App.escapeHtml(e.message)}</div>`; return; }
    const open = loans.find(l => !l.ReturnedAt);
    const id = App.jsArg(d.ID);
    el.innerHTML = `
      <h4 class="sec-title"><span class="material-icons-round">inventory_2</span> ที่เก็บต้นฉบับกระดาษ</h4>
      ${edit ? `<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
          <div style="flex:2; min-width:220px;"><label class="mini-label">ห้อง › ตู้ › ชั้น › กล่อง</label>
            <select id="loc-select" class="form-control"><option value="">— ยังไม่ระบุ —</option>${locs.map(l => `<option value="${l.ID}" ${String(l.ID) === String(d.LocationId) ? 'selected' : ''}>${App.escapeHtml(l.Label)}</option>`).join('')}</select></div>
          <div style="flex:1; min-width:160px;"><label class="mini-label">หมายเหตุ เช่น ลำดับในกล่อง</label><input id="loc-note" class="form-control" value="${App.escapeHtml(d.LocationNote || '')}"></div>
          <button class="btn btn-primary" style="padding:10px 16px;" onclick="App.DetailExtra.saveLocation(${id})">บันทึกที่เก็บ</button>
        </div>
        ${!locs.length ? '<div class="doc-sub" style="margin-top:6px;">ยังไม่มีรายการที่เก็บ — ผู้ดูแลระบบเพิ่มได้ที่ ตั้งค่า › ที่เก็บตัวจริง</div>' : ''}`
        : `<div>${d.LocationLabel ? App.escapeHtml(d.LocationLabel) : 'ยังไม่ระบุ'}</div>`}

      <h4 class="sec-title" style="margin-top:22px;"><span class="material-icons-round">assignment_return</span> การยืม-คืนตัวจริง</h4>
      ${open ? `<div class="notice ${open.Overdue ? 'danger' : 'info'}" style="align-items:center;"><span class="material-icons-round">person</span>
          <div style="flex:1;">ยืมโดย <b>${App.escapeHtml(open.BorrowerName)}</b>${open.Purpose ? ' · ' + App.escapeHtml(open.Purpose) : ''}<br>
          ตั้งแต่ ${App.fmtDate(open.LoanedAt, true)} · กำหนดคืน ${App.fmtDate(open.DueDate)}${open.Overdue ? ' <b>(เกินกำหนด)</b>' : ''}</div>
          ${edit ? `<button class="btn btn-primary" style="padding:8px 14px;" onclick="App.DetailExtra.returnLoan(${open.ID})">บันทึกการคืน</button>` : ''}</div>`
        : edit ? `<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
          <div style="flex:1.4; min-width:180px;"><label class="mini-label">ผู้ยืม *</label><input id="loan-name" class="form-control" placeholder="ชื่อ-สกุล / หน่วยงาน"></div>
          <div style="flex:1.4; min-width:180px;"><label class="mini-label">วัตถุประสงค์</label><input id="loan-purpose" class="form-control" placeholder="เช่น ส่งตรวจ สตง."></div>
          <div style="width:160px;"><label class="mini-label">กำหนดคืน *</label><input id="loan-due" type="date" class="form-control" value="${this.addDays(7)}"></div>
          <button class="btn btn-primary" style="padding:10px 16px;" onclick="App.DetailExtra.borrow(${id})">บันทึกการยืม</button></div>`
        : '<div style="color:var(--text-muted);">ตัวจริงอยู่ที่ที่เก็บ</div>'}

      ${loans.length ? `<table class="data-table" style="margin-top:14px;"><thead><tr><th>ผู้ยืม</th><th>วันที่ยืม</th><th>กำหนดคืน</th><th>คืนเมื่อ</th><th class="hide-sm">บันทึกโดย</th></tr></thead><tbody>
        ${loans.map(l => `<tr><td>${App.escapeHtml(l.BorrowerName)}${l.Purpose ? `<div class="doc-sub">${App.escapeHtml(l.Purpose)}</div>` : ''}</td>
          <td style="white-space:nowrap;">${App.fmtDate(l.LoanedAt)}</td><td style="white-space:nowrap;">${App.fmtDate(l.DueDate)}</td>
          <td style="white-space:nowrap;">${l.ReturnedAt ? App.fmtDate(l.ReturnedAt, true) : `<span class="badge ${l.Overdue ? 'danger' : 'info'}">${l.Overdue ? 'เกินกำหนด' : 'ยังไม่คืน'}</span>`}</td>
          <td class="hide-sm" style="font-size:13px;">${App.escapeHtml(l.LoanedByName || '-')}${l.ReturnedByName ? ' / ' + App.escapeHtml(l.ReturnedByName) : ''}</td></tr>`).join('')}
      </tbody></table>` : ''}`;
  },

  saveLocation: function(id) {
    App.api('api/locations.php', { action: 'setDocLocation', DocumentID: id, LocationId: document.getElementById('loc-select').value, LocationNote: document.getElementById('loc-note').value })
      .then(() => { App.toast('บันทึกที่เก็บแล้ว', 'success'); App.Detail.refresh(); })
      .catch(e => App.toast(e.message));
  },

  borrow: function(id) {
    const name = document.getElementById('loan-name').value.trim();
    if (!name) { App.toast('กรุณาระบุผู้ยืม'); return; }
    App.api('api/locations.php', { action: 'borrow', DocumentID: id, BorrowerName: name, Purpose: document.getElementById('loan-purpose').value, DueDate: document.getElementById('loan-due').value })
      .then(() => { App.toast('บันทึกการยืมแล้ว', 'success'); App.Detail.refresh(); })
      .catch(e => App.toast(e.message));
  },

  returnLoan: async function(loanId) {
    const r = await App.Dialog.form({ title: 'บันทึกการคืนเอกสาร', icon: 'assignment_returned', fields: [{ name: 'note', label: 'หมายเหตุ (ถ้ามี)', placeholder: 'เช่น เอกสารครบถ้วน' }], confirmText: 'บันทึกการคืน' });
    if (!r) return;
    App.api('api/locations.php', { action: 'return', LoanID: loanId, Note: r.note })
      .then(() => { App.toast('บันทึกการคืนแล้ว', 'success'); App.Detail.refresh(); if (App.currentView === 'loans') App.Loans.load(); })
      .catch(e => App.toast(e.message));
  },

  // ---------- related documents ----------
  relationTypes: ['ฎีกา', 'ใบเสร็จรับเงิน', 'สัญญา', 'ใบส่งของ', 'ใบสั่งซื้อ', 'บันทึกขออนุมัติ', 'เอกสารแนบ', 'ฉบับแก้ไข', 'เกี่ยวข้อง'],

  linksTab: function(d) {
    setTimeout(() => this.loadLinks(d), 0);
    return `<div id="links-body" style="color:var(--text-muted);">กำลังโหลด...</div>`;
  },

  loadLinks: async function(d) {
    const el = document.getElementById('links-body');
    if (!el) return;
    let links = [];
    try { links = (await App.api('api/links.php', { action: 'list', DocumentID: d.ID })).links; } catch (e) { el.innerHTML = App.escapeHtml(e.message); return; }
    const edit = App.canEdit() && !d.DeletedAt;
    el.innerHTML = `
      ${links.length ? links.map(l => `<div class="file-row">
          <span class="material-icons-round" style="color:var(--primary);">link</span>
          <div class="meta"><div class="name"><span class="badge info" style="padding:1px 8px;">${App.escapeHtml(l.RelationType)}</span>
            <span class="doc-id" onclick="App.Detail.open(${App.jsArg(l.ID)})">${App.escapeHtml(l.ID)}</span> ${App.escapeHtml(l.Filename)}</div>
            <div class="sub">${l.Note ? App.escapeHtml(l.Note) + ' · ' : ''}เชื่อมโดย ${App.escapeHtml(l.CreatedByName || '-')} ${App.fmtDate(l.CreatedAt)}${+l.HasFiles ? '' : ' · ไม่มีไฟล์'}</div></div>
          ${edit ? `<button class="btn btn-outline btn-icon" title="ยกเลิกการเชื่อม" style="color:var(--danger);" onclick="App.DetailExtra.removeLink(${l.LinkID})"><span class="material-icons-round" style="font-size:18px;">link_off</span></button>` : ''}
        </div>`).join('') : '<div style="margin-bottom:12px;">ยังไม่มีเอกสารที่เกี่ยวข้อง</div>'}
      ${edit ? `<h4 class="sec-title" style="margin-top:16px;"><span class="material-icons-round">add_link</span> เชื่อมกับเอกสารอื่น</h4>
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; position:relative;">
          <div style="flex:2; min-width:220px; position:relative;"><label class="mini-label">ค้นหาเอกสาร (รหัสหรือชื่อเรื่อง)</label>
            <input id="link-q" class="form-control" autocomplete="off" placeholder="พิมพ์อย่างน้อย 2 ตัวอักษร" oninput="App.DetailExtra.searchLink(this.value)">
            <div id="link-results" class="suggest-box" style="display:none;"></div></div>
          <div style="width:180px;"><label class="mini-label">เอกสารนั้นคือ</label><select id="link-type" class="form-control">${this.relationTypes.map(t => `<option>${t}</option>`).join('')}</select></div>
          <div style="flex:1; min-width:150px;"><label class="mini-label">หมายเหตุ</label><input id="link-note" class="form-control"></div>
          <button class="btn btn-primary" style="padding:10px 16px;" onclick="App.DetailExtra.addLink(${App.jsArg(d.ID)})">เชื่อม</button>
        </div>` : ''}`;
  },

  searchLink: App.debounce(async function(q) {
    const box = document.getElementById('link-results');
    if (!box) return;
    App.DetailExtra.linkTarget = null;
    if (q.trim().length < 2) { box.style.display = 'none'; return; }
    try {
      const r = await App.api('api/search.php', { q, pageSize: 10 });
      const docs = r.documents.filter(x => !App.Detail.doc || x.ID !== App.Detail.doc.ID);
      box.innerHTML = docs.length ? docs.map(x => `<div class="suggest-item" onclick="App.DetailExtra.chooseLink(${App.jsArg(x.ID)}, ${App.jsArg(x.ID + ' ' + x.Filename)})"><b>${App.escapeHtml(x.ID)}</b> ${App.escapeHtml(x.Filename)}</div>`).join('')
        : '<div class="suggest-item" style="color:var(--text-muted);">ไม่พบเอกสาร</div>';
      box.style.display = 'block';
    } catch (e) { box.style.display = 'none'; }
  }, 300),

  chooseLink: function(id, label) {
    this.linkTarget = id;
    document.getElementById('link-q').value = label;
    document.getElementById('link-results').style.display = 'none';
  },

  addLink: function(docId) {
    if (!this.linkTarget) { App.toast('เลือกเอกสารจากรายการค้นหาก่อน', 'info'); return; }
    App.api('api/links.php', { action: 'add', DocA: docId, DocB: this.linkTarget, RelationType: document.getElementById('link-type').value, Note: document.getElementById('link-note').value })
      .then(() => { App.toast('เชื่อมเอกสารแล้ว', 'success'); this.linkTarget = null; this.loadLinks(App.Detail.doc); })
      .catch(e => App.toast(e.message));
  },

  removeLink: async function(linkId) {
    if (!await App.Dialog.confirm({ title: 'ยกเลิกการเชื่อมโยง?', icon: 'link_off', tone: 'warning', message: 'เอกสารทั้งสองยังอยู่ครบ แค่ไม่แสดงว่าเกี่ยวข้องกัน', confirmText: 'ยกเลิกการเชื่อม' })) return;
    App.api('api/links.php', { action: 'remove', LinkID: linkId }).then(() => this.loadLinks(App.Detail.doc)).catch(e => App.toast(e.message));
  },

  // ---------- PDPA ----------
  togglePii: async function() {
    const d = App.Detail.doc;
    if (d.PiiFlag) {
      if (!await App.Dialog.confirm({ title: 'ยกเลิกการระบุข้อมูลส่วนบุคคล?', icon: 'shield', tone: 'warning',
        message: 'ระบบจะตรวจข้อความในไฟล์ใหม่อีกครั้ง ถ้ายังพบเลขบัตรประชาชน/เลขบัญชี จะกลับมาเป็น "ตรวจพบอัตโนมัติ"', confirmText: 'ยกเลิกการระบุ' })) return;
      App.Detail.act({ action: 'setPii', ID: d.ID, flag: 0 }, 'อัปเดตแล้ว');
    } else {
      const r = await App.Dialog.form({ title: 'ระบุว่ามีข้อมูลส่วนบุคคล', icon: 'privacy_tip',
        message: 'ผู้ที่ไม่มีสิทธิ์ PDPA จะเปิดไฟล์ของเอกสารนี้ไม่ได้ และการเปิดดูทุกครั้งจะอยู่ในรายงาน PDPA',
        fields: [{ name: 'summary', label: 'ข้อมูลที่มี', placeholder: 'เช่น สำเนาบัตรประชาชน, สมุดบัญชีธนาคาร' }], confirmText: 'บันทึก' });
      if (r) App.Detail.act({ action: 'setPii', ID: d.ID, flag: 1, Summary: r.summary }, 'ระบุข้อมูลส่วนบุคคลแล้ว');
    }
  },

  // ---------- edit pages of a saved file ----------
  editPages: function(fileRowId) {
    const d = App.Detail.doc;
    const f = d.Files.find(x => String(x.ID) === String(fileRowId));
    if (!f) return;
    // Work on the original (before the QR stamp) so the new version gets a single, fresh stamp
    App.PageEditor.open(null, [], {
      title: `แก้ไขหน้า: ${d.ID} · ${f.OriginalName}`,
      items: [{ fileId: 'uploads/' + (f.OriginalPath || f.StoredPath), name: f.OriginalName, format: 'pdf' }],
      onSaved: async (res, pages) => {
        App.showLoader('กำลังบันทึกเวอร์ชันใหม่และประทับ QR Code...');
        const r = await App.api('api/document.php', { action: 'replaceFile', ID: d.ID, FileRowId: f.ID, newFileId: [res.fileId], RowVersion: d.RowVersion });
        App.toast(`บันทึกแล้ว ${pages} หน้า (ไฟล์เดิมอยู่ในแท็บเวอร์ชันเก่า)`, 'success');
        if (r.stampFailed && r.stampFailed.length) App.toast('ประทับ QR ไม่ได้ จัดเก็บไฟล์ต้นฉบับแทน', 'info');
        App.Detail.tabName = 'files';
        App.Detail.refresh();
      },
    });
  },
};

/* Reviewer links (admin): share selected documents with an external auditor for a limited time. */
App.Share = {
  create: async function(ids) {
    if (!ids.length) return;
    const r = await App.Dialog.form({
      title: `ลิงก์สำหรับผู้ตรวจสอบ (${ids.length} เอกสาร)`, icon: 'share',
      message: 'ผู้รับเปิดดูได้โดยไม่ต้องมีบัญชี เห็นเฉพาะเอกสารที่เลือก ทุกไฟล์มีลายน้ำ และระบบบันทึกการเปิดทุกครั้ง',
      fields: [
        { name: 'recipient', label: 'ผู้รับ', required: true, placeholder: 'เช่น สำนักงานการตรวจเงินแผ่นดิน ภูมิภาคที่ 1' },
        { name: 'note', label: 'หมายเหตุ', placeholder: 'เช่น เอกสารประกอบการตรวจสอบงบปี 2568' },
        { name: 'expires', label: 'ใช้ได้ถึงวันที่ (ไม่เกิน 180 วัน)', type: 'date', value: App.DetailExtra.addDays(14), required: true },
        { name: 'download', label: 'สิทธิ์', type: 'checkbox', checkLabel: 'อนุญาตให้ดาวน์โหลดไฟล์ (มีลายน้ำ)' },
      ],
      confirmText: 'สร้างลิงก์',
    });
    if (!r) return;
    try {
      const res = await App.api('api/share.php', { action: 'create', Recipient: r.recipient, Note: r.note, ExpiresAt: r.expires, AllowDownload: r.download === '1', IDs: ids });
      await App.Dialog.form({
        title: 'สร้างลิงก์แล้ว', icon: 'link', message: `ส่งลิงก์นี้ให้ ${r.recipient} (${res.count} เอกสาร) · ลิงก์จะแสดงครั้งเดียว ระบบเก็บแค่ค่าที่ตรวจสอบได้`,
        fields: [{ name: 'url', label: 'ลิงก์', value: res.url }], note: 'ยกเลิกลิงก์ได้ที่ ตั้งค่า › ลิงก์ผู้ตรวจ', confirmText: 'คัดลอกและปิด', cancelText: 'ปิด',
      }).then(x => { if (x && navigator.clipboard) navigator.clipboard.writeText(res.url).then(() => App.toast('คัดลอกลิงก์แล้ว', 'success')).catch(() => {}); });
      if (App.Docs.selected) App.Docs.selected.clear();
      if (App.ListTools) App.ListTools.updateBar();
    } catch (e) { App.toast(e.message); }
  },
};
