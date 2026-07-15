// assets/js/grid/mass_edit.js — Mass-edit selection bar + bulk panels (edit / owner / export) over selected rows; previews then applies via api/mass_edit.php. Built on BulkPanel.

import { I18n } from '../i18n.js';
import { showToast } from '../toast.js';
import { state, clearSelection } from './state.js';
import { BulkPanel } from '../bulk_panel.js';
import { loadTable, getState } from '../grid.js';
import { getCsrfToken } from '../util/csrf.js';
import { apiFetch } from '../util/api.js';

let bar          = null;
let panel        = null;
let ownerPanel   = null;
let exportPanel  = null;

// Preview state — tracks whether the current payload has been previewed server-side.
let previewLoaded      = false;
let lastPreviewPayload = null;

const SKIP_TYPES = new Set(['virtual', 'file', 'm2m']);

function isEditableCol(name, cfg) {
    if (name === 'id') return false;
    return !SKIP_TYPES.has((cfg.type ?? '').toLowerCase().split('(')[0].trim());
}

// Shared fetch/parse for the api/mass_edit.php CSRF-protected POST actions below;
// caller keeps its own error handling since that differs (toast vs panel status).
async function postMassEditJson(url, body) {
    const res = await apiFetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body,
    });
    return res.json();
}

// Shared "<label> + control" field wrapper for panel bodies below.
function makeField(className, labelText, forId, controlEl) {
    const field = document.createElement('div');
    field.className = className;
    const label = document.createElement('label');
    label.htmlFor = forId;
    label.textContent = labelText;
    field.appendChild(label);
    if (controlEl) field.appendChild(controlEl);
    return field;
}

// Shared "select all/none" quick button for the export column picker below.
function makeColPickerQuickBtn(label, checked, body, panelInstance) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'me-col-picker-quick-btn';
    btn.textContent = label;
    btn.addEventListener('click', () => {
        body.querySelectorAll('.me-col-picker-cb').forEach(cb => { cb.checked = checked; });
        panelInstance.setApplyDisabled(!checked);
    });
    return btn;
}

// ─── Floating selection bar ───────────────────────────────────────────────────

function getBar() {
    if (bar) return bar;

    bar = document.createElement('div');
    bar.className = 'me-bar';
    bar.id = 'me-bar';

    const countEl = document.createElement('span');
    countEl.className = 'me-bar-count';
    countEl.id = 'me-bar-count';

    const actions = document.createElement('div');
    actions.className = 'me-bar-actions';

    const editBtn = document.createElement('button');
    editBtn.className = 'me-bar-edit-btn';
    editBtn.textContent = I18n.t('mass_edit.edit_fields');
    editBtn.addEventListener('click', openPanel);

    const exportBtn = document.createElement('button');
    exportBtn.className = 'me-bar-export-btn';
    exportBtn.textContent = I18n.t('mass_edit.export_btn');
    exportBtn.addEventListener('click', openExportPanel);

    const ownerBtn = document.createElement('button');
    ownerBtn.className = 'me-bar-owner-btn';
    ownerBtn.textContent = I18n.t('mass_owner.btn');
    ownerBtn.addEventListener('click', openOwnerPanel);

    const dupBtn = document.createElement('button');
    dupBtn.className = 'me-bar-dup-btn';
    dupBtn.textContent = I18n.t('mass_duplicate.duplicate_btn');
    dupBtn.addEventListener('click', massDuplicateSelected);

    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'me-bar-delete-btn';
    deleteBtn.textContent = I18n.t('mass_delete.delete_btn');
    deleteBtn.addEventListener('click', massDeleteSelected);

    const clearBtn = document.createElement('button');
    clearBtn.className = 'me-bar-clear-btn';
    clearBtn.textContent = I18n.t('mass_edit.deselect_all');
    clearBtn.addEventListener('click', deselectAll);

    actions.appendChild(editBtn);
    actions.appendChild(exportBtn);
    actions.appendChild(ownerBtn);
    actions.appendChild(dupBtn);
    actions.appendChild(deleteBtn);
    actions.appendChild(clearBtn);
    bar.appendChild(countEl);
    bar.appendChild(actions);

    document.body.appendChild(bar);
    return bar;
}

