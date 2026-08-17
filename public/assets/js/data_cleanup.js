// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from './i18n.js';
import { state as gridState } from './grid/state.js';
import { loadTable } from './grid.js';

let panel    = null;
let overlay  = null;
let debounceTimer  = null;
let previewHash    = null;
let currentPayload = null;
let lastCount      = 0;

const SKIP_TYPES = new Set([
    'boolean', 'bool',
    'integer', 'int', 'int2', 'int4', 'int8', 'bigint', 'smallint', 'serial', 'bigserial',
    'decimal', 'numeric', 'float', 'float4', 'float8', 'real', 'money', 'double precision',
    'date', 'timestamp', 'timestamptz', 'time', 'timetz', 'interval',
    'uuid', 'json', 'jsonb', 'virtual', 'm2m', 'file',
]);

function isTextColumn(config) {
    const normalizedType = (config.type ?? '').toLowerCase().split('(')[0].trim();
    return !SKIP_TYPES.has(normalizedType) && !normalizedType.startsWith('int') && !normalizedType.startsWith('float')
        && !normalizedType.startsWith('double') && !normalizedType.startsWith('numeric') && !normalizedType.startsWith('decimal')
        && !normalizedType.startsWith('timestamp') && !normalizedType.startsWith('time') && !normalizedType.startsWith('date');
}

import { escHtml as esc } from './util/esc.js';
import { apiFetch } from './util/api.js';

