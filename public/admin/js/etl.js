// admin/js/etl.js — ETL admin module (MySQL → PostgreSQL import)
// 4 tabs: Connection, Jobs, Schedule, History.
// Persists the "etl" config via etl_save (optimistic-lock version).
// Cron worker: cron/cron_etl.php.
import { apiFetch } from '../../assets/js/util/api.js';
import { buildInnerTabs } from './ui.js';
import { escHtml } from '../../assets/js/util/esc.js';

let etlConfig  = null;
let etlVersion = 0;

function mkStatus() {
    const el = document.createElement('p');
    el.style.cssText = 'margin-top:10px; display:none;';
    return el;
}
function showStatus(el, msg, ok) {
    el.textContent = msg;
    el.style.color = ok ? 'var(--ok)' : '#a80000';
    el.style.display = '';
}
function fg(label, node) {
    const g = document.createElement('div');
    g.className = 'form-group';
    const l = document.createElement('label');
    l.textContent = label;
    g.append(l, node);
    return g;
}
function input(value, type = 'text') {
    const i = document.createElement('input');
    i.type = type;
    i.className = 'adm-input';
    i.value = value ?? '';
    return i;
}

async function saveConfig(statusEl) {
    // Rebuild the connection password: keep the stored one unless the user typed a new value.
    const payload = { ...etlConfig, version: etlVersion };
    try {
        const res  = await apiFetch('api.php?action=etl_save', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.status === 'success') {
            etlVersion = data.version;
            if (statusEl) showStatus(statusEl, 'Configuration saved.', true);
            return true;
        }
        if (statusEl) showStatus(statusEl, data.error || 'Save failed.', false);
    } catch (_) {
        if (statusEl) showStatus(statusEl, 'Network error while saving.', false);
    }
    return false;
}

/* ---------- Connection tab ---------- */
function renderConnectionTab(panel) {
    const conn = etlConfig.connection;
    const status = mkStatus();

    const host = input(conn.host);
    host.oninput = () => { conn.host = host.value; };
    const port = input(String(conn.port ?? 3306), 'number');
    port.oninput = () => { conn.port = parseInt(port.value, 10) || 3306; };
    const db = input(conn.database);
    db.oninput = () => { conn.database = db.value; };
    const user = input(conn.user);
    user.oninput = () => { conn.user = user.value; };
    const pass = input(conn.password || '', 'password');
    pass.placeholder = conn.password === '********' ? 'Leave to keep current' : '';
    pass.oninput = () => { conn.password = pass.value; };

    panel.append(
        fg('Host', host),
        fg('Port', port),
        fg('Database', db),
        fg('User', user),
        fg('Password', pass),
    );

    const btnTest = document.createElement('button');
    btnTest.className = 'btn';
    btnTest.textContent = 'Test connection';
    btnTest.onclick = async () => {
        showStatus(status, 'Testing…', true);
        try {
            const res  = await apiFetch('api.php?action=etl_test_connection', {
                method: 'POST',
                body: JSON.stringify({ connection: conn }),
            });
            const data = await res.json();
            showStatus(status, data.status === 'success' ? (data.message || 'Connection OK.') : (data.error || 'Failed.'), data.status === 'success');
        } catch (_) {
            showStatus(status, 'Network error.', false);
        }
    };

    const btnSave = document.createElement('button');
    btnSave.className = 'btn btn-success';
    btnSave.textContent = 'Save configuration';
    btnSave.style.marginLeft = '8px';
    btnSave.onclick = () => saveConfig(status);

    const bar = document.createElement('div');
    bar.style.marginTop = '12px';
    bar.append(btnTest, btnSave);
    panel.append(bar, status);
}

