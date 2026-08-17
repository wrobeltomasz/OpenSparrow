// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { buildInnerTabs, createPageHeader } from './ui.js';
import { escHtml } from '../../assets/js/util/esc.js';

export async function renderMigrationsPage(context) {
    const { workspaceEl: workspaceElement } = context;

    workspaceElement.innerHTML = '';

    const outer = document.createElement('div');
    outer.className = 'admin-page';
    workspaceElement.appendChild(outer);

    outer.appendChild(createPageHeader(
        'Migrations',
        'Scan for schema drift and apply pending database migrations, or review release migrations from upgrades.'
    ));

    const [panel0, panel1] = buildInnerTabs(outer, [
        { label: 'Database Migrations', icon: 'database.png' },
        { label: 'Release Migrations', icon: 'box.png' },
    ]);

    const subtitle = document.createElement('p');
    subtitle.style.cssText = 'margin:0 0 20px;  ';
    subtitle.textContent = 'Each migration runs once and is recorded in spw_migrations. Running "Apply Migrations" is safe to repeat.';

    const runButton = document.createElement('button');
    runButton.id = 'mig-run-btn';
    runButton.className = 'btn btn-primary';
    runButton.style.marginBottom = '24px';
    runButton.textContent = 'Apply Pending Migrations';

    const statusElement = document.createElement('p');
    statusElement.id = 'mig-status';
    statusElement.style.cssText = ' margin:0 0 20px; min-height:18px;';

    const tableWrap = document.createElement('div');
    tableWrap.id = 'mig-table';
    tableWrap.innerHTML = '<p style=" ">Loading…</p>';

    panel0.append(subtitle, runButton, statusElement, tableWrap);

    const relSub = document.createElement('p');
    relSub.style.cssText = 'margin:0 0 20px;  ';
    relSub.textContent = 'File and config cleanup tasks defined in config/migrations.json. Run after upgrading to a new version.';

    const relContainer = document.createElement('div');
    relContainer.id = 'mig-release-container';
    relContainer.innerHTML = '<p style=" ">Loading…</p>';

    panel1.append(relSub, relContainer);

    runButton.addEventListener('click', async () => {
        if (!confirm('Apply all pending migrations now?')) return;

        runButton.disabled    = true;
        runButton.textContent = 'Applying…';
        statusElement.textContent = '';

        try {
            const result  = await apiFetch('api.php?action=init_db', {
                method: 'POST',
            });
            const data = await result.json();

            if (data.status === 'success') {
                statusElement.style.color = 'var(--ok)';
                statusElement.textContent = '✓ ' + data.message;
                await loadMigrations(tableWrap);
            } else {
                statusElement.style.color = 'var(--error)';
                statusElement.textContent = '✗ ' + (data.error || 'Unknown error.');
            }
        } catch {
            statusElement.style.color = 'var(--error)';
            statusElement.textContent = '✗ Network error.';
        } finally {
            runButton.disabled    = false;
            runButton.textContent = 'Apply Pending Migrations';
        }
    });

    await loadMigrations(tableWrap);
    loadReleaseMigrations(relContainer);
}

