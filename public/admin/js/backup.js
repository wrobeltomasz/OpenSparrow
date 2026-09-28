// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { buildInnerTabs, buildSectionCard, createPageHeader, el, mkTable, mkThead, td, tdEl } from './ui.js';

import { escHtml } from '../../assets/js/util/esc.js';
import { getGlobalSchema, showStatusPill } from './app.js';

const MAX_KEEP_COUNT = 100;
const MAX_RETENTION_DAYS = 3650;

function buildGroupPanel(panel, tables) {
    if (tables.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'c-muted';
        empty.textContent = 'No tables in this group.';
        panel.appendChild(empty);
        return;
    }

    const selectRow = document.createElement('div');
    selectRow.style.cssText = 'margin-bottom:14px;display:flex;gap:10px;';
    const buttonAll  = document.createElement('button');
    const buttonNone = document.createElement('button');
    buttonAll.type  = 'button'; buttonAll.textContent  = 'Select all';   buttonAll.className  = 'btn btn-xs';
    buttonNone.type = 'button'; buttonNone.textContent = 'Deselect all'; buttonNone.className = 'btn btn-xs';
    selectRow.append(buttonAll, buttonNone);
    panel.appendChild(selectRow);

    const checkboxes = [];

    tables.forEach(tableEntry => {
        const label = document.createElement('label');
        label.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid var(--border);border-radius:4px;margin-bottom:4px;cursor:pointer;background:#fff;user-select:none;';

        const callback = document.createElement('input');
        callback.type = 'checkbox';
        callback.dataset.name   = tableEntry.name;
        callback.dataset.schema = tableEntry.schema;
        callback.style.cssText  = 'width:15px;height:15px;flex-shrink:0;cursor:pointer;';
        checkboxes.push(callback);

        const nameSpan = document.createElement('span');
        nameSpan.style.flex = '1';
        nameSpan.textContent = tableEntry.display !== tableEntry.name ? `${tableEntry.display}  (${tableEntry.name})` : tableEntry.name;

        const schemaTag = document.createElement('span');
        schemaTag.style.cssText = 'font-family:var(--font-mono);';
        schemaTag.textContent = tableEntry.schema;

        label.append(callback, nameSpan, schemaTag);
        panel.appendChild(label);
    });

    buttonAll.addEventListener('click',  () => checkboxes.forEach(callback => callback.checked = true));
    buttonNone.addEventListener('click', () => checkboxes.forEach(callback => callback.checked = false));

    const actionRow = document.createElement('div');
    actionRow.style.cssText = 'margin-top:22px;display:flex;align-items:center;gap:14px;';
    const buttonBackup = document.createElement('button');
    buttonBackup.type = 'button';
    buttonBackup.textContent = 'Backup selected tables';
    buttonBackup.className = 'btn btn-primary';
    actionRow.appendChild(buttonBackup);
    panel.appendChild(actionRow);

    const resultArea = document.createElement('div');
    resultArea.style.marginTop = '16px';
    panel.appendChild(resultArea);

    buttonBackup.addEventListener('click', async () => {
        const selected = checkboxes
            .filter(callback => callback.checked)
            .map(callback => ({ name: callback.dataset.name, schema: callback.dataset.schema }));

        if (selected.length === 0) {
            resultArea.innerHTML = '<p style="color:var(--warn);margin:0;">No tables selected.</p>';
            return;
        }

        buttonBackup.disabled = true;
        buttonBackup.textContent = 'Running…';
        resultArea.innerHTML = '';

        try {
            const result = await apiFetch('api.php?action=backup_tables', {
                method: 'POST',
                body: JSON.stringify({ tables: selected })
            });
            const data = await result.json();

            if (data.status === 'success') {
                const ul = document.createElement('ul');
                ul.style.cssText = 'list-style:none;padding:0;margin:0;';
                data.results.forEach(resultRow => {
                    const li = document.createElement('li');
                    li.style.cssText = 'padding:8px 12px;border-radius:4px;margin-bottom:4px;display:flex;gap:8px;align-items:baseline;';
                    if (resultRow.status === 'success') {
                        li.style.background = 'var(--ok-light)';
                        li.innerHTML = `<span style="color:var(--ok);font-weight:var(--font-weight-bold);">✓</span>`
                            + ` <strong>${escHtml(resultRow.table)}</strong> → <code style="background:var(--ok-light);padding:1px 5px;border-radius:3px;">${escHtml(resultRow.backup)}</code>`
                            + ` <span style="color:var(--ok);">(${escHtml(resultRow.rows)} row${resultRow.rows !== 1 ? 's' : ''})</span>`
                            + (resultRow.dropped > 0
                                ? ` <span style="color:var(--muted);">retention removed ${escHtml(resultRow.dropped)} older cop${resultRow.dropped !== 1 ? 'ies' : 'y'}</span>`
                                : '');
                    } else {
                        li.style.background = 'var(--error-light)';
                        li.innerHTML = `<span style="color:var(--error);font-weight:var(--font-weight-bold);">✗</span>`
                            + ` <strong>${escHtml(resultRow.table)}</strong>: <span style="color:var(--error);">${escHtml(resultRow.message)}</span>`;
                    }
                    ul.appendChild(li);
                });
                resultArea.appendChild(ul);
            } else {
                resultArea.innerHTML = `<p style="color:var(--error);margin:0;">Error: ${escHtml(data.error || 'Unknown error')}</p>`;
            }
        } catch (error) {
            resultArea.innerHTML = `<p style="color:var(--error);margin:0;">Request failed: ${escHtml(error.message)}</p>`;
        }

        buttonBackup.disabled = false;
        buttonBackup.textContent = 'Backup selected tables';
    });
}

