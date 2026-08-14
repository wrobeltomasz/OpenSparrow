// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.
//
// admin/js/clickstats.js — Click Statistics tab (renderClickstatsPage): the on/off
// switch for click collection, and the recorded log with a top-elements rollup.
// Reads/writes via api.php?action=clickstats_*.
import { apiFetch } from '../../assets/js/util/api.js';
import { showStatusPill } from './app.js';
import { buildInnerTabs, buildSectionCard, createPageHeader, el, mkTable, mkThead, td } from './ui.js';

// Every label below comes from the DOM of a user-facing page. It is rendered with
// textContent (via td()/el()) and never interpolated into innerHTML.

// Longest retention window the Log tab offers. Mirrors ADMIN_PURGE_MAX_DAYS in
// includes/admin/helpers.php, which is what actually enforces it for every module.
const MAX_RETENTION_DAYS = 3650;

export async function renderClickstatsPage(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '<h3>Loading click statistics...</h3>';

    // Guards against a slow response landing after the user switched tabs.
    workspaceEl._renderId = (workspaceEl._renderId || 0) + 1;
    const myId = workspaceEl._renderId;

    let data;
    try {
        const res = await apiFetch('api.php?action=clickstats_load');
        data = await res.json();
    } catch (e) {
        if (workspaceEl._renderId !== myId) return;
        workspaceEl.innerHTML = '<h3 style="color:var(--error);">Error loading click statistics. Check server logs.</h3>';
        return;
    }
    if (workspaceEl._renderId !== myId) return;

    if (data.status !== 'success') {
        workspaceEl.innerHTML = '';
        const wrap = el('div', 'admin-page');
        wrap.appendChild(createPageHeader('Click Statistics', data.error || 'Could not load the module.'));
        workspaceEl.appendChild(wrap);
        return;
    }

    const state = {
        config: data.config || { enabled: false, track_records: true },
        version: data.version ?? null,
        tableExists: data.table_exists ?? false,
        total: data.total,
        filters: { element: '', user: '' },
        page: 1,
    };

    workspaceEl.innerHTML = '';
    const wrap = el('div', 'admin-page');
    workspaceEl.appendChild(wrap);

    wrap.appendChild(createPageHeader(
        'Click Statistics',
        'Records which page elements users click: who, when, which element, and — when the page has one — '
        + 'the record in context. Disabled by default. While it is off nothing is loaded into the page and '
        + 'no request is made, so the application behaves exactly as if the module did not exist.'
    ));

    // Same container as the header, so the tab strip is inserted above the title
    // and the panels land after the description — the shared ETL layout order.
    const [settingsPanel, logPanel] = buildInnerTabs(wrap, [
        { label: 'Settings', icon: 'build.png' },
        { label: 'Log', icon: 'bar_chart.png' },
    ]);
    renderSettings(settingsPanel, state);
    renderLog(logPanel, state);
}

// ── Settings ────────────────────────────────────────────────────────────────

function renderSettings(panel, state) {
    panel.innerHTML = '';

    if (!state.tableExists) {
        const warn = el('p', 'admin-page-desc',
            'The spw_clickstats table does not exist yet. Run Admin → Migrations → Initialize System Tables '
            + 'before enabling collection.');
        warn.style.color = 'var(--error)';
        panel.appendChild(warn);
    }

    const card = el('div', 'adm-sec-card');
    const body = el('div', 'adm-sec-body');
    card.appendChild(body);
    panel.appendChild(card);

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

    // How much is already stored, so the size of the log is visible next to the
    // switch that fills it. Worded as a snapshot on purpose: the count is read once
    // when the tab opens and is not refreshed by a purge on the Log tab.
    if (typeof state.total === 'number') {
        body.appendChild(el('p', 'admin-page-desc',
            `${state.total} click(s) recorded when this tab was opened. Retention is manual — `
            + 'trim or clear the log on the Log tab.'));
    }

    const actions = el('div');
    actions.style.cssText = 'display:flex; align-items:center; gap:10px; margin-top:16px;';
    const btn = el('button', 'btn btn-primary', 'Save');
    const pillAnchor = el('span');
    actions.appendChild(btn);
    actions.appendChild(pillAnchor);
    body.appendChild(actions);

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
            const res = await apiFetch('api.php?action=clickstats_save', {
                method: 'POST',
                body: JSON.stringify({
                    enabled: enabled.input.checked,
                    track_records: records.input.checked,
                    version: state.version,
                }),
            });
            const result = await res.json();
            if (result.status === 'success') {
                state.config.enabled = enabled.input.checked;
                state.config.track_records = records.input.checked;
                // Carry the new version forward, or the next save of this open tab
                // loses the optimistic-lock race against itself.
                state.version = result.version ?? state.version;
                showStatusPill(pillAnchor, enabled.input.checked ? 'Collection enabled' : 'Collection disabled', 'success');
            } else {
                showStatusPill(pillAnchor, result.error || 'Error saving settings', 'error');
            }
        } catch (e) {
            showStatusPill(pillAnchor, 'Request failed', 'error');
        }
        btn.disabled = false;
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

// ── Log ─────────────────────────────────────────────────────────────────────

