// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { escHtml } from '../../assets/js/util/esc.js';
import { buildInnerTabs, buildModal, buildSectionCard, createPageHeader, el, mkTable, mkThead, td, tdStatus } from './ui.js';
import { buildCollapsibleCard, checkbox, fg, input, mkStatus, showStatus } from './etl_common.js';

const FILTER_OPERATORS = [
    ['eq', 'equals'],
    ['neq', 'not equals'],
    ['gt', 'greater than'],
    ['gte', 'greater than or equal'],
    ['lt', 'less than'],
    ['lte', 'less than or equal'],
    ['contains', 'contains'],
];

let apiConfig = null;
let apiVersion = 0;
let schemaTables = [];
let shownKeys = {};

async function loadSchemaTables() {
    try {
        const response = await apiFetch('api.php?action=get&file=schema');
        const schema = await response.json();
        schemaTables = Object.entries(schema?.tables ?? {})
            .filter(([, tableConfig]) => !tableConfig?.hidden)
            .map(([name, tableConfig]) => ({
                name,
                label: tableConfig.display_name || name,
                columns: Object.entries(tableConfig.columns ?? {})
                    .filter(([, columnConfig]) => (columnConfig?.type ?? '') !== 'virtual')
                    .map(([columnName]) => columnName),
            }))
            .sort((left, right) => left.label.localeCompare(right.label));
    } catch (_) {
        schemaTables = [];
    }
}

function tableColumns(tableName) {
    const table = schemaTables.find(candidate => candidate.name === tableName);
    return table ? table.columns : [];
}

function endpointUrl(api) {
    return window.location.origin + window.location.pathname.replace(/\/admin\/.*$/, '/api/external.php');
}

async function saveConfig(statusElement) {
    try {
        const response = await apiFetch('api.php?action=api_save', {
            method: 'POST',
            body: JSON.stringify({ ...apiConfig, version: apiVersion }),
        });
        const data = await response.json();
        if (data.status === 'success') {
            apiVersion = data.version;
            if (statusElement) showStatus(statusElement, 'Configuration saved.', true);
            return data;
        }
        if (statusElement) showStatus(statusElement, data.error || 'Save failed.', false);
        return null;
    } catch (_) {
        if (statusElement) showStatus(statusElement, 'Network error while saving.', false);
        return null;
    }
}