async function loadMigrations(container) {
    container.innerHTML = '<p style=" ">Loading…</p>';

    let data;
    try {
        const result = await apiFetch('api.php?action=migrations_list');
        data = await result.json();
    } catch {
        container.innerHTML = '<p style="color:var(--error); ">Failed to load migrations.</p>';
        return;
    }

    if (data.status !== 'success') {
        container.innerHTML = `<p style="color:var(--error); ">Error: ${escHtml(data.error)}</p>`;
        return;
    }

    const migrations = data.migrations;
    const pending    = migrations.filter(migration => migration.status === 'pending');
    const applied    = migrations.filter(migration => migration.status === 'applied');

    const table = document.createElement('table');
    table.className = 'adm-tbl';

    const thead = table.createTHead();
    const hrow = thead.insertRow();
    ['Migration', 'Status', 'Applied at'].forEach(headerLabel => {
        const th = document.createElement('th');
        th.className = 'adm-th';
        th.textContent = headerLabel;
        hrow.appendChild(th);
    });

    const tbody = table.createTBody();

    migrations.forEach(migration => {
        const tr = tbody.insertRow();

        const isPending = migration.status === 'pending';
        const badge = isPending
            ? '<span class="adm-badge adm-badge-warn">PENDING</span>'
            : '<span class="adm-badge adm-badge-ok">APPLIED</span>';

        const appliedAt = migration.applied_at
            ? new Date(migration.applied_at).toLocaleString()
            : '—';

        tr.innerHTML = `
            <td class="adm-td mono">${escHtml(migration.name)}</td>
            <td class="adm-td">${badge}</td>
            <td class="adm-td">${escHtml(appliedAt)}</td>`;
    });

    const summary = document.createElement('p');
    summary.style.cssText = '  margin-top:12px;';
    summary.textContent = `Total: ${migrations.length} | Applied: ${applied.length} | Pending: ${pending.length}`;

    container.innerHTML = '';
    container.append(table, summary);
}

async function loadReleaseMigrations(container) {
    container.innerHTML = '<p style=" ">Loading…</p>';

    let data;
    try {
        const result = await apiFetch('api_migrations.php?action=scan');
        data = await result.json();
    } catch {
        container.innerHTML = '<p style="color:var(--error); ">Failed to load release migrations.</p>';
        return;
    }

    if (data.status !== 'success') {
        container.innerHTML = `<p style="color:var(--error); ">Error: ${escHtml(data.error || 'Unknown')}</p>`;
        return;
    }

    container.innerHTML = '';

    const versions = data.versions || [];
    if (versions.length === 0) {
        container.innerHTML = '<p style=" ">No release migrations defined in config/migrations.json.</p>';
        return;
    }

    versions.forEach(version => renderVersionCard(version, container));
}

