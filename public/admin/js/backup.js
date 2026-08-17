// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { buildInnerTabs, createPageHeader } from './ui.js';

import { escHtml } from '../../assets/js/util/esc.js';
import { getGlobalSchema } from './app.js';

const GLOBAL_SETTINGS_TABLES = ['spw_config', 'spw_config_log'];

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
                        li.innerHTML = `<span style="color:var(--ok);font-weight:700;">✓</span>`
                            + ` <strong>${escHtml(resultRow.table)}</strong> → <code style="background:var(--ok-light);padding:1px 5px;border-radius:3px;">${escHtml(resultRow.backup)}</code>`
                            + ` <span style="color:var(--ok);">(${escHtml(resultRow.rows)} row${resultRow.rows !== 1 ? 's' : ''})</span>`;
                    } else {
                        li.style.background = 'var(--error-light)';
                        li.innerHTML = `<span style="color:var(--error);font-weight:700;">✗</span>`
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

export async function renderBackupPage(context) {
    const { workspaceEl: workspaceElement } = context;

    workspaceElement.innerHTML = '<p style="padding:20px;">Loading tables…</p>';

    workspaceElement._renderId = (workspaceElement._renderId || 0) + 1;
    const myId = workspaceElement._renderId;

    let userTables = [];
    let systemTables = [];
    let globalSettingsTables = [];

    try {
        const [schemaData, sysResult] = await Promise.all([
            getGlobalSchema(),
            apiFetch('api.php?action=list_system_tables')
        ]);
        const sysData = await sysResult.json();

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
                const entry = { name: tableEntry.name, schema: tableEntry.schema, display: tableEntry.name };
                if (GLOBAL_SETTINGS_TABLES.includes(tableEntry.name)) {
                    globalSettingsTables.push(entry);
                } else {
                    systemTables.push(entry);
                }
            });
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
        { label: 'Application Tables', icon: 'data_table.png' },
        { label: 'System Tables (spw_*)', icon: 'database.png' },
        { label: 'Global Settings', icon: 'car_gear.png' },
    ]);

    buildGroupPanel(appPanel, userTables);
    buildGroupPanel(sysPanel, systemTables);
    buildGroupPanel(globalPanel, globalSettingsTables);

    workspaceElement.innerHTML = '';
    workspaceElement.appendChild(wrap);
}