function buildApiCard(api, index, redraw, status) {
    const { card, body, title } = buildCollapsibleCard({
        titleText: api.name,
        placeholder: '(unnamed API)',
        confirmMsg: `Delete API "${api.name}"? External services using its key will lose access.`,
        onDelete: () => { apiConfig.apis.splice(index, 1); redraw(); },
    });

    const name = input(api.name);
    name.oninput = () => { api.name = name.value; title.textContent = api.name || '(unnamed API)'; };

    const enabledLabel = checkbox('Enabled', api.enabled !== false, (value) => { api.enabled = value; }).label;

    const keyInput = input('', 'password');
    keyInput.placeholder = api.key_configured ? 'Leave blank to keep the current key' : 'A key is generated on save';
    keyInput.autocomplete = 'new-password';
    keyInput.oninput = () => { api.key = keyInput.value; };

    const keyStatus = el('p', 'c-muted');
    keyStatus.style.cssText = 'margin:4px 0 12px; font-size:var(--font-size-sm);';
    function renderKeyStatus() {
        const shownKey = shownKeys[api.id];
        if (shownKey) {
            keyStatus.textContent = '';
            keyStatus.style.color = 'var(--ok)';
            keyStatus.style.fontWeight = '600';
            keyStatus.append(
                document.createTextNode('Key: '),
                el('code', '', shownKey),
                document.createTextNode(' — visible until you reload the page. Copy it now.')
            );
            return;
        }
        keyStatus.style.color = '';
        keyStatus.style.fontWeight = '';
        keyStatus.textContent = api.key_configured
            ? 'Key configured — stored encrypted. Leave the field blank to keep it.'
            : 'No key set yet — one will be generated when you save.';
    }
    renderKeyStatus();

    const regenerateLabel = checkbox('Regenerate key on save', false, (value) => { api.key_regenerate = value; }).label;

    const table = document.createElement('select');
    table.className = 'adm-input';
    const emptyOption = document.createElement('option');
    emptyOption.value = '';
    emptyOption.textContent = '-- Select Table --';
    table.appendChild(emptyOption);
    schemaTables.forEach(candidate => {
        const option = document.createElement('option');
        option.value = candidate.name;
        option.textContent = candidate.label;
        if (api.table === candidate.name) option.selected = true;
        table.appendChild(option);
    });
    table.onchange = () => {
        api.table = table.value;
        api.columns = api.columns.filter(column => tableColumns(api.table).includes(column));
        api.filters = api.filters.filter(filter => tableColumns(api.table).includes(filter.column));
        redraw();
    };

    const columnsBox = el('div', 'multiselect-box');
    function renderColumns() {
        columnsBox.innerHTML = '';
        const available = tableColumns(api.table);
        if (available.length === 0) {
            columnsBox.appendChild(el('span', 'c-muted', 'No columns available'));
            return;
        }
        available.forEach(column => {
            const label = document.createElement('label');
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.value = column;
            box.checked = api.columns.includes(column);
            box.addEventListener('change', () => {
                if (box.checked) {
                    if (!api.columns.includes(column)) api.columns.push(column);
                } else {
                    api.columns = api.columns.filter(candidate => candidate !== column);
                }
            });
            label.append(box, document.createTextNode(column));
            columnsBox.appendChild(label);
        });
    }
    renderColumns();

    const filtersHost = el('div');
    function renderFilters() {
        filtersHost.innerHTML = '';
        api.filters.forEach((filter, filterIndex) => {
            const row = el('div');
            row.style.cssText = 'display:flex; gap:8px; align-items:center; margin-bottom:8px; flex-wrap:wrap;';

            const column = document.createElement('select');
            column.className = 'adm-input';
            column.style.flex = '1';
            tableColumns(api.table).forEach(columnName => {
                const option = document.createElement('option');
                option.value = columnName;
                option.textContent = columnName;
                if (filter.column === columnName) option.selected = true;
                column.appendChild(option);
            });
            column.onchange = () => { filter.column = column.value; };

            const operator = document.createElement('select');
            operator.className = 'adm-input';
            FILTER_OPERATORS.forEach(([value, label]) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                if ((filter.operator || 'eq') === value) option.selected = true;
                operator.appendChild(option);
            });
            operator.onchange = () => { filter.operator = operator.value; };

            const value = input(filter.value || '');
            value.style.flex = '1';
            value.oninput = () => { filter.value = value.value; };

            const remove = el('button', 'icon-btn icon-btn-danger', '✕');
            remove.type = 'button';
            remove.title = 'Remove filter';
            remove.onclick = () => { api.filters.splice(filterIndex, 1); renderFilters(); };

            row.append(column, operator, value, remove);
            filtersHost.appendChild(row);
        });

        const add = el('button', 'btn btn-secondary btn-sm', '+ Add filter');
        add.type = 'button';
        add.onclick = () => {
            api.filters.push({ column: tableColumns(api.table)[0] || '', operator: 'eq', value: '' });
            renderFilters();
        };
        filtersHost.appendChild(add);
    }
    renderFilters();

    const limit = input(String(api.limit ?? 100), 'number');
    limit.min = '1';
    limit.max = '1000';
    limit.oninput = () => { api.limit = Math.max(1, Math.min(1000, parseInt(limit.value, 10) || 100)); };

    const endpoint = el('code', 'adm-endpoint');
    endpoint.style.cssText = 'display:block; padding:8px 10px; word-break:break-all;';
    endpoint.textContent = endpointUrl(api);

    const copyButton = el('button', 'btn btn-secondary btn-sm', 'Copy endpoint URL');
    copyButton.type = 'button';
    copyButton.onclick = () => {
        navigator.clipboard.writeText(endpointUrl(api)).then(
            () => showStatus(status, 'Endpoint URL copied.', true),
            () => showStatus(status, 'Could not copy the URL.', false)
        );
    };

    const keyNote = el('p', 'c-muted', 'Send the key as "Authorization: Bearer <key>". The key is stored encrypted and is never shown again after it is generated.');
    keyNote.style.cssText = 'margin:4px 0 12px; font-size:var(--font-size-sm);';

    body.append(
        fg('Name', name),
        fg('', enabledLabel),
        fg('API key', keyInput),
        keyStatus,
        fg('', regenerateLabel),
        keyNote,
        fg('Table', table),
        fg('Columns', columnsBox),
        fg('Filters (fixed, applied server-side)', filtersHost),
        fg('Row limit', limit),
        fg('Endpoint', endpoint),
        copyButton,
    );

    return card;
}