function renderSettingsPanel(panel, settings, version, reloadBackups) {
    const { card, body } = buildSectionCard(
        'Backup Global Settings',
        'Retention applied automatically after every backup. Both rules are disabled when set to 0.'
    );
    panel.appendChild(card);

    const keepCountRow = el('div');
    keepCountRow.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;';
    const keepCountInput = document.createElement('input');
    keepCountInput.type = 'number';
    keepCountInput.min = '0';
    keepCountInput.max = String(MAX_KEEP_COUNT);
    keepCountInput.className = 'adm-input w-80';
    keepCountInput.value = String(settings.keep_count ?? 0);
    keepCountRow.append(
        el('strong', '', 'Keep at most'),
        keepCountInput,
        el('span', '', 'copies per table')
    );
    body.appendChild(keepCountRow);

    const keepCountNote = el('p', 'admin-page-desc',
        'After a backup finishes, older copies of the same table beyond this count are dropped. 0 disables the count rule.');
    body.appendChild(keepCountNote);

    const retentionRow = el('div');
    retentionRow.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;';
    const retentionInput = document.createElement('input');
    retentionInput.type = 'number';
    retentionInput.min = '0';
    retentionInput.max = String(MAX_RETENTION_DAYS);
    retentionInput.className = 'adm-input w-80';
    retentionInput.value = String(settings.retention_days ?? 0);
    retentionRow.append(
        el('strong', '', 'Delete copies older than'),
        retentionInput,
        el('span', '', 'days')
    );
    body.appendChild(retentionRow);

    const retentionNote = el('p', 'admin-page-desc',
        'Copies with a timestamp prefix older than this many days are dropped. 0 disables the age rule.');
    body.appendChild(retentionNote);

    const actions = el('div');
    actions.style.cssText = 'display:flex;align-items:center;gap:10px;margin-top:16px;';
    const saveButton = el('button', 'btn btn-primary', 'Save');
    const pillAnchor = el('span');
    actions.append(saveButton, pillAnchor);
    body.appendChild(actions);

    saveButton.addEventListener('click', async () => {
        const keepCount = parseInt(keepCountInput.value, 10);
        const retentionDays = parseInt(retentionInput.value, 10);
        if (!Number.isFinite(keepCount) || keepCount < 0 || keepCount > MAX_KEEP_COUNT) {
            showStatusPill(pillAnchor, `Keep count must be 0-${MAX_KEEP_COUNT}.`, 'error');
            return;
        }
        if (!Number.isFinite(retentionDays) || retentionDays < 0 || retentionDays > MAX_RETENTION_DAYS) {
            showStatusPill(pillAnchor, `Retention must be 0-${MAX_RETENTION_DAYS} days.`, 'error');
            return;
        }

        saveButton.disabled = true;
        try {
            const response = await apiFetch('api.php?action=backup_settings_save', {
                method: 'POST',
                body: JSON.stringify({
                    keep_count: keepCount,
                    retention_days: retentionDays,
                    version,
                }),
            });
            const result = await response.json();
            if (result.status === 'success') {
                showStatusPill(pillAnchor, 'Settings saved', 'success');
                reloadBackups();
            } else {
                showStatusPill(pillAnchor, result.error || 'Error saving settings', 'error');
            }
        } catch (error) {
            showStatusPill(pillAnchor, 'Request failed', 'error');
        }
        saveButton.disabled = false;
    });
}