function deselectAll() {
    clearSelection();
    document.querySelectorAll('.row-select-cb').forEach(cb => { cb.checked = false; });
    document.querySelectorAll('.th-select input[type="checkbox"]').forEach(cb => { cb.checked = false; });
    document.dispatchEvent(new CustomEvent('selectionChanged'));
}

function updateBar() {
    const size = state.selectedIds.size;
    const b = getBar();
    b.querySelector('#me-bar-count').textContent =
        I18n.t('mass_edit.rows_selected').replace('{n}', size);

    if (size > 0) {
        b.classList.add('active');
    } else {
        b.classList.remove('active');
        if (panel?.isOpen()) panel.close();
    }
}

// ─── Mass duplicate ───────────────────────────────────────────────────────────

async function massDuplicateSelected() {
    const n = state.selectedIds.size;
    if (n === 0) return;

    if (!confirm(I18n.t('mass_duplicate.confirm').replace('{n}', n))) return;

    let data;
    try {
        data = await postMassEditJson('api/mass_edit.php?action=mass_duplicate', {
            table:   state.currentTable,
            row_ids: Array.from(state.selectedIds),
        });
    } catch {
        showToast(I18n.t('common.error_generic'), 'error');
        return;
    }

    if (data.error) {
        const msg = data.is_unique
            ? I18n.t('mass_duplicate.error_unique')
            : data.error;
        showToast(msg, 'error');
        return;
    }

    showToast(I18n.t('mass_duplicate.applied').replace('{n}', data.duplicated ?? 0), 'success');
    deselectAll();
    reloadGrid();
}

// ─── Mass delete ──────────────────────────────────────────────────────────────

async function massDeleteSelected() {
    const n = state.selectedIds.size;
    if (n === 0) return;

    if (!confirm(I18n.t('mass_delete.confirm').replace('{n}', n))) return;

    let data;
    try {
        data = await postMassEditJson('api/mass_edit.php?action=mass_delete', {
            table:   state.currentTable,
            row_ids: Array.from(state.selectedIds),
        });
    } catch {
        showToast(I18n.t('common.error_generic'), 'error');
        return;
    }

    if (data.error) {
        showToast(data.error, 'error');
        return;
    }

    showToast(I18n.t('mass_delete.applied').replace('{n}', data.deleted ?? 0), 'success');
    deselectAll();
    reloadGrid();
}

// ─── Value input factory ──────────────────────────────────────────────────────

async function buildValueInput(colCfg, colName = '') {
    // FK column → render a select populated from the reference table
    const fks = window.schema?.tables?.[state.currentTable]?.foreign_keys ?? {};
    if (colName && fks[colName]) {
        const fkCfg   = fks[colName];
        const dispCols = Array.isArray(fkCfg.display_column)
            ? fkCfg.display_column
            : [fkCfg.display_column || 'id'];
        const cacheKey = `${state.currentTable}_${colName}`;

        const sel = document.createElement('select');
        sel.id = 'me-value';
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = I18n.t('mass_edit.select_fk_placeholder');
        sel.appendChild(blank);

        let refData = [];
        if (state.fkCache.has(cacheKey)) {
            refData = await state.fkCache.get(cacheKey);
        } else {
            try {
                const res = await fetch(
                    `api/fk.php?table=${encodeURIComponent(state.currentTable)}&col=${encodeURIComponent(colName)}`,
                    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
                );
                const json = await res.json();
                refData = json.rows ?? [];
            } catch { /* leave select empty */ }
        }

        refData.forEach(r => {
            const dv  = dispCols.map(c => r[c + '__display'] ?? r[c] ?? '').join(' - ') || String(r.id);
            const opt = document.createElement('option');
            opt.value       = String(r.id);
            opt.textContent = dv;
            sel.appendChild(opt);
        });

        return sel;
    }

    const type = (colCfg.type ?? '').toLowerCase().split('(')[0].trim();

    if (type === 'boolean' || type === 'bool') {
        const sel = document.createElement('select');
        sel.id = 'me-value';
        [['true', I18n.t('common.yes')], ['false', I18n.t('common.no')]].forEach(([v, l]) => {
            const opt = document.createElement('option');
            opt.value = v; opt.textContent = l;
            sel.appendChild(opt);
        });
        return sel;
    }

    if (type === 'enum' && Array.isArray(colCfg.options)) {
        const sel = document.createElement('select');
        sel.id = 'me-value';
        colCfg.options.forEach(o => {
            const opt = document.createElement('option');
            opt.value = o; opt.textContent = o;
            sel.appendChild(opt);
        });
        return sel;
    }

    const NUMERIC_PREFIXES = ['int', 'int2', 'int4', 'int8', 'bigint', 'smallint',
        'serial', 'bigserial', 'numeric', 'decimal', 'float', 'float4',
        'float8', 'real', 'double', 'money'];
    if (NUMERIC_PREFIXES.some(p => type.startsWith(p))) {
        const inp = document.createElement('input');
        inp.type = 'number'; inp.id = 'me-value';
        inp.placeholder = I18n.t('mass_edit.value_placeholder');
        return inp;
    }

    if (type === 'date') {
        const inp = document.createElement('input');
        inp.type = 'date'; inp.id = 'me-value'; return inp;
    }

    if (type.startsWith('timestamp')) {
        const inp = document.createElement('input');
        inp.type = 'datetime-local'; inp.id = 'me-value'; return inp;
    }

    const inp = document.createElement('input');
    inp.type = 'text'; inp.id = 'me-value';
    inp.placeholder = I18n.t('mass_edit.value_placeholder');
    return inp;
}