export async function renderApiPage(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '<p class="c-muted" style="padding:16px;">Loading API configuration…</p>';

    await loadSchemaTables();

    try {
        const response = await apiFetch('api.php?action=api_load');
        const data = await response.json();
        if (data.status !== 'success') {
            workspaceElement.innerHTML = `<p style="color:var(--error); padding:16px;">${escHtml(data.error || 'Failed to load config.')}</p>`;
            return;
        }
        apiConfig = data.config;
        apiVersion = data.version || 0;
    } catch (_) {
        workspaceElement.innerHTML = '<p style="color:var(--error); padding:16px;">Network error loading API config.</p>';
        return;
    }

    if (!Array.isArray(apiConfig.apis)) apiConfig.apis = [];

    workspaceElement.innerHTML = '';
    const wrap = el('div', 'admin-page');
    workspaceElement.appendChild(wrap);

    wrap.appendChild(createPageHeader(
        'External API',
        'Expose table data to external services through read-only API keys. Each API binds one table, '
        + 'a set of columns and fixed filters. External services call public/api/external.php with the key.'
    ));

    const [configPanel, usagePanel] = buildInnerTabs(wrap, [
        { label: 'Configuration', icon: 'material/key.svg' },
        { label: 'Usage', icon: 'material/bar_chart.svg' },
    ]);

    const status = mkStatus();
    const list = el('div');

    function redraw() {
        list.innerHTML = '';
        apiConfig.apis.forEach((api, index) => list.appendChild(buildApiCard(api, index, redraw, status)));
    }

    const buttonAdd = el('button', 'btn btn-success', '+ Add API');
    buttonAdd.onclick = () => {
        apiConfig.apis.push({
            id: '', name: 'New API', enabled: true, key: '', key_regenerate: false,
            table: '', columns: [], filters: [], limit: 100,
        });
        redraw();
    };

    const buttonSave = el('button', 'btn', 'Save configuration');
    buttonSave.style.marginLeft = '8px';
    buttonSave.onclick = async () => {
        const result = await saveConfig(status);
        if (!result) return;
        if (Array.isArray(result.apis)) {
            apiConfig.apis = result.apis;
        }
        apiConfig.apis.forEach(api => {
            api.key = '';
            api.key_regenerate = false;
        });
        const generatedKeys = result.generated_keys || {};
        Object.entries(generatedKeys).forEach(([apiId, key]) => {
            shownKeys[apiId] = key;
        });
        redraw();
        const keys = Object.values(generatedKeys);
        if (keys.length > 0) {
            const modal = buildModal({ title: 'New API key(s) generated' });
            modal.saveBtn.textContent = 'Copy';
            modal.cancelBtn.textContent = 'Close';
            const note = el('p', 'c-muted', 'Copy the key(s) now — they are stored encrypted and will not be shown again after you reload the page.');
            const keyBox = el('textarea', 'adm-input');
            keyBox.readOnly = true;
            keyBox.rows = keys.length;
            keyBox.style.cssText = 'width:100%; resize:vertical;';
            keyBox.value = keys.join('\n');
            modal.body.append(note, keyBox);
            modal.saveBtn.addEventListener('click', () => {
                navigator.clipboard.writeText(keys.join('\n')).then(
                    () => { modal.msgEl.textContent = 'Copied to clipboard.'; },
                    () => { modal.msgEl.textContent = 'Could not copy — select the text and copy it manually.'; }
                );
            });
        }
    };

    const bar = el('div');
    bar.style.marginBottom = '12px';
    bar.append(buttonAdd, buttonSave);
    configPanel.append(bar, status, list);
    redraw();

    renderUsage(usagePanel);
}