function escRe(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function highlightBefore(text, find, ignoreCase) {
    if (!find || text == null) return esc(text ?? '');
    let pattern;
    try { pattern = new RegExp(escRe(find), ignoreCase ? 'gi' : 'g'); } catch { return esc(text); }
    const parts = [];
    let last = 0, m;
    pattern.lastIndex = 0;
    while ((m = pattern.exec(text)) !== null) {
        parts.push(esc(text.slice(last, m.index)));
        parts.push('<del class="dc-del">' + esc(m[0]) + '</del>');
        last = m.index + m[0].length;
        if (m[0].length === 0) pattern.lastIndex++;
    }
    parts.push(esc(text.slice(last)));
    return parts.join('');
}

function highlightAfter(text, replace, ignoreCase) {
    if (!replace || text == null) return esc(text ?? '');
    let pattern;
    try { pattern = new RegExp(escRe(replace), ignoreCase ? 'gi' : 'g'); } catch { return esc(text); }
    const parts = [];
    let last = 0, m;
    pattern.lastIndex = 0;
    while ((m = pattern.exec(text)) !== null) {
        parts.push(esc(text.slice(last, m.index)));
        parts.push('<ins class="dc-ins">' + esc(m[0]) + '</ins>');
        last = m.index + m[0].length;
        if (m[0].length === 0) pattern.lastIndex++;
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

function updateApplyButton() {
    const button = panel.querySelector('#dc-apply');
    button.disabled = !previewHash || payloadHash(getPayload()) !== previewHash;
}

function setStatus(message, isError) {
    const element = panel.querySelector('#dc-status');
    element.textContent = message;
    element.className = 'dc-status' + (isError ? ' error' : '');
}

function clearPreview() {
    panel.querySelector('#dc-preview-area').innerHTML = '';
    panel.querySelector('#dc-status').textContent = '';
    previewHash = null;
    lastCount   = 0;
    updateApplyButton();
}

async function runPreview() {
    const payload = getPayload();
    if (!payload.find || !payload.table || !payload.column) { clearPreview(); return; }

    setStatus(I18n.t('common.loading'), false);
    panel.querySelector('#dc-preview-area').innerHTML = '';
    previewHash = null;
    updateApplyButton();

    let data;
    try {
        const result = await apiFetch('api/data_cleanup.php?action=data_cleanup_preview', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: payload,
        });
        data = await result.json();
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
        const tableElement = document.createElement('table');
        tableElement.className = 'dc-preview-table';

        const thead = document.createElement('thead');
        const hr    = document.createElement('tr');
        ['data_cleanup.col_before', 'data_cleanup.col_after'].forEach(translationKey => {
            const th = document.createElement('th');
            th.textContent = I18n.t(translationKey);
            hr.appendChild(th);
        });
        thead.appendChild(hr);
        tableElement.appendChild(thead);

        const tbody = document.createElement('tbody');
        for (const row of rows) {
            const tr = document.createElement('tr');
            const beforeCell = document.createElement('td');
            const afterCell = document.createElement('td');
            beforeCell.innerHTML = highlightBefore(row.before, payload.find, !payload.case_insensitive);
            if (row.after === '' || row.after === null) {
                afterCell.innerHTML = '<em class="dc-empty">' + esc(I18n.t('data_cleanup.empty_result')) + '</em>';
            } else {
                afterCell.innerHTML = highlightAfter(row.after, payload.replace, !payload.case_insensitive);
            }
            tr.appendChild(beforeCell);
            tr.appendChild(afterCell);
            tbody.appendChild(tr);
        }
        tableElement.appendChild(tbody);
        panel.querySelector('#dc-preview-area').appendChild(tableElement);
    }

    updateApplyButton();
}

function schedulePreview() {
    clearTimeout(debounceTimer);
    previewHash = null;
    updateApplyButton();
    debounceTimer = setTimeout(runPreview, 400);
}

async function applyChanges() {
    if (!currentPayload || !previewHash) return;
    if (payloadHash(getPayload()) !== previewHash) return;

    const confirmMessage = I18n.t('data_cleanup.confirm').replace('{n}', lastCount);
    if (!confirm(confirmMessage)) return;

    let data;
    try {
        const result = await apiFetch('api/data_cleanup.php?action=data_cleanup_apply', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: currentPayload,
        });
        data = await result.json();
    } catch {
        setStatus(I18n.t('common.error_generic'), true);
        return;
    }

    if (data.error) {
        setStatus(data.error, true);
        return;
    }

    const doneMessage = I18n.t('data_cleanup.applied').replace('{n}', data.updated ?? 0);
    setStatus(doneMessage, false);
    clearPreview();

    if (gridState.currentTable && window.schema && document.getElementById('gridTitle') && document.getElementById('addRow')) {
        loadTable(window.schema, gridState.currentTable, document.getElementById('gridTitle'), document.getElementById('addRow'));
    }
}

function populateColumns() {
    const selectElement   = panel.querySelector('#dc-column');
    const table = gridState.currentTable;
    selectElement.innerHTML = '';
    if (!table || !window.schema?.tables?.[table]) return;

    const cols = window.schema.tables[table].columns ?? {};
    let first = true;
    for (const [name, config] of Object.entries(cols)) {
        if (!isTextColumn(config)) continue;
        const option = document.createElement('option');
        option.value       = name;
        option.textContent = config.display_name ?? name;
        if (first) { option.selected = true; first = false; }
        selectElement.appendChild(option);
    }
}

function buildPanel() {
    const normalizedType  = translationKey => esc(I18n.t(translationKey));
    const element = document.createElement('div');
    element.className = 'dc-panel';
    element.id        = 'dc-panel';
    element.innerHTML = `
<div class="dc-header">
    <h3 class="dc-title">${normalizedType('data_cleanup.title')}</h3>
    <button class="dc-close" id="dc-close" title="${normalizedType('header.close')}" aria-label="${normalizedType('header.close')}">&#x2715;</button>
</div>
<div class="dc-body">
    <div class="dc-field">
        <label for="dc-column">${normalizedType('data_cleanup.column')}</label>
        <select id="dc-column"></select>
    </div>
    <div class="dc-field">
        <label for="dc-find">${normalizedType('data_cleanup.find')}</label>
        <input type="text" id="dc-find" autocomplete="off" />
    </div>
    <div class="dc-field">
        <label for="dc-replace">${normalizedType('data_cleanup.replace')}</label>
        <input type="text" id="dc-replace" autocomplete="off"
            placeholder="${normalizedType('data_cleanup.replace_hint')}" />
    </div>
    <div class="dc-toggles">
        <label class="dc-toggle-row">
            <input type="checkbox" id="dc-toggle-case" />
            <span class="dc-toggle-label">${normalizedType('data_cleanup.toggle_case')}</span>
        </label>
        <label class="dc-toggle-row">
            <input type="checkbox" id="dc-toggle-word" />
            <span class="dc-toggle-label">${normalizedType('data_cleanup.toggle_word')}</span>
        </label>
        <label class="dc-toggle-row">
            <input type="checkbox" id="dc-toggle-accent" />
            <span class="dc-toggle-label">${normalizedType('data_cleanup.toggle_accent')}</span>
        </label>
    </div>
    <div id="dc-status" class="dc-status"></div>
    <div id="dc-preview-area" class="dc-preview-area"></div>
    <div class="dc-footer">
        <button id="dc-apply" class="dc-apply-btn" disabled>${normalizedType('data_cleanup.apply')}</button>
    </div>
</div>`;
    return element;
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

        ['#dc-find', '#dc-replace'].forEach(selectElement => {
            panel.querySelector(selectElement).addEventListener('input', schedulePreview);
        });
        panel.querySelector('#dc-column').addEventListener('change', schedulePreview);
        ['#dc-toggle-case', '#dc-toggle-word', '#dc-toggle-accent'].forEach(selectElement => {
            panel.querySelector(selectElement).addEventListener('change', schedulePreview);
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
        const button = document.getElementById('dataCleanupBtn');
        if (button) button.addEventListener('click', openPanel);
    });

    document.addEventListener('tableLoaded', () => {
        if (panel?.classList.contains('active')) {
            populateColumns();
            clearPreview();
        }
    });
}
