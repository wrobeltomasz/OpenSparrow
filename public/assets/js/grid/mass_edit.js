// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

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

let previewLoaded      = false;
let lastPreviewPayload = null;

const SKIP_TYPES = new Set(['virtual', 'file', 'm2m']);

function isEditableColumn(name, config) {
    if (name === 'id') return false;
    return !SKIP_TYPES.has((config.type ?? '').toLowerCase().split('(')[0].trim());
}

async function postMassEditJson(url, body) {
    const result = await apiFetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body,
    });
    return result.json();
}

function makeField(className, labelText, forId, controlElement) {
    const field = document.createElement('div');
    field.className = className;
    const label = document.createElement('label');
    label.htmlFor = forId;
    label.textContent = labelText;
    field.appendChild(label);
    if (controlElement) field.appendChild(controlElement);
    return field;
}

function makeColumnPickerQuickButton(label, checked, body, panelInstance) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'me-col-picker-quick-btn';
    button.textContent = label;
    button.addEventListener('click', () => {
        body.querySelectorAll('.me-col-picker-cb').forEach(callback => { callback.checked = checked; });
        panelInstance.setApplyDisabled(!checked);
    });
    return button;
}

function getBar() {
    if (bar) return bar;

    bar = document.createElement('div');
    bar.className = 'me-bar';
    bar.id = 'me-bar';

    const countElement = document.createElement('span');
    countElement.className = 'me-bar-count';
    countElement.id = 'me-bar-count';

    const actions = document.createElement('div');
    actions.className = 'me-bar-actions';

    const editButton = document.createElement('button');
    editButton.className = 'me-bar-edit-btn';
    editButton.textContent = I18n.t('mass_edit.edit_fields');
    editButton.addEventListener('click', openPanel);

    const exportButton = document.createElement('button');
    exportButton.className = 'me-bar-export-btn';
    exportButton.textContent = I18n.t('mass_edit.export_btn');
    exportButton.addEventListener('click', openExportPanel);

    const ownerButton = document.createElement('button');
    ownerButton.className = 'me-bar-owner-btn';
    ownerButton.textContent = I18n.t('mass_owner.btn');
    ownerButton.addEventListener('click', openOwnerPanel);

    const dupButton = document.createElement('button');
    dupButton.className = 'me-bar-dup-btn';
    dupButton.textContent = I18n.t('mass_duplicate.duplicate_btn');
    dupButton.addEventListener('click', massDuplicateSelected);

    const deleteButton = document.createElement('button');
    deleteButton.className = 'me-bar-delete-btn';
    deleteButton.textContent = I18n.t('mass_delete.delete_btn');
    deleteButton.addEventListener('click', massDeleteSelected);

    const clearButton = document.createElement('button');
    clearButton.className = 'me-bar-clear-btn';
    clearButton.textContent = I18n.t('mass_edit.deselect_all');
    clearButton.addEventListener('click', deselectAll);

    actions.appendChild(editButton);
    actions.appendChild(exportButton);
    actions.appendChild(ownerButton);
    actions.appendChild(dupButton);
    actions.appendChild(deleteButton);
    actions.appendChild(clearButton);
    bar.appendChild(countElement);
    bar.appendChild(actions);

    document.body.appendChild(bar);
    return bar;
}

function deselectAll() {
    clearSelection();
    document.querySelectorAll('.row-select-cb').forEach(callback => { callback.checked = false; });
    document.querySelectorAll('.th-select input[type="checkbox"]').forEach(callback => { callback.checked = false; });
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
        const message = data.is_unique
            ? I18n.t('mass_duplicate.error_unique')
            : data.error;
        showToast(message, 'error');
        return;
    }

    showToast(I18n.t('mass_duplicate.applied').replace('{n}', data.duplicated ?? 0), 'success');
    deselectAll();
    reloadGrid();
}

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