/* ---------- Jobs tab ---------- */
function renderJobsTab(panel) {
    const status = mkStatus();
    const list = document.createElement('div');

    function redraw() {
        list.innerHTML = '';
        etlConfig.jobs.forEach((job, idx) => list.appendChild(buildJobCard(job, idx, redraw, status)));
    }

    const btnAdd = document.createElement('button');
    btnAdd.className = 'btn btn-success';
    btnAdd.textContent = '+ Add job';
    btnAdd.onclick = () => {
        etlConfig.jobs.push({
            id: '', name: 'New job', source_query: '', target_table: '',
            load_mode: 'full_refresh', upsert_key: [], enabled: true,
        });
        redraw();
    };

    const btnSave = document.createElement('button');
    btnSave.className = 'btn';
    btnSave.textContent = 'Save configuration';
    btnSave.style.marginLeft = '8px';
    btnSave.onclick = () => saveConfig(status);

    const bar = document.createElement('div');
    bar.style.marginBottom = '12px';
    bar.append(btnAdd, btnSave);
    panel.append(bar, status, list);
    redraw();
}

function buildJobCard(job, idx, redraw, status) {
    const card = document.createElement('div');
    card.className = 'column-block';

    const hdr = document.createElement('div');
    hdr.className = 'block-header';
    const chevron = document.createElement('span');
    chevron.className = 'block-chevron';
    chevron.textContent = '▶';
    const title = document.createElement('strong');
    title.className = 'block-title';
    title.textContent = job.name || '(unnamed job)';

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'icon-btn icon-btn-danger';
    del.title = 'Delete';
    del.textContent = '✕';
    del.onclick = (e) => {
        e.stopPropagation();
        if (!confirm(`Delete job "${job.name}"?`)) return;
        etlConfig.jobs.splice(idx, 1);
        redraw();
    };
    hdr.append(chevron, title, del);
    hdr.onclick = (e) => { if (!e.target.closest('button')) card.classList.toggle('collapsed'); };
    card.appendChild(hdr);
    card.classList.add('collapsed');

    const body = document.createElement('div');
    body.className = 'block-body';

    const name = input(job.name);
    name.oninput = () => { job.name = name.value; title.textContent = job.name || '(unnamed job)'; };

    const query = document.createElement('textarea');
    query.className = 'adm-input';
    query.rows = 4;
    query.style.resize = 'vertical';
    query.value = job.source_query || '';
    query.oninput = () => { job.source_query = query.value; };

    const target = input(job.target_table);
    target.oninput = () => { job.target_table = target.value; };

    const mode = document.createElement('select');
    mode.className = 'adm-input';
    [['full_refresh', 'Full refresh (truncate + insert)'], ['append', 'Append'], ['upsert', 'Upsert (by key)']]
        .forEach(([v, lbl]) => {
            const o = document.createElement('option');
            o.value = v; o.textContent = lbl;
            if (job.load_mode === v) o.selected = true;
            mode.appendChild(o);
        });

    const keyGrp = fg('Upsert key column(s), comma-separated', (() => {
        const k = input((job.upsert_key || []).join(', '));
        k.oninput = () => { job.upsert_key = k.value.split(',').map(s => s.trim()).filter(Boolean); };
        return k;
    })());
    keyGrp.style.display = job.load_mode === 'upsert' ? '' : 'none';
    mode.onchange = () => { job.load_mode = mode.value; keyGrp.style.display = mode.value === 'upsert' ? '' : 'none'; };

    const enabled = input('', 'checkbox');
    enabled.className = 'adm-check';
    enabled.checked = job.enabled !== false;
    enabled.onchange = () => { job.enabled = enabled.checked; };
    const enabledLbl = document.createElement('label');
    enabledLbl.style.cssText = 'display:flex; align-items:center; gap:8px;';
    enabledLbl.append(enabled, document.createTextNode('Enabled (runs on schedule)'));

    body.append(
        fg('Name', name),
        fg('Source query (MySQL, read-only SELECT)', query),
        fg('Target table (PostgreSQL)', target),
        fg('Load mode', mode),
        keyGrp,
        fg('', enabledLbl),
    );

    const out = document.createElement('pre');
    out.className = 'adm-input';
    out.style.cssText = 'white-space:pre-wrap; max-height:220px; overflow:auto; display:none;';

    const btnPreview = document.createElement('button');
    btnPreview.className = 'btn';
    btnPreview.textContent = 'Preview';
    btnPreview.onclick = async () => {
        out.style.display = '';
        out.textContent = 'Loading preview…';
        try {
            const res  = await apiFetch('api.php?action=etl_preview', {
                method: 'POST',
                body: JSON.stringify({ connection: etlConfig.connection, source_query: job.source_query }),
            });
            const data = await res.json();
            if (data.status !== 'success') { out.textContent = 'Error: ' + (data.error || 'preview failed'); return; }
            out.textContent = renderPreview(data.columns, data.rows);
        } catch (_) { out.textContent = 'Network error.'; }
    };

    const btnRun = document.createElement('button');
    btnRun.className = 'btn btn-success';
    btnRun.textContent = 'Run now';
    btnRun.style.marginLeft = '8px';
    btnRun.onclick = async () => {
        if (!(await saveConfig(status))) return; // persist so the cron reads the latest job
        out.style.display = '';
        out.textContent = 'Running…';
        try {
            const res  = await apiFetch('api.php?action=run_etl', {
                method: 'POST',
                body: JSON.stringify({ job_id: job.id }),
            });
            const data = await res.json();
            out.textContent = data.output || (data.error || 'No output.');
        } catch (_) { out.textContent = 'Network error.'; }
    };

    const btnBar = document.createElement('div');
    btnBar.style.marginTop = '10px';
    btnBar.append(btnPreview, btnRun);
    body.append(btnBar, out);

    card.appendChild(body);
    return card;
}

