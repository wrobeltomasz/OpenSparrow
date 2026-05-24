import { I18n } from './i18n.js';
import { state as gridState } from './grid/state.js';
import { loadTable } from './grid.js';

let panel    = null;
let overlay  = null;
let debounceTimer  = null;
let previewHash    = null;
let currentPayload = null;
let lastCount      = 0;

// Column types that REPLACE/regex operations do not make sense on.
const SKIP_TYPES = new Set([
    'boolean', 'bool',
    'integer', 'int', 'int2', 'int4', 'int8', 'bigint', 'smallint', 'serial', 'bigserial',
    'decimal', 'numeric', 'float', 'float4', 'float8', 'real', 'money', 'double precision',
    'date', 'timestamp', 'timestamptz', 'time', 'timetz', 'interval',
    'uuid', 'json', 'jsonb', 'virtual', 'm2m', 'file',
]);

function isTextCol(cfg) {
    const t = (cfg.type ?? '').toLowerCase().split('(')[0].trim();
    return !SKIP_TYPES.has(t) && !t.startsWith('int') && !t.startsWith('float')
        && !t.startsWith('double') && !t.startsWith('numeric') && !t.startsWith('decimal')
        && !t.startsWith('timestamp') && !t.startsWith('time') && !t.startsWith('date');
}