async function buildValueInput(columnConfig, columnName = '') {
    const fks = window.schema?.tables?.[state.currentTable]?.foreign_keys ?? {};
    if (columnName && fks[columnName]) {
        const fkConfig   = fks[columnName];
        const dispColumns = Array.isArray(fkConfig.display_column)
            ? fkConfig.display_column
            : [fkConfig.display_column || 'id'];
        const cacheKey = `${state.currentTable}_${columnName}`;

        const sel = document.createElement('select');
        sel.id = 'me-value';
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = I18n.t('mass_edit.select_fk_placeholder');
        sel.appendChild(blank);

        let referenceData = [];
        if (state.fkCache.has(cacheKey)) {
            referenceData = await state.fkCache.get(cacheKey);
        } else {
            try {
                const result = await fetch(
                    `api/fk.php?table=${encodeURIComponent(state.currentTable)}&col=${encodeURIComponent(columnName)}`,
                    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
                );
                const json = await result.json();
                referenceData = json.rows ?? [];
            } catch {  }
        }

        referenceData.forEach(r => {
            const dv  = dispColumns.map(c => r[c + '__display'] ?? r[c] ?? '').join(' - ') || String(r.id);
            const option = document.createElement('option');
            option.value       = String(r.id);
            option.textContent = dv;
            sel.appendChild(option);
        });

        return sel;
    }

    const type = (columnConfig.type ?? '').toLowerCase().split('(')[0].trim();

    if (type === 'boolean' || type === 'bool') {
        const sel = document.createElement('select');
        sel.id = 'me-value';
        [['true', I18n.t('common.yes')], ['false', I18n.t('common.no')]].forEach(([v, l]) => {
            const option = document.createElement('option');
            option.value = v; option.textContent = l;
            sel.appendChild(option);
        });
        return sel;
    }

    if (type === 'enum' && Array.isArray(columnConfig.options)) {
        const sel = document.createElement('select');
        sel.id = 'me-value';
        columnConfig.options.forEach(o => {
            const option = document.createElement('option');
            option.value = o; option.textContent = o;
            sel.appendChild(option);
        });
        return sel;
    }

    const NUMERIC_PREFIXES = ['int', 'int2', 'int4', 'int8', 'bigint', 'smallint',
        'serial', 'bigserial', 'numeric', 'decimal', 'float', 'float4',
        'float8', 'real', 'double', 'money'];
    if (NUMERIC_PREFIXES.some(p => type.startsWith(p))) {
        const input = document.createElement('input');
        input.type = 'number'; input.id = 'me-value';
        input.placeholder = I18n.t('mass_edit.value_placeholder');
        return input;
    }

    if (type === 'date') {
        const input = document.createElement('input');
        input.type = 'date'; input.id = 'me-value'; return input;
    }

    if (type.startsWith('timestamp')) {
        const input = document.createElement('input');
        input.type = 'datetime-local'; input.id = 'me-value'; return input;
    }

    const input = document.createElement('input');
    input.type = 'text'; input.id = 'me-value';
    input.placeholder = I18n.t('mass_edit.value_placeholder');
    return input;
}

function clearPreviewUI(panelInstance) {
    previewLoaded      = false;
    lastPreviewPayload = null;
    panelInstance.setApplyDisabled(true);
    panelInstance.clearStatus();
    const area = panelInstance.bodyEl.querySelector('.me-preview-area');
    if (area) area.innerHTML = '';
}