function renderPreview(columns, rows) {
    if (!rows || rows.length === 0) return 'No rows.';
    const head = columns.join(' | ');
    const lines = rows.slice(0, 20).map(r => columns.map(c => String(r[c] ?? '')).join(' | '));
    return head + '\n' + '-'.repeat(head.length) + '\n' + lines.join('\n');
}

/* ---------- Schedule tab ---------- */
function renderScheduleTab(panel) {
    const status = mkStatus();

    const enabled = input('', 'checkbox');
    enabled.className = 'adm-check';
    enabled.checked = !!etlConfig.enabled;
    enabled.onchange = () => { etlConfig.enabled = enabled.checked; };
    const enabledLbl = document.createElement('label');
    enabledLbl.style.cssText = 'display:flex; align-items:center; gap:8px;';
    enabledLbl.append(enabled, document.createTextNode('Enable scheduled ETL runs'));

    const freq = document.createElement('select');
    freq.className = 'adm-input';
    [['manual', 'Manual only'], ['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly']]
        .forEach(([v, lbl]) => {
            const o = document.createElement('option');
            o.value = v; o.textContent = lbl;
            if ((etlConfig.frequency || 'daily') === v) o.selected = true;
            freq.appendChild(o);
        });
    freq.onchange = () => { etlConfig.frequency = freq.value; };

    const btnSave = document.createElement('button');
    btnSave.className = 'btn btn-success';
    btnSave.textContent = 'Save configuration';
    btnSave.onclick = () => saveConfig(status);

    const guide = document.createElement('div');
    guide.className = 'c-muted';
    guide.style.marginTop = '16px';
    guide.innerHTML = 'Add to crontab to run scheduled jobs (respects the frequency window):<br>'
        + '<code>' + escHtml('0 * * * * php /path/to/cron/cron_etl.php') + '</code>';

    panel.append(fg('', enabledLbl), fg('Frequency', freq), btnSave, status, guide);
}

