/* In-app notifications (bell in the top bar). Refreshes every minute. */
App.Bell = {
  items: [],
  timer: null,

  start: function() {
    this.refresh();
    clearInterval(this.timer);
    this.timer = setInterval(() => { if (!document.hidden) this.refresh(); }, 60000);
    document.addEventListener('click', e => {
      const panel = document.getElementById('bell-panel');
      if (panel && panel.style.display === 'block' && !e.target.closest('#bell-wrap')) panel.style.display = 'none';
    });
  },

  refresh: async function() {
    try {
      const r = await App.api('api/notifications.php', {});
      this.items = r.items;
      const badge = document.getElementById('bell-badge');
      badge.textContent = r.badge > 99 ? '99+' : r.badge;
      badge.style.display = r.badge ? 'flex' : 'none';
      this.render();
    } catch (e) { /* offline: keep the last state */ }
  },

  toggle: function() {
    const panel = document.getElementById('bell-panel');
    panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
    if (panel.style.display === 'block') this.refresh();
  },

  render: function() {
    const list = document.getElementById('bell-list');
    if (!list) return;
    list.innerHTML = this.items.length ? this.items.map((it, i) => `
      <button class="bell-item tone-${it.tone}" onclick="App.Bell.go(${i})">
        <span class="material-icons-round">${it.icon}</span>
        <span class="t">${App.escapeHtml(it.text)}</span>
        ${it.count ? `<span class="n">${it.count.toLocaleString()}</span>` : ''}
      </button>`).join('')
      : `<div style="padding:24px; text-align:center; color:var(--text-muted);"><span class="material-icons-round" style="font-size:36px; color:#cbd5e1;">notifications_none</span><div>ไม่มีเรื่องที่ต้องดำเนินการ</div></div>`;
  },

  go: function(i) {
    const link = this.items[i].link || {};
    document.getElementById('bell-panel').style.display = 'none';
    if (link.view) {
      App.navigate(link.view);
      if (link.view === 'settings' && link.tab) App.Settings.tab(link.tab);
      return;
    }
    App.Docs.state = Object.assign(App.Docs.defaultState(), link);
    App.navigate('dashboard');
  },
};

/* Register of physical originals on loan. */
App.Loans = {
  filter: 'open',
  init: function() { this.load(); },
  setFilter: function(f) {
    this.filter = f;
    document.querySelectorAll('#loan-chips .chip').forEach(c => c.classList.toggle('active', c.dataset.f === f));
    this.load();
  },
  load: async function() {
    const body = document.getElementById('loans-body');
    if (!body) return;
    body.innerHTML = `<tr><td colspan="6" style="padding:24px; color:var(--text-muted);">กำลังโหลด...</td></tr>`;
    try {
      const r = await App.api('api/locations.php', { action: 'loans', filter: this.filter });
      body.innerHTML = r.loans.length ? r.loans.map(l => `<tr>
          <td><span class="doc-id" onclick="App.Detail.open(${App.jsArg(l.DocumentID)})">${App.escapeHtml(l.DocumentID)}</span><div class="doc-sub">${App.escapeHtml(l.Filename)}</div></td>
          <td><b>${App.escapeHtml(l.BorrowerName)}</b>${l.Purpose ? `<div class="doc-sub">${App.escapeHtml(l.Purpose)}</div>` : ''}</td>
          <td style="white-space:nowrap;">${App.fmtDate(l.LoanedAt)}</td>
          <td style="white-space:nowrap;">${App.fmtDate(l.DueDate)}</td>
          <td>${l.ReturnedAt ? `<span class="badge success">คืนแล้ว ${App.fmtDate(l.ReturnedAt)}</span>` : `<span class="badge ${l.Overdue ? 'danger' : 'info'}">${l.Overdue ? 'เกินกำหนด' : 'ยังไม่คืน'}</span>`}</td>
          <td style="text-align:right;">${!l.ReturnedAt && App.canEdit() ? `<button class="btn btn-outline" style="padding:6px 12px; font-size:13px;" onclick="App.DetailExtra.returnLoan(${l.ID})">บันทึกการคืน</button>` : ''}</td>
        </tr>`).join('')
        : `<tr><td colspan="6" style="text-align:center; padding:36px; color:var(--text-muted);">ไม่มีรายการ</td></tr>`;
    } catch (e) { body.innerHTML = `<tr><td colspan="6">${App.escapeHtml(e.message)}</td></tr>`; }
  },
};