// ─── Panel body ───────────────────────────────────────────────────────────────

function clearPreviewUI(panelInstance) {
    previewLoaded      = false;
    lastPreviewPayload = null;
    panelInstance.setApplyDisabled(true);
    panelInstance.clearStatus();
    const area = panelInstance.bodyEl.querySelector('.me-preview-area');
    if (area) area.innerHTML = '';
}

async function rebuildValueInput(panelInstance) {
    const colSel = panelInstance.bodyEl.querySelector('#me-column');
    if (!colSel) return;
    const cols = window.schema?.tables?.[state.currentTable]?.columns ?? {};
    const old  = panelInstance.bodyEl.querySelector('#me-value');
    if (old) old.remove();
    const valField = panelInstance.bodyEl.querySelector('.me-val-field');
    if (valField) valField.appendChild(await buildValueInput(cols[colSel.value] ?? {}, colSel.value));
    clearPreviewUI(panelInstance);
}

async function buildMassEditBody(panelInstance) {
    const table = state.currentTable;
    const body  = panelInstance.bodyEl;
    body.innerHTML = '';
    previewLoaded      = false;
    lastPreviewPayload = null;
    panelInstance.setApplyDisabled(true);

    if (!table || !window.schema?.tables?.[table]) return;

    const cols  = window.schema.tables[table].columns ?? {};
    const count = state.selectedIds.size;

    // Scope info
    const scopeEl = document.createElement('p');
    scopeEl.className = 'me-scope-info';
    scopeEl.textContent = I18n.t('mass_edit.scope_info').replace('{n}', count);
    body.appendChild(scopeEl);

    // Column select
    const colSel = document.createElement('select');
    colSel.id = 'me-column';

    let firstKey = null;
    for (const [name, cfg] of Object.entries(cols)) {
        if (!isEditableCol(name, cfg)) continue;
        const opt = document.createElement('option');
        opt.value = name; opt.textContent = cfg.display_name ?? name;
        colSel.appendChild(opt);
        if (!firstKey) firstKey = name;
    }
    body.appendChild(makeField('bp-field', I18n.t('mass_edit.column'), 'me-column', colSel));

    // Value input
    const valField = makeField('bp-field me-val-field', I18n.t('mass_edit.new_value'), 'me-value', null);
    if (firstKey) valField.appendChild(await buildValueInput(cols[firstKey] ?? {}, firstKey));
    body.appendChild(valField);

    // Null toggle
    const nullRow = document.createElement('label');
    nullRow.className = 'me-null-row';
    const nullCb = document.createElement('input');
    nullCb.type = 'checkbox'; nullCb.id = 'me-set-null';
    const nullSpan = document.createElement('span');
    nullSpan.textContent = I18n.t('mass_edit.set_null');
    nullRow.appendChild(nullCb); nullRow.appendChild(nullSpan);
    body.appendChild(nullRow);

    // Preview button
    const previewBtn = document.createElement('button');
    previewBtn.className = 'me-preview-btn';
    previewBtn.id = 'me-preview-btn';
    previewBtn.textContent = I18n.t('mass_edit.preview');
    previewBtn.addEventListener('click', () => runPreview(panelInstance));
    body.appendChild(previewBtn);

    // Preview area
    const previewArea = document.createElement('div');
    previewArea.className = 'me-preview-area';
    body.appendChild(previewArea);

    // Wire change handlers
    colSel.addEventListener('change', () => rebuildValueInput(panelInstance));
    nullCb.addEventListener('change', () => {
        const valEl = body.querySelector('#me-value');
        if (valEl) valEl.disabled = nullCb.checked;
        clearPreviewUI(panelInstance);
    });
    body.addEventListener('input', e => {
        if (e.target.id === 'me-value') clearPreviewUI(panelInstance);
    });
    body.addEventListener('change', e => {
        if (e.target.id === 'me-value') clearPreviewUI(panelInstance);
    });
}

