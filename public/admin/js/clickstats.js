// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { showStatusPill } from './app.js';
import { buildInnerTabs, buildSectionCard, createPageHeader, el, mkTable, mkThead, td } from './ui.js';

const MAX_RETENTION_DAYS = 3650;

export async function renderClickstatsPage(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '<h3>Loading click statistics...</h3>';

    workspaceElement._renderId = (workspaceElement._renderId || 0) + 1;
    const myId = workspaceElement._renderId;

    let data;
    try {
        const response = await apiFetch('api.php?action=clickstats_load');
        data = await response.json();
    } catch (error) {
        if (workspaceElement._renderId !== myId) return;
        workspaceElement.innerHTML = '<h3 style="color:var(--error);">Error loading click statistics. Check server logs.</h3>';
        return;
    }
    if (workspaceElement._renderId !== myId) return;

    if (data.status !== 'success') {
        workspaceElement.innerHTML = '';
        const wrap = el('div', 'admin-page');
        wrap.appendChild(createPageHeader('Click Statistics', data.error || 'Could not load the module.'));
        workspaceElement.appendChild(wrap);
        return;
    }

    const state = {
        config: data.config || { enabled: false, track_records: true, retention_days: 90 },
        version: data.version ?? null,
        tableExists: data.table_exists ?? false,
        total: data.total,
        filters: { element: '', user: '' },
        page: 1,
    };

    workspaceElement.innerHTML = '';
    const wrap = el('div', 'admin-page');
    workspaceElement.appendChild(wrap);

    wrap.appendChild(createPageHeader(
        'Click Statistics',
        'Records which page elements users click: who, when, which element, and — when the page has one — '
        + 'the record in context. Disabled by default. While it is off nothing is loaded into the page and '
        + 'no request is made, so the application behaves exactly as if the module did not exist.'
    ));

    const [logPanel, topPanel, settingsPanel] = buildInnerTabs(wrap, [
        { label: 'Log', icon: 'material/list.svg' },
        { label: 'Top Elements', icon: 'material/leaderboard.svg' },
        { label: 'Settings', icon: 'material/settings.svg' },
    ]);
    renderLog(logPanel, state);
    renderTopPanel(topPanel);
    renderSettings(settingsPanel, state);
}

function renderSettings(panel, state) {
    const content = el('div');
    panel.appendChild(content);
    content.innerHTML = '';

    if (!state.tableExists) {
        const warn = el('p', 'admin-page-desc',
            'The spw_clickstats table does not exist yet. Run Admin → Migrations → Initialize System Tables '
            + 'before enabling collection.');
        warn.style.color = 'var(--error)';
        content.appendChild(warn);
    }

    const { card, body } = buildSectionCard(
        'Click Statistics Settings',
        'Collection toggle, retention and what is stored with every recorded click.'
    );
    content.appendChild(card);

    const enabled = checkboxRow(
        'Enable Click Statistics',
        'Load the collector on user-facing pages and record clicks.',
        state.config.enabled
    );
    enabled.input.disabled = !state.tableExists;

    const records = checkboxRow(
        'Record Table And Record',
        'Also store which table and record was open when the click happened. Turn this off to keep '
        + 'the log to user, time and element only.',
        state.config.track_records
    );

    body.appendChild(enabled.row);
    body.appendChild(records.row);

    const retentionRow = el('div');
    retentionRow.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:14px; flex-wrap:wrap;';
    const retentionInput = document.createElement('input');
    retentionInput.type = 'number';
    retentionInput.min = '0';
    retentionInput.max = String(MAX_RETENTION_DAYS);
    retentionInput.className = 'adm-input w-80';
    retentionInput.value = String(state.config.retention_days ?? 90);
    retentionRow.append(
        el('strong', '', 'Delete Automatically After'),
        retentionInput,
        el('span', '', 'days'),
    );
    const retentionNote = el('p', 'admin-page-desc',
        'Applied by the notifications cron. Set to 0 to keep every click until you '
        + 'purge the log by hand — the log has no other automatic expiry.');
    body.appendChild(retentionRow);
    body.appendChild(retentionNote);

    if (typeof state.total === 'number') {
        body.appendChild(el('p', 'admin-page-desc',
            `${state.total} click(s) recorded when this tab was opened. Expired rows are `
            + 'removed by the notifications cron; the Log tab trims or clears on demand.'));
    }

    const actions = el('div');
    actions.style.cssText = 'display:flex; align-items:center; gap:10px; margin-top:16px;';
    const button = el('button', 'btn btn-primary', 'Save');
    const pillAnchor = el('span');
    actions.appendChild(button);
    actions.appendChild(pillAnchor);
    body.appendChild(actions);

    button.addEventListener('click', async () => {
        const retention = parseInt(retentionInput.value, 10);
        if (!Number.isFinite(retention) || retention < 0 || retention > MAX_RETENTION_DAYS) {
            showStatusPill(pillAnchor, `Retention must be 0-${MAX_RETENTION_DAYS} days.`, 'error');
            return;
        }
        button.disabled = true;
        try {
            const response = await apiFetch('api.php?action=clickstats_save', {
                method: 'POST',
                body: JSON.stringify({
                    enabled: enabled.input.checked,
                    track_records: records.input.checked,
                    retention_days: retention,
                    version: state.version,
                }),
            });
            const result = await response.json();
            if (result.status === 'success') {
                state.config.enabled = enabled.input.checked;
                state.config.track_records = records.input.checked;
                state.config.retention_days = retention;

                state.version = result.version ?? state.version;
                showStatusPill(pillAnchor, enabled.input.checked ? 'Collection enabled' : 'Collection disabled', 'success');
            } else {
                showStatusPill(pillAnchor, result.error || 'Error saving settings', 'error');
            }
        } catch (error) {
            showStatusPill(pillAnchor, 'Request failed', 'error');
        }
        button.disabled = false;
    });
}

