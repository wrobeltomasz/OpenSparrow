// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// admin/js/audit.js — Audit-log settings editor (renderAuditEditor); reads/writes audit config via api.php.
import { apiFetch } from '../../assets/js/util/api.js';
import { showStatusPill } from './app.js';
import { createPageHeader } from './ui.js';

export async function renderAuditEditor(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '<h3>Loading audit settings...</h3>';

    workspaceEl._renderId = (workspaceEl._renderId || 0) + 1;
    const myId = workspaceEl._renderId;

    let data;
    try {
        const res = await apiFetch('api.php?action=get_snapshot_setting');
        data = await res.json();
    } catch (e) {
        if (workspaceEl._renderId !== myId) return;
        workspaceEl.innerHTML = '<h3 style="color:#d00000;">Error loading audit settings. Check server logs.</h3>';
        return;
    }

    if (workspaceEl._renderId !== myId) return;

    const lockedByEnv = data.locked_by_env ?? false;
    let enabled = data.enabled ?? false;
    const tableExists = data.table_exists ?? false;
    const snapshotCount = data.snapshot_count;

    workspaceEl.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    workspaceEl.appendChild(wrap);

    wrap.appendChild(createPageHeader(
        'Audit & Record Snapshots',
        'When enabled, every INSERT, UPDATE, and DELETE on user data tables saves a full JSONB snapshot of the record to spw_record_snapshots, linked to the audit log entry in spw_users_log.'
    ));

    // --- Status cards ---
    const grid = document.createElement('div');
    grid.style.cssText = 'display:grid; gap:10px; margin-bottom:24px;';

    const statusCard = (title, isOk, msg) => {
        const div = document.createElement('div');
        div.style.cssText = `padding:12px 16px; border-left:4px solid ${isOk ? '#2b9348' : '#d00000'}; background:white; box-shadow:0 1px 3px rgba(0,0,0,.08); border-radius:4px;`;
        div.innerHTML = `<strong style=" display:block; margin-bottom:4px; color:${isOk ? '#2b9348' : '#d00000'};">${isOk ? '[OK]' : '[FAIL]'} ${title}</strong><span style="color:#64748B; ">${msg}</span>`;
        return div;
    };

    const infoCard = (title, msg) => {
        const div = document.createElement('div');
        div.style.cssText = 'padding:12px 16px; border-left:4px solid #005A9E; background:white; box-shadow:0 1px 3px rgba(0,0,0,.08); border-radius:4px;';
        div.innerHTML = `<strong style=" display:block; margin-bottom:4px; color:#1E293B;">[INFO] ${title}</strong><span style="color:#64748B; ">${msg}</span>`;
        return div;
    };

    grid.appendChild(statusCard(
        'spw_record_snapshots table',
        tableExists,
        tableExists
            ? `Table exists. ${snapshotCount !== null ? `Stored snapshots: <strong>${snapshotCount}</strong>.` : ''}`
            : 'Table not found. Run <strong>Initialize System Tables</strong> in System Health first.'
    ));

    if (lockedByEnv) {
        grid.appendChild(infoCard(
            'Controlled by environment variable',
            'The <code>RECORD_SNAPSHOTS_ENABLED</code> env var is set — the toggle below is read-only. Remove the env var to control this setting from the admin panel.'
        ));
    }

    wrap.appendChild(grid);

    // --- Toggle ---
    const toggleSection = document.createElement('div');
    toggleSection.className = 'adm-sec-card';

    const toggleBody = document.createElement('div');
    toggleBody.className = 'adm-sec-body';
    toggleSection.appendChild(toggleBody);

    const toggleRow = document.createElement('div');
    toggleRow.style.cssText = 'display:flex; align-items:center; justify-content:space-between; gap:16px;';

    const labelGroup = document.createElement('div');
    const labelTitle = document.createElement('strong');
    labelTitle.style.cssText = 'display:block;  margin-bottom:4px;';
    labelTitle.textContent = 'Record Snapshots';
    const labelDesc = document.createElement('span');
    labelDesc.style.cssText = 'color:#64748B; ';
    labelDesc.textContent = 'Capture full record state on every write operation and store it in spw_record_snapshots.';
    labelGroup.appendChild(labelTitle);
    labelGroup.appendChild(labelDesc);

    const switchLabel = document.createElement('label');
    switchLabel.style.cssText = 'position:relative; display:inline-block; width:48px; height:26px; flex-shrink:0;';

    const switchInput = document.createElement('input');
    switchInput.type = 'checkbox';
    switchInput.checked = enabled;
    switchInput.disabled = lockedByEnv || !tableExists;
    switchInput.style.cssText = 'opacity:0; width:0; height:0; position:absolute;';

    const switchSlider = document.createElement('span');
    switchSlider.style.cssText = `
        position:absolute; cursor:${lockedByEnv || !tableExists ? 'not-allowed' : 'pointer'};
        top:0; left:0; right:0; bottom:0;
        background:${enabled ? '#005A9E' : '#CBD5E1'};
        border-radius:26px; transition:background .2s;
    `;
    const switchKnob = document.createElement('span');
    switchKnob.style.cssText = `
        position:absolute; height:20px; width:20px;
        left:${enabled ? '24px' : '3px'}; bottom:3px;
        background:white; border-radius:50%; transition:left .2s;
        box-shadow:0 1px 3px rgba(0,0,0,.2);
    `;
    switchSlider.appendChild(switchKnob);
    switchLabel.appendChild(switchInput);
    switchLabel.appendChild(switchSlider);

    const pillAnchor = document.createElement('span');

    switchInput.addEventListener('change', async () => {
        const newVal = switchInput.checked;
        switchInput.disabled = true;
        try {
            const res = await apiFetch('api.php?action=set_snapshot_setting', {
                method: 'POST',
                body: JSON.stringify({ enabled: newVal }),
            });
            const result = await res.json();
            if (result.status === 'success') {
                enabled = newVal;
                switchSlider.style.background = newVal ? '#005A9E' : '#CBD5E1';
                switchKnob.style.left = newVal ? '24px' : '3px';
                showStatusPill(pillAnchor, newVal ? 'Snapshots enabled' : 'Snapshots disabled', 'success');
            } else {
                switchInput.checked = !newVal;
                showStatusPill(pillAnchor, result.error || 'Error saving setting', 'error');
            }
        } catch (e) {
            switchInput.checked = !newVal;
            showStatusPill(pillAnchor, 'Request failed', 'error');
        }
        switchInput.disabled = lockedByEnv || !tableExists;
    });

    toggleRow.appendChild(labelGroup);
    toggleRow.appendChild(switchLabel);
    toggleBody.appendChild(toggleRow);
    toggleBody.appendChild(pillAnchor);
    wrap.appendChild(toggleSection);

    // --- Schema info ---
    const schemaSection = document.createElement('div');
    schemaSection.className = 'adm-sec-card';
    schemaSection.innerHTML = `
        <div class="adm-sec-hdr" style="display:block;">
            <h3 style="margin:0;">Table: spw_record_snapshots</h3>
        </div>
        <div class="adm-sec-body">
            <table class="adm-tbl">
                <thead>
                    <tr>
                        <th class="adm-th">Column</th>
                        <th class="adm-th">Type</th>
                        <th class="adm-th">Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="adm-td">id</td><td class="adm-td">serial</td><td class="adm-td">Primary key</td></tr>
                    <tr><td class="adm-td">log_id</td><td class="adm-td">int4</td><td class="adm-td">FK to spw_users_log.id (CASCADE DELETE)</td></tr>
                    <tr><td class="adm-td">table_name</td><td class="adm-td">varchar(100)</td><td class="adm-td">Name of the affected table</td></tr>
                    <tr><td class="adm-td">record_id</td><td class="adm-td">int4</td><td class="adm-td">PK of the affected record</td></tr>
                    <tr><td class="adm-td">snapshot</td><td class="adm-td">jsonb</td><td class="adm-td">Full record as JSON (row_to_json)</td></tr>
                    <tr><td class="adm-td">created_at</td><td class="adm-td">timestamp</td><td class="adm-td">When the snapshot was saved</td></tr>
                </tbody>
            </table>
        </div>
    `;
    wrap.appendChild(schemaSection);
}