// ─── Preview ──────────────────────────────────────────────────────────────────

async function runPreview(panelInstance) {
    const payload = getPayload(panelInstance);
    if (!payload) return;

    const previewBtn  = panelInstance.bodyEl.querySelector('#me-preview-btn');
    const previewArea = panelInstance.bodyEl.querySelector('.me-preview-area');

    previewBtn.disabled = true;
    previewLoaded       = false;
    panelInstance.setApplyDisabled(true);
    panelInstance.setStatus(I18n.t('common.loading'), false);
    previewArea.innerHTML = '';

    let data;
    try {
        data = await postMassEditJson('api/mass_edit.php?action=mass_edit_preview', payload);
    } catch {
        panelInstance.setStatus(I18n.t('common.error_generic'), true);
        previewBtn.disabled = false;
        return;
    }

    previewBtn.disabled = false;

    if (data.error) {
        panelInstance.setStatus(data.error, true);
        return;
    }

    const count  = data.count ?? 0;
    const newVal = payload.value === null ? '(null)' : String(payload.value);
    panelInstance.setStatus(I18n.t('mass_edit.preview_count').replace('{n}', count), false);

    const rows = data.rows ?? [];
    if (rows.length > 0) {
        const tbl = document.createElement('table');
        tbl.className = 'bp-preview-table';

        const thead = document.createElement('thead');
        const hr    = document.createElement('tr');
        [
            I18n.t('mass_edit.col_id'),
            I18n.t('mass_edit.col_current'),
            I18n.t('mass_edit.col_new'),
        ].forEach(h => {
            const th = document.createElement('th');
            th.textContent = h; hr.appendChild(th);
        });
        thead.appendChild(hr); tbl.appendChild(thead);

        const tbody = document.createElement('tbody');
        for (const row of rows) {
            const tr = document.createElement('tr');
            const tdId  = document.createElement('td'); tdId.textContent  = String(row.id);
            const tdOld = document.createElement('td'); tdOld.textContent = String(row.current ?? '');
            const tdNew = document.createElement('td'); tdNew.textContent = newVal;
            tdNew.className = 'me-new-val';
            tr.appendChild(tdId); tr.appendChild(tdOld); tr.appendChild(tdNew);
            tbody.appendChild(tr);
        }
        tbl.appendChild(tbody);
        previewArea.appendChild(tbl);
    }

    previewLoaded      = true;
    lastPreviewPayload = JSON.stringify(payload);
    panelInstance.setApplyDisabled(false);
}

// ─── Apply ────────────────────────────────────────────────────────────────────

function getPayload(panelInstance) {
    const body   = panelInstance.bodyEl;
    const colSel = body.querySelector('#me-column');
    const valEl  = body.querySelector('#me-value');
    const nullCb = body.querySelector('#me-set-null');

    if (!colSel) return null;

    const value = nullCb?.checked
        ? null
        : (valEl ? (valEl.value === '' ? null : valEl.value) : null);

    return {
        table:   state.currentTable,
        column:  colSel.value,
        value,
        row_ids: Array.from(state.selectedIds),
    };
}