function renderBackupsPanel(panel, reloadBackups) {
    const { card, body } = buildSectionCard(
        'Existing Backups',
        'Timestamped copies discovered in the database. Copies matching the retention rules are marked stale.'
    );
    panel.appendChild(card);

    const actionBar = el('div');
    actionBar.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;';
    const cleanupButton = el('button', 'btn btn-secondary', 'Clean Up Now');
    const cleanupPill = el('span');
    actionBar.append(cleanupButton, cleanupPill);
    panel.insertBefore(actionBar, card);

    const tableWrapper = el('div');
    tableWrapper.style.overflowX = 'auto';
    body.appendChild(tableWrapper);

    async function load() {
        tableWrapper.innerHTML = '<p>Loading...</p>';
        let data;
        try {
            const response = await apiFetch('api.php?action=backup_list');
            data = await response.json();
        } catch (error) {
            tableWrapper.innerHTML = '<p style="color:var(--error);">Request failed.</p>';
            return;
        }
        if (data.status !== 'success') {
            tableWrapper.innerHTML = '';
            tableWrapper.appendChild(el('p', '', data.error || 'Could not load backups.')).style.color = 'var(--error)';
            return;
        }

        const rows = data.rows || [];
        tableWrapper.innerHTML = '';
        if (rows.length === 0) {
            tableWrapper.appendChild(el('p', '', 'No backup copies found.'));
            return;
        }

        const table = mkTable();
        mkThead(table, ['Backup Table', 'Source Table', 'Schema', 'Created', 'Status']);
        const tbody = table.createTBody();
        rows.forEach(row => {
            const tr = tbody.insertRow();
            tr.appendChild(td(row.name));
            tr.appendChild(td(row.source));
            tr.appendChild(td(row.schema));
            tr.appendChild(td(row.created));
            const badge = el('span', 'adm-badge adm-badge-' + (row.stale ? 'danger' : 'muted'), row.stale ? 'stale' : 'kept');
            tr.appendChild(tdEl(badge));
        });
        tableWrapper.appendChild(table);
    }

    cleanupButton.addEventListener('click', async () => {
        cleanupButton.disabled = true;
        try {
            const response = await apiFetch('api.php?action=backup_cleanup', {
                method: 'POST',
                body: JSON.stringify({})
            });
            const result = await response.json();
            if (result.status === 'success') {
                showStatusPill(cleanupPill, `Removed ${result.deleted ?? 0} stale cop${result.deleted === 1 ? 'y' : 'ies'}`, 'success');
                load();
                reloadBackups();
            } else {
                showStatusPill(cleanupPill, result.error || 'Cleanup failed', 'error');
            }
        } catch (error) {
            showStatusPill(cleanupPill, 'Request failed', 'error');
        }
        cleanupButton.disabled = false;
    });

    load();
}

export async function renderBackupPage(context) {
    const { workspaceEl: workspaceElement } = context;

    workspaceElement.innerHTML = '<p style="padding:20px;">Loading tables…</p>';

    workspaceElement._renderId = (workspaceElement._renderId || 0) + 1;
    const myId = workspaceElement._renderId;

    let userTables = [];
    let systemTables = [];
    let settings = { keep_count: 0, retention_days: 0 };
    let settingsVersion = null;

    try {
        const [schemaData, sysResult, settingsResult] = await Promise.all([
            getGlobalSchema(),
            apiFetch('api.php?action=list_system_tables'),
            apiFetch('api.php?action=backup_settings'),
        ]);
        const sysData = await sysResult.json();
        const settingsData = await settingsResult.json();

        if (schemaData?.tables) {
            for (const [name, config] of Object.entries(schemaData.tables)) {
                userTables.push({
                    name,
                    schema:  config.schema || 'public',
                    display: config.display_name || name,
                });
            }
        }
        if (sysData.status === 'success') {
            sysData.tables.forEach(tableEntry => {
                systemTables.push({ name: tableEntry.name, schema: tableEntry.schema, display: tableEntry.name });
            });
        }
        if (settingsData.status === 'success') {
            settings = settingsData.settings || settings;
            settingsVersion = settingsData.version ?? null;
        }
    } catch (error) {
        if (workspaceElement._renderId !== myId) return;
        workspaceElement.innerHTML = '<p style="color:var(--error);padding:20px;">Failed to load tables.</p>';
        return;
    }

    if (workspaceElement._renderId !== myId) return;

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';

    wrap.appendChild(createPageHeader('Backup Tables',
        'Creates a copy of selected tables in the same schema using <code>CREATE TABLE prefix_name AS SELECT * FROM name</code>.'
        + ' The prefix is the current date and time — e.g. <code>202604211709_tablename</code>.'
        + ' Data and column structure are copied; indexes and constraints are not.'));

    const [appPanel, sysPanel, globalPanel] = buildInnerTabs(wrap, [
        { label: 'Application Tables', icon: 'material/data_table.svg' },
        { label: 'System Tables (spw_*)', icon: 'material/database.svg' },
        { label: 'Global Settings', icon: 'material/settings.svg' },
    ]);

    buildGroupPanel(appPanel, userTables);
    buildGroupPanel(sysPanel, systemTables);

    renderSettingsPanel(globalPanel, settings, settingsVersion, renderBackups);

    const backupsHost = el('div');
    globalPanel.appendChild(backupsHost);

    function renderBackups() {
        backupsHost.innerHTML = '';
        renderBackupsPanel(backupsHost, renderBackups);
    }

    renderBackups();

    workspaceElement.innerHTML = '';
    workspaceElement.appendChild(wrap);
}