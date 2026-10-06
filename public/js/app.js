(() => {
  'use strict';

  const COLUMN_TYPES = [
    { v: 'text', l: 'Teks' },
    { v: 'number', l: 'Angka' },
    { v: 'textarea', l: 'Teks Panjang' },
    { v: 'email', l: 'Email' },
    { v: 'date', l: 'Tanggal' },
    { v: 'datetime', l: 'Tanggal & Jam' },
    { v: 'select', l: 'Pilihan (dropdown tetap)' },
    { v: 'status', l: 'Status (bebas, otomatis berwarna)' },
  ];
  const COLUMN_ROLES = [
    { v: 'normal', l: 'Normal' },
    { v: 'group', l: 'Grouping (chart batang)' },
    { v: 'status', l: 'Status (chart donat)' },
    { v: 'date', l: 'Tanggal (chart timeline)' },
  ];

  const state = {
    projects: [],
    projectSearch: '',
    currentProject: null,
    columns: [],
    rows: [],
    dashboardData: null,
    activeTab: 'dashboard',
    dataSearch: '',
    charts: [],
    importPreview: null,
    importParams: null,
    sortColKey: null,
    sortDir: 'asc',
    hiddenCols: new Set(),
    freezeCount: 0,
  };

  function loadHiddenCols(projectId) {
    try {
      const raw = localStorage.getItem('mopboard:hiddenCols:' + projectId);
      return raw ? new Set(JSON.parse(raw)) : new Set();
    } catch (e) { return new Set(); }
  }

  function saveHiddenCols() {
    try {
      localStorage.setItem('mopboard:hiddenCols:' + state.currentProject.id, JSON.stringify([...state.hiddenCols]));
    } catch (e) { /* private mode / quota — non-fatal, just won't persist */ }
  }

  function loadFreezeCount(projectId) {
    try {
      const raw = localStorage.getItem('mopboard:freezeCount:' + projectId);
      return raw ? Math.max(0, parseInt(raw, 10) || 0) : 0;
    } catch (e) { return 0; }
  }

  function saveFreezeCount() {
    try {
      localStorage.setItem('mopboard:freezeCount:' + state.currentProject.id, String(state.freezeCount));
    } catch (e) { /* private mode / quota — non-fatal, just won't persist */ }
  }

  /* ---------------- API helper ---------------- */

  async function api(path, { method = 'GET', body } = {}) {
    const opts = { method };
    if (body !== undefined) {
      opts.headers = { 'Content-Type': 'application/json' };
      opts.body = JSON.stringify(body);
    }
    const res = await fetch('api.php?path=' + path, opts);
    if (res.status === 401) {
      window.location.href = 'login.php';
      throw new Error('Sesi berakhir, mengalihkan ke halaman login...');
    }
    let json = null;
    try { json = await res.json(); } catch (e) { /* no body */ }
    if (!res.ok) {
      throw new Error((json && json.error) || ('Terjadi kesalahan (HTTP ' + res.status + ')'));
    }
    return json;
  }

  function toast(message, type = '') {
    const root = document.getElementById('toastRoot');
    const el = document.createElement('div');
    el.className = 'toast' + (type ? ' ' + type : '');
    el.textContent = message;
    root.appendChild(el);
    setTimeout(() => el.remove(), 3200);
  }

  function escapeHtml(v) {
    return String(v ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  // Mirrors helpers.php classify_status(). Returns a "kind" (mapped to a
  // fixed .badge-<kind> CSS class) rather than a raw color, so no inline
  // style attribute is needed to paint the badge — keeps style-src-elem strict.
  function classifyStatus(value) {
    const v = String(value ?? '').trim();
    const lower = v.toLowerCase();
    if (v === '') return { bucket: '(Kosong)', kind: 'neutral' };
    if (lower.includes('not ready')) return { bucket: v, kind: 'danger' };
    if (lower.includes('fail') || lower.includes('refused') || lower.includes('error') || lower.includes('gagal')) return { bucket: v, kind: 'dangerdark' };
    if (lower.startsWith('ready')) return { bucket: v, kind: 'success' };
    if (lower.includes('done') || lower.includes('selesai')) return { bucket: v, kind: 'info' };
    if (lower.includes('confirm') || lower.includes('dismantle') || lower.includes('power off') || lower.includes('cancel') || lower.includes('pending')) return { bucket: v, kind: 'warning' };
    return { bucket: v, kind: 'other' };
  }

  /* ---------------- Init ---------------- */

  async function init() {
    bindStaticEvents();
    await loadProjects();
    const lastId = localStorage.getItem('mopboard:lastProject');
    if (lastId && state.projects.some((p) => String(p.id) === lastId)) {
      await selectProject(Number(lastId));
    } else if (state.projects.length) {
      await selectProject(state.projects[0].id);
    }
  }

  async function loadProjects() {
    state.projects = await api('projects');
    renderSidebar();
  }

  function renderSidebar() {
    const list = document.getElementById('projectList');
    const q = state.projectSearch.toLowerCase();
    const filtered = state.projects.filter((p) => p.name.toLowerCase().includes(q));
    if (!filtered.length) {
      list.innerHTML = '<p class="muted" style="padding:8px 4px">Tidak ada project.</p>';
      return;
    }
    list.innerHTML = filtered.map((p) => `
      <div class="project-item ${state.currentProject && state.currentProject.id === p.id ? 'active' : ''}" data-id="${p.id}">
        <div class="project-item-name">${escapeHtml(p.name)}</div>
        <div class="project-item-meta">
          <span>${p.row_count} baris</span>
          <span>·</span>
          <span>${p.column_count} kolom</span>
        </div>
      </div>
    `).join('');
    list.querySelectorAll('.project-item').forEach((el) => {
      el.addEventListener('click', () => selectProject(Number(el.dataset.id)));
    });
  }

  /* ---------------- Project selection ---------------- */

  async function selectProject(id) {
    try {
      const project = await api('projects/' + id);
      state.currentProject = project;
      state.columns = project.columns;
      state.hiddenCols = loadHiddenCols(id);
      state.freezeCount = loadFreezeCount(id);
      state.sortColKey = null;
      state.sortDir = 'asc';
      localStorage.setItem('mopboard:lastProject', String(id));

      document.getElementById('emptyState').classList.add('hidden');
      document.getElementById('projectView').classList.remove('hidden');
      document.getElementById('projectName').textContent = project.name;
      document.getElementById('projectDesc').textContent = project.description || '';

      renderSidebar();
      await refreshRows();
      setActiveTab(state.activeTab || 'dashboard');
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  async function refreshRows() {
    if (!state.currentProject) return;
    state.rows = await api('projects/' + state.currentProject.id + '/rows');
    // keep sidebar counters fresh
    const p = state.projects.find((x) => x.id === state.currentProject.id);
    if (p) p.row_count = state.rows.length;
  }

  async function refreshDashboard() {
    if (!state.currentProject) return;
    state.dashboardData = await api('projects/' + state.currentProject.id + '/dashboard');
  }

  /* ---------------- Tabs ---------------- */

  function setActiveTab(tab) {
    state.activeTab = tab;
    document.querySelectorAll('.tab-btn').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('.tab-panel').forEach((p) => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');

    if (tab === 'dashboard') renderDashboardTab();
    if (tab === 'data') renderDataTab();
    if (tab === 'columns') renderColumnsTab();
    if (tab === 'io') renderIoTab();
  }

  /* ---------------- Dashboard tab ---------------- */

  async function renderDashboardTab() {
    const el = document.getElementById('tab-dashboard');
    el.innerHTML = '<p class="muted">Memuat dashboard...</p>';
    try {
      await refreshDashboard();
    } catch (e) {
      el.innerHTML = '<p class="muted">Gagal memuat dashboard: ' + escapeHtml(e.message) + '</p>';
      return;
    }
    const d = state.dashboardData;
    state.charts.forEach((c) => c.destroy());
    state.charts = [];

    if (!d.total) {
      el.innerHTML = `
        <div class="kpi-grid"><div class="card kpi-card"><div class="kpi-label">Total Item</div><div class="kpi-value">0</div></div></div>
        <p class="muted">Belum ada data. Tambahkan baris di tab <strong>Data MOP</strong> atau import CSV di tab <strong>Import / Export</strong>.</p>
      `;
      return;
    }

    const completionCard = d.completion ? `
      <div class="card kpi-card">
        <div class="kpi-label">${escapeHtml(d.completion.label)}</div>
        <div class="kpi-value">${d.completion.pct}%</div>
        <div class="progress-ring-wrap">
          <progress class="mop-progress" value="${d.completion.pct}" max="100"></progress>
        </div>
        <div class="kpi-sub">${d.completion.done} / ${d.completion.total} selesai</div>
      </div>` : '';

    let extraKpis = '';
    d.group_breakdown.slice(0, 2).forEach((g) => {
      const entries = Object.entries(g.counts);
      const top = entries[0];
      extraKpis += `
        <div class="card kpi-card">
          <div class="kpi-label">${escapeHtml(g.label)} terbanyak</div>
          <div class="kpi-value kpi-value-sm">${top ? escapeHtml(top[0]) : '—'}</div>
          <div class="kpi-sub">${top ? top[1] + ' item · ' + entries.length + ' kategori' : ''}</div>
        </div>`;
    });

    el.innerHTML = `
      <div class="kpi-grid">
        <div class="card kpi-card">
          <div class="kpi-label">Total Item</div>
          <div class="kpi-value">${d.total}</div>
        </div>
        ${completionCard}
        ${extraKpis}
      </div>
      <div class="charts-grid" id="chartsGrid"></div>
    `;

    const grid = document.getElementById('chartsGrid');

    d.status_breakdown.forEach((s, idx) => {
      const canvasId = 'chart_status_' + idx;
      const card = document.createElement('div');
      card.className = 'chart-card card';
      card.innerHTML = `<h3>${escapeHtml(s.label)}${s.is_primary ? ' <span class="chip">Utama</span>' : ''}</h3><p class="chart-note">Distribusi nilai (otomatis berwarna berdasarkan kata kunci)</p><canvas id="${canvasId}"></canvas>`;
      grid.appendChild(card);
      const labels = Object.keys(s.counts);
      const values = Object.values(s.counts);
      const colors = labels.map((l) => s.colors[l] || '#94a3b8');
      const chart = new Chart(document.getElementById(canvasId), {
        type: 'doughnut',
        data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 1, borderColor: '#fff' }] },
        options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }, maintainAspectRatio: false },
      });
      state.charts.push(chart);
    });

    d.group_breakdown.forEach((g, idx) => {
      const canvasId = 'chart_group_' + idx;
      const card = document.createElement('div');
      card.className = 'chart-card card';
      card.innerHTML = `<h3>${escapeHtml(g.label)}</h3><p class="chart-note">Top ${Object.keys(g.counts).length} kategori berdasarkan jumlah item</p><canvas id="${canvasId}"></canvas>`;
      grid.appendChild(card);
      const labels = Object.keys(g.counts);
      const values = Object.values(g.counts);
      const chart = new Chart(document.getElementById(canvasId), {
        type: 'bar',
        data: { labels, datasets: [{ data: values, backgroundColor: '#4f46e5', borderRadius: 4 }] },
        options: {
          indexAxis: 'y',
          plugins: { legend: { display: false } },
          maintainAspectRatio: false,
          scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
        },
      });
      state.charts.push(chart);
    });

    d.timeline.forEach((t, idx) => {
      const canvasId = 'chart_timeline_' + idx;
      const card = document.createElement('div');
      card.className = 'chart-card card full';
      card.innerHTML = `<h3>Timeline — ${escapeHtml(t.label)}</h3><p class="chart-note">Jumlah item per tanggal</p><canvas id="${canvasId}"></canvas>`;
      grid.appendChild(card);
      const labels = Object.keys(t.counts);
      const values = Object.values(t.counts);
      const chart = new Chart(document.getElementById(canvasId), {
        type: 'bar',
        data: { labels, datasets: [{ data: values, backgroundColor: '#2563eb', borderRadius: 4 }] },
        options: {
          plugins: { legend: { display: false } },
          maintainAspectRatio: false,
          scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { ticks: { autoSkip: true, maxRotation: 45, minRotation: 0 } } },
        },
      });
      state.charts.push(chart);
    });

    if (!d.status_breakdown.length && !d.group_breakdown.length && !d.timeline.length) {
      grid.innerHTML = '<p class="muted">Tandai kolom sebagai <strong>Status</strong>, <strong>Grouping</strong>, atau <strong>Tanggal</strong> di tab Kolom supaya chart muncul di sini.</p>';
    }
  }

  /* ---------------- Data tab ---------------- */

  // Type-aware row comparator used by header-click sorting.
  function compareRowsForSort(a, b, col) {
    const va = a.data[col.col_key];
    const vb = b.data[col.col_key];
    if (col.type === 'number') {
      const na = parseFloat(va); const nb = parseFloat(vb);
      const fa = Number.isNaN(na) ? -Infinity : na;
      const fb = Number.isNaN(nb) ? -Infinity : nb;
      return fa - fb;
    }
    if (col.type === 'date' || col.type === 'datetime') {
      const da = Date.parse(va); const db = Date.parse(vb);
      if (!Number.isNaN(da) && !Number.isNaN(db)) return da - db;
    }
    return String(va ?? '').localeCompare(String(vb ?? ''), 'id', { sensitivity: 'base', numeric: true });
  }

  function renderSortableHeader(col) {
    const active = state.sortColKey === col.col_key;
    const arrow = active ? (state.sortDir === 'asc' ? ' ▲' : ' ▼') : '';
    return `<th class="sortable${active ? ' sorted' : ''}" data-col-key="${col.col_key}" title="Klik untuk urutkan">${escapeHtml(col.label)}${arrow}</th>`;
  }

  function setSort(colKey) {
    if (state.sortColKey !== colKey) {
      state.sortColKey = colKey;
      state.sortDir = 'asc';
    } else if (state.sortDir === 'asc') {
      state.sortDir = 'desc';
    } else {
      state.sortColKey = null; // third click: back to insertion order
    }
    renderDataTab();
  }

  // Pins the "#" column plus the first `state.freezeCount` visible data
  // columns so they stay put while the rest of the table scrolls
  // horizontally — like Excel's freeze panes. Column widths are
  // content-dependent (not fixed), so offsets are measured from the
  // rendered <th> widths rather than hardcoded, and applied as `left` on
  // both the header and every body cell in that column.
  function applyColumnFreeze(cols) {
    const table = document.querySelector('#tab-data table.data-table');
    if (!table) return;
    const theadCells = table.querySelectorAll('thead th');
    if (!theadCells.length) return;

    const freezeN = Math.max(0, Math.min(state.freezeCount, cols.length));
    let left = 0;
    for (let i = 0; i < theadCells.length; i++) {
      const isFrozen = i === 0 || i <= freezeN; // index 0 = "#", indices 1..freezeN = first N data cols
      const cells = table.querySelectorAll(`tr > *:nth-child(${i + 1})`);
      cells.forEach((cell) => {
        cell.classList.toggle('frozen-col', isFrozen);
        cell.classList.remove('frozen-col-shadow');
        if (isFrozen) {
          cell.style.left = left + 'px';
        } else {
          cell.style.left = '';
        }
      });
      if (isFrozen) left += theadCells[i].offsetWidth;
    }
    // Shadow divider on the last frozen column so the freeze boundary is visible.
    if (freezeN >= 0 && theadCells[freezeN]) {
      table.querySelectorAll(`tr > *:nth-child(${freezeN + 1})`).forEach((cell) => {
        cell.classList.add('frozen-col-shadow');
      });
    }
  }

  function renderDataTab() {
    const el = document.getElementById('tab-data');
    const cols = state.columns.filter((c) => !state.hiddenCols.has(c.col_key));
    const q = state.dataSearch.toLowerCase();
    let rows = state.rows.filter((r) => {
      if (!q) return true;
      return Object.values(r.data).some((v) => String(v ?? '').toLowerCase().includes(q));
    });

    if (state.sortColKey) {
      const sortCol = state.columns.find((c) => c.col_key === state.sortColKey);
      if (sortCol) {
        rows = rows.slice().sort((a, b) => compareRowsForSort(a, b, sortCol) * (state.sortDir === 'asc' ? 1 : -1));
      }
    }

    el.innerHTML = `
      <div class="toolbar">
        <div class="toolbar-left">
          <input type="text" id="dataSearchInput" placeholder="Cari di semua kolom..." value="${escapeHtml(state.dataSearch)}">
          <span class="muted">${rows.length} dari ${state.rows.length} baris</span>
        </div>
        <div class="toolbar-left">
          <span class="freeze-control muted">
            Freeze <input type="number" id="freezeCountInput" class="freeze-input" min="0" max="${cols.length}" value="${Math.min(state.freezeCount, cols.length)}"> kolom pertama
          </span>
          <button class="btn btn-primary" id="btnAddRow">+ Tambah Baris</button>
          <button class="btn" id="btnFilterCols">Filter Kolom${state.hiddenCols.size ? ` (${state.hiddenCols.size} disembunyikan)` : ''}</button>
          <a class="btn" href="api.php?path=projects/${state.currentProject.id}/export">Export CSV</a>
        </div>
      </div>
      <div class="table-scroll">
        <table class="data-table">
          <thead><tr>
            <th class="rowno">#</th>
            ${cols.map((c) => renderSortableHeader(c)).join('')}
            <th class="row-actions-col"></th>
          </tr></thead>
          <tbody id="dataTableBody"></tbody>
        </table>
      </div>
    `;

    document.getElementById('dataSearchInput').addEventListener('input', (e) => {
      state.dataSearch = e.target.value;
      renderDataTab();
      document.getElementById('dataSearchInput').focus();
      const val = document.getElementById('dataSearchInput');
      val.setSelectionRange(val.value.length, val.value.length);
    });
    document.getElementById('btnAddRow').addEventListener('click', addRow);
    document.getElementById('btnFilterCols').addEventListener('click', openColumnFilterModal);
    document.getElementById('freezeCountInput').addEventListener('change', (e) => {
      const n = Math.max(0, Math.min(cols.length, parseInt(e.target.value, 10) || 0));
      state.freezeCount = n;
      saveFreezeCount();
      renderDataTab();
    });
    document.querySelectorAll('#tab-data th.sortable').forEach((th) => {
      th.addEventListener('click', () => setSort(th.dataset.colKey));
    });

    const body = document.getElementById('dataTableBody');
    if (!rows.length) {
      body.innerHTML = `<tr><td colspan="${cols.length + 2}" style="padding:20px;text-align:center" class="muted">Belum ada data.</td></tr>`;
      applyColumnFreeze(cols);
      return;
    }

    // Column whose values get a tone accent matching the row's status (best
    // effort: the first column literally labeled "host..."). Falls back to
    // nothing if the project's template doesn't have one, or it's hidden.
    const hostCol = cols.find((c) => /host/i.test(c.label));

    body.innerHTML = rows.map((r, i) => `
      <tr data-row-id="${r.id}" title="${escapeHtml(rowStatusTooltip(r))}">
        <td class="rowno"><span class="rowno-content">
          <button type="button" class="rowno-edit-btn" data-edit-row="${r.id}" title="Edit baris lewat form">✎</button>${renderRowNoDots(r)}${i + 1}
        </span></td>
        ${cols.map((c) => renderCell(r, c, c === hostCol)).join('')}
        <td class="row-actions-col"><button class="btn btn-icon btn-ghost btn-danger" data-del-row="${r.id}" title="Hapus baris">×</button></td>
      </tr>
    `).join('');

    applyColumnFreeze(cols);

    body.querySelectorAll('td[data-col-key]').forEach((td) => {
      td.addEventListener('click', () => activateCellEditor(td));
    });
    body.querySelectorAll('[data-del-row]').forEach((btn) => {
      btn.addEventListener('click', () => deleteRow(Number(btn.dataset.delRow)));
    });
    body.querySelectorAll('[data-edit-row]').forEach((btn) => {
      btn.addEventListener('click', () => openEditRowModal(Number(btn.dataset.editRow)));
    });
  }

  // All "status"-role columns for the current project, in display order.
  function statusColumns() {
    return state.columns.filter((c) => c.role === 'status');
  }

  // The status column used to color the host-cell accent / row indicator:
  // whichever is marked "Utama", else the first status column, else none.
  function primaryToneForRow(row) {
    const cols = statusColumns();
    if (!cols.length) return null;
    const primary = cols.find((c) => c.is_primary) || cols[0];
    return classifyStatus(row.data[primary.col_key]).kind;
  }

  // "Status Replicate: Ready · Status CutOver: Not Ready" — shown as the
  // native title tooltip when hovering anywhere over the row.
  function rowStatusTooltip(row) {
    const cols = statusColumns();
    if (!cols.length) return '';
    return cols.map((c) => `${c.label}: ${row.data[c.col_key] || '(Kosong)'}`).join(' · ');
  }

  function renderRowNoDots(row) {
    const cols = statusColumns();
    if (!cols.length) return '';
    const dots = cols.map((c) => {
      const cls = classifyStatus(row.data[c.col_key]);
      return `<span class="status-dot badge-${cls.kind}"></span>`;
    }).join('');
    return `<span class="rowno-dots">${dots}</span>`;
  }

  function renderCell(row, col, isHostCol) {
    const val = row.data[col.col_key] ?? '';
    let inner = escapeHtml(val);
    if (col.role === 'status' || col.type === 'status') {
      const cls = classifyStatus(val);
      inner = `<span class="badge badge-${cls.kind}">${escapeHtml(val) || '(Kosong)'}</span>`;
    }
    const tone = isHostCol ? primaryToneForRow(row) : null;
    const toneClass = tone ? ` cell-tone-${tone}` : '';
    return `<td class="${toneClass.trim()}" data-col-key="${col.col_key}" data-row-id="${row.id}"><div class="cell-inner">${inner}</div></td>`;
  }

  function activateCellEditor(td) {
    if (td.classList.contains('editing')) return;
    const rowId = Number(td.dataset.rowId);
    const colKey = td.dataset.colKey;
    const col = state.columns.find((c) => c.col_key === colKey);
    const row = state.rows.find((r) => r.id === rowId);
    const current = row.data[colKey] ?? '';
    td.classList.add('editing');

    let inputHtml;
    if (col.type === 'textarea') {
      inputHtml = `<textarea rows="2">${escapeHtml(current)}</textarea>`;
    } else if (col.type === 'select') {
      const opts = (col.options || []).map((o) => `<option value="${escapeHtml(o)}" ${o === current ? 'selected' : ''}>${escapeHtml(o)}</option>`).join('');
      inputHtml = `<select><option value="">—</option>${opts}</select>`;
    } else if (col.type === 'status') {
      const dlId = 'dl_' + colKey;
      const uniqueVals = [...new Set(state.rows.map((r) => r.data[colKey]).filter(Boolean))];
      inputHtml = `<input type="text" list="${dlId}" value="${escapeHtml(current)}"><datalist id="${dlId}">${uniqueVals.map((v) => `<option value="${escapeHtml(v)}">`).join('')}</datalist>`;
    } else if (col.type === 'date') {
      inputHtml = `<input type="date" value="${escapeHtml(current)}">`;
    } else if (col.type === 'datetime') {
      inputHtml = `<input type="datetime-local" value="${escapeHtml(current)}">`;
    } else if (col.type === 'number') {
      inputHtml = `<input type="number" value="${escapeHtml(current)}">`;
    } else if (col.type === 'email') {
      inputHtml = `<input type="email" value="${escapeHtml(current)}">`;
    } else {
      inputHtml = `<input type="text" value="${escapeHtml(current)}">`;
    }
    td.innerHTML = inputHtml;
    const field = td.querySelector('input, select, textarea');
    field.focus();
    if (field.select) field.select();

    const commit = async () => {
      const newVal = field.value;
      td.classList.remove('editing');
      if (newVal === current) {
        renderCellInto(td, row, col);
        return;
      }
      row.data[colKey] = newVal;
      renderCellInto(td, row, col);
      if (col.role === 'status') refreshRowStatusIndicators(row);
      try {
        await api('rows/' + rowId, { method: 'PUT', body: { data: { [colKey]: newVal } } });
      } catch (e) {
        toast('Gagal menyimpan: ' + e.message, 'error');
      }
    };
    field.addEventListener('blur', commit);
    field.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' && col.type !== 'textarea') { ev.preventDefault(); field.blur(); }
      if (ev.key === 'Escape') { td.classList.remove('editing'); renderCellInto(td, row, col); }
    });
  }

  // After a status-column value is edited inline, refresh that row's
  // tooltip, row-number dots and host-column accent without re-rendering
  // the whole table.
  function refreshRowStatusIndicators(row) {
    const tr = document.querySelector(`tr[data-row-id="${row.id}"]`);
    if (!tr) return;
    tr.title = rowStatusTooltip(row);

    const rownoTd = tr.querySelector('td.rowno');
    if (rownoTd) {
      const num = rownoTd.textContent.trim();
      rownoTd.innerHTML = renderRowNoDots(row) + num;
    }

    const hostCol = state.columns.find((c) => /host/i.test(c.label));
    if (hostCol) {
      const hostTd = tr.querySelector(`td[data-col-key="${hostCol.col_key}"]`);
      if (hostTd) {
        const tone = primaryToneForRow(row);
        hostTd.className = tone ? `cell-tone-${tone}` : '';
      }
    }
  }

  function renderCellInto(td, row, col) {
    const val = row.data[col.col_key] ?? '';
    let inner = escapeHtml(val);
    if (col.role === 'status' || col.type === 'status') {
      const cls = classifyStatus(val);
      inner = `<span class="badge badge-${cls.kind}">${escapeHtml(val) || '(Kosong)'}</span>`;
    }
    td.innerHTML = `<div class="cell-inner">${inner}</div>`;
    td.addEventListener('click', () => activateCellEditor(td));
  }

  async function addRow() {
    try {
      const created = await api('projects/' + state.currentProject.id + '/rows', { method: 'POST', body: { data: {} } });
      state.rows.push({ id: created.id, row_no: created.row_no, data: created.data });
      renderDataTab();
      toast('Baris ditambahkan');
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  async function deleteRow(id) {
    if (!confirm('Hapus baris ini?')) return;
    try {
      await api('rows/' + id, { method: 'DELETE' });
      state.rows = state.rows.filter((r) => r.id !== id);
      renderDataTab();
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  /* ---------------- Edit row via modal form ---------------- */

  function modalFieldHtml(col, value) {
    const id = 'ef_' + col.col_key;
    const wide = col.type === 'textarea' ? ' field-wide' : '';
    let input;
    if (col.type === 'textarea') {
      input = `<textarea id="${id}" rows="3">${escapeHtml(value)}</textarea>`;
    } else if (col.type === 'select') {
      const opts = (col.options || []).map((o) => `<option value="${escapeHtml(o)}" ${o === value ? 'selected' : ''}>${escapeHtml(o)}</option>`).join('');
      input = `<select id="${id}"><option value="">—</option>${opts}</select>`;
    } else if (col.type === 'status') {
      const dlId = id + '_dl';
      const uniqueVals = [...new Set(state.rows.map((r) => r.data[col.col_key]).filter(Boolean))];
      input = `<input type="text" id="${id}" list="${dlId}" value="${escapeHtml(value)}"><datalist id="${dlId}">${uniqueVals.map((v) => `<option value="${escapeHtml(v)}">`).join('')}</datalist>`;
    } else if (col.type === 'date') {
      input = `<input type="date" id="${id}" value="${escapeHtml(value)}">`;
    } else if (col.type === 'datetime') {
      input = `<input type="datetime-local" id="${id}" value="${escapeHtml(value)}">`;
    } else if (col.type === 'number') {
      input = `<input type="number" id="${id}" value="${escapeHtml(value)}">`;
    } else if (col.type === 'email') {
      input = `<input type="email" id="${id}" value="${escapeHtml(value)}">`;
    } else {
      input = `<input type="text" id="${id}" value="${escapeHtml(value)}">`;
    }
    return `<div class="field${wide}"><label for="${id}">${escapeHtml(col.label)}</label>${input}</div>`;
  }

  function openEditRowModal(rowId) {
    const row = state.rows.find((r) => r.id === rowId);
    if (!row) return;

    openModal(`
      <div class="modal-header"><h2>Edit Baris #${row.row_no ?? rowId}</h2><button class="modal-close" id="mClose">✕</button></div>
      <form id="editRowForm">
        <div class="edit-row-fields">
          ${state.columns.map((c) => modalFieldHtml(c, row.data[c.col_key] ?? '')).join('')}
        </div>
        <div class="modal-footer">
          <button type="button" class="btn" id="mCancel">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
      </form>
    `, { large: true });

    document.getElementById('mClose').addEventListener('click', closeModal);
    document.getElementById('mCancel').addEventListener('click', closeModal);
    document.getElementById('editRowForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = {};
      state.columns.forEach((c) => {
        const field = document.getElementById('ef_' + c.col_key);
        if (field) data[c.col_key] = field.value;
      });
      const submitBtn = e.target.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      try {
        await api('rows/' + rowId, { method: 'PUT', body: { data } });
        Object.assign(row.data, data);
        closeModal();
        renderDataTab();
        toast('Baris disimpan');
      } catch (err) {
        toast('Gagal menyimpan: ' + err.message, 'error');
        submitBtn.disabled = false;
      }
    });
  }

  /* ---------------- Columns tab ---------------- */

  function renderColumnsTab() {
    const el = document.getElementById('tab-columns');
    el.innerHTML = `
      <p class="muted" style="margin-bottom:14px">
        Atur kolom MOP untuk project ini. <strong>Role</strong> menentukan bagaimana kolom tampil di dashboard:
        <em>Status</em> → chart donat, <em>Grouping</em> → chart batang (mis. Tim/Site/PIC), <em>Tanggal</em> → timeline.
        Tandai satu kolom Status sebagai <strong>Utama</strong> untuk kartu progress.
      </p>
      <div class="columns-list" id="columnsList"></div>
      <div class="card" style="max-width:640px">
        <h3 style="margin-top:0">+ Tambah Kolom</h3>
        <div class="field"><label>Nama Kolom</label><input type="text" id="newColLabel" placeholder="Contoh: Status Approval"></div>
        <div class="field-row">
          <div class="field"><label>Tipe</label><select id="newColType">${COLUMN_TYPES.map((t) => `<option value="${t.v}">${t.l}</option>`).join('')}</select></div>
          <div class="field"><label>Role</label><select id="newColRole">${COLUMN_ROLES.map((r) => `<option value="${r.v}">${r.l}</option>`).join('')}</select></div>
        </div>
        <button class="btn btn-primary" id="btnAddColumn">Tambah Kolom</button>
      </div>
    `;

    const list = document.getElementById('columnsList');
    list.innerHTML = state.columns.map((c, idx) => `
      <div class="column-row" data-col-id="${c.id}">
        <input type="text" data-field="label" value="${escapeHtml(c.label)}">
        <select data-field="type">${COLUMN_TYPES.map((t) => `<option value="${t.v}" ${t.v === c.type ? 'selected' : ''}>${t.l}</option>`).join('')}</select>
        <select data-field="role">${COLUMN_ROLES.map((r) => `<option value="${r.v}" ${r.v === c.role ? 'selected' : ''}>${r.l}</option>`).join('')}</select>
        <label class="checkbox-inline"><input type="radio" name="primaryStatus" data-field="is_primary" ${c.is_primary ? 'checked' : ''}> Utama</label>
        <div class="col-order-btns">
          <button data-move="up" ${idx === 0 ? 'disabled' : ''}>▲</button>
          <button data-move="down" ${idx === state.columns.length - 1 ? 'disabled' : ''}>▼</button>
        </div>
        <span class="muted" style="font-size:11px">${escapeHtml(c.col_key)}</span>
        <button class="btn btn-icon btn-ghost btn-danger" data-del-col="${c.id}">🗑</button>
      </div>
    `).join('');

    list.querySelectorAll('.column-row').forEach((rowEl) => {
      const colId = Number(rowEl.dataset.colId);
      rowEl.querySelectorAll('[data-field]').forEach((field) => {
        const evt = field.tagName === 'SELECT' || field.type === 'radio' ? 'change' : 'blur';
        field.addEventListener(evt, () => updateColumnField(colId, field));
      });
      rowEl.querySelectorAll('[data-move]').forEach((btn) => {
        btn.addEventListener('click', () => moveColumn(colId, btn.dataset.move));
      });
    });
    list.querySelectorAll('[data-del-col]').forEach((btn) => {
      btn.addEventListener('click', () => deleteColumn(Number(btn.dataset.delCol)));
    });

    document.getElementById('btnAddColumn').addEventListener('click', addColumn);
  }

  async function updateColumnField(colId, field) {
    const key = field.dataset.field;
    let value = field.type === 'radio' ? field.checked : field.value;
    try {
      await api('columns/' + colId, { method: 'PUT', body: { [key]: value } });
      const col = state.columns.find((c) => c.id === colId);
      if (key === 'is_primary' && value) {
        state.columns.forEach((c) => { c.is_primary = c.id === colId; });
      } else {
        col[key] = value;
      }
      toast('Kolom diperbarui');
      if (key === 'label' || key === 'type') renderDataTab();
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  async function moveColumn(colId, dir) {
    const idx = state.columns.findIndex((c) => c.id === colId);
    const swapWith = dir === 'up' ? idx - 1 : idx + 1;
    if (swapWith < 0 || swapWith >= state.columns.length) return;
    [state.columns[idx], state.columns[swapWith]] = [state.columns[swapWith], state.columns[idx]];
    renderColumnsTab();
    renderDataTab();
    try {
      await api('projects/' + state.currentProject.id + '/columns/reorder', { method: 'POST', body: { ids: state.columns.map((c) => c.id) } });
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  async function deleteColumn(colId) {
    const col = state.columns.find((c) => c.id === colId);
    if (!confirm(`Hapus kolom "${col.label}"? Data pada kolom ini di semua baris akan hilang.`)) return;
    try {
      await api('columns/' + colId, { method: 'DELETE' });
      state.columns = state.columns.filter((c) => c.id !== colId);
      renderColumnsTab();
      renderDataTab();
      toast('Kolom dihapus');
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  async function addColumn() {
    const label = document.getElementById('newColLabel').value.trim();
    if (!label) { toast('Nama kolom wajib diisi', 'error'); return; }
    const type = document.getElementById('newColType').value;
    const role = document.getElementById('newColRole').value;
    try {
      const created = await api('projects/' + state.currentProject.id + '/columns', { method: 'POST', body: { label, type, role } });
      state.columns.push({ id: created.id, col_key: created.col_key, label, type, role, options: [], is_primary: false, required: false, width: null });
      renderColumnsTab();
      renderDataTab();
      toast('Kolom ditambahkan');
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  /* ---------------- Import / Export tab ---------------- */

  function renderIoTab() {
    const el = document.getElementById('tab-io');
    el.innerHTML = `
      <div class="io-grid">
        <div class="card">
          <h3 style="margin-top:0">Import Data (CSV)</h3>
          <p class="muted" style="margin-top:-6px">Tempel isi CSV, atau upload file. Kolom akan dicocokkan otomatis berdasarkan nama header; header baru otomatis dibuatkan kolom.</p>
          <div class="field"><label>Upload file .csv</label><input type="file" id="csvFile" accept=".csv,.txt"></div>
          <div class="field"><label>Atau tempel CSV di sini</label><textarea id="csvText" rows="8" placeholder="No;VM Name;...\n1;sarinadapp1;..."></textarea></div>
          <div class="field-row">
            <div class="field"><label>Baris ke berapa header-nya?</label><input type="number" id="headerRow" value="1" min="1"></div>
            <div class="field"><label>Delimiter</label>
              <select id="csvDelimiter"><option value="auto">Deteksi otomatis</option><option value=";">Titik koma ( ; )</option><option value=",">Koma ( , )</option></select>
            </div>
          </div>
          <div style="display:flex;gap:8px">
            <button class="btn" id="btnPreviewImport">Preview</button>
            <button class="btn btn-primary" id="btnCommitImport" disabled>Import Sekarang</button>
          </div>
          <div id="importResult" style="margin-top:14px"></div>
        </div>
        <div class="card">
          <h3 style="margin-top:0">Export Data</h3>
          <p class="muted" style="margin-top:-6px">Unduh seluruh data project ini sebagai CSV (delimiter titik koma, siap dibuka di Excel).</p>
          <a class="btn btn-primary" href="api.php?path=projects/${state.currentProject.id}/export">⬇ Download CSV</a>
          <h3 style="margin-top:22px">Kolom saat ini</h3>
          <p class="muted" style="margin-top:-6px">CSV dengan header persis nama-nama berikut akan otomatis cocok saat diimport:</p>
          <div style="display:flex;flex-wrap:wrap;gap:6px">
            ${state.columns.map((c) => `<span class="chip">${escapeHtml(c.label)}</span>`).join('')}
          </div>
        </div>
      </div>
    `;

    document.getElementById('csvFile').addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      // Excel "CSV (Comma delimited)" saves as Windows-1252, not UTF-8 —
      // decoding that as UTF-8 turns chars like "–" into "?". Try UTF-8 strictly first.
      const buf = await file.arrayBuffer();
      let text;
      try {
        text = new TextDecoder('utf-8', { fatal: true }).decode(buf);
      } catch {
        text = new TextDecoder('windows-1252').decode(buf);
      }
      document.getElementById('csvText').value = text.replace(/^﻿/, '');
    });
    document.getElementById('btnPreviewImport').addEventListener('click', doPreviewImport);
    document.getElementById('btnCommitImport').addEventListener('click', doCommitImport);
  }

  async function doPreviewImport() {
    const csv = document.getElementById('csvText').value;
    const header_row = Number(document.getElementById('headerRow').value) || 1;
    const delimiter = document.getElementById('csvDelimiter').value;
    if (!csv.trim()) { toast('CSV masih kosong', 'error'); return; }
    try {
      const res = await api('projects/' + state.currentProject.id + '/preview-import', { method: 'POST', body: { csv, header_row, delimiter } });
      state.importParams = { csv, header_row, delimiter };
      renderImportPreview(res);
      document.getElementById('btnCommitImport').disabled = false;
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  function renderImportPreview(res) {
    const matched = res.mapping.filter((m) => !m.will_create).length;
    const created = res.mapping.filter((m) => m.will_create).length;
    const box = document.getElementById('importResult');
    box.innerHTML = `
      <p><strong>${res.total_data_rows}</strong> baris data ditemukan · <strong>${matched}</strong> kolom cocok, <strong>${created}</strong> kolom baru akan dibuat · delimiter: <code>${escapeHtml(res.delimiter)}</code></p>
      <table class="mapping-table">
        <thead><tr><th>Header CSV</th><th>Status</th></tr></thead>
        <tbody>
          ${res.mapping.map((m) => `<tr><td>${escapeHtml(m.header)}</td><td>${m.will_create ? '<span class="tag tag-new">Kolom baru</span>' : '<span class="tag tag-match">Cocok: ' + escapeHtml(m.matched_label) + '</span>'}</td></tr>`).join('')}
        </tbody>
      </table>
      <p class="muted" style="margin-top:10px">Contoh baris pertama:</p>
      <div class="table-scroll" style="max-height:180px">
        <table class="data-table">
          <thead><tr>${res.mapping.map((m) => `<th>${escapeHtml(m.header)}</th>`).join('')}</tr></thead>
          <tbody>
            ${res.preview.map((row) => `<tr>${res.mapping.map((m) => `<td><div class="cell-inner">${escapeHtml(row[m.header])}</div></td>`).join('')}</tr>`).join('')}
          </tbody>
        </table>
      </div>
    `;
  }

  async function doCommitImport() {
    if (!state.importParams) return;
    try {
      const res = await api('projects/' + state.currentProject.id + '/commit-import', { method: 'POST', body: state.importParams });
      toast(`Import selesai: ${res.imported} baris ditambahkan${res.created_columns.length ? ', ' + res.created_columns.length + ' kolom baru dibuat' : ''}`, 'success');
      document.getElementById('btnCommitImport').disabled = true;
      document.getElementById('csvText').value = '';
      document.getElementById('importResult').innerHTML = '';
      state.importParams = null;

      const project = await api('projects/' + state.currentProject.id);
      state.currentProject = project;
      state.columns = project.columns;
      await refreshRows();
      await loadProjects();
      renderSidebar();
      renderColumnsTab();
      renderDataTab();
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  /* ---------------- Column show/hide filter ---------------- */

  function openColumnFilterModal() {
    const renderList = () => state.columns.map((c) => `
      <label class="checkbox-inline colvis-item">
        <input type="checkbox" data-colvis="${c.col_key}" ${state.hiddenCols.has(c.col_key) ? '' : 'checked'}>
        ${escapeHtml(c.label)}
      </label>
    `).join('');

    openModal(`
      <div class="modal-header"><h2>Filter Kolom</h2><button class="modal-close" id="mClose">✕</button></div>
      <p class="muted" style="margin-top:-6px">Centang kolom yang ingin ditampilkan di tabel Data MOP. Preferensi ini disimpan per project di browser ini.</p>
      <div class="colvis-actions">
        <button class="btn btn-sm" id="colvisAll">Tampilkan Semua</button>
        <button class="btn btn-sm" id="colvisNone">Sembunyikan Semua</button>
      </div>
      <div class="colvis-list" id="colvisList">${renderList()}</div>
      <div class="modal-footer"><button class="btn btn-primary" id="mDone">Selesai</button></div>
    `);

    const list = document.getElementById('colvisList');
    const bindCheckboxes = () => {
      list.querySelectorAll('[data-colvis]').forEach((cb) => {
        cb.addEventListener('change', () => {
          if (cb.checked) state.hiddenCols.delete(cb.dataset.colvis);
          else state.hiddenCols.add(cb.dataset.colvis);
          saveHiddenCols();
          renderDataTab();
        });
      });
    };
    bindCheckboxes();

    document.getElementById('colvisAll').addEventListener('click', () => {
      state.hiddenCols.clear();
      saveHiddenCols();
      list.innerHTML = renderList();
      bindCheckboxes();
      renderDataTab();
    });
    document.getElementById('colvisNone').addEventListener('click', () => {
      state.columns.forEach((c) => state.hiddenCols.add(c.col_key));
      saveHiddenCols();
      list.innerHTML = renderList();
      bindCheckboxes();
      renderDataTab();
    });
    document.getElementById('mClose').addEventListener('click', closeModal);
    document.getElementById('mDone').addEventListener('click', closeModal);
  }

  /* ---------------- Modals: new / edit project ---------------- */

  function openModal(html, { large = false } = {}) {
    const root = document.getElementById('modalRoot');
    root.innerHTML = `<div class="modal-backdrop" id="modalBackdrop"><div class="modal ${large ? 'modal-lg' : ''}">${html}</div></div>`;
    document.getElementById('modalBackdrop').addEventListener('click', (e) => {
      if (e.target.id === 'modalBackdrop') closeModal();
    });
  }
  function closeModal() {
    document.getElementById('modalRoot').innerHTML = '';
  }

  async function openNewProjectModal() {
    let templates = [];
    try { templates = await api('templates'); } catch (e) { /* ignore */ }
    let selected = templates[0] ? templates[0].key : 'blank';

    const renderTemplates = () => templates.map((t) => `
      <div class="template-option ${t.key === selected ? 'selected' : ''}" data-tpl="${t.key}">
        <div class="t-title">${escapeHtml(t.label)}</div>
        <div class="t-desc">${escapeHtml(t.description)}</div>
      </div>
    `).join('');

    openModal(`
      <div class="modal-header"><h2>Project MOP Baru</h2><button class="modal-close" id="mClose">✕</button></div>
      <div class="field"><label>Nama Project</label><input type="text" id="mName" placeholder="Contoh: Migrasi VM Produksi Q1"></div>
      <div class="field"><label>Deskripsi (opsional)</label><textarea id="mDesc" rows="2"></textarea></div>
      <div class="field"><label>Format MOP</label><div class="template-options" id="mTemplates">${renderTemplates()}</div></div>
      <div class="modal-footer">
        <button class="btn" id="mCancel">Batal</button>
        <button class="btn btn-primary" id="mSubmit">Buat Project</button>
      </div>
    `, { large: true });

    document.getElementById('mClose').addEventListener('click', closeModal);
    document.getElementById('mCancel').addEventListener('click', closeModal);
    document.getElementById('mTemplates').addEventListener('click', (e) => {
      const opt = e.target.closest('.template-option');
      if (!opt) return;
      selected = opt.dataset.tpl;
      document.getElementById('mTemplates').innerHTML = renderTemplates();
    });
    document.getElementById('mSubmit').addEventListener('click', async () => {
      const name = document.getElementById('mName').value.trim();
      if (!name) { toast('Nama project wajib diisi', 'error'); return; }
      const description = document.getElementById('mDesc').value.trim();
      try {
        const project = await api('projects', { method: 'POST', body: { name, description, template: selected } });
        closeModal();
        await loadProjects();
        await selectProject(project.id);
        toast('Project dibuat');
      } catch (e) {
        toast(e.message, 'error');
      }
    });
  }

  function openEditProjectModal() {
    const p = state.currentProject;
    openModal(`
      <div class="modal-header"><h2>Edit Project</h2><button class="modal-close" id="mClose">✕</button></div>
      <div class="field"><label>Nama Project</label><input type="text" id="mName" value="${escapeHtml(p.name)}"></div>
      <div class="field"><label>Deskripsi</label><textarea id="mDesc" rows="3">${escapeHtml(p.description || '')}</textarea></div>
      <div class="modal-footer">
        <button class="btn" id="mCancel">Batal</button>
        <button class="btn btn-primary" id="mSubmit">Simpan</button>
      </div>
    `);
    document.getElementById('mClose').addEventListener('click', closeModal);
    document.getElementById('mCancel').addEventListener('click', closeModal);
    document.getElementById('mSubmit').addEventListener('click', async () => {
      const name = document.getElementById('mName').value.trim();
      const description = document.getElementById('mDesc').value.trim();
      try {
        const updated = await api('projects/' + p.id, { method: 'PUT', body: { name, description } });
        state.currentProject = updated;
        document.getElementById('projectName').textContent = updated.name;
        document.getElementById('projectDesc').textContent = updated.description || '';
        closeModal();
        await loadProjects();
        renderSidebar();
        toast('Project diperbarui');
      } catch (e) {
        toast(e.message, 'error');
      }
    });
  }

  async function deleteCurrentProject() {
    const p = state.currentProject;
    if (!confirm(`Hapus project "${p.name}"? Semua data & kolom akan hilang permanen.`)) return;
    try {
      await api('projects/' + p.id, { method: 'DELETE' });
      state.currentProject = null;
      document.getElementById('projectView').classList.add('hidden');
      document.getElementById('emptyState').classList.remove('hidden');
      localStorage.removeItem('mopboard:lastProject');
      await loadProjects();
      if (state.projects.length) await selectProject(state.projects[0].id);
      toast('Project dihapus');
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  /* ---------------- Static events ---------------- */

  function bindStaticEvents() {
    document.getElementById('btnNewProject').addEventListener('click', openNewProjectModal);
    document.getElementById('btnEditProject').addEventListener('click', openEditProjectModal);
    document.getElementById('btnDeleteProject').addEventListener('click', deleteCurrentProject);
    document.getElementById('projectSearch').addEventListener('input', (e) => {
      state.projectSearch = e.target.value;
      renderSidebar();
    });
    document.querySelectorAll('.tab-btn').forEach((btn) => {
      btn.addEventListener('click', () => setActiveTab(btn.dataset.tab));
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeModal();
    });
  }

  init();
})();