async function applyMassEdit(panelInstance) {
    if (!previewLoaded) {
        panelInstance.setStatus(I18n.t('mass_edit.run_preview_first'), true);
        return;
    }

    const payload = getPayload(panelInstance);
    if (!payload) return;

    // Guard: form changed after preview was run
    if (JSON.stringify(payload) !== lastPreviewPayload) {
        panelInstance.setStatus(I18n.t('mass_edit.run_preview_first'), true);
        panelInstance.setApplyDisabled(true);
        previewLoaded = false;
        return;
    }

    const n = payload.row_ids.length;
    if (!confirm(I18n.t('mass_edit.confirm').replace('{n}', n))) return;

    panelInstance.setApplyDisabled(true);
    panelInstance.setStatus(I18n.t('common.loading'), false);

    let data;
    try {
        data = await postMassEditJson('api/mass_edit.php?action=mass_edit_apply', payload);
    } catch {
        panelInstance.setStatus(I18n.t('common.error_generic'), true);
        panelInstance.setApplyDisabled(false);
        return;
    }

    if (data.error) {
        panelInstance.setStatus(data.error, true);
        panelInstance.setApplyDisabled(false);
        return;
    }

    panelInstance.setStatus(
        I18n.t('mass_edit.applied').replace('{n}', data.updated ?? 0), false
    );

    deselectAll();
    reloadGrid();
}

function reloadGrid() {
    if (state.currentTable && window.schema
        && document.getElementById('gridTitle')
        && document.getElementById('addRow')) {
        loadTable(
            window.schema, state.currentTable,
            document.getElementById('gridTitle'),
            document.getElementById('addRow')
        );
    }
}

// ─── Panel open ───────────────────────────────────────────────────────────────

async function openPanel() {
    if (!panel) {
        panel = new BulkPanel({
            id:         'me-panel',
            title:      I18n.t('mass_edit.title'),
            applyLabel: I18n.t('mass_edit.apply'),
        });
        panel.onApply(applyMassEdit);
    }

    await buildMassEditBody(panel);
    panel.clearStatus();
    panel.open();
}

// ─── Export with column picker ────────────────────────────────────────────────

function buildExportBody(panelInstance) {
    const body = panelInstance.bodyEl;
    body.innerHTML = '';
    panelInstance.setApplyDisabled(false);

    const { displayedColumns } = getState();
    const schemaCols = window.schema?.tables?.[state.currentTable]?.columns ?? {};

    const info = document.createElement('p');
    info.className = 'me-scope-info';
    info.textContent = I18n.t('mass_edit.export_rows').replace('{n}', state.selectedIds.size);
    body.appendChild(info);

    const quickRow = document.createElement('div');
    quickRow.className = 'me-col-picker-quick';
    quickRow.appendChild(makeColPickerQuickBtn(I18n.t('mass_edit.export_select_all'), true, body, panelInstance));
    quickRow.appendChild(makeColPickerQuickBtn(I18n.t('mass_edit.export_select_none'), false, body, panelInstance));
    body.appendChild(quickRow);

    const list = document.createElement('div');
    list.className = 'me-col-picker-list';

    displayedColumns.forEach(col => {
        const item = document.createElement('label');
        item.className = 'me-col-picker-item';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'me-col-picker-cb';
        cb.value = col;
        cb.checked = true;
        cb.addEventListener('change', () => {
            const any = Array.from(body.querySelectorAll('.me-col-picker-cb')).some(c => c.checked);
            panelInstance.setApplyDisabled(!any);
        });
        const span = document.createElement('span');
        span.textContent = schemaCols[col]?.display_name ?? col;
        item.appendChild(cb);
        item.appendChild(span);
        list.appendChild(item);
    });

    body.appendChild(list);
}

function applyExport(panelInstance) {
    const body = panelInstance.bodyEl;
    const checkedCols = Array.from(body.querySelectorAll('.me-col-picker-cb:checked'))
        .map(cb => cb.value);

    if (checkedCols.length === 0) {
        panelInstance.setStatus(I18n.t('mass_edit.export_none_selected'), true);
        return;
    }

    const { filteredData } = getState();
    const rows        = filteredData.filter(r => state.selectedIds.has(r.id));
    const schemaCols  = window.schema?.tables?.[state.currentTable]?.columns ?? {};
    const header      = checkedCols.map(c => JSON.stringify(schemaCols[c]?.display_name ?? c)).join(',');
    const lines       = rows.map(r =>
        checkedCols.map(c => JSON.stringify(r[c] ?? '')).join(',')
    );
    const csv  = [header, ...lines].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'export_selected.csv';
    a.click();
    URL.revokeObjectURL(url);
    panelInstance.close();
}

