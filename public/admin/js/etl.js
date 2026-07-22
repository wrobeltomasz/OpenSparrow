// admin/js/etl.js — ETL admin module (external source → PostgreSQL import; MySQL,
// MariaDB, PostgreSQL, SQLite)
// 5 tabs: Sources (2+ named source connections), Jobs (each picks a source), Schedule,
// History, Flows (ordered chains of existing jobs — see etl_flow.js).
// Persists the "etl" config via etl_save (optimistic-lock version).
// Cron worker: cron/cron_etl.php.
import { apiFetch } from '../../assets/js/util/api.js';
import { buildInnerTabs } from './ui.js';
import { escHtml } from '../../assets/js/util/esc.js';
import { renderFlowsTab } from './etl_flow.js';
import {
    mkStatus, showStatus, fg, input, checkbox,
    buildCollapsibleCard, buildHistoryTable, persistConfig, runCronAction,
} from './etl_common.js';

let etlConfig  = null;
let etlVersion = 0;

let schemasPromise = null;
function fetchTargetSchemas() {
    if (!schemasPromise) {
        schemasPromise = apiFetch('api.php?action=etl_target_schemas')
            .then(res => res.json())
            .then(data => (data.status === 'success' ? data.schemas : []))
            .catch(() => []);
    }
    return schemasPromise;
}

const tablesCache = new Map();
function fetchTargetTables(schema) {
    if (!schema) return Promise.resolve([]);
    if (!tablesCache.has(schema)) {
        tablesCache.set(schema, apiFetch('api.php?action=etl_target_tables&schema=' + encodeURIComponent(schema))
            .then(res => res.json())
            .then(data => (data.status === 'success' ? data.tables : []))
            .catch(() => []));
    }
    return tablesCache.get(schema);
}

async function saveConfig(statusEl) {
    const result = await persistConfig('etl_save', { ...etlConfig, version: etlVersion });
    if (result.ok) {
        etlVersion = result.version;
        if (statusEl) showStatus(statusEl, 'Configuration saved.', true);
        return true;
    }
    if (statusEl) showStatus(statusEl, result.error, false);
    return false;
}

const DRIVER_PORTS = { mysql: 3306, mariadb: 3306, pgsql: 5432, sqlite: 0, csv_ftp: 21 };
const DRIVER_LABELS = [
    ['mysql', 'MySQL'],
    ['mariadb', 'MariaDB'],
    ['pgsql', 'PostgreSQL'],
    ['sqlite', 'SQLite'],
    ['csv_ftp', 'CSV file (FTP/FTPS)'],
];
const FILE_DRIVERS = ['sqlite'];
const REMOTE_FILE_DRIVERS = ['csv_ftp'];

function sourceLabel(src) {
    if (REMOTE_FILE_DRIVERS.includes(src.driver)) {
        return (src.name || '(unnamed source)') + ' — ' + (src.protocol || 'ftp') + '://' + (src.host || '?') + '/' + (src.file_name || '?');
    }
    const where = FILE_DRIVERS.includes(src.driver) ? (src.database || '?') : (src.host || '?');
    return (src.name || '(unnamed source)') + ' — ' + (src.driver || 'mysql') + '://' + where;
}

