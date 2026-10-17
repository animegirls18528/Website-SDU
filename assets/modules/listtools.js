/* Document list extras: advanced filters, saved searches, multi-select with bulk actions. */
App.ListTools = {
  ADV_KEYS: ['fiscalYear', 'amountMin', 'amountMax', 'department', 'fieldName', 'fieldValue', 'locationId', 'loan', 'pii'],
  locations: null,
  saved: [],

  init: async function() {
    if (!this.locations) {
      try { this.locations = (await App.api('api/locations.php', { action: 'list' })).locations; } catch (e) { this.locations = []; }
    }
    this.renderAdvanced();
    this.loadSaved();
    this.updateBar();
  },

  advancedCount: function() {
    const s = App.Docs.state;
    return this.ADV_KEYS.filter(k => s[k] !== '' && s[k] !== undefined && !(k === 'fieldName' && !s.fieldValue)).length;
  },

  renderAdvanced: function() {
    const el = document.getElementById('adv-panel');
    if (!el) return;
    const s = App.Docs.state;
    const fy = new Date().getFullYear() + 543 + (new Date().getMonth() >= 9 ? 1 : 0);
    const years = Array.from({ length: 20 }, (_, i) => fy - i);
    const fields = Array.from(new Set([...App.data.customFields.map(f => f.FieldName), 'เลขที่', 'ปีงบประมาณ', 'จำนวนเงิน', 'แหล่งเงิน', 'หมวดรายจ่าย', 'เลขที่ลงบัญชี', 'รายละเอียดการเบิกจ่าย', 'หน่วยงาน']));
    const n = this.advancedCount();
    document.getElementById('adv-toggle-count').textContent = n ? ` (${n})` : '';
    el.innerHTML = `
      <div><label>ปีงบประมาณ</label><select class="form-control" data-adv="fiscalYear"><option value="">ทั้งหมด</option>${years.map(y => `<option ${String(s.fiscalYear) === String(y) ? 'selected' : ''}>${y}</option>`).join('')}</select></div>
      <div><label>จำนวนเงินตั้งแต่</label><input type="number" class="form-control" data-adv="amountMin" value="${App.escapeHtml(s.amountMin)}" placeholder="บาท"></div>
      <div><label>ถึง</label><input type="number" class="form-control" data-adv="amountMax" value="${App.escapeHtml(s.amountMax)}" placeholder="บาท"></div>
      <div><label>หน่วยงาน</label><input type="text" class="form-control" data-adv="department" value="${App.escapeHtml(s.department)}" placeholder="บางส่วนของชื่อ"></div>
      <div style="min-width:180px;"><label>ช่องข้อมูล</label><select class="form-control" data-adv="fieldName"><option value="">— เลือกช่อง —</option>${fields.map(f => `<option ${s.fieldName === f ? 'selected' : ''}>${App.escapeHtml(f)}</option>`).join('')}</select></div>
      <div><label>มีคำว่า</label><input type="text" class="form-control" data-adv="fieldValue" value="${App.escapeHtml(s.fieldValue)}"></div>
      <div style="min-width:180px;"><label>ที่เก็บตัวจริง</label><select class="form-control" data-adv="locationId"><option value="">ทั้งหมด</option><option value="none" ${s.locationId === 'none' ? 'selected' : ''}>— ยังไม่ระบุ —</option>${(this.locations || []).map(l => `<option value="${l.ID}" ${String(s.locationId) === String(l.ID) ? 'selected' : ''}>${App.escapeHtml(l.Label)}</option>`).join('')}</select></div>
      <div><label>การยืมตัวจริง</label><select class="form-control" data-adv="loan"><option value="">ทั้งหมด</option><option value="onloan" ${s.loan === 'onloan' ? 'selected' : ''}>กำลังถูกยืม</option><option value="overdue" ${s.loan === 'overdue' ? 'selected' : ''}>เกินกำหนดคืน</option></select></div>
      <div><label>ข้อมูลส่วนบุคคล</label><select class="form-control" data-adv="pii"><option value="">ทั้งหมด</option><option value="1" ${s.pii === '1' ? 'selected' : ''}>มีข้อมูลส่วนบุคคล</option></select></div>
      <div style="display:flex; gap:6px; align-items:flex-end;">
        <button class="btn btn-primary" style="padding:8px 14px;" onclick="App.ListTools.applyAdvanced()">ค้นหา</button>
        <button class="btn btn-outline" style="padding:8px 12px;" onclick="App.ListTools.clearAdvanced()">ล้าง</button>
      </div>`;
    el.querySelectorAll('input[data-adv]').forEach(i => i.addEventListener('keydown', e => { if (e.key === 'Enter') this.applyAdvanced(); }));
  },

  toggleAdvanced: function() {
    const el = document.getElementById('adv-panel');
    el.style.display = el.style.display === 'none' ? 'flex' : 'none';
  },

  applyAdvanced: function() {
    document.querySelectorAll('#adv-panel [data-adv]').forEach(el => { App.Docs.state[el.dataset.adv] = el.value.trim(); });
    App.Docs.state.page = 1;
    document.getElementById('adv-toggle-count').textContent = this.advancedCount() ? ` (${this.advancedCount()})` : '';
    App.Docs.load();
  },

  clearAdvanced: function() {
    this.ADV_KEYS.forEach(k => App.Docs.state[k] = '');
    this.renderAdvanced();
    this.applyAdvanced();
  },

  // ---------- saved searches ----------
  loadSaved: async function() {
    try { this.saved = (await App.api('api/saved.php', { action: 'list' })).saved; } catch (e) { this.saved = []; }
    const sel = document.getElementById('saved-select');
    if (!sel) return;
    sel.innerHTML = `<option value="">— การค้นหาที่บันทึกไว้ (${this.saved.length}) —</option>` +
      this.saved.map(s => `<option value="${s.ID}">${App.escapeHtml(s.Name)}</option>`).join('');
  },

  useSaved: function(id) {
    const s = this.saved.find(x => String(x.ID) === String(id));
    if (!s) return;
    App.Docs.state = Object.assign(App.Docs.defaultState(), s.Filters, { page: 1 });
    App.Docs.init();
    App.toast(`ใช้เงื่อนไข "${s.Name}"`, 'info');
  },

  saveCurrent: async function() {
    const r = await App.Dialog.form({
      title: 'บันทึกเงื่อนไขการค้นหา', icon: 'bookmark_add',
      message: 'บันทึกคำค้น ตัวกรอง และการเรียงลำดับปัจจุบันไว้ใช้ซ้ำ (ชื่อซ้ำจะบันทึกทับ)',
      fields: [{ name: 'name', label: 'ชื่อ', required: true, placeholder: 'เช่น ฎีกางบรายได้ ปี 2568 เกิน 1 แสน' }],
      confirmText: 'บันทึก',
    });
    if (!r) return;
    const filters = Object.assign({}, App.Docs.state);
    delete filters.page; delete filters.pageSize;
    App.api('api/saved.php', { action: 'save', Name: r.name, Filters: filters })
      .then(() => { App.toast('บันทึกแล้ว', 'success'); this.loadSaved(); })
      .catch(e => App.toast(e.message));
  },

  deleteSaved: async function() {
    const id = document.getElementById('saved-select').value;
    const s = this.saved.find(x => String(x.ID) === String(id));
    if (!s) { App.toast('เลือกรายการที่จะลบก่อน', 'info'); return; }
    if (!await App.Dialog.confirm({ title: 'ลบเงื่อนไขที่บันทึกไว้?', icon: 'bookmark_remove', tone: 'danger', message: `"${s.Name}"`, confirmText: 'ลบ' })) return;
    App.api('api/saved.php', { action: 'delete', ID: s.ID }).then(() => this.loadSaved());
  },

  // ---------- selection & bulk actions ----------
  toggle: function(id, box) {
    if (box.checked) App.Docs.selected.add(id); else App.Docs.selected.delete(id);
    box.closest('tr').classList.toggle('row-selected', box.checked);
    this.updateBar();
  },

  selectPage: function(on) {
    (App.Docs.lastPageIds || []).forEach(id => on ? App.Docs.selected.add(id) : App.Docs.selected.delete(id));
    document.querySelectorAll('#doc-table-body .sel-cell input').forEach(b => { b.checked = on; b.closest('tr').classList.toggle('row-selected', on); });
    this.updateBar();
  },

  clear: function() { App.Docs.selected.clear(); this.selectPage(false); },

  updateBar: function() {
    const bar = document.getElementById('bulk-bar');
    if (!bar) return;
    const n = App.Docs.selected.size;
    bar.style.display = n ? 'flex' : 'none';
    const c = document.getElementById('bulk-count');
    if (c) c.textContent = `เลือก ${n.toLocaleString()} เอกสาร`;
    const all = document.getElementById('sel-all');
    if (all) {
      const ids = App.Docs.lastPageIds || [];
      const sel = ids.filter(id => App.Docs.selected.has(id)).length;
      all.checked = ids.length > 0 && sel === ids.length;
      all.indeterminate = sel > 0 && sel < ids.length;
    }
  },

  ids: function() { return Array.from(App.Docs.selected); },

  run: async function(op, extra, okText) {
    try {
      App.showLoader('กำลังดำเนินการ...');
      const r = await App.api('api/bulk.php', Object.assign({ op, IDs: this.ids() }, extra || {}));
      App.hideLoader();
      App.toast(`${okText} ${r.done.toLocaleString()} เอกสาร${r.skipped ? ` · ข้าม ${r.skipped} (สถานะไม่ตรงหรือไม่มีสิทธิ์)` : ''}`, 'success');
      App.Docs.selected.clear();
      App.reloadMeta();
      App.Docs.load();
    } catch (e) { App.hideLoader(); App.toast(e.message); }
  },

  bulkMove: async function() {
    const folder = await this.pick(`ย้ายแฟ้ม ${App.Docs.selected.size} เอกสาร`, 'drive_file_move',
      App.data.folders.map(f => ({ id: String(f.ID), label: f.FolderName })).concat([{ id: '', label: '— ไม่มีแฟ้ม —', muted: true }]));
    if (folder !== null) this.run('move', { FolderId: folder }, 'ย้ายแฟ้มแล้ว');
  },

  /** List picker with a filter box: resolves the chosen option id, or null when cancelled. */
  pick: function(title, icon, options) {
    return new Promise(resolve => {
      const wrap = document.createElement('div');
      wrap.className = 'dlg-backdrop show';
      wrap.innerHTML = `<div class="dlg" style="opacity:1; transform:none;">
        <div class="dlg-head"><div class="dlg-icon"><span class="material-icons-round">${icon}</span></div><div class="dlg-titles"><h3>${App.escapeHtml(title)}</h3></div></div>
        <input class="form-control" placeholder="กรอง..." style="margin-top:14px;">
        <div class="pick-list" style="max-height:300px; overflow-y:auto; margin-top:8px; display:flex; flex-direction:column; gap:4px;">
          ${options.map(o => `<button class="btn btn-outline" style="justify-content:flex-start; ${o.muted ? 'color:var(--text-muted);' : ''}" data-id="${App.escapeHtml(o.id)}">${App.escapeHtml(o.label)}</button>`).join('')}
        </div>
        <div class="dlg-actions"><button class="btn btn-outline" data-cancel>ยกเลิก</button></div></div>`;
      document.body.appendChild(wrap);
      const input = wrap.querySelector('input');
      input.focus();
      input.addEventListener('input', () => wrap.querySelectorAll('.pick-list button').forEach(b => b.style.display = b.textContent.toLowerCase().includes(input.value.toLowerCase()) ? '' : 'none'));
      wrap.addEventListener('keydown', e => { e.stopPropagation(); if (e.key === 'Escape') { wrap.remove(); resolve(null); } });
      wrap.addEventListener('click', e => {
        const b = e.target.closest('button');
        if (e.target === wrap || (b && b.hasAttribute('data-cancel'))) { wrap.remove(); resolve(null); return; }
        if (b && b.dataset.id !== undefined) { wrap.remove(); resolve(b.dataset.id); }
      });
    });
  },
  bulkTags: async function() {
    const r = await App.Dialog.form({ title: `เพิ่มแท็กให้ ${App.Docs.selected.size} เอกสาร`, icon: 'sell',
      fields: [{ name: 'tags', label: 'แท็ก (คั่นด้วยจุลภาค)', required: true, placeholder: 'เช่น ตรวจสอบแล้ว, สตง.2569' }], confirmText: 'เพิ่มแท็ก' });
    if (r) this.run('addTags', { Tags: r.tags }, 'เพิ่มแท็กแล้ว');
  },

  bulkSubmit: function() { this.run('submit', {}, 'ส่งตรวจสอบแล้ว'); },

  bulkApprove: async function() {
    if (!await App.Dialog.confirm({ title: `อนุมัติ ${App.Docs.selected.size} เอกสาร?`, icon: 'task_alt', message: 'อนุมัติเฉพาะเอกสารที่อยู่ในสถานะ "รอตรวจสอบ"', confirmText: 'อนุมัติ' })) return;
    this.run('approve', {}, 'อนุมัติแล้ว');
  },

  bulkLocation: async function() {
    const locs = this.locations || [];
    if (!locs.length) { App.toast('ยังไม่มีที่เก็บตัวจริง — เพิ่มได้ที่ ตั้งค่า › ที่เก็บตัวจริง', 'info'); return; }
    const loc = await this.pick(`ที่เก็บตัวจริงของ ${App.Docs.selected.size} เอกสาร`, 'inventory_2',
      locs.map(l => ({ id: String(l.ID), label: l.Label })).concat([{ id: '', label: '— ล้างที่เก็บ —', muted: true }]));
    if (loc !== null) this.run('setLocation', { LocationId: loc }, 'กำหนดที่เก็บแล้ว');
  },
  bulkCovers: function() {
    const ids = this.ids();
    if (ids.length > 200) { App.toast('พิมพ์ใบปะหน้าได้ครั้งละไม่เกิน 200 ใบ', 'info'); return; }
    window.open('cover_print.php?ids=' + encodeURIComponent(ids.join(',')), '_blank');
  },

  bulkShare: function() { App.Share.create(this.ids()); },

  bulkTrash: async function() {
    const r = await App.Dialog.form({ title: `ย้าย ${App.Docs.selected.size} เอกสารไปถังขยะ?`, icon: 'delete_outline', tone: 'danger',
      fields: [{ name: 'reason', label: 'เหตุผล (ถ้ามี)' }], note: 'กู้คืนได้จากตัวกรอง "ถังขยะ" จนกว่าจะครบกำหนดลบถาวร', confirmText: 'ย้ายไปถังขยะ' });
    if (r) this.run('trash', { Reason: r.reason }, 'ย้ายไปถังขยะแล้ว');
  },

  bulkRestore: function() { this.run('restore', {}, 'กู้คืนแล้ว'); },
};