function openExportPanel() {
    if (!exportPanel) {
        exportPanel = new BulkPanel({
            id:         'me-export-panel',
            title:      I18n.t('mass_edit.export_title'),
            applyLabel: I18n.t('mass_edit.export_download'),
        });
        exportPanel.onApply(applyExport);
    }
    buildExportBody(exportPanel);
    exportPanel.clearStatus();
    exportPanel.open();
}

// ─── Mass owner panel ─────────────────────────────────────────────────────────

async function buildOwnerBody(panelInstance) {
    const body = panelInstance.bodyEl;
    body.innerHTML = '';
    panelInstance.setApplyDisabled(true);

    const scopeEl = document.createElement('p');
    scopeEl.className = 'me-scope-info';
    scopeEl.textContent = I18n.t('mass_owner.scope_info').replace('{n}', state.selectedIds.size);
    body.appendChild(scopeEl);

    const sel = document.createElement('select');
    sel.id = 'me-owner-sel';
    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = '— ' + I18n.t('mass_owner.select_user') + ' —';
    sel.appendChild(blank);
    body.appendChild(makeField('bp-field', I18n.t('mass_owner.select_user'), 'me-owner-sel', sel));

    panelInstance.setStatus(I18n.t('common.loading'), false);

    try {
        const res  = await fetch('api/owners.php?action=editors', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        (data.users ?? []).forEach(u => {
            const opt       = document.createElement('option');
            opt.value       = String(u.id);
            opt.textContent = u.username;
            sel.appendChild(opt);
        });
        panelInstance.clearStatus();
    } catch {
        panelInstance.setStatus(I18n.t('common.error_generic'), true);
    }

    sel.addEventListener('change', () => panelInstance.setApplyDisabled(sel.value === ''));
}

async function applyMassOwner(panelInstance) {
    const sel = panelInstance.bodyEl.querySelector('#me-owner-sel');
    if (!sel || sel.value === '') {
        panelInstance.setStatus(I18n.t('mass_owner.select_first'), true);
        return;
    }

    const n = state.selectedIds.size;
    if (!confirm(I18n.t('mass_owner.confirm').replace('{n}', n))) return;

    panelInstance.setApplyDisabled(true);
    panelInstance.setStatus(I18n.t('common.loading'), false);

    let data;
    try {
        const res = await apiFetch('api/owners.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: {
                action:     'mass_set',
                table:      state.currentTable,
                owner_id:   parseInt(sel.value, 10),
                row_ids:    Array.from(state.selectedIds),
                csrf_token: getCsrfToken(),
            },
        });
        data = await res.json();
    } catch {
        panelInstance.setStatus(I18n.t('common.error_generic'), true);
        panelInstance.setApplyDisabled(false);
        return;
    }

    if (!data.success) {
        panelInstance.setStatus(data.error ?? I18n.t('common.error_generic'), true);
        panelInstance.setApplyDisabled(false);
        return;
    }

    showToast(I18n.t('mass_owner.applied').replace('{n}', data.updated ?? n), 'success');
    panelInstance.close();
    deselectAll();
    reloadGrid();
}

function openOwnerPanel() {
    if (!ownerPanel) {
        ownerPanel = new BulkPanel({
            id:         'me-owner-panel',
            title:      I18n.t('mass_owner.title'),
            applyLabel: I18n.t('mass_owner.apply'),
        });
        ownerPanel.onApply(applyMassOwner);
    }
    buildOwnerBody(ownerPanel);
    ownerPanel.clearStatus();
    ownerPanel.open();
}

// ─── Init ─────────────────────────────────────────────────────────────────────

export function initMassEdit() {
    if ((window.USER_ROLE || 'viewer') !== 'editor') return;

    document.addEventListener('selectionChanged', updateBar);

    document.addEventListener('tableLoaded', () => {
        clearSelection();
        if (bar) bar.classList.remove('active');
        if (panel?.isOpen()) panel.close();
    });
}