async function rebuildValueInput(panelInstance) {
    const columnSelect = panelInstance.bodyEl.querySelector('#me-column');
    if (!columnSelect) return;
    const cols = window.schema?.tables?.[state.currentTable]?.columns ?? {};
    const old  = panelInstance.bodyEl.querySelector('#me-value');
    if (old) old.remove();
    const valueField = panelInstance.bodyEl.querySelector('.me-val-field');
    if (valueField) valueField.appendChild(await buildValueInput(cols[columnSelect.value] ?? {}, columnSelect.value));
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

    const scopeElement = document.createElement('p');
    scopeElement.className = 'me-scope-info';
    scopeElement.textContent = I18n.t('mass_edit.scope_info').replace('{n}', count);
    body.appendChild(scopeElement);

    const columnSelect = document.createElement('select');
    columnSelect.id = 'me-column';

    let firstKey = null;
    for (const [name, config] of Object.entries(cols)) {
        if (!isEditableColumn(name, config)) continue;
        const option = document.createElement('option');
        option.value = name; option.textContent = config.display_name ?? name;
        columnSelect.appendChild(option);
        if (!firstKey) firstKey = name;
    }
    body.appendChild(makeField('bp-field', I18n.t('mass_edit.column'), 'me-column', columnSelect));

    const valueField = makeField('bp-field me-val-field', I18n.t('mass_edit.new_value'), 'me-value', null);
    if (firstKey) valueField.appendChild(await buildValueInput(cols[firstKey] ?? {}, firstKey));
    body.appendChild(valueField);

    const nullRow = document.createElement('label');
    nullRow.className = 'me-null-row';
    const nullCallback = document.createElement('input');
    nullCallback.type = 'checkbox'; nullCallback.id = 'me-set-null';
    const nullSpan = document.createElement('span');
    nullSpan.textContent = I18n.t('mass_edit.set_null');
    nullRow.appendChild(nullCallback); nullRow.appendChild(nullSpan);
    body.appendChild(nullRow);

    const previewButton = document.createElement('button');
    previewButton.className = 'me-preview-btn';
    previewButton.id = 'me-preview-btn';
    previewButton.textContent = I18n.t('mass_edit.preview');
    previewButton.addEventListener('click', () => runPreview(panelInstance));
    body.appendChild(previewButton);

    const previewArea = document.createElement('div');
    previewArea.className = 'me-preview-area';
    body.appendChild(previewArea);

    columnSelect.addEventListener('change', () => rebuildValueInput(panelInstance));
    nullCallback.addEventListener('change', () => {
        const valueElement = body.querySelector('#me-value');
        if (valueElement) valueElement.disabled = nullCallback.checked;
        clearPreviewUI(panelInstance);
    });
    body.addEventListener('input', e => {
        if (e.target.id === 'me-value') clearPreviewUI(panelInstance);
    });
    body.addEventListener('change', e => {
        if (e.target.id === 'me-value') clearPreviewUI(panelInstance);
    });
}