function renderUsage(panel) {
    const content = el('div');
    panel.appendChild(content);
    content.innerHTML = '<p class="c-muted" style="padding:16px;">Loading usage statistics…</p>';

    const state = { filter: '', page: 1 };

    async function loadStats() {
        let data;
        try {
            const response = await apiFetch('api.php?action=api_stats');
            data = await response.json();
        } catch (_) {
            content.innerHTML = '<p style="color:var(--error); padding:16px;">Request failed.</p>';
            return;
        }
        if (data.status !== 'success') {
            content.innerHTML = '';
            content.appendChild(el('p', '', data.error || 'Could not load usage statistics.')).style.color = 'var(--error)';
            return;
        }
        if (data.note) {
            content.innerHTML = '';
            content.appendChild(el('p', '', data.note));
            return;
        }

        content.innerHTML = '';

        const { card: summaryCard, body: summaryBody } = buildSectionCard(
            'Usage Statistics',
            'Aggregated metrics from all requests handled by the external API.'
        );
        content.appendChild(summaryCard);

        const cardsGrid = el('div');
        cardsGrid.style.cssText = 'display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:4px;';
        summaryBody.appendChild(cardsGrid);

        [
            ['Total Requests', String(data.total ?? 0)],
            ['Avg Duration (ms)', String(data.avg_ms ?? 0)],
            ['Slowest Request (ms)', String(data.max_ms ?? 0)],
        ].forEach(([label, value]) => {
            const box = el('div');
            box.style.cssText = 'text-align:center;padding:16px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg);';
            const valueEntry = el('div', '', value);
            valueEntry.style.cssText = 'font-weight:var(--font-weight-bold);margin-bottom:4px;';
            const labelDiv = el('div', '', label);
            labelDiv.style.cssText = 'font-weight:var(--font-weight-bold);';
            box.append(valueEntry, labelDiv);
            cardsGrid.appendChild(box);
        });

        const { card, body } = buildSectionCard(
            'Requests per API',
            'Request counts, timings and returned rows per configured API.'
        );
        const tableWrap = el('div');
        tableWrap.style.cssText = 'overflow-x:auto;';
        const table = mkTable();
        mkThead(table, ['API', 'Table', 'Requests', 'Avg ms', 'Max ms', 'Rows returned']);
        const tbody = table.createTBody();
        (data.per_api || []).forEach(row => {
            const tr = tbody.insertRow();
            tr.appendChild(td(row.api_name || row.api_id));
            tr.appendChild(td(row.table_name));
            tr.appendChild(td(row.requests));
            tr.appendChild(td(row.avg_ms));
            tr.appendChild(td(row.max_ms));
            tr.appendChild(td(row.rows_total));
        });
        tableWrap.appendChild(table);
        body.appendChild(tableWrap);
        content.appendChild(card);

        const { card: logCard, body: logHost } = buildSectionCard(
            'Request Log',
            'Recent requests, newest first. Filter by API name, trim by age or clear the log.'
        );
        content.appendChild(logCard);
        renderLog(logHost, state);
    }

    loadStats();
}