function checkboxRow(title, description, checked) {
    const row = el('div');
    row.style.cssText = 'display:flex; align-items:flex-start; gap:10px; margin-bottom:14px;';

    const input = document.createElement('input');
    input.type = 'checkbox';
    input.className = 'adm-check';
    input.checked = !!checked;

    const label = el('div');
    label.appendChild(el('strong', '', title)).style.display = 'block';
    const desc = el('span', '', description);
    desc.style.color = 'var(--muted)';
    label.appendChild(desc);

    row.appendChild(input);
    row.appendChild(label);
    return { row, input };
}

function renderLog(panel, state) {
    const content = el('div');
    panel.appendChild(content);
    content.innerHTML = '';

    const filterBar = el('div');
    filterBar.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:14px; flex-wrap:wrap;';

    const elementFilter = document.createElement('input');
    elementFilter.type = 'search';
    elementFilter.className = 'adm-input w-220';
    elementFilter.placeholder = 'Filter by element';

    const userFilter = document.createElement('input');
    userFilter.type = 'search';
    userFilter.className = 'adm-input w-160';
    userFilter.placeholder = 'Filter by user';

    const applyButton = el('button', 'btn btn-secondary', 'Apply');
    const clearButton = el('button', 'btn btn-secondary', 'Clear Filters');
    const purgeButton = el('button', 'btn btn-danger', 'Clear Log');
    const pillAnchor = el('span');

    filterBar.append(elementFilter, userFilter, applyButton, clearButton, purgeButton, pillAnchor);
    content.appendChild(filterBar);

    const retentionBar = el('div');
    retentionBar.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:14px; flex-wrap:wrap;';

    const daysInput = document.createElement('input');
    daysInput.type = 'number';
    daysInput.min = '1';
    daysInput.max = String(MAX_RETENTION_DAYS);
    daysInput.value = '30';
    daysInput.className = 'adm-input w-80';

    const trimButton = el('button', 'btn btn-secondary', 'Delete Older Than');
    const trimPill = el('span');

    retentionBar.append(trimButton, daysInput, el('span', '', 'days'), trimPill);
    content.appendChild(retentionBar);

    const summary = el('p', 'admin-page-desc', '');
    content.appendChild(summary);

    const { card: logCard, body: rowsHost } = buildSectionCard(
        'Click log',
        'Recorded clicks, newest first. Filter by element or user, trim by age or clear the log.'
    );
    content.appendChild(logCard);

    const pager = el('div');
    pager.style.cssText = 'display:flex; align-items:center; gap:10px; margin-top:12px;';
    content.appendChild(pager);

    async function load() {
        rowsHost.innerHTML = '<p>Loading...</p>';
        const parameters = new URLSearchParams({
            action: 'clickstats_log',
            page: String(state.page),
        });
        if (state.filters.element) parameters.set('element', state.filters.element);
        if (state.filters.user) parameters.set('user', state.filters.user);

        let data;
        try {
            const response = await apiFetch('api.php?' + parameters.toString());
            data = await response.json();
        } catch (error) {
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
            ? 'No clicks recorded yet.'
            : `${total} recorded click(s).`;

        renderRows(rowsHost, data.rows || []);
        renderPager(pager, state, total, limit, load);
    }

    applyButton.addEventListener('click', () => {
        state.filters.element = elementFilter.value.trim();
        state.filters.user = userFilter.value.trim();
        state.page = 1;
        load();
    });
    clearButton.addEventListener('click', () => {
        elementFilter.value = '';
        userFilter.value = '';
        state.filters = { element: '', user: '' };
        state.page = 1;
        load();
    });

    async function purge(button, pill, payload) {
        button.disabled = true;
        try {
            const response = await apiFetch('api.php?action=clickstats_purge_log', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (result.status === 'success') {
                showStatusPill(pill, result.note || `Deleted ${result.deleted ?? 0} row(s)`, 'success');
                state.page = 1;
                load();
            } else {
                showStatusPill(pill, result.error || 'Could not clear the log', 'error');
            }
        } catch (error) {
            showStatusPill(pill, 'Request failed', 'error');
        }
        button.disabled = false;
    }

    purgeButton.addEventListener('click', () => {
        if (!confirm('Delete every recorded click? This cannot be undone.')) return;
        purge(purgeButton, pillAnchor, { all: true });
    });

    trimButton.addEventListener('click', () => {
        const days = parseInt(daysInput.value, 10);

        if (!Number.isFinite(days) || days < 1 || days > MAX_RETENTION_DAYS) {
            showStatusPill(trimPill, `Enter a valid number of days (1-${MAX_RETENTION_DAYS}).`, 'error');
            return;
        }
        if (!confirm(`Delete recorded clicks older than ${days} day(s)? This cannot be undone.`)) return;
        purge(trimButton, trimPill, { days });
    });

    load();
}

function renderTopPanel(panel) {
    const content = el('div');
    panel.appendChild(content);
    content.innerHTML = '';

    const filterBar = el('div');
    filterBar.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:14px; flex-wrap:wrap;';

    const elementFilter = document.createElement('input');
    elementFilter.type = 'search';
    elementFilter.className = 'adm-input w-220';
    elementFilter.placeholder = 'Filter by element';

    const userFilter = document.createElement('input');
    userFilter.type = 'search';
    userFilter.className = 'adm-input w-160';
    userFilter.placeholder = 'Filter by user';

    const applyButton = el('button', 'btn btn-secondary', 'Apply');
    const clearButton = el('button', 'btn btn-secondary', 'Clear Filters');
    const pillAnchor = el('span');

    filterBar.append(elementFilter, userFilter, applyButton, clearButton, pillAnchor);
    content.appendChild(filterBar);

    const filters = { element: '', user: '' };

    const { card, body } = buildSectionCard(
        'Top Elements',
        'The most-clicked elements, aggregated from all recorded clicks.'
    );
    content.appendChild(card);

    async function load() {
        body.innerHTML = '<p>Loading...</p>';
        const parameters = new URLSearchParams({ action: 'clickstats_log', page: '1' });
        if (filters.element) parameters.set('element', filters.element);
        if (filters.user) parameters.set('user', filters.user);

        let data;
        try {
            const response = await apiFetch('api.php?' + parameters.toString());
            data = await response.json();
        } catch (error) {
            body.innerHTML = '<p style="color:var(--error);">Request failed.</p>';
            return;
        }
        if (data.status !== 'success') {
            body.innerHTML = '';
            body.appendChild(el('p', '', data.error || 'Could not load the statistics.')).style.color = 'var(--error)';
            return;
        }
        if (data.note) {
            body.innerHTML = '';
            body.appendChild(el('p', '', data.note));
            return;
        }

        const top = data.top || [];
        body.innerHTML = '';
        if (top.length === 0) {
            body.appendChild(el('p', '', 'Nothing to show.'));
            return;
        }

        const table = mkTable();
        mkThead(table, ['Element', 'Clicks']);
        const tbody = table.createTBody();
        top.forEach(row => {
            const tr = tbody.insertRow();
            tr.appendChild(td(row.element));
            tr.appendChild(td(row.clicks));
        });
        body.appendChild(table);
    }

    applyButton.addEventListener('click', () => {
        filters.element = elementFilter.value.trim();
        filters.user = userFilter.value.trim();
        load();
    });
    clearButton.addEventListener('click', () => {
        elementFilter.value = '';
        userFilter.value = '';
        filters.element = '';
        filters.user = '';
        load();
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
    mkThead(table, ['User', 'Element', 'Page', 'Table', 'Record', 'Time']);
    const tbody = table.createTBody();
    rows.forEach(row => {
        const tr = tbody.insertRow();
        tr.appendChild(td(row.username));
        tr.appendChild(td(row.element));
        tr.appendChild(td(row.page));
        tr.appendChild(td(row.table_name));
        tr.appendChild(td(row.record_id));
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