/* ---------- History tab ---------- */
async function renderHistoryTab(panel) {
    const status = mkStatus();
    const tableWrap = document.createElement('div');
    tableWrap.textContent = 'Loading…';

    const btnPurge = document.createElement('button');
    btnPurge.className = 'btn';
    btnPurge.textContent = 'Purge logs older than 90 days';
    btnPurge.onclick = async () => {
        if (!confirm('Delete ETL log entries older than 90 days?')) return;
        try {
            const res  = await apiFetch('api.php?action=etl_purge_log', {
                method: 'POST', body: JSON.stringify({ days: 90 }),
            });
            const data = await res.json();
            showStatus(status, data.status === 'success' ? `Deleted ${data.deleted} row(s).` : (data.error || 'Failed.'), data.status === 'success');
            load();
        } catch (_) { showStatus(status, 'Network error.', false); }
    };

    panel.append(btnPurge, status, tableWrap);

    async function load() {
        tableWrap.textContent = 'Loading…';
        try {
            const res  = await apiFetch('api.php?action=etl_log');
            const data = await res.json();
            if (data.status !== 'success') { tableWrap.textContent = data.error || 'Failed to load.'; return; }
            if (data.note && (!data.rows || data.rows.length === 0)) { tableWrap.textContent = data.note; return; }
            if (!data.rows || data.rows.length === 0) { tableWrap.textContent = 'No runs yet.'; return; }
            tableWrap.innerHTML = buildHistoryTable(data.rows);
        } catch (_) { tableWrap.textContent = 'Network error.'; }
    }
    load();
}

function buildHistoryTable(rows) {
    const head = ['Started', 'Job', 'Trigger', 'Status', 'Read', 'Written', 'Duration (s)', 'Error']
        .map(h => `<th>${escHtml(h)}</th>`).join('');
    const body = rows.map(r => {
        const dur = r.duration_sec != null ? Math.round(parseFloat(r.duration_sec)) : '';
        const cells = [
            r.started_at || '', r.job_name || '', r.triggered_by || '', r.status || '',
            r.rows_read || '0', r.rows_written || '0', dur, r.error_message || '',
        ];
        return '<tr>' + cells.map(c => `<td>${escHtml(String(c))}</td>`).join('') + '</tr>';
    }).join('');
    return `<div style="overflow-x:auto"><table class="adm-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
}

/* ---------- entry ---------- */
export async function renderEtlPage(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '<p class="c-muted" style="padding:16px;">Loading ETL configuration…</p>';

    try {
        const res  = await apiFetch('api.php?action=etl_load');
        const data = await res.json();
        if (data.status !== 'success') {
            workspaceEl.innerHTML = `<p style="color:#a80000; padding:16px;">${escHtml(data.error || 'Failed to load config.')}</p>`;
            return;
        }
        etlConfig  = data.config;
        etlVersion = data.version || 0;
    } catch (_) {
        workspaceEl.innerHTML = '<p style="color:#a80000; padding:16px;">Network error loading ETL config.</p>';
        return;
    }

    if (!etlConfig.connection) etlConfig.connection = { host: '', port: 3306, database: '', user: '', password: '' };
    if (!Array.isArray(etlConfig.jobs)) etlConfig.jobs = [];

    workspaceEl.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.style.cssText = 'padding:20px 24px; max-width:900px;';
    const intro = document.createElement('div');
    intro.innerHTML = '<h2 style="margin:0 0 4px;">ETL — MySQL → PostgreSQL import</h2>'
        + '<p class="c-muted" style="margin:0 0 16px;">Extract data from an external MySQL source and load it into PostgreSQL tables. Data lands natively in PostgreSQL — external tables are not shown live.</p>';
    wrap.appendChild(intro);
    workspaceEl.appendChild(wrap);

    const [connPanel, jobsPanel, schedPanel, histPanel] = buildInnerTabs(wrap, [
        { label: 'Connection' }, { label: 'Jobs' }, { label: 'Schedule' }, { label: 'History' },
    ]);
    renderConnectionTab(connPanel);
    renderJobsTab(jobsPanel);
    renderScheduleTab(schedPanel);
    renderHistoryTab(histPanel);
}