function renderLog(host, state) {
    host.innerHTML = '';

    const filterBar = el('div');
    filterBar.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:14px; flex-wrap:wrap;';

    const apiFilter = document.createElement('input');
    apiFilter.type = 'search';
    apiFilter.className = 'adm-input w-220';
    apiFilter.placeholder = 'Filter by API name';
    apiFilter.value = state.filter;

    const applyButton = el('button', 'btn btn-secondary', 'Apply');
    const clearButton = el('button', 'btn btn-secondary', 'Clear Filters');
    const purgeButton = el('button', 'btn btn-danger', 'Clear Log');
    const pillAnchor = el('span');

    filterBar.append(apiFilter, applyButton, clearButton, purgeButton, pillAnchor);
    host.appendChild(filterBar);

    const summary = el('p', 'admin-page-desc', '');
    host.appendChild(summary);

    const rowsHost = el('div');
    host.appendChild(rowsHost);

    const pager = el('div');
    pager.style.cssText = 'display:flex; align-items:center; gap:10px; margin-top:12px;';
    host.appendChild(pager);

    async function load() {
        rowsHost.innerHTML = '<p>Loading…</p>';
        const parameters = new URLSearchParams({ action: 'api_log', page: String(state.page) });
        if (state.filter) parameters.set('api', state.filter);

        let data;
        try {
            const response = await apiFetch('api.php?' + parameters.toString());
            data = await response.json();
        } catch (_) {
            rowsHost.innerHTML = '<p style="color:var(--error);">Request failed.</p>';
            return;
        }
        if (data.status !== 'success') {
            rowsHost.innerHTML = '';
            rowsHost.appendChild(el('p', '', data.error || 'Could not load the log.')).style.color = 'var(--error)';
            return;
        }
        if (data.note) {
            rowsHost.innerHTML = '';
            rowsHost.appendChild(el('p', '', data.note));
            summary.textContent = '';
            pager.innerHTML = '';
            return;
        }

        const total = data.total ?? 0;
        const limit = data.limit ?? 100;
        summary.textContent = total === 0
            ? 'No requests recorded yet.'
            : `${total} recorded request(s).`;

        renderRows(rowsHost, data.rows || []);
        renderPager(pager, state, total, limit, load);
    }

    applyButton.addEventListener('click', () => {
        state.filter = apiFilter.value.trim();
        state.page = 1;
        load();
    });
    clearButton.addEventListener('click', () => {
        apiFilter.value = '';
        state.filter = '';
        state.page = 1;
        load();
    });

    async function purge(button, pill, payload) {
        button.disabled = true;
        try {
            const response = await apiFetch('api.php?action=api_purge_log', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (result.status === 'success') {
                pill.textContent = result.note || `Deleted ${result.deleted ?? 0} row(s)`;
                state.page = 1;
                load();
            } else {
                pill.textContent = result.error || 'Could not clear the log';
            }
        } catch (_) {
            pill.textContent = 'Request failed';
        }
        button.disabled = false;
    }

    purgeButton.addEventListener('click', () => {
        if (!confirm('Delete every recorded request? This cannot be undone.')) return;
        purge(purgeButton, pillAnchor, { all: true });
    });

    load();
}

function renderRows(host, rows) {
    host.innerHTML = '';
    if (rows.length === 0) {
        host.appendChild(el('p', '', 'Nothing to show.'));
        return;
    }

    const table = mkTable();
    mkThead(table, ['API', 'Table', 'Status', 'Rows', 'Duration', 'Time']);
    const tbody = table.createTBody();
    rows.forEach(row => {
        const tr = tbody.insertRow();
        tr.appendChild(td(row.api_name || row.api_id));
        tr.appendChild(td(row.table_name));
        tr.appendChild(tdStatus(row.status));
        tr.appendChild(td(row.rows_returned));
        tr.appendChild(td(row.duration_ms + ' ms'));
        tr.appendChild(td(row.created_at));
    });
    host.appendChild(table);
}

function renderPager(host, state, total, limit, reload) {
    host.innerHTML = '';
    const pages = Math.max(1, Math.ceil(total / limit));
    if (pages <= 1) return;

    const previous = el('button', 'btn btn-secondary', 'Previous');
    const next = el('button', 'btn btn-secondary', 'Next');
    previous.disabled = state.page <= 1;
    next.disabled = state.page >= pages;
    previous.addEventListener('click', () => { state.page--; reload(); });
    next.addEventListener('click', () => { state.page++; reload(); });

    host.append(previous, el('span', '', `Page ${state.page} of ${pages}`), next);
}