function renderLog(panel, state) {
    panel.innerHTML = '';

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

    const applyBtn = el('button', 'btn btn-secondary', 'Apply');
    const clearBtn = el('button', 'btn btn-secondary', 'Clear Filters');
    const purgeBtn = el('button', 'btn btn-danger', 'Clear Log');
    const pillAnchor = el('span');

    filterBar.append(elementFilter, userFilter, applyBtn, clearBtn, purgeBtn, pillAnchor);
    panel.appendChild(filterBar);

    // Retention. There is no cron worker for this log, so trimming it is a manual
    // action — but "delete everything" is the wrong tool for keeping a rolling
    // window, which is what an admin who leaves collection on actually wants.
    const retentionBar = el('div');
    retentionBar.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:14px; flex-wrap:wrap;';

    // Bounds mirror the server's ADMIN_PURGE_MAX_DAYS, which rejects anything
    // outside them — this only keeps the spinner honest.
    const daysInput = document.createElement('input');
    daysInput.type = 'number';
    daysInput.min = '1';
    daysInput.max = String(MAX_RETENTION_DAYS);
    daysInput.value = '30';
    daysInput.className = 'adm-input w-80';

    const trimBtn = el('button', 'btn btn-secondary', 'Delete Older Than');
    const trimPill = el('span');

    retentionBar.append(trimBtn, daysInput, el('span', '', 'days'), trimPill);
    panel.appendChild(retentionBar);

    const summary = el('p', 'admin-page-desc', '');
    panel.appendChild(summary);

    const topHost = el('div');
    const rowsHost = el('div');
    panel.appendChild(topHost);
    panel.appendChild(rowsHost);

    const pager = el('div');
    pager.style.cssText = 'display:flex; align-items:center; gap:10px; margin-top:12px;';
    panel.appendChild(pager);

    async function load() {
        rowsHost.innerHTML = '<p>Loading...</p>';
        const params = new URLSearchParams({
            action: 'clickstats_log',
            page: String(state.page),
        });
        if (state.filters.element) params.set('element', state.filters.element);
        if (state.filters.user) params.set('user', state.filters.user);

        let data;
        try {
            const res = await apiFetch('api.php?' + params.toString());
            data = await res.json();
        } catch (e) {
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
            topHost.innerHTML = '';
            pager.innerHTML = '';
            return;
        }

        const total = data.total ?? 0;
        const limit = data.limit ?? 100;
        summary.textContent = total === 0
            ? 'No clicks recorded yet.'
            : `${total} recorded click(s).`;

        renderTop(topHost, data.top || []);
        renderRows(rowsHost, data.rows || []);
        renderPager(pager, state, total, limit, load);
    }

    applyBtn.addEventListener('click', () => {
        state.filters.element = elementFilter.value.trim();
        state.filters.user = userFilter.value.trim();
        state.page = 1;
        load();
    });
    clearBtn.addEventListener('click', () => {
        elementFilter.value = '';
        userFilter.value = '';
        state.filters = { element: '', user: '' };
        state.page = 1;
        load();
    });
    // Both buttons hit the same endpoint: {all: true} clears the whole log, a
    // positive {days} trims to that window. Neither is implied — the server refuses
    // a body naming neither or both — so send exactly one, spelled out. Kept in one
    // place so the two paths cannot report their result differently.
    async function purge(btn, pill, payload) {
        btn.disabled = true;
        try {
            const res = await apiFetch('api.php?action=clickstats_purge_log', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const result = await res.json();
            if (result.status === 'success') {
                // `note` is the "table not created yet" hint — say that rather than
                // claiming a deletion that never had anything to delete.
                showStatusPill(pill, result.note || `Deleted ${result.deleted ?? 0} row(s)`, 'success');
                state.page = 1;
                load();
            } else {
                showStatusPill(pill, result.error || 'Could not clear the log', 'error');
            }
        } catch (e) {
            showStatusPill(pill, 'Request failed', 'error');
        }
        btn.disabled = false;
    }

    purgeBtn.addEventListener('click', () => {
        if (!confirm('Delete every recorded click? This cannot be undone.')) return;
        purge(purgeBtn, pillAnchor, { all: true });
    });

    trimBtn.addEventListener('click', () => {
        const days = parseInt(daysInput.value, 10);
        // Never send an unusable value: an empty or malformed field must not turn a
        // "trim to N days" click into a request the server could read as anything else.
        if (!Number.isFinite(days) || days < 1 || days > MAX_RETENTION_DAYS) {
            showStatusPill(trimPill, `Enter a valid number of days (1-${MAX_RETENTION_DAYS}).`, 'error');
            return;
        }
        if (!confirm(`Delete recorded clicks older than ${days} day(s)? This cannot be undone.`)) return;
        purge(trimBtn, trimPill, { days });
    });

    load();
}

function renderTop(host, top) {
    host.innerHTML = '';
    if (top.length === 0) return;

    const { card, body } = buildSectionCard('Top Elements');

    const table = mkTable();
    mkThead(table, ['Element', 'Clicks']);
    const tbody = table.createTBody();
    top.forEach(row => {
        const tr = tbody.insertRow();
        tr.appendChild(td(row.element));
        tr.appendChild(td(row.clicks));
    });
    body.appendChild(table);
    host.appendChild(card);
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

    const prev = el('button', 'btn btn-secondary', 'Previous');
    const next = el('button', 'btn btn-secondary', 'Next');
    prev.disabled = state.page <= 1;
    next.disabled = state.page >= pages;
    prev.addEventListener('click', () => { state.page--; reload(); });
    next.addEventListener('click', () => { state.page++; reload(); });

    host.append(prev, el('span', '', `Page ${state.page} of ${pages}`), next);
}
