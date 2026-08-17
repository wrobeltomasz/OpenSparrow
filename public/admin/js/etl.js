// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

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
            .then(response => response.json())
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
            .then(response => response.json())
            .then(data => (data.status === 'success' ? data.tables : []))
            .catch(() => []));
    }
    return tablesCache.get(schema);
}

async function saveConfig(statusElement) {
    const result = await persistConfig('etl_save', { ...etlConfig, version: etlVersion });
    if (result.ok) {
        etlVersion = result.version;
        if (statusElement) showStatus(statusElement, 'Configuration saved.', true);
        return true;
    }
    if (statusElement) showStatus(statusElement, result.error, false);
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

function sourceLabel(sourceConfig) {
    if (REMOTE_FILE_DRIVERS.includes(sourceConfig.driver)) {
        return (sourceConfig.name || '(unnamed source)') + ' — ' + (sourceConfig.protocol || 'ftp') + '://' + (sourceConfig.host || '?') + '/' + (sourceConfig.file_name || '?');
    }
    const where = FILE_DRIVERS.includes(sourceConfig.driver) ? (sourceConfig.database || '?') : (sourceConfig.host || '?');
    return (sourceConfig.name || '(unnamed source)') + ' — ' + (sourceConfig.driver || 'mysql') + '://' + where;
}

function renderSourcesTab(panel) {
    const status = mkStatus();
    const list = document.createElement('div');

    function redraw() {
        list.innerHTML = '';
        etlConfig.sources.forEach((sourceConfig, index) => list.appendChild(buildSourceCard(sourceConfig, index, redraw, status)));
    }

    const buttonAdd = document.createElement('button');
    buttonAdd.className = 'btn btn-success';
    buttonAdd.textContent = '+ Add source';
    buttonAdd.onclick = () => {
        etlConfig.sources.push({
            id: '', name: 'New source', driver: 'mysql', host: '', port: 3306,
            database: '', user: '', password: '',
            protocol: 'ftp', remote_dir: '', file_name: '', csv_delimiter: ',',
            csv_has_header: true, passive_mode: true,
        });
        redraw();
    };

    const buttonSave = document.createElement('button');
    buttonSave.className = 'btn';
    buttonSave.textContent = 'Save configuration';
    buttonSave.style.marginLeft = '8px';
    buttonSave.onclick = () => saveConfig(status);

    const bar = document.createElement('div');
    bar.style.marginBottom = '12px';
    bar.append(buttonAdd, buttonSave);
    panel.append(bar, status, list);
    redraw();
}

function buildSourceCard(sourceConfig, index, redraw, status) {
    const { card, body, title } = buildCollapsibleCard({
        titleText: sourceConfig.name,
        placeholder: '(unnamed source)',
        confirmMsg: `Delete source "${sourceConfig.name}"? Jobs using it will need a new source assigned.`,
        onDelete: () => { etlConfig.sources.splice(index, 1); redraw(); },
    });

    const name = input(sourceConfig.name);
    name.oninput = () => { sourceConfig.name = name.value; title.textContent = sourceConfig.name || '(unnamed source)'; };

    const driver = document.createElement('select');
    driver.className = 'adm-input';
    DRIVER_LABELS.forEach(([v, labelElement]) => {
        const optionElement = document.createElement('option');
        optionElement.value = v; optionElement.textContent = labelElement;
        if ((sourceConfig.driver || 'mysql') === v) optionElement.selected = true;
        driver.appendChild(optionElement);
    });

    const host = input(sourceConfig.host);
    host.oninput = () => { sourceConfig.host = host.value; };
    const port = input(String(sourceConfig.port ?? DRIVER_PORTS[sourceConfig.driver] ?? 3306), 'number');
    port.oninput = () => { sourceConfig.port = parseInt(port.value, 10) || (DRIVER_PORTS[sourceConfig.driver] ?? 3306); };
    const databaseInput = input(sourceConfig.database);
    databaseInput.oninput = () => { sourceConfig.database = databaseInput.value; };
    const user = input(sourceConfig.user);
    user.oninput = () => { sourceConfig.user = user.value; };
    const pass = input(sourceConfig.password || '', 'password');
    pass.placeholder = sourceConfig.password === '********' ? 'Leave to keep current' : '';
    pass.oninput = () => { sourceConfig.password = pass.value; };

    const protocol = document.createElement('select');
    protocol.className = 'adm-input';
    [['ftp', 'FTP'], ['ftps', 'FTPS (FTP over TLS)']].forEach(([v, labelElement]) => {
        const optionElement = document.createElement('option');
        optionElement.value = v; optionElement.textContent = labelElement;
        if ((sourceConfig.protocol || 'ftp') === v) optionElement.selected = true;
        protocol.appendChild(optionElement);
    });
    protocol.onchange = () => { sourceConfig.protocol = protocol.value; };

    const remoteDirectory = input(sourceConfig.remote_dir || '');
    remoteDirectory.placeholder = '/exports (leave empty for the login directory)';
    remoteDirectory.oninput = () => { sourceConfig.remote_dir = remoteDirectory.value; };

    const fileName = input(sourceConfig.file_name || '');
    fileName.placeholder = 'export.csv';
    fileName.oninput = () => { sourceConfig.file_name = fileName.value; };

    const csvDelimiter = input(sourceConfig.csv_delimiter || ',');
    csvDelimiter.maxLength = 1;
    csvDelimiter.oninput = () => { sourceConfig.csv_delimiter = csvDelimiter.value.slice(0, 1) || ','; };

    const csvHasHeaderLabel = checkbox('First row is a header row', sourceConfig.csv_has_header !== false, (v) => { sourceConfig.csv_has_header = v; }).label;
    const passiveModeLabel = checkbox('Passive mode (usually required behind NAT/firewalls)', sourceConfig.passive_mode !== false, (v) => { sourceConfig.passive_mode = v; }).label;

    const hostGroup          = fg('Host', host);
    const portGroup           = fg('Port', port);
    const dbGroup              = fg('Database', databaseInput);
    const userGroup           = fg('User', user);
    const passGroup           = fg('Password', pass);
    const protocolGroup       = fg('Protocol', protocol);
    const remoteDirectoryGroup      = fg('Remote directory', remoteDirectory);
    const fileNameGroup       = fg('CSV file name', fileName);
    const csvDelimiterGroup   = fg('Column delimiter', csvDelimiter);
    const csvHasHeaderGroup   = fg('', csvHasHeaderLabel);
    const passiveModeGroup    = fg('', passiveModeLabel);

    function applyDriverVisibility() {
        const isFile   = FILE_DRIVERS.includes(sourceConfig.driver);
        const isRemote = REMOTE_FILE_DRIVERS.includes(sourceConfig.driver);
        hostGroup.style.display = isFile ? 'none' : '';
        portGroup.style.display = isFile ? 'none' : '';
        userGroup.style.display = isFile ? 'none' : '';
        passGroup.style.display = isFile ? 'none' : '';
        dbGroup.style.display   = isRemote ? 'none' : '';
        protocolGroup.style.display     = isRemote ? '' : 'none';
        remoteDirectoryGroup.style.display    = isRemote ? '' : 'none';
        fileNameGroup.style.display     = isRemote ? '' : 'none';
        csvDelimiterGroup.style.display = isRemote ? '' : 'none';
        csvHasHeaderGroup.style.display = isRemote ? '' : 'none';
        passiveModeGroup.style.display  = isRemote ? '' : 'none';
        dbGroup.querySelector('label').textContent = isFile ? 'Database file path' : 'Database';
        databaseInput.placeholder = isFile ? '/path/to/database.sqlite' : '';
    }

    driver.onchange = () => {
        const oldDefault = DRIVER_PORTS[sourceConfig.driver];
        sourceConfig.driver = driver.value;
        if (!port.value || parseInt(port.value, 10) === oldDefault) {
            port.value = String(DRIVER_PORTS[sourceConfig.driver] ?? '');
            sourceConfig.port = DRIVER_PORTS[sourceConfig.driver];
        }
        applyDriverVisibility();
    };

    body.append(
        fg('Name', name),
        fg('Source type', driver),
        hostGroup,
        portGroup,
        protocolGroup,
        remoteDirectoryGroup,
        fileNameGroup,
        csvDelimiterGroup,
        csvHasHeaderGroup,
        passiveModeGroup,
        dbGroup,
        userGroup,
        passGroup,
    );
    applyDriverVisibility();

    const testStatus = mkStatus();
    const buttonTest = document.createElement('button');
    buttonTest.className = 'btn';
    buttonTest.textContent = 'Test connection';
    buttonTest.onclick = async () => {
        showStatus(testStatus, 'Testing…', true);
        try {
            const response  = await apiFetch('api.php?action=etl_test_connection', {
                method: 'POST',
                body: JSON.stringify({ connection: sourceConfig }),
            });
            const data = await response.json();
            showStatus(testStatus, data.status === 'success' ? (data.message || 'Connection OK.') : (data.error || 'Failed.'), data.status === 'success');
        } catch (_) {
            showStatus(testStatus, 'Network error.', false);
        }
    };
    const buttonBar = document.createElement('div');
    buttonBar.style.marginTop = '10px';
    buttonBar.append(buttonTest);
    body.append(buttonBar, testStatus);

    return card;
}

function renderJobsTab(panel) {
    const status = mkStatus();
    const list = document.createElement('div');

    function redraw() {
        list.innerHTML = '';
        etlConfig.jobs.forEach((job, index) => list.appendChild(buildJobCard(job, index, redraw, status)));
    }

    const buttonAdd = document.createElement('button');
    buttonAdd.className = 'btn btn-success';
    buttonAdd.textContent = '+ Add job';
    buttonAdd.onclick = () => {
        etlConfig.jobs.push({
            id: '', name: 'New job', source_id: (etlConfig.sources[0] || {}).id || '', source_query: '',
            target_schema: '', target_table: '',
            load_mode: 'full_refresh', upsert_key: [], enabled: true,
            batch_size: 500, incremental_column: '', incremental_initial_value: '', column_map: [],
        });
        redraw();
    };

    const buttonSave = document.createElement('button');
    buttonSave.className = 'btn';
    buttonSave.textContent = 'Save configuration';
    buttonSave.style.marginLeft = '8px';
    buttonSave.onclick = () => saveConfig(status);

    const bar = document.createElement('div');
    bar.style.marginBottom = '12px';
    bar.append(buttonAdd, buttonSave);
    panel.append(bar, status, list);
    redraw();
}

function buildJobCard(job, index, redraw, status) {
    const { card, body, title } = buildCollapsibleCard({
        titleText: job.name,
        placeholder: '(unnamed job)',
        confirmMsg: `Delete job "${job.name}"?`,
        onDelete: () => { etlConfig.jobs.splice(index, 1); redraw(); },
    });

    const name = input(job.name);
    name.oninput = () => { job.name = name.value; title.textContent = job.name || '(unnamed job)'; };

    const source = document.createElement('select');
    source.className = 'adm-input';
    if (etlConfig.sources.length === 0) {
        const optionElement = document.createElement('option');
        optionElement.value = ''; optionElement.textContent = '(no sources configured — add one in the Sources tab)';
        source.appendChild(optionElement);
    }
    etlConfig.sources.forEach((sourceConfig) => {
        const optionElement = document.createElement('option');
        optionElement.value = sourceConfig.id; optionElement.textContent = sourceLabel(sourceConfig);
        if (job.source_id === sourceConfig.id) optionElement.selected = true;
        source.appendChild(optionElement);
    });
    const query = document.createElement('textarea');
    query.className = 'adm-input';
    query.rows = 4;
    query.style.resize = 'vertical';
    query.value = job.source_query || '';
    query.oninput = () => { job.source_query = query.value; };

    const queryGroup = fg('Source query (read-only SELECT)', query);
    const queryNote = document.createElement('p');
    queryNote.className = 'c-muted';
    queryNote.style.cssText = 'margin:4px 0 12px; font-size:12px; display:none;';
    queryNote.textContent = 'This source reads a CSV file — the whole file is imported on every run, no query needed.';

    function isRemoteFileSource() {
        const sourceConfig = etlConfig.sources.find(candidateSource => candidateSource.id === job.source_id);
        return !!sourceConfig && REMOTE_FILE_DRIVERS.includes(sourceConfig.driver);
    }
    function applySourceKindVisibility() {
        const isRemote = isRemoteFileSource();
        queryGroup.style.display = isRemote ? 'none' : '';
        queryNote.style.display = isRemote ? '' : 'none';
        incColumnGroup.style.display = isRemote ? 'none' : '';
        incInitGroup.style.display = isRemote ? 'none' : '';
        incHint.style.display = isRemote ? 'none' : '';
    }
    source.onchange = () => { job.source_id = source.value; applySourceKindVisibility(); };

    const targetSchema = document.createElement('select');
    targetSchema.className = 'adm-input';
    const targetSchemaGroup = fg('Target schema (PostgreSQL)', targetSchema);

    const targetTable = document.createElement('select');
    targetTable.className = 'adm-input';
    const targetTableGroup = fg('Target table (PostgreSQL)', targetTable);

    function populateTableOptions(tables) {
        targetTable.innerHTML = '';
        if (tables.length === 0) {
            const optionElement = document.createElement('option');
            optionElement.value = ''; optionElement.textContent = '(no tables found in this schema)';
            targetTable.appendChild(optionElement);
            return;
        }
        tables.forEach((t) => {
            const optionElement = document.createElement('option');
            optionElement.value = t; optionElement.textContent = t;
            if (job.target_table === t) optionElement.selected = true;
            targetTable.appendChild(optionElement);
        });

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
        schemas.forEach((candidateSource) => {
            const optionElement = document.createElement('option');
            optionElement.value = candidateSource; optionElement.textContent = candidateSource;
            if (job.target_schema === candidateSource) optionElement.selected = true;
            targetSchema.appendChild(optionElement);
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
        .forEach(([v, labelElement]) => {
            const optionElement = document.createElement('option');
            optionElement.value = v; optionElement.textContent = labelElement;
            if (job.load_mode === v) optionElement.selected = true;
            mode.appendChild(optionElement);
        });

    const keyGroup = fg('Upsert key column(s), comma-separated', (() => {
        const upsertKeyInput = input((job.upsert_key || []).join(', '));
        upsertKeyInput.oninput = () => { job.upsert_key = upsertKeyInput.value.split(',').map(candidateSource => candidateSource.trim()).filter(Boolean); };
        return upsertKeyInput;
    })());
    keyGroup.style.display = job.load_mode === 'upsert' ? '' : 'none';
    mode.onchange = () => { job.load_mode = mode.value; keyGroup.style.display = mode.value === 'upsert' ? '' : 'none'; };

    const enabledLabel = checkbox('Enabled (runs on schedule)', job.enabled !== false, (v) => { job.enabled = v; }).label;

    const batchSize = input(String(job.batch_size ?? 500), 'number');
    batchSize.min = '50'; batchSize.max = '5000';
    batchSize.oninput = () => { job.batch_size = Math.max(50, Math.min(5000, parseInt(batchSize.value, 10) || 500)); };

    const incColumn = input(job.incremental_column || '');
    incColumn.placeholder = 'e.g. updated_at (leave empty to disable)';
    incColumn.oninput = () => { job.incremental_column = incColumn.value.trim(); };

    const incInit = input(job.incremental_initial_value || '');
    incInit.placeholder = 'e.g. 1970-01-01 or 0';
    incInit.oninput = () => { job.incremental_initial_value = incInit.value.trim(); };

    const incColumnGroup = fg('Incremental column (source, optional)', incColumn);
    const incInitGroup = fg('Incremental initial value', incInit);

    const incHint = document.createElement('p');
    incHint.className = 'c-muted';
    incHint.style.cssText = 'margin:4px 0 0; font-size:12px;';
    incHint.textContent = 'Use the {{watermark}} placeholder in the source query, e.g. "WHERE updated_at > {{watermark}}". The watermark auto-advances to the max value seen after each successful run.';

    const columnMap = input((job.column_map || []).map(columnPair => `${columnPair.source}:${columnPair.target}`).join(', '));
    columnMap.placeholder = 'source_col:target_col, source_col2:target_col2';
    columnMap.oninput = () => {
        job.column_map = columnMap.value.split(',').map(candidateSource => candidateSource.trim()).filter(Boolean).map(pair => {
            const [source, target] = pair.split(':').map(part => (part || '').trim());
            return { source, target: target || source };
        }).filter(columnPair => columnPair.source);
    };
    const columnMapHint = document.createElement('p');
    columnMapHint.className = 'c-muted';
    columnMapHint.style.cssText = 'margin:4px 0 0; font-size:12px;';
    columnMapHint.textContent = 'Optional. Leave empty to match columns by identical name (default behavior).';

    body.append(
        fg('Name', name),
        fg('Source', source),
        queryGroup,
        queryNote,
        targetSchemaGroup,
        targetTableGroup,
        fg('Load mode', mode),
        keyGroup,
        fg('Batch size (rows per INSERT chunk)', batchSize),
        incColumnGroup,
        incInitGroup,
        incHint,
        fg('Column mapping (optional)', columnMap),
        columnMapHint,
        fg('', enabledLabel),
    );
    applySourceKindVisibility();

    const outputElement = document.createElement('pre');
    outputElement.className = 'adm-input';
    outputElement.style.cssText = 'white-space:pre-wrap; max-height:220px; overflow:auto; display:none;';

    const buttonPreview = document.createElement('button');
    buttonPreview.className = 'btn';
    buttonPreview.textContent = 'Preview';
    buttonPreview.onclick = async () => {
        const sourceConfig = etlConfig.sources.find(candidateSource => candidateSource.id === job.source_id);
        if (!sourceConfig) { outputElement.style.display = ''; outputElement.textContent = 'No source assigned to this job.'; return; }
        outputElement.style.display = '';
        outputElement.textContent = 'Loading preview…';
        try {
            const response  = await apiFetch('api.php?action=etl_preview', {
                method: 'POST',
                body: JSON.stringify({ connection: sourceConfig, source_query: job.source_query }),
            });
            const data = await response.json();
            if (data.status !== 'success') { outputElement.textContent = 'Error: ' + (data.error || 'preview failed'); return; }
            outputElement.textContent = renderPreview(data.columns, data.rows);
        } catch (_) { outputElement.textContent = 'Network error.'; }
    };

    const buttonRun = document.createElement('button');
    buttonRun.className = 'btn btn-success';
    buttonRun.textContent = 'Run now';
    buttonRun.style.marginLeft = '8px';
    buttonRun.onclick = async () => {
        if (!(await saveConfig(status))) return;
        await runCronAction('run_etl', { job_id: job.id }, outputElement);
    };

    const buttonBar = document.createElement('div');
    buttonBar.style.marginTop = '10px';
    buttonBar.append(buttonPreview, buttonRun);
    body.append(buttonBar, outputElement);

    return card;
}

function renderPreview(columns, rows) {
    if (!rows || rows.length === 0) return 'No rows.';
    const head = columns.join(' | ');
    const lines = rows.slice(0, 20).map(previewRow => columns.map(columnName => String(previewRow[columnName] ?? '')).join(' | '));
    return head + '\n' + '-'.repeat(head.length) + '\n' + lines.join('\n');
}

function renderScheduleTab(panel) {
    const status = mkStatus();

    const enabledLabel = checkbox('Enable scheduled ETL runs', !!etlConfig.enabled, (v) => { etlConfig.enabled = v; }).label;

    const freq = document.createElement('select');
    freq.className = 'adm-input';
    [['manual', 'Manual only'], ['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly']]
        .forEach(([v, labelElement]) => {
            const optionElement = document.createElement('option');
            optionElement.value = v; optionElement.textContent = labelElement;
            if ((etlConfig.frequency || 'daily') === v) optionElement.selected = true;
            freq.appendChild(optionElement);
        });
    freq.onchange = () => { etlConfig.frequency = freq.value; };

    const buttonSave = document.createElement('button');
    buttonSave.className = 'btn btn-success';
    buttonSave.textContent = 'Save configuration';
    buttonSave.onclick = () => saveConfig(status);

    const guide = document.createElement('div');
    guide.className = 'c-muted';
    guide.style.marginTop = '16px';
    guide.innerHTML = 'Add to crontab to run scheduled jobs (respects the frequency window):<br>'
        + '<code>' + escHtml('0 * * * * php /path/to/cron/cron_etl.php') + '</code>';

    panel.append(fg('', enabledLabel), fg('Frequency', freq), buttonSave, status, guide);
}

async function renderHistoryTab(panel) {
    const status = mkStatus();
    const tableWrap = document.createElement('div');
    tableWrap.textContent = 'Loading…';

    const buttonPurge = document.createElement('button');
    buttonPurge.className = 'btn';
    buttonPurge.textContent = 'Purge logs older than 90 days';
    buttonPurge.onclick = async () => {
        if (!confirm('Delete ETL log entries older than 90 days?')) return;
        try {
            const response  = await apiFetch('api.php?action=etl_purge_log', {
                method: 'POST', body: JSON.stringify({ days: 90 }),
            });
            const data = await response.json();
            showStatus(status, data.status === 'success' ? `Deleted ${data.deleted} row(s).` : (data.error || 'Failed.'), data.status === 'success');
            load();
        } catch (_) { showStatus(status, 'Network error.', false); }
    };

    panel.append(buttonPurge, status, tableWrap);

    async function load() {
        tableWrap.textContent = 'Loading…';
        try {
            const response  = await apiFetch('api.php?action=etl_log');
            const data = await response.json();
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
        (previewRow, h) => [
            h.td(previewRow.started_at || ''),
            h.td(previewRow.job_name || ''),
            h.td(previewRow.triggered_by || ''),
            h.statusCell(previewRow.status),
            h.td(previewRow.rows_read || '0'),
            h.td(previewRow.rows_written || '0'),
            h.td(previewRow.duration_sec != null ? Math.round(parseFloat(previewRow.duration_sec)) : ''),
            h.errorCell(previewRow.error_message),
        ]
    );
}

export async function renderEtlPage(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '<p class="c-muted" style="padding:16px;">Loading ETL configuration…</p>';

    schemasPromise = null;
    tablesCache.clear();

    try {
        const response  = await apiFetch('api.php?action=etl_load');
        const data = await response.json();
        if (data.status !== 'success') {
            workspaceElement.innerHTML = `<p style="color:var(--error); padding:16px;">${escHtml(data.error || 'Failed to load config.')}</p>`;
            return;
        }
        etlConfig  = data.config;
        etlVersion = data.version || 0;
    } catch (_) {
        workspaceElement.innerHTML = '<p style="color:var(--error); padding:16px;">Network error loading ETL config.</p>';
        return;
    }

    if (!Array.isArray(etlConfig.sources)) etlConfig.sources = [];
    if (!Array.isArray(etlConfig.jobs)) etlConfig.jobs = [];

    workspaceElement.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    wrap.innerHTML = '<h2 class="admin-page-title">ETL — external source → PostgreSQL import</h2>'
        + '<p class="admin-page-desc">Extract data from one or more external source databases (MySQL, MariaDB, PostgreSQL, SQLite) or a CSV file fetched from an FTP/FTPS server, and load it into PostgreSQL tables. Each job picks which source it reads from. Data lands natively in PostgreSQL — external tables are not shown live.</p>';
    workspaceElement.appendChild(wrap);

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
