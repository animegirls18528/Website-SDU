/* Management reports (admin). */
App.Reports = {
  current: 'fiscal',
  chart: null,

  init: function() { this.tab('fiscal'); },

  tab: function(name) {
    this.current = name;
    document.querySelectorAll('#report-tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
    if (this.chart) { this.chart.destroy(); this.chart = null; }
    this[name]();
  },

  box: function() { return document.getElementById('report-body'); },

  csv: function(name, rows) {
    const text = '﻿' + rows.map(r => r.map(c => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
    a.download = name + '.csv';
    a.click();
  },

  fiscal: async function() {
    const el = this.box();
    el.innerHTML = 'กำลังโหลด...';
    const r = await App.api('api/reports.php', { report: 'fiscal' });
    const years = [...new Set(r.rows.map(x => x.FY))];
    const prefixes = [...new Set(r.rows.map(x => x.Prefix))];
    const get = (fy, p) => r.rows.find(x => x.FY === fy && x.Prefix === p);
    this.lastCsv = [['ปีงบประมาณ', 'ประเภท', 'ชื่อประเภท', 'เอกสาร', 'มีไฟล์'], ...r.rows.map(x => [x.FY || 'ไม่ระบุ', x.Prefix, x.PrefixName, x.Docs, x.WithFiles])];
    el.innerHTML = `
      <div style="position:relative; height:260px; margin-bottom:18px;"><canvas id="rep-chart"></canvas></div>
      <div class="table-responsive"><table class="data-table"><thead><tr><th>ปีงบประมาณ</th>${prefixes.map(p => `<th style="text-align:right;">${App.escapeHtml(p || '-')}<div class="doc-sub" style="text-transform:none;">${App.escapeHtml((r.rows.find(x => x.Prefix === p) || {}).PrefixName || '')}</div></th>`).join('')}<th style="text-align:right;">รวม</th><th style="text-align:right;">มีไฟล์</th></tr></thead>
      <tbody>${years.map(fy => {
        const row = prefixes.map(p => (get(fy, p) || {}).Docs || 0);
        const files = prefixes.reduce((s, p) => s + ((get(fy, p) || {}).WithFiles || 0), 0);
        const total = row.reduce((a, b) => a + b, 0);
        return `<tr><td><b>${fy || 'ไม่ระบุวันที่'}</b></td>${row.map(n => `<td style="text-align:right;">${n.toLocaleString()}</td>`).join('')}<td style="text-align:right;"><b>${total.toLocaleString()}</b></td><td style="text-align:right;">${files.toLocaleString()} (${total ? Math.round(files / total * 100) : 0}%)</td></tr>`;
      }).join('')}</tbody></table></div>`;
    const ys = years.filter(Boolean).slice(0, 10).reverse();
    this.chart = new Chart(document.getElementById('rep-chart'), {
      type: 'bar',
      data: { labels: ys.map(String), datasets: prefixes.map((p, i) => ({ label: p || '-', data: ys.map(fy => (get(fy, p) || {}).Docs || 0), backgroundColor: ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6'][i % 6], borderRadius: 4 })) },
      options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, ticks: { precision: 0 } } } },
    });
  },

  productivity: async function() {
    const el = this.box();
    el.innerHTML = 'กำลังโหลด...';
    const r = await App.api('api/reports.php', { report: 'productivity', months: 6 });
    this.lastCsv = [['เดือน', 'ผู้ใช้', 'สร้างเอกสาร', 'แนบไฟล์', 'จำนวนหน้า', 'งานสแกน'], ...r.rows.map(x => [x.Month, x.Username, x.Docs, x.Files, x.Pages, x.Scans])];
    const users = [...new Set(r.rows.map(x => x.Username))];
    const months = [...new Set(r.rows.map(x => x.Month))].sort();
    el.innerHTML = `<p class="subtitle" style="margin-bottom:12px;">ตั้งแต่ ${App.fmtDate(r.since)} · นับจากเอกสารที่สร้าง ไฟล์ที่แนบ (จำนวนหน้า) และงานสแกนที่สำเร็จ</p>
      <div style="position:relative; height:260px; margin-bottom:18px;"><canvas id="rep-chart"></canvas></div>
      <div class="table-responsive"><table class="data-table"><thead><tr><th>เดือน</th><th>ผู้ใช้</th><th style="text-align:right;">สร้างเอกสาร</th><th style="text-align:right;">แนบไฟล์</th><th style="text-align:right;">จำนวนหน้า</th><th style="text-align:right;">งานสแกน</th></tr></thead>
      <tbody>${r.rows.length ? r.rows.map(x => `<tr><td>${App.escapeHtml(x.Month)}</td><td><b>${App.escapeHtml(x.Username)}</b></td><td style="text-align:right;">${(+x.Docs).toLocaleString()}</td><td style="text-align:right;">${(+x.Files).toLocaleString()}</td><td style="text-align:right;">${(+x.Pages).toLocaleString()}</td><td style="text-align:right;">${(+x.Scans).toLocaleString()}</td></tr>`).join('')
        : '<tr><td colspan="6" style="color:var(--text-muted); padding:20px;">ยังไม่มีข้อมูลในช่วงนี้</td></tr>'}</tbody></table></div>`;
    this.chart = new Chart(document.getElementById('rep-chart'), {
      type: 'bar',
      data: { labels: months, datasets: users.map((u, i) => ({ label: u + ' (หน้า)', data: months.map(m => +((r.rows.find(x => x.Month === m && x.Username === u) || {}).Pages || 0)), backgroundColor: ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6'][i % 6], borderRadius: 4 })) },
      options: { responsive: true, maintainAspectRatio: false, scales: { y: { ticks: { precision: 0 } } } },
    });
  },

  disk: async function() {
    const el = this.box();
    el.innerHTML = 'กำลังคำนวณ...';
    const r = await App.api('api/reports.php', { report: 'disk' });
    const used = r.totalBytes - r.freeBytes;
    const pct = r.totalBytes ? Math.round(used / r.totalBytes * 100) : 0;
    this.lastCsv = [['วันที่', 'ไบต์ที่เพิ่ม'], ...r.daily.map(d => [d.Day, d.Bytes])];
    el.innerHTML = `
      <div class="stat-row">
        <div class="stat-box"><div class="lbl">พื้นที่ว่าง</div><div class="num">${App.fmtBytes(r.freeBytes)}</div><div class="bar-track"><div class="bar-fill" style="width:${pct}%; background:${pct > 90 ? 'var(--danger)' : pct > 75 ? 'var(--warning)' : 'var(--primary)'}"></div></div><div class="doc-sub">ใช้ไป ${pct}% จาก ${App.fmtBytes(r.totalBytes)}</div></div>
        <div class="stat-box"><div class="lbl">ไฟล์เอกสารทั้งหมด</div><div class="num">${App.fmtBytes(r.filesBytes)}</div><div class="doc-sub">รวมต้นฉบับและเวอร์ชันเก่า</div></div>
        <div class="stat-box"><div class="lbl">เพิ่มขึ้นเฉลี่ย</div><div class="num">${App.fmtBytes(r.perDayBytes)}/วัน</div><div class="doc-sub">จาก 90 วันล่าสุด</div></div>
        <div class="stat-box"><div class="lbl">คาดว่าดิสก์เต็ม</div><div class="num" style="color:${r.daysLeft !== null && r.daysLeft < 90 ? 'var(--danger)' : 'inherit'}">${r.daysLeft === null ? 'ยังคำนวณไม่ได้' : r.daysLeft > 3650 ? 'มากกว่า 10 ปี' : r.daysLeft.toLocaleString() + ' วัน'}</div><div class="doc-sub">${r.daysLeft === null ? 'ยังไม่มีไฟล์ใหม่ใน 90 วัน' : r.daysLeft > 3650 ? 'ที่อัตราการเพิ่มปัจจุบัน' : 'ประมาณ ' + App.fmtDate(r.fullDate)}</div></div>
      </div>
      <div style="position:relative; height:240px; margin-top:18px;"><canvas id="rep-chart"></canvas></div>
      <p class="doc-sub" style="margin-top:8px;">คาดการณ์จากอัตราเพิ่มเฉลี่ย หากจะนำภาพเอกสารระบบเดิมเข้าหรือสแกนย้อนหลังจำนวนมาก พื้นที่จะหมดเร็วกว่านี้</p>`;
    this.chart = new Chart(document.getElementById('rep-chart'), {
      type: 'line',
      data: { labels: r.daily.map(d => App.fmtDate(d.Day)), datasets: [{ label: 'MB ที่เพิ่มต่อวัน', data: r.daily.map(d => +(d.Bytes / 1048576).toFixed(2)), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.1)', fill: true, tension: 0.3 }] },
      options: { responsive: true, maintainAspectRatio: false },
    });
  },

  pii: async function() {
    const el = this.box();
    const from = document.getElementById('pii-from')?.value || '';
    const to = document.getElementById('pii-to')?.value || '';
    el.innerHTML = 'กำลังโหลด...';
    const r = await App.api('api/reports.php', { report: 'pii', from, to });
    this.lastCsv = [['วัน-เวลา', 'ผู้ใช้', 'การกระทำ', 'IP', 'รหัสเอกสาร', 'เรื่อง', 'ข้อมูลส่วนบุคคล'], ...r.rows.map(x => [x.CreatedAt, x.Username, x.Action, x.IPAddress, x.ID, x.Filename, x.PiiSummary])];
    const byUser = {};
    r.rows.forEach(x => { byUser[x.Username] = (byUser[x.Username] || 0) + 1; });
    el.innerHTML = `
      <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:14px;">
        <div><label class="mini-label">ตั้งแต่</label><input type="date" id="pii-from" class="form-control" value="${r.from}"></div>
        <div><label class="mini-label">ถึง</label><input type="date" id="pii-to" class="form-control" value="${r.to}"></div>
        <button class="btn btn-outline" onclick="App.Reports.pii()">แสดง</button>
        <span class="doc-sub">เอกสารที่มีข้อมูลส่วนบุคคล ${r.flaggedDocs.toLocaleString()} ฉบับ · การเข้าถึง ${r.rows.length.toLocaleString()} ครั้ง</span>
      </div>
      ${Object.keys(byUser).length ? `<div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px;">${Object.entries(byUser).sort((a, b) => b[1] - a[1]).map(([u, n]) => `<span class="badge purple">${App.escapeHtml(u)}: ${n} ครั้ง</span>`).join('')}</div>` : ''}
      <div class="table-responsive"><table class="data-table"><thead><tr><th>วัน-เวลา</th><th>ผู้ใช้</th><th>การกระทำ</th><th>เอกสาร</th><th class="hide-sm">ข้อมูลส่วนบุคคล</th><th class="hide-sm">IP</th></tr></thead>
      <tbody>${r.rows.length ? r.rows.map(x => `<tr><td style="white-space:nowrap;">${App.fmtDate(x.CreatedAt, true)}</td><td><b>${App.escapeHtml(x.Username)}</b></td>
        <td>${{ 'View PII': 'เปิดดูรายละเอียด', 'View File': 'เปิดดูไฟล์', 'Download File': 'ดาวน์โหลด', 'Share Access': 'ผ่านลิงก์ผู้ตรวจ' }[x.Action] || App.escapeHtml(x.Action)}</td>
        <td><span class="doc-id" onclick="App.Detail.open(${App.jsArg(x.ID)})">${App.escapeHtml(x.ID)}</span><div class="doc-sub">${App.escapeHtml(x.Filename)}</div></td>
        <td class="hide-sm" style="font-size:13px;">${App.escapeHtml(x.PiiSummary || '-')}</td><td class="hide-sm mono">${App.escapeHtml(x.IPAddress)}</td></tr>`).join('')
        : '<tr><td colspan="6" style="color:var(--text-muted); padding:20px;">ไม่มีการเข้าถึงในช่วงนี้</td></tr>'}</tbody></table></div>`;
  },

  exportCsv: function() {
    if (this.lastCsv) this.csv('report_' + this.current + '_' + App.today(), this.lastCsv);
  },
};

/* Destruction of records (admin): batches, committee, decisions, approval, execution, printable forms. */
App.Destruction = {
  labels: {},
  init: function() { this.list(); },

  list: async function() {
    const el = document.getElementById('dest-body');
    el.innerHTML = 'กำลังโหลด...';
    try {
      const r = await App.api('api/destruction.php', { action: 'list' });
      this.labels = r.statusLabels;
      this.fy = r.currentFiscalYear;
      document.getElementById('dest-candidates').textContent = r.candidates.toLocaleString();
      el.innerHTML = r.batches.length ? `<table class="data-table"><thead><tr><th>บัญชี</th><th>ปีงบประมาณ</th><th>สถานะ</th><th style="text-align:right;">รายการ</th><th style="text-align:right;">ทำลาย</th><th></th></tr></thead><tbody>
        ${r.batches.map(b => `<tr><td><b>${App.escapeHtml(b.Title)}</b><div class="doc-sub">สร้าง ${App.fmtDate(b.CreatedAt)}${b.DoneAt ? ' · ทำลาย ' + App.fmtDate(b.DoneAt) : ''}</div></td><td>${b.FiscalYear}</td>
          <td><span class="badge ${{ draft: 'muted', review: 'purple', approved: 'info', done: 'success' }[b.Status]}">${this.labels[b.Status]}</span></td>
          <td style="text-align:right;">${(+b.Items).toLocaleString()}</td><td style="text-align:right;">${(+b.ToDestroy).toLocaleString()}</td>
          <td style="text-align:right; white-space:nowrap;"><button class="btn btn-outline" style="padding:6px 12px; font-size:13px;" onclick="App.Destruction.open(${b.ID})">เปิด</button></td></tr>`).join('')}
        </tbody></table>` : '<div style="color:var(--text-muted); padding:20px;">ยังไม่มีบัญชีขอทำลาย</div>';
    } catch (e) { el.textContent = e.message; }
  },

  create: async function(source) {
    const r = await App.Dialog.form({ title: 'สร้างบัญชีหนังสือขอทำลาย', icon: 'playlist_add',
      message: source === 'requested' ? 'รวมเอกสารที่มีคำขอทำลายและยังไม่อยู่ในบัญชีอื่น' : 'รวมเอกสารที่ครบอายุการเก็บและยังไม่อยู่ในบัญชีอื่น (สูงสุด 5,000 รายการ)',
      fields: [{ name: 'fy', label: 'ปีงบประมาณ (พ.ศ.)', type: 'number', value: String(this.fy), required: true }, { name: 'title', label: 'ชื่อบัญชี', placeholder: `บัญชีหนังสือขอทำลาย ประจำปี ${this.fy}` }],
      confirmText: 'สร้างบัญชี' });
    if (!r) return;
    try {
      const x = await App.api('api/destruction.php', { action: 'create', source, FiscalYear: r.fy, Title: r.title });
      App.toast(`สร้างบัญชีแล้ว ${x.count.toLocaleString()} รายการ`, 'success');
      this.open(x.id);
    } catch (e) { App.toast(e.message); }
  },

  open: async function(id) {
    const el = document.getElementById('dest-body');
    try {
      const { batch: b } = await App.api('api/destruction.php', { action: 'get', ID: id });
      this.batch = b;
      const editable = b.Status === 'draft' || b.Status === 'review';
      const members = b.Committee.length ? b.Committee : [{ name: '', position: '', role: 'ประธานกรรมการ' }, { name: '', position: '', role: 'กรรมการ' }, { name: '', position: '', role: 'กรรมการและเลขานุการ' }];
      const destroy = b.Items.filter(i => i.Decision === 'destroy').length;
      el.innerHTML = `
        <button class="btn btn-outline" style="padding:6px 12px; margin-bottom:12px;" onclick="App.Destruction.list()"><span class="material-icons-round" style="font-size:18px;">arrow_back</span> รายการบัญชี</button>
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
          <div><h3 style="font-family:var(--font-heading);">${App.escapeHtml(b.Title)}</h3>
            <div class="doc-sub">ปีงบประมาณ ${b.FiscalYear} · ${b.Items.length.toLocaleString()} รายการ · ทำลาย ${destroy.toLocaleString()} · เก็บต่อ ${(b.Items.length - destroy).toLocaleString()}</div>
            <div style="margin-top:6px;"><span class="badge ${{ draft: 'muted', review: 'purple', approved: 'info', done: 'success' }[b.Status]}">${b.StatusLabel}</span>
              ${b.ApprovedAt ? `<span class="doc-sub"> อนุมัติโดย ${App.escapeHtml(b.ApprovedByName || '-')} ${App.fmtDate(b.ApprovedAt, true)}</span>` : ''}</div></div>
          <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <button class="btn btn-outline" onclick="window.open('destruction_print.php?id=${b.ID}', '_blank')"><span class="material-icons-round" style="font-size:18px;">print</span> พิมพ์บัญชีขอทำลาย</button>
            ${b.Status === 'draft' ? `<button class="btn btn-primary" onclick="App.Destruction.status('review')">ส่งคณะกรรมการพิจารณา</button><button class="btn btn-outline" style="color:var(--danger);" onclick="App.Destruction.remove()">ลบร่าง</button>` : ''}
            ${b.Status === 'review' ? `<button class="btn btn-outline" onclick="App.Destruction.status('draft')">กลับเป็นร่าง</button><button class="btn btn-primary" onclick="App.Destruction.status('approved')">อนุมัติบัญชี</button>` : ''}
            ${b.Status === 'approved' ? `<button class="btn dlg-btn-danger" onclick="App.Destruction.execute()"><span class="material-icons-round" style="font-size:18px;">delete_sweep</span> ดำเนินการทำลาย</button>` : ''}
          </div>
        </div>

        <h4 class="sec-title" style="margin-top:20px;"><span class="material-icons-round">groups</span> คณะกรรมการทำลายหนังสือ (อย่างน้อย 3 คน)</h4>
        <div id="dest-committee">${members.map((m, i) => `<div class="committee-row">
          <input class="form-control" data-c="name" value="${App.escapeHtml(m.name)}" placeholder="ชื่อ-สกุล" ${editable ? '' : 'disabled'}>
          <input class="form-control" data-c="position" value="${App.escapeHtml(m.position)}" placeholder="ตำแหน่ง" ${editable ? '' : 'disabled'}>
          <select class="form-control" data-c="role" ${editable ? '' : 'disabled'}>${['ประธานกรรมการ', 'กรรมการ', 'กรรมการและเลขานุการ'].map(r => `<option ${m.role === r ? 'selected' : ''}>${r}</option>`).join('')}</select>
        </div>`).join('')}</div>
        ${editable ? `<div style="display:flex; gap:8px; margin:8px 0 4px;"><button class="btn btn-outline" style="padding:6px 12px; font-size:13px;" onclick="App.Destruction.addMember()">+ เพิ่มกรรมการ</button></div>` : ''}
        <label class="mini-label" style="margin-top:10px;">ความเห็นคณะกรรมการ</label>
        <textarea id="dest-note" class="form-control" rows="2" ${editable ? '' : 'disabled'}>${App.escapeHtml(b.Note || '')}</textarea>
        ${editable ? `<button class="btn btn-primary" style="margin-top:8px;" onclick="App.Destruction.saveCommittee()">บันทึกคณะกรรมการ</button>` : ''}

        <h4 class="sec-title" style="margin-top:22px;"><span class="material-icons-round">fact_check</span> รายการและการพิจารณา</h4>
        ${editable ? `<div style="display:flex; gap:6px; margin-bottom:8px; flex-wrap:wrap;"><span class="doc-sub" style="align-self:center;">รายการที่เลือก:</span>
          <button class="btn btn-outline" style="padding:6px 12px; font-size:13px;" onclick="App.Destruction.decide('destroy')">ทำลาย</button>
          <button class="btn btn-outline" style="padding:6px 12px; font-size:13px;" onclick="App.Destruction.decide('keep')">ขยายเวลาเก็บ...</button></div>` : ''}
        <div class="table-responsive" style="max-height:480px; overflow:auto;"><table class="data-table"><thead><tr>${editable ? '<th style="width:36px;"><input type="checkbox" onchange="document.querySelectorAll(\'.dest-sel\').forEach(c => c.checked = this.checked)"></th>' : ''}<th>ที่</th><th>เรื่อง</th><th class="hide-sm">ลงวันที่</th><th class="hide-sm">แฟ้ม</th><th>ครบอายุ</th><th>การพิจารณา</th></tr></thead><tbody>
          ${b.Items.map(i => `<tr>${editable ? `<td><input type="checkbox" class="dest-sel" value="${App.escapeHtml(i.DocumentID)}"></td>` : ''}
            <td><span class="doc-id" onclick="App.Detail.open(${App.jsArg(i.DocumentID)})">${App.escapeHtml(i.DocumentID)}</span></td><td>${App.escapeHtml(i.Title)}</td>
            <td class="hide-sm">${App.fmtDate(i.DocDate)}</td><td class="hide-sm" style="font-size:13px;">${App.escapeHtml(i.FolderName)}</td><td>${App.fmtDate(i.ExpiryDate)}</td>
            <td>${i.Decision === 'keep' ? `<span class="badge info">เก็บต่อ ${i.KeepYears} ปี</span>` : '<span class="badge danger">ทำลาย</span>'}${i.Note ? `<div class="doc-sub">${App.escapeHtml(i.Note)}</div>` : ''}</td></tr>`).join('')}
        </tbody></table></div>`;
    } catch (e) { App.toast(e.message); }
  },

  addMember: function() {
    const div = document.createElement('div');
    div.className = 'committee-row';
    div.innerHTML = `<input class="form-control" data-c="name" placeholder="ชื่อ-สกุล"><input class="form-control" data-c="position" placeholder="ตำแหน่ง"><select class="form-control" data-c="role"><option>กรรมการ</option><option>ประธานกรรมการ</option><option>กรรมการและเลขานุการ</option></select>`;
    document.getElementById('dest-committee').appendChild(div);
  },

  saveCommittee: function() {
    const members = Array.from(document.querySelectorAll('#dest-committee .committee-row')).map(r => ({
      name: r.querySelector('[data-c=name]').value, position: r.querySelector('[data-c=position]').value, role: r.querySelector('[data-c=role]').value,
    }));
    return App.api('api/destruction.php', { action: 'saveCommittee', ID: this.batch.ID, Committee: members, Note: document.getElementById('dest-note').value })
      .then(() => { App.toast('บันทึกคณะกรรมการแล้ว', 'success'); this.open(this.batch.ID); })
      .catch(e => App.toast(e.message));
  },

  decide: async function(decision) {
    const ids = Array.from(document.querySelectorAll('.dest-sel:checked')).map(c => c.value);
    if (!ids.length) { App.toast('เลือกรายการก่อน', 'info'); return; }
    let extra = {};
    if (decision === 'keep') {
      const r = await App.Dialog.form({ title: `ขยายเวลาเก็บ ${ids.length} รายการ`, icon: 'more_time',
        fields: [{ name: 'years', label: 'เก็บต่ออีก (ปี)', type: 'number', value: '1', required: true }, { name: 'note', label: 'เหตุผล', placeholder: 'เช่น ยังใช้ประกอบคดี / การตรวจสอบ' }], confirmText: 'บันทึก' });
      if (!r) return;
      extra = { KeepYears: r.years, Note: r.note };
    }
    App.api('api/destruction.php', Object.assign({ action: 'decide', ID: this.batch.ID, Decision: decision, DocumentIDs: ids }, extra))
      .then(() => this.open(this.batch.ID)).catch(e => App.toast(e.message));
  },

  status: async function(to) {
    const texts = { review: ['ส่งคณะกรรมการพิจารณา?', 'บัญชีจะเข้าสู่ขั้นพิจารณา'], approved: ['อนุมัติบัญชีขอทำลาย?', 'หลังอนุมัติจะแก้ไขรายการไม่ได้'], draft: ['กลับเป็นร่าง?', ''] };
    if (!await App.Dialog.confirm({ title: texts[to][0], icon: 'approval', message: texts[to][1], confirmText: 'ยืนยัน' })) return;
    App.api('api/destruction.php', { action: 'setStatus', ID: this.batch.ID, Status: to }).then(() => this.open(this.batch.ID)).catch(e => App.toast(e.message));
  },

  execute: async function() {
    const destroy = this.batch.Items.filter(i => i.Decision === 'destroy').length;
    if (!await App.Dialog.confirm({ title: 'ดำเนินการทำลายตามบัญชี?', icon: 'delete_sweep', tone: 'danger',
      message: `ย้าย ${destroy.toLocaleString()} เอกสารไปถังขยะ และขยายวันครบอายุของรายการที่ให้เก็บต่อ`,
      note: `เอกสารในถังขยะจะถูกลบถาวรเมื่อครบ ${App.data.settings.trashRetentionDays || 30} วัน ทะเบียนการทำลายยังเก็บไว้ครบ`, confirmText: 'ดำเนินการทำลาย' })) return;
    App.api('api/destruction.php', { action: 'execute', ID: this.batch.ID })
      .then(r => { App.toast(`ทำลาย ${r.destroyed} · เก็บต่อ ${r.kept} รายการ`, 'success'); this.open(this.batch.ID); })
      .catch(e => App.toast(e.message));
  },

  remove: async function() {
    if (!await App.Dialog.confirm({ title: 'ลบร่างบัญชีนี้?', icon: 'delete', tone: 'danger', message: 'เอกสารจริงไม่ได้รับผลกระทบ', confirmText: 'ลบร่าง' })) return;
    App.api('api/destruction.php', { action: 'delete', ID: this.batch.ID }).then(() => this.list()).catch(e => App.toast(e.message));
  },

  yearReport: async function() {
    const r = await App.Dialog.form({ title: 'รายงานการทำลายประจำปี', icon: 'summarize', fields: [{ name: 'fy', label: 'ปีงบประมาณ (พ.ศ.)', type: 'number', value: String(this.fy), required: true }], confirmText: 'เปิดรายงาน' });
    if (r) window.open('destruction_print.php?year=' + encodeURIComponent(r.fy), '_blank');
  },
};