/* ---------- Sources tab ---------- */
function renderSourcesTab(panel) {
    const status = mkStatus();
    const list = document.createElement('div');

    function redraw() {
        list.innerHTML = '';
        etlConfig.sources.forEach((src, idx) => list.appendChild(buildSourceCard(src, idx, redraw, status)));
    }

    const btnAdd = document.createElement('button');
    btnAdd.className = 'btn btn-success';
    btnAdd.textContent = '+ Add source';
    btnAdd.onclick = () => {
        etlConfig.sources.push({
            id: '', name: 'New source', driver: 'mysql', host: '', port: 3306,
            database: '', user: '', password: '',
            protocol: 'ftp', remote_dir: '', file_name: '', csv_delimiter: ',',
            csv_has_header: true, passive_mode: true,
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

function buildSourceCard(src, idx, redraw, status) {
    const { card, body, title } = buildCollapsibleCard({
        titleText: src.name,
        placeholder: '(unnamed source)',
        confirmMsg: `Delete source "${src.name}"? Jobs using it will need a new source assigned.`,
        onDelete: () => { etlConfig.sources.splice(idx, 1); redraw(); },
    });

    const name = input(src.name);
    name.oninput = () => { src.name = name.value; title.textContent = src.name || '(unnamed source)'; };

    const driver = document.createElement('select');
    driver.className = 'adm-input';
    DRIVER_LABELS.forEach(([v, lbl]) => {
        const o = document.createElement('option');
        o.value = v; o.textContent = lbl;
        if ((src.driver || 'mysql') === v) o.selected = true;
        driver.appendChild(o);
    });

    const host = input(src.host);
    host.oninput = () => { src.host = host.value; };
    const port = input(String(src.port ?? DRIVER_PORTS[src.driver] ?? 3306), 'number');
    port.oninput = () => { src.port = parseInt(port.value, 10) || (DRIVER_PORTS[src.driver] ?? 3306); };
    const db = input(src.database);
    db.oninput = () => { src.database = db.value; };
    const user = input(src.user);
    user.oninput = () => { src.user = user.value; };
    const pass = input(src.password || '', 'password');
    pass.placeholder = src.password === '********' ? 'Leave to keep current' : '';
    pass.oninput = () => { src.password = pass.value; };

    const protocol = document.createElement('select');
    protocol.className = 'adm-input';
    [['ftp', 'FTP'], ['ftps', 'FTPS (FTP over TLS)']].forEach(([v, lbl]) => {
        const o = document.createElement('option');
        o.value = v; o.textContent = lbl;
        if ((src.protocol || 'ftp') === v) o.selected = true;
        protocol.appendChild(o);
    });
    protocol.onchange = () => { src.protocol = protocol.value; };

    const remoteDir = input(src.remote_dir || '');
    remoteDir.placeholder = '/exports (leave empty for the login directory)';
    remoteDir.oninput = () => { src.remote_dir = remoteDir.value; };

    const fileName = input(src.file_name || '');
    fileName.placeholder = 'export.csv';
    fileName.oninput = () => { src.file_name = fileName.value; };

    const csvDelimiter = input(src.csv_delimiter || ',');
    csvDelimiter.maxLength = 1;
    csvDelimiter.oninput = () => { src.csv_delimiter = csvDelimiter.value.slice(0, 1) || ','; };

    const csvHasHeaderLbl = checkbox('First row is a header row', src.csv_has_header !== false, (v) => { src.csv_has_header = v; }).label;
    const passiveModeLbl = checkbox('Passive mode (usually required behind NAT/firewalls)', src.passive_mode !== false, (v) => { src.passive_mode = v; }).label;

    const hostGrp          = fg('Host', host);
    const portGrp           = fg('Port', port);
    const dbGrp              = fg('Database', db);
    const userGrp           = fg('User', user);
    const passGrp           = fg('Password', pass);
    const protocolGrp       = fg('Protocol', protocol);
    const remoteDirGrp      = fg('Remote directory', remoteDir);
    const fileNameGrp       = fg('CSV file name', fileName);
    const csvDelimiterGrp   = fg('Column delimiter', csvDelimiter);
    const csvHasHeaderGrp   = fg('', csvHasHeaderLbl);
    const passiveModeGrp    = fg('', passiveModeLbl);

    function applyDriverVisibility() {
        const isFile   = FILE_DRIVERS.includes(src.driver);
        const isRemote = REMOTE_FILE_DRIVERS.includes(src.driver);
        hostGrp.style.display = isFile ? 'none' : '';
        portGrp.style.display = isFile ? 'none' : '';
        userGrp.style.display = isFile ? 'none' : '';
        passGrp.style.display = isFile ? 'none' : '';
        dbGrp.style.display   = isRemote ? 'none' : '';
        protocolGrp.style.display     = isRemote ? '' : 'none';
        remoteDirGrp.style.display    = isRemote ? '' : 'none';
        fileNameGrp.style.display     = isRemote ? '' : 'none';
        csvDelimiterGrp.style.display = isRemote ? '' : 'none';
        csvHasHeaderGrp.style.display = isRemote ? '' : 'none';
        passiveModeGrp.style.display  = isRemote ? '' : 'none';
        dbGrp.querySelector('label').textContent = isFile ? 'Database file path' : 'Database';
        db.placeholder = isFile ? '/path/to/database.sqlite' : '';
    }

    driver.onchange = () => {
        const oldDefault = DRIVER_PORTS[src.driver];
        src.driver = driver.value;
        if (!port.value || parseInt(port.value, 10) === oldDefault) {
            port.value = String(DRIVER_PORTS[src.driver] ?? '');
            src.port = DRIVER_PORTS[src.driver];
        }
        applyDriverVisibility();
    };

    body.append(
        fg('Name', name),
        fg('Source type', driver),
        hostGrp,
        portGrp,
        protocolGrp,
        remoteDirGrp,
        fileNameGrp,
        csvDelimiterGrp,
        csvHasHeaderGrp,
        passiveModeGrp,
        dbGrp,
        userGrp,
        passGrp,
    );
    applyDriverVisibility();

    const testStatus = mkStatus();
    const btnTest = document.createElement('button');
    btnTest.className = 'btn';
    btnTest.textContent = 'Test connection';
    btnTest.onclick = async () => {
        showStatus(testStatus, 'Testing…', true);
        try {
            const res  = await apiFetch('api.php?action=etl_test_connection', {
                method: 'POST',
                body: JSON.stringify({ connection: src }),
            });
            const data = await res.json();
            showStatus(testStatus, data.status === 'success' ? (data.message || 'Connection OK.') : (data.error || 'Failed.'), data.status === 'success');
        } catch (_) {
            showStatus(testStatus, 'Network error.', false);
        }
    };
    const btnBar = document.createElement('div');
    btnBar.style.marginTop = '10px';
    btnBar.append(btnTest);
    body.append(btnBar, testStatus);

    return card;
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
            id: '', name: 'New job', source_id: (etlConfig.sources[0] || {}).id || '', source_query: '',
            target_schema: '', target_table: '',
            load_mode: 'full_refresh', upsert_key: [], enabled: true,
            batch_size: 500, incremental_column: '', incremental_initial_value: '', column_map: [],
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
    const { card, body, title } = buildCollapsibleCard({
        titleText: job.name,
        placeholder: '(unnamed job)',
        confirmMsg: `Delete job "${job.name}"?`,
        onDelete: () => { etlConfig.jobs.splice(idx, 1); redraw(); },
    });

    const name = input(job.name);
    name.oninput = () => { job.name = name.value; title.textContent = job.name || '(unnamed job)'; };

    const source = document.createElement('select');
    source.className = 'adm-input';
    if (etlConfig.sources.length === 0) {
        const o = document.createElement('option');
        o.value = ''; o.textContent = '(no sources configured — add one in the Sources tab)';
        source.appendChild(o);
    }
    etlConfig.sources.forEach((src) => {
        const o = document.createElement('option');
        o.value = src.id; o.textContent = sourceLabel(src);
        if (job.source_id === src.id) o.selected = true;
        source.appendChild(o);
    });
    const query = document.createElement('textarea');
    query.className = 'adm-input';
    query.rows = 4;
    query.style.resize = 'vertical';
    query.value = job.source_query || '';
    query.oninput = () => { job.source_query = query.value; };

    const queryGrp = fg('Source query (read-only SELECT)', query);
    const queryNote = document.createElement('p');
    queryNote.className = 'c-muted';
    queryNote.style.cssText = 'margin:4px 0 12px; font-size:12px; display:none;';
    queryNote.textContent = 'This source reads a CSV file — the whole file is imported on every run, no query needed.';

    function isRemoteFileSource() {
        const src = etlConfig.sources.find(s => s.id === job.source_id);
        return !!src && REMOTE_FILE_DRIVERS.includes(src.driver);
    }
    function applySourceKindVisibility() {
        const isRemote = isRemoteFileSource();
        queryGrp.style.display = isRemote ? 'none' : '';
        queryNote.style.display = isRemote ? '' : 'none';
        incColGrp.style.display = isRemote ? 'none' : '';
        incInitGrp.style.display = isRemote ? 'none' : '';
        incHint.style.display = isRemote ? 'none' : '';
    }
    source.onchange = () => { job.source_id = source.value; applySourceKindVisibility(); };

    const targetSchema = document.createElement('select');
    targetSchema.className = 'adm-input';
    const targetSchemaGrp = fg('Target schema (PostgreSQL)', targetSchema);

    const targetTable = document.createElement('select');
    targetTable.className = 'adm-input';
    const targetTableGrp = fg('Target table (PostgreSQL)', targetTable);

    function populateTableOptions(tables) {
        targetTable.innerHTML = '';
        if (tables.length === 0) {
            const o = document.createElement('option');
            o.value = ''; o.textContent = '(no tables found in this schema)';
            targetTable.appendChild(o);
            return;
        }
        tables.forEach((t) => {
            const o = document.createElement('option');
            o.value = t; o.textContent = t;
            if (job.target_table === t) o.selected = true;
            targetTable.appendChild(o);
        });
        // Keep the stored value if it's still present; otherwise default to the first table.
        if (!tables.includes(job.target_table)) {
            job.target_table = tables[0];
        }
        targetTable.value = job.target_table;
    }

    async function reloadTargetTables() {
        const tables = await fetchTargetTables(job.target_schema);
        populateTableOptions(tables);
    }

    targetSchema.onchange = () => { job.target_schema = targetSchema.value; reloadTargetTables(); };
    targetTable.onchange = () => { job.target_table = targetTable.value; };

    fetchTargetSchemas().then((schemas) => {
        targetSchema.innerHTML = '';
        schemas.forEach((s) => {
            const o = document.createElement('option');
            o.value = s; o.textContent = s;
            if (job.target_schema === s) o.selected = true;
            targetSchema.appendChild(o);
        });
        if (!schemas.includes(job.target_schema)) {
            job.target_schema = schemas[0] || '';
            targetSchema.value = job.target_schema;
        }
        reloadTargetTables();
    });

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

    const enabledLbl = checkbox('Enabled (runs on schedule)', job.enabled !== false, (v) => { job.enabled = v; }).label;

    const batchSize = input(String(job.batch_size ?? 500), 'number');
    batchSize.min = '50'; batchSize.max = '5000';
    batchSize.oninput = () => { job.batch_size = Math.max(50, Math.min(5000, parseInt(batchSize.value, 10) || 500)); };

    const incCol = input(job.incremental_column || '');
    incCol.placeholder = 'e.g. updated_at (leave empty to disable)';
    incCol.oninput = () => { job.incremental_column = incCol.value.trim(); };

    const incInit = input(job.incremental_initial_value || '');
    incInit.placeholder = 'e.g. 1970-01-01 or 0';
    incInit.oninput = () => { job.incremental_initial_value = incInit.value.trim(); };

    const incColGrp = fg('Incremental column (source, optional)', incCol);
    const incInitGrp = fg('Incremental initial value', incInit);

    const incHint = document.createElement('p');
    incHint.className = 'c-muted';
    incHint.style.cssText = 'margin:4px 0 0; font-size:12px;';
    incHint.textContent = 'Use the {{watermark}} placeholder in the source query, e.g. "WHERE updated_at > {{watermark}}". The watermark auto-advances to the max value seen after each successful run.';

    const colMap = input((job.column_map || []).map(m => `${m.source}:${m.target}`).join(', '));
    colMap.placeholder = 'source_col:target_col, source_col2:target_col2';
    colMap.oninput = () => {
        job.column_map = colMap.value.split(',').map(s => s.trim()).filter(Boolean).map(pair => {
            const [source, target] = pair.split(':').map(x => (x || '').trim());
            return { source, target: target || source };
        }).filter(m => m.source);
    };
    const colMapHint = document.createElement('p');
    colMapHint.className = 'c-muted';
    colMapHint.style.cssText = 'margin:4px 0 0; font-size:12px;';
    colMapHint.textContent = 'Optional. Leave empty to match columns by identical name (default behavior).';

    body.append(
        fg('Name', name),
        fg('Source', source),
        queryGrp,
        queryNote,
        targetSchemaGrp,
        targetTableGrp,
        fg('Load mode', mode),
        keyGrp,
        fg('Batch size (rows per INSERT chunk)', batchSize),
        incColGrp,
        incInitGrp,
        incHint,
        fg('Column mapping (optional)', colMap),
        colMapHint,
        fg('', enabledLbl),
    );
    applySourceKindVisibility();

    const out = document.createElement('pre');
    out.className = 'adm-input';
    out.style.cssText = 'white-space:pre-wrap; max-height:220px; overflow:auto; display:none;';

    const btnPreview = document.createElement('button');
    btnPreview.className = 'btn';
    btnPreview.textContent = 'Preview';
    btnPreview.onclick = async () => {
        const src = etlConfig.sources.find(s => s.id === job.source_id);
        if (!src) { out.style.display = ''; out.textContent = 'No source assigned to this job.'; return; }
        out.style.display = '';
        out.textContent = 'Loading preview…';
        try {
            const res  = await apiFetch('api.php?action=etl_preview', {
                method: 'POST',
                body: JSON.stringify({ connection: src, source_query: job.source_query }),
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
        await runCronAction('run_etl', { job_id: job.id }, out);
    };

    const btnBar = document.createElement('div');
    btnBar.style.marginTop = '10px';
    btnBar.append(btnPreview, btnRun);
    body.append(btnBar, out);

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

    const enabledLbl = checkbox('Enable scheduled ETL runs', !!etlConfig.enabled, (v) => { etlConfig.enabled = v; }).label;

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
            tableWrap.innerHTML = '';
            tableWrap.appendChild(buildJobHistory(data.rows));
        } catch (_) { tableWrap.textContent = 'Network error.'; }
    }
    load();
}

function buildJobHistory(rows) {
    return buildHistoryTable(
        ['Started', 'Job', 'Trigger', 'Status', 'Read', 'Written', 'Duration (s)', 'Error'],
        rows,
        (r, h) => [
            h.td(r.started_at || ''),
            h.td(r.job_name || ''),
            h.td(r.triggered_by || ''),
            h.statusCell(r.status),
            h.td(r.rows_read || '0'),
            h.td(r.rows_written || '0'),
            h.td(r.duration_sec != null ? Math.round(parseFloat(r.duration_sec)) : ''),
            h.errorCell(r.error_message),
        ]
    );
}

/* ---------- entry ---------- */
export async function renderEtlPage(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '<p class="c-muted" style="padding:16px;">Loading ETL configuration…</p>';

    // Reset the per-session schema/table caches so reopening the page re-reads them
    // (schemas/tables may have changed since the last visit).
    schemasPromise = null;
    tablesCache.clear();

    try {
        const res  = await apiFetch('api.php?action=etl_load');
        const data = await res.json();
        if (data.status !== 'success') {
            workspaceEl.innerHTML = `<p style="color:var(--danger); padding:16px;">${escHtml(data.error || 'Failed to load config.')}</p>`;
            return;
        }
        etlConfig  = data.config;
        etlVersion = data.version || 0;
    } catch (_) {
        workspaceEl.innerHTML = '<p style="color:var(--danger); padding:16px;">Network error loading ETL config.</p>';
        return;
    }

    if (!Array.isArray(etlConfig.sources)) etlConfig.sources = [];
    if (!Array.isArray(etlConfig.jobs)) etlConfig.jobs = [];

    workspaceEl.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    wrap.innerHTML = '<h2 class="admin-page-title">ETL — external source → PostgreSQL import</h2>'
        + '<p class="admin-page-desc">Extract data from one or more external source databases (MySQL, MariaDB, PostgreSQL, SQLite) or a CSV file fetched from an FTP/FTPS server, and load it into PostgreSQL tables. Each job picks which source it reads from. Data lands natively in PostgreSQL — external tables are not shown live.</p>';
    workspaceEl.appendChild(wrap);

    const [sourcesPanel, jobsPanel, schedPanel, histPanel, flowsPanel] = buildInnerTabs(wrap, [
        { label: 'Sources', icon: 'database.png' },
        { label: 'Jobs', icon: 'checklist_rtl.png' },
        { label: 'Schedule', icon: 'calendar_check.png' },
        { label: 'History', icon: 'manage_history.png' },
        { label: 'Flows', icon: 'arrow_split.png' },
    ]);
    renderSourcesTab(sourcesPanel);
    renderJobsTab(jobsPanel);
    renderScheduleTab(schedPanel);
    renderHistoryTab(histPanel);
    renderFlowsTab(flowsPanel);
}
