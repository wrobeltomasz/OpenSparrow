// admin/js/backup.js — Backup & restore page (renderBackupPage): per-table export/import and full backup via api.php (export / import / backup_tables). CSRF via apiFetch().

import { apiFetch } from '../../assets/js/util/api.js';
import { buildInnerTabs, createPageHeader } from './ui.js';

import { escHtml as esc } from '../../assets/js/util/esc.js';

// spw_config holds the whole application configuration (schema, menu, dashboard,
// workflows, ...) as one JSONB row per key — treated as "Global Settings" rather
// than a regular system table, and pulled out of the System Tables (spw_*) group.
const GLOBAL_SETTINGS_TABLES = ['spw_config', 'spw_config_log'];

// Renders one tab's worth of table checkboxes + its own select-all/backup controls.
function buildGroupPanel(panel, tables) {
    if (tables.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'c-muted';
        empty.textContent = 'No tables in this group.';
        panel.appendChild(empty);
        return;
    }

    const selRow = document.createElement('div');
    selRow.style.cssText = 'margin-bottom:14px;display:flex;gap:10px;';
    const btnAll  = document.createElement('button');
    const btnNone = document.createElement('button');
    btnAll.type  = 'button'; btnAll.textContent  = 'Select all';   btnAll.className  = 'btn btn-xs';
    btnNone.type = 'button'; btnNone.textContent = 'Deselect all'; btnNone.className = 'btn btn-xs';
    selRow.append(btnAll, btnNone);
    panel.appendChild(selRow);

    const checkboxes = [];

    tables.forEach(t => {
        const label = document.createElement('label');
        label.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid var(--border);border-radius:4px;margin-bottom:4px;cursor:pointer;background:#fff;user-select:none;';

        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.dataset.name   = t.name;
        cb.dataset.schema = t.schema;
        cb.style.cssText  = 'width:15px;height:15px;flex-shrink:0;cursor:pointer;';
        checkboxes.push(cb);

        const nameSpan = document.createElement('span');
        nameSpan.style.flex = '1';
        nameSpan.textContent = t.display !== t.name ? `${t.display}  (${t.name})` : t.name;

        const schemaTag = document.createElement('span');
        schemaTag.style.cssText = 'font-family:monospace;';
        schemaTag.textContent = t.schema;

        label.append(cb, nameSpan, schemaTag);
        panel.appendChild(label);
    });

    btnAll.addEventListener('click',  () => checkboxes.forEach(cb => cb.checked = true));
    btnNone.addEventListener('click', () => checkboxes.forEach(cb => cb.checked = false));

    // Backup button + result area
    const actionRow = document.createElement('div');
    actionRow.style.cssText = 'margin-top:22px;display:flex;align-items:center;gap:14px;';
    const btnBackup = document.createElement('button');
    btnBackup.type = 'button';
    btnBackup.textContent = 'Backup selected tables';
    btnBackup.className = 'btn btn-primary';
    actionRow.appendChild(btnBackup);
    panel.appendChild(actionRow);

    const resultArea = document.createElement('div');
    resultArea.style.marginTop = '16px';
    panel.appendChild(resultArea);

    btnBackup.addEventListener('click', async () => {
        const selected = checkboxes
            .filter(cb => cb.checked)
            .map(cb => ({ name: cb.dataset.name, schema: cb.dataset.schema }));

        if (selected.length === 0) {
            resultArea.innerHTML = '<p style="color:var(--warn);margin:0;">No tables selected.</p>';
            return;
        }

        btnBackup.disabled = true;
        btnBackup.textContent = 'Running…';
        resultArea.innerHTML = '';

        try {
            const res = await apiFetch('api.php?action=backup_tables', {
                method: 'POST',
                body: JSON.stringify({ tables: selected })
            });
            const data = await res.json();

            if (data.status === 'success') {
                const ul = document.createElement('ul');
                ul.style.cssText = 'list-style:none;padding:0;margin:0;';
                data.results.forEach(r => {
                    const li = document.createElement('li');
                    li.style.cssText = 'padding:8px 12px;border-radius:4px;margin-bottom:4px;display:flex;gap:8px;align-items:baseline;';
                    if (r.status === 'success') {
                        li.style.background = 'rgba(43,147,72,0.12)';
                        li.innerHTML = `<span style="color:var(--ok);font-weight:700;">✓</span>`
                            + ` <strong>${esc(r.table)}</strong> → <code style="background:rgba(43,147,72,0.12);padding:1px 5px;border-radius:3px;">${esc(r.backup)}</code>`
                            + ` <span style="color:var(--ok);">(${esc(r.rows)} row${r.rows !== 1 ? 's' : ''})</span>`;
                    } else {
                        li.style.background = 'rgba(208,0,0,0.08)';
                        li.innerHTML = `<span style="color:#a80000;font-weight:700;">✗</span>`
                            + ` <strong>${esc(r.table)}</strong>: <span style="color:#a80000;">${esc(r.message)}</span>`;
                    }
                    ul.appendChild(li);
                });
                resultArea.appendChild(ul);
            } else {
                resultArea.innerHTML = `<p style="color:var(--danger);margin:0;">Error: ${esc(data.error || 'Unknown error')}</p>`;
            }
        } catch (e) {
            resultArea.innerHTML = `<p style="color:var(--danger);margin:0;">Request failed: ${esc(e.message)}</p>`;
        }

        btnBackup.disabled = false;
        btnBackup.textContent = 'Backup selected tables';
    });
}

export async function renderBackupPage(ctx) {
    const { workspaceEl } = ctx;

    workspaceEl.innerHTML = '<p style="padding:20px;">Loading tables…</p>';

    workspaceEl._renderId = (workspaceEl._renderId || 0) + 1;
    const myId = workspaceEl._renderId;


    let userTables = [];
    let systemTables = [];
    let globalSettingsTables = [];

    try {
        const [schemaRes, sysRes] = await Promise.all([
            apiFetch('api.php?action=get&file=schema'),
            apiFetch('api.php?action=list_system_tables')
        ]);
        const schemaData = await schemaRes.json();
        const sysData    = await sysRes.json();

        if (schemaData.tables) {
            for (const [name, cfg] of Object.entries(schemaData.tables)) {
                userTables.push({
                    name,
                    schema:  cfg.schema || 'public',
                    display: cfg.display_name || name,
                });
            }
        }
        if (sysData.status === 'success') {
            sysData.tables.forEach(t => {
                const entry = { name: t.name, schema: t.schema, display: t.name };
                if (GLOBAL_SETTINGS_TABLES.includes(t.name)) {
                    globalSettingsTables.push(entry);
                } else {
                    systemTables.push(entry);
                }
            });
        }
    } catch (e) {
        if (workspaceEl._renderId !== myId) return;
        workspaceEl.innerHTML = '<p style="color:var(--danger);padding:20px;">Failed to load tables.</p>';
        return;
    }

    if (workspaceEl._renderId !== myId) return;

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

    workspaceEl.innerHTML = '';
    workspaceEl.appendChild(wrap);
}