function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escRe(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function highlightBefore(text, find, ignoreCase) {
    if (!find || text == null) return esc(text ?? '');
    let re;
    try { re = new RegExp(escRe(find), ignoreCase ? 'gi' : 'g'); } catch { return esc(text); }
    const parts = [];
    let last = 0, m;
    re.lastIndex = 0;
    while ((m = re.exec(text)) !== null) {
        parts.push(esc(text.slice(last, m.index)));
        parts.push('<del class="dc-del">' + esc(m[0]) + '</del>');
        last = m.index + m[0].length;
        if (m[0].length === 0) re.lastIndex++;
    }
    parts.push(esc(text.slice(last)));
    return parts.join('');
}

function highlightAfter(text, replace, ignoreCase) {
    if (!replace || text == null) return esc(text ?? '');
    let re;
    try { re = new RegExp(escRe(replace), ignoreCase ? 'gi' : 'g'); } catch { return esc(text); }
    const parts = [];
    let last = 0, m;
    re.lastIndex = 0;
    while ((m = re.exec(text)) !== null) {
        parts.push(esc(text.slice(last, m.index)));
        parts.push('<ins class="dc-ins">' + esc(m[0]) + '</ins>');
        last = m.index + m[0].length;
        if (m[0].length === 0) re.lastIndex++;
    }
    parts.push(esc(text.slice(last)));
    return parts.join('');
}

function getPayload() {
    return {
        table:  gridState.currentTable ?? '',
        column: panel.querySelector('#dc-column').value,
        find:   panel.querySelector('#dc-find').value,
        replace: panel.querySelector('#dc-replace').value,
        case_insensitive: !panel.querySelector('#dc-toggle-case').checked,
        whole_word:       panel.querySelector('#dc-toggle-word').checked,
        ignore_accents:   panel.querySelector('#dc-toggle-accent').checked,
    };
}

function payloadHash(p) { return JSON.stringify(p); }

function updateApplyBtn() {
    const btn = panel.querySelector('#dc-apply');
    btn.disabled = !previewHash || payloadHash(getPayload()) !== previewHash;
}

function setStatus(msg, isError) {
    const el = panel.querySelector('#dc-status');
    el.textContent = msg;
    el.className = 'dc-status' + (isError ? ' error' : '');
}

function clearPreview() {
    panel.querySelector('#dc-preview-area').innerHTML = '';
    panel.querySelector('#dc-status').textContent = '';
    previewHash = null;
    lastCount   = 0;
    updateApplyBtn();
}

async function runPreview() {
    const payload = getPayload();
    if (!payload.find || !payload.table || !payload.column) { clearPreview(); return; }

    setStatus(I18n.t('common.loading'), false);
    panel.querySelector('#dc-preview-area').innerHTML = '';
    previewHash = null;
    updateApplyBtn();

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    let data;
    try {
        const res = await fetch('api_data_cleanup.php?action=data_cleanup_preview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });
        data = await res.json();
    } catch {
        setStatus(I18n.t('common.error_generic'), true);
        return;
    }

    if (data.error) {
        setStatus(data.error, true);
        return;
    }

    lastCount   = data.count ?? 0;
    previewHash = payloadHash(payload);
    currentPayload = { ...payload };

    const label = I18n.t('data_cleanup.preview_count').replace('{n}', lastCount);
    setStatus(label, false);

    const rows = data.rows ?? [];
    if (rows.length > 0) {
        const tbl = document.createElement('table');
        tbl.className = 'dc-preview-table';

        const thead = document.createElement('thead');
        const hr    = document.createElement('tr');
        ['data_cleanup.col_before', 'data_cleanup.col_after'].forEach(k => {
            const th = document.createElement('th');
            th.textContent = I18n.t(k);
            hr.appendChild(th);
        });
        thead.appendChild(hr);
        tbl.appendChild(thead);

        const tbody = document.createElement('tbody');
        for (const row of rows) {
            const tr = document.createElement('tr');
            const tdB = document.createElement('td');
            const tdA = document.createElement('td');
            tdB.innerHTML = highlightBefore(row.before, payload.find, !payload.case_insensitive);
            if (row.after === '' || row.after === null) {
                tdA.innerHTML = '<em class="dc-empty">' + esc(I18n.t('data_cleanup.empty_result')) + '</em>';
            } else {
                tdA.innerHTML = highlightAfter(row.after, payload.replace, !payload.case_insensitive);
            }
            tr.appendChild(tdB);
            tr.appendChild(tdA);
            tbody.appendChild(tr);
        }
        tbl.appendChild(tbody);
        panel.querySelector('#dc-preview-area').appendChild(tbl);
    }

    updateApplyBtn();
}

function schedulePreview() {
    clearTimeout(debounceTimer);
    previewHash = null;
    updateApplyBtn();
    debounceTimer = setTimeout(runPreview, 400);
}

async function applyChanges() {
    if (!currentPayload || !previewHash) return;
    if (payloadHash(getPayload()) !== previewHash) return;

    const confirmMsg = I18n.t('data_cleanup.confirm').replace('{n}', lastCount);
    if (!confirm(confirmMsg)) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    let data;
    try {
        const res = await fetch('api_data_cleanup.php?action=data_cleanup_apply', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(currentPayload),
        });
        data = await res.json();
    } catch {
        setStatus(I18n.t('common.error_generic'), true);
        return;
    }

    if (data.error) {
        setStatus(data.error, true);
        return;
    }

    const doneMsg = I18n.t('data_cleanup.applied').replace('{n}', data.updated ?? 0);
    setStatus(doneMsg, false);
    clearPreview();

    if (gridState.currentTable && window.schema && document.getElementById('gridTitle') && document.getElementById('addRow')) {
        loadTable(window.schema, gridState.currentTable, document.getElementById('gridTitle'), document.getElementById('addRow'));
    }
}

function populateColumns() {
    const sel   = panel.querySelector('#dc-column');
    const table = gridState.currentTable;
    sel.innerHTML = '';
    if (!table || !window.schema?.tables?.[table]) return;

    const cols = window.schema.tables[table].columns ?? {};
    let first = true;
    for (const [name, cfg] of Object.entries(cols)) {
        if (!isTextCol(cfg)) continue;
        const opt = document.createElement('option');
        opt.value       = name;
        opt.textContent = cfg.display_name ?? name;
        if (first) { opt.selected = true; first = false; }
        sel.appendChild(opt);
    }
}

function buildPanel() {
    const t  = k => esc(I18n.t(k));
    const el = document.createElement('div');
    el.className = 'dc-panel';
    el.id        = 'dc-panel';
    el.innerHTML = `
<div class="dc-header">
    <h3 class="dc-title">${t('data_cleanup.title')}</h3>
    <button class="dc-close" id="dc-close" title="${t('header.close')}" aria-label="${t('header.close')}">&#x2715;</button>
</div>
<div class="dc-body">
    <div class="dc-field">
        <label for="dc-column">${t('data_cleanup.column')}</label>
        <select id="dc-column"></select>
    </div>
    <div class="dc-field">
        <label for="dc-find">${t('data_cleanup.find')}</label>
        <input type="text" id="dc-find" autocomplete="off" />
    </div>
    <div class="dc-field">
        <label for="dc-replace">${t('data_cleanup.replace')}</label>
        <input type="text" id="dc-replace" autocomplete="off"
            placeholder="${t('data_cleanup.replace_hint')}" />
    </div>
    <div class="dc-toggles">
        <label class="dc-toggle-row">
            <input type="checkbox" id="dc-toggle-case" />
            <span class="dc-toggle-label">${t('data_cleanup.toggle_case')}</span>
        </label>
        <label class="dc-toggle-row">
            <input type="checkbox" id="dc-toggle-word" />
            <span class="dc-toggle-label">${t('data_cleanup.toggle_word')}</span>
        </label>
        <label class="dc-toggle-row">
            <input type="checkbox" id="dc-toggle-accent" />
            <span class="dc-toggle-label">${t('data_cleanup.toggle_accent')}</span>
        </label>
    </div>
    <div id="dc-status" class="dc-status"></div>
    <div id="dc-preview-area" class="dc-preview-area"></div>
    <div class="dc-footer">
        <button id="dc-apply" class="dc-apply-btn" disabled>${t('data_cleanup.apply')}</button>
    </div>
</div>`;
    return el;
}

function openPanel() {
    if (!panel) {
        overlay = document.createElement('div');
        overlay.className = 'dc-overlay';
        document.body.appendChild(overlay);

        panel = buildPanel();
        document.body.appendChild(panel);

        panel.querySelector('#dc-close').addEventListener('click', closePanel);
        overlay.addEventListener('click', closePanel);

        ['#dc-find', '#dc-replace'].forEach(sel => {
            panel.querySelector(sel).addEventListener('input', schedulePreview);
        });
        panel.querySelector('#dc-column').addEventListener('change', schedulePreview);
        ['#dc-toggle-case', '#dc-toggle-word', '#dc-toggle-accent'].forEach(sel => {
            panel.querySelector(sel).addEventListener('change', schedulePreview);
        });
        panel.querySelector('#dc-apply').addEventListener('click', applyChanges);
    }

    populateColumns();
    clearPreview();
    panel.querySelector('#dc-find').value    = '';
    panel.querySelector('#dc-replace').value = '';
    panel.classList.add('active');
    overlay.classList.add('active');
    panel.querySelector('#dc-find').focus();
}

function closePanel() {
    clearTimeout(debounceTimer);
    panel?.classList.remove('active');
    overlay?.classList.remove('active');
}

export function initDataCleanup() {
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('dataCleanupBtn');
        if (btn) btn.addEventListener('click', openPanel);
    });

    document.addEventListener('tableLoaded', () => {
        if (panel?.classList.contains('active')) {
            populateColumns();
            clearPreview();
        }
    });
}