async function runPreview(panelInstance) {
    const payload = getPayload(panelInstance);
    if (!payload) return;

    const previewButton  = panelInstance.bodyEl.querySelector('#me-preview-btn');
    const previewArea = panelInstance.bodyEl.querySelector('.me-preview-area');

    previewButton.disabled = true;
    previewLoaded       = false;
    panelInstance.setApplyDisabled(true);
    panelInstance.setStatus(I18n.t('common.loading'), false);
    previewArea.innerHTML = '';

    let data;
    try {
        data = await postMassEditJson('api/mass_edit.php?action=mass_edit_preview', payload);
    } catch {
        panelInstance.setStatus(I18n.t('common.error_generic'), true);
        previewButton.disabled = false;
        return;
    }

    previewButton.disabled = false;

    if (data.error) {
        panelInstance.setStatus(data.error, true);
        return;
    }

    const count  = data.count ?? 0;
    const newValue = payload.value === null ? '(null)' : String(payload.value);
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
            const tdNew = document.createElement('td'); tdNew.textContent = newValue;
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

function getPayload(panelInstance) {
    const body   = panelInstance.bodyEl;
    const columnSelect = body.querySelector('#me-column');
    const valueElement  = body.querySelector('#me-value');
    const nullCallback = body.querySelector('#me-set-null');

    if (!columnSelect) return null;

    const value = nullCallback?.checked
        ? null
        : (valueElement ? (valueElement.value === '' ? null : valueElement.value) : null);

    return {
        table:   state.currentTable,
        column:  columnSelect.value,
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

function buildExportBody(panelInstance) {
    const body = panelInstance.bodyEl;
    body.innerHTML = '';
    panelInstance.setApplyDisabled(false);

    const { displayedColumns } = getState();
    const schemaColumns = window.schema?.tables?.[state.currentTable]?.columns ?? {};

    const information = document.createElement('p');
    information.className = 'me-scope-info';
    information.textContent = I18n.t('mass_edit.export_rows').replace('{n}', state.selectedIds.size);
    body.appendChild(information);

    const quickRow = document.createElement('div');
    quickRow.className = 'me-col-picker-quick';
    quickRow.appendChild(makeColumnPickerQuickButton(I18n.t('mass_edit.export_select_all'), true, body, panelInstance));
    quickRow.appendChild(makeColumnPickerQuickButton(I18n.t('mass_edit.export_select_none'), false, body, panelInstance));
    body.appendChild(quickRow);

    const list = document.createElement('div');
    list.className = 'me-col-picker-list';

    displayedColumns.forEach(col => {
        const item = document.createElement('label');
        item.className = 'me-col-picker-item';
        const callback = document.createElement('input');
        callback.type = 'checkbox';
        callback.className = 'me-col-picker-cb';
        callback.value = col;
        callback.checked = true;
        callback.addEventListener('change', () => {
            const any = Array.from(body.querySelectorAll('.me-col-picker-cb')).some(c => c.checked);
            panelInstance.setApplyDisabled(!any);
        });
        const span = document.createElement('span');
        span.textContent = schemaColumns[col]?.display_name ?? col;
        item.appendChild(callback);
        item.appendChild(span);
        list.appendChild(item);
    });

    body.appendChild(list);
}

function applyExport(panelInstance) {
    const body = panelInstance.bodyEl;
    const checkedColumns = Array.from(body.querySelectorAll('.me-col-picker-cb:checked'))
        .map(callback => callback.value);

    if (checkedColumns.length === 0) {
        panelInstance.setStatus(I18n.t('mass_edit.export_none_selected'), true);
        return;
    }

    const { filteredData } = getState();
    const rows        = filteredData.filter(r => state.selectedIds.has(r.id));
    const schemaColumns  = window.schema?.tables?.[state.currentTable]?.columns ?? {};
    const header      = checkedColumns.map(c => JSON.stringify(schemaColumns[c]?.display_name ?? c)).join(',');
    const lines       = rows.map(r =>
        checkedColumns.map(c => JSON.stringify(r[c] ?? '')).join(',')
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

async function buildOwnerBody(panelInstance) {
    const body = panelInstance.bodyEl;
    body.innerHTML = '';
    panelInstance.setApplyDisabled(true);

    const scopeElement = document.createElement('p');
    scopeElement.className = 'me-scope-info';
    scopeElement.textContent = I18n.t('mass_owner.scope_info').replace('{n}', state.selectedIds.size);
    body.appendChild(scopeElement);

    const sel = document.createElement('select');
    sel.id = 'me-owner-sel';
    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = '— ' + I18n.t('mass_owner.select_user') + ' —';
    sel.appendChild(blank);
    body.appendChild(makeField('bp-field', I18n.t('mass_owner.select_user'), 'me-owner-sel', sel));

    panelInstance.setStatus(I18n.t('common.loading'), false);

    try {
        const result  = await fetch('api/owners.php?action=editors', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await result.json();
        (data.users ?? []).forEach(u => {
            const option       = document.createElement('option');
            option.value       = String(u.id);
            option.textContent = u.username;
            sel.appendChild(option);
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
        const result = await apiFetch('api/owners.php', {
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
        data = await result.json();
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

export function initMassEdit() {
    if ((window.USER_ROLE || 'viewer') !== 'editor') return;

    document.addEventListener('selectionChanged', updateBar);

    document.addEventListener('tableLoaded', () => {
        clearSelection();
        if (bar) bar.classList.remove('active');
        if (panel?.isOpen()) panel.close();
    });
}