function renderVersionCard(version, container) {
    const isPending  = version.status === 'pending';
    const hasActions = version.actions.some(versionAction => versionAction.type !== 'file_deprecated');

    const card = document.createElement('div');
    card.style.cssText = `border:1px solid ${isPending ? 'var(--warn)' : 'var(--accent-mid)'}; border-radius:6px; padding:16px 20px; margin-bottom:16px; background:${isPending ? 'var(--warn-light)' : 'var(--accent-mid)'};`;

    const headerRow = document.createElement('div');
    headerRow.style.cssText = 'display:flex; align-items:center; gap:12px; margin-bottom:8px;';

    const verSpan = document.createElement('span');
    verSpan.style.cssText = 'font-family:var(--font-mono);  font-weight:700; color:var(--text);';
    verSpan.textContent = 'v' + version.version;

    const badge = document.createElement('span');
    badge.className = `adm-badge ${isPending ? 'adm-badge-warn' : 'adm-badge-ok'}`;
    badge.textContent = isPending ? 'PENDING' : 'APPLIED';

    headerRow.append(verSpan, badge);
    card.appendChild(headerRow);

    if (version.notes) {
        const notes = document.createElement('p');
        notes.style.cssText = '  margin:0 0 12px;';
        notes.textContent = version.notes;
        card.appendChild(notes);
    }

    const checkboxes = [];

    if (isPending && version.actions.length > 0) {
        const actionsLabel = document.createElement('p');
        actionsLabel.style.cssText = ' font-weight:600;  margin:0 0 8px;  ';
        actionsLabel.textContent = 'Actions';
        card.appendChild(actionsLabel);

        version.actions.forEach((versionAction, index) => {
            const row = document.createElement('label');
            row.style.cssText = 'display:flex; align-items:center; gap:8px;   margin-bottom:6px; cursor:pointer;';

            const callback = document.createElement('input');
            callback.type = 'checkbox';
            if (versionAction.type !== 'file_deprecated') {
                callback.checked = true;
                callback.dataset.idx = index;
                checkboxes.push(callback);
            } else {
                callback.disabled = true;
                callback.title = 'Informational only — no action taken';
            }

            const labelElement = document.createElement('span');
            const typeTag = versionAction.type === 'file_deprecated'
                ? '<span style=" ">[info]</span> '
                : '';
            const existTag = (versionAction.type === 'file_remove' && !versionAction.exists)
                ? ' <span style=" ">(file not found — will skip)</span>'
                : (versionAction.type === 'config_key_remove' && !versionAction.present)
                    ? ' <span style=" ">(key not found — will skip)</span>'
                    : '';
            labelElement.innerHTML = typeTag + escHtml(versionAction.label) + existTag;

            row.append(callback, labelElement);
            card.appendChild(row);
        });
    } else if (isPending && version.actions.length === 0) {
        const none = document.createElement('p');
        none.style.cssText = '  margin:0 0 12px;';
        none.textContent = 'No file or config changes required for this release.';
        card.appendChild(none);
    }

    if (!isPending && version.applied_data) {
        const appliedData = version.applied_data;
        const hist = document.createElement('p');
        hist.style.cssText = '  margin:4px 0 0;';
        hist.textContent = 'Applied: ' + new Date(appliedData.applied_at).toLocaleString();
        card.appendChild(hist);

        if (appliedData.actions && appliedData.actions.length > 0) {
            const actList = document.createElement('ul');
            actList.style.cssText = 'margin:8px 0 0; padding-left:18px;  ';
            appliedData.actions.forEach(versionAction => {
                const li = document.createElement('li');
                if (versionAction.status === 'done' && versionAction.backup) {
                    li.innerHTML = escHtml(versionAction.type + ': ' + (versionAction.path || versionAction.file)) +
                        ' <span style="">— backup: ' + escHtml(versionAction.backup) + '</span>';
                } else {
                    li.textContent = versionAction.type + ': ' + (versionAction.path || versionAction.file || '') + ' [' + versionAction.status + ']';
                }
                actList.appendChild(li);
            });
            card.appendChild(actList);
        }
    }

    if (isPending) {
        const buttonRow = document.createElement('div');
        buttonRow.style.cssText = 'margin-top:14px;';

        const applyButton = document.createElement('button');
        applyButton.className = 'btn btn-primary btn-sm';
        applyButton.textContent = hasActions ? 'Apply selected' : 'Mark as applied';

        const statusMessage = document.createElement('span');
        statusMessage.style.cssText = 'margin-left:12px; ';

        applyButton.addEventListener('click', async () => {
            const selected = checkboxes.filter(callback => callback.checked).map(callback => parseInt(callback.dataset.idx, 10));

            if (!confirm('Apply release migration v' + version.version + '? This will run the selected actions and cannot be undone.')) return;

            applyButton.disabled    = true;
            applyButton.textContent = 'Applying…';
            statusMessage.textContent = '';

            try {
                const result  = await apiFetch('api_migrations.php?action=apply', {
                    method: 'POST',
                    body: JSON.stringify({ version: version.version, selected }),
                });
                const data = await result.json();

                if (data.status === 'success') {
                    statusMessage.style.color = 'var(--ok)';
                    statusMessage.textContent = '✓ Applied.';
                    const relContainer = document.getElementById('mig-release-container');
                    if (relContainer) loadReleaseMigrations(relContainer);
                    const banner = document.getElementById('mig-pending-banner');
                    if (banner) banner.style.display = 'none';
                } else {
                    statusMessage.style.color = 'var(--error)';
                    statusMessage.textContent = '✗ ' + (data.error || 'Unknown error.');
                    applyButton.disabled    = false;
                    applyButton.textContent = hasActions ? 'Apply selected' : 'Mark as applied';
                }
            } catch {
                statusMessage.style.color = 'var(--error)';
                statusMessage.textContent = '✗ Network error.';
                applyButton.disabled    = false;
                applyButton.textContent = hasActions ? 'Apply selected' : 'Mark as applied';
            }
        });

        buttonRow.append(applyButton, statusMessage);
        card.appendChild(buttonRow);
    }

    container.appendChild(card);
}
