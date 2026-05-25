// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

// admin/audit.js
import { showStatusPill } from './app.js';

export async function renderAuditEditor(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '<h3>Loading audit settings...</h3>';

    workspaceEl._renderId = (workspaceEl._renderId || 0) + 1;
    const myId = workspaceEl._renderId;

    let data;
    try {
        const res = await fetch('api.php?action=get_snapshot_setting');
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

    function getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    }

    workspaceEl.innerHTML = '';

    const h3 = document.createElement('h3');
    h3.style.marginTop = '0';
    h3.textContent = 'Audit & Record Snapshots';
    workspaceEl.appendChild(h3);

    const desc = document.createElement('p');
    desc.style.cssText = 'color:#9db4c0; font-size:14px; margin-bottom:24px;';
    desc.textContent = 'When enabled, every INSERT, UPDATE, and DELETE on user data tables saves a full JSONB snapshot of the record to spw_record_snapshots, linked to the audit log entry in spw_users_log.';
    workspaceEl.appendChild(desc);

    // --- Status cards ---
    const grid = document.createElement('div');
    grid.style.cssText = 'display:grid; gap:10px; margin-bottom:24px;';

    const statusCard = (title, isOk, msg) => {
        const div = document.createElement('div');
        div.style.cssText = `padding:12px 16px; border-left:4px solid ${isOk ? '#2b9348' : '#d00000'}; background:white; box-shadow:0 1px 3px rgba(0,0,0,.08); border-radius:4px;`;
        div.innerHTML = `<strong style="font-size:14px; display:block; margin-bottom:4px; color:${isOk ? '#2b9348' : '#d00000'};">${isOk ? '[OK]' : '[FAIL]'} ${title}</strong><span style="color:#5c6b73; font-size:13px;">${msg}</span>`;
        return div;
    };

    const infoCard = (title, msg) => {
        const div = document.createElement('div');
        div.style.cssText = 'padding:12px 16px; border-left:4px solid #5c6b73; background:white; box-shadow:0 1px 3px rgba(0,0,0,.08); border-radius:4px;';
        div.innerHTML = `<strong style="font-size:14px; display:block; margin-bottom:4px; color:#253237;">[INFO] ${title}</strong><span style="color:#5c6b73; font-size:13px;">${msg}</span>`;
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

    workspaceEl.appendChild(grid);

    // --- Toggle ---
    const toggleSection = document.createElement('div');
    toggleSection.style.cssText = 'padding:20px; background:white; border:1px solid #c2dfe3; border-radius:8px; margin-bottom:24px;';

    const toggleRow = document.createElement('div');
    toggleRow.style.cssText = 'display:flex; align-items:center; justify-content:space-between; gap:16px;';

    const labelGroup = document.createElement('div');
    const labelTitle = document.createElement('strong');
    labelTitle.style.cssText = 'display:block; font-size:15px; margin-bottom:4px;';
    labelTitle.textContent = 'Record Snapshots';
    const labelDesc = document.createElement('span');
    labelDesc.style.cssText = 'color:#9db4c0; font-size:13px;';
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
        background:${enabled ? '#5c6b73' : '#c2dfe3'};
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
            const res = await fetch('api.php?action=set_snapshot_setting', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                body: JSON.stringify({ enabled: newVal }),
            });
            const result = await res.json();
            if (result.status === 'success') {
                enabled = newVal;
                switchSlider.style.background = newVal ? '#5c6b73' : '#c2dfe3';
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
    toggleSection.appendChild(toggleRow);
    toggleSection.appendChild(pillAnchor);
    workspaceEl.appendChild(toggleSection);

    // --- Schema info ---
    const schemaSection = document.createElement('div');
    schemaSection.style.cssText = 'padding:16px 20px; background:#c2dfe3; border:1px solid #c2dfe3; border-radius:8px;';
    schemaSection.innerHTML = `
        <h4 style="margin:0 0 10px; font-size:13px; text-transform:uppercase; letter-spacing:.06em; color:#9db4c0;">Table: spw_record_snapshots</h4>
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
                <tr style="text-align:left; color:#9db4c0; border-bottom:1px solid #c2dfe3;">
                    <th style="padding:4px 8px 6px;">Column</th>
                    <th style="padding:4px 8px 6px;">Type</th>
                    <th style="padding:4px 8px 6px;">Description</th>
                </tr>
            </thead>
            <tbody style="color:#5c6b73;">
                <tr><td style="padding:4px 8px;">id</td><td style="padding:4px 8px;">serial</td><td style="padding:4px 8px;">Primary key</td></tr>
                <tr style="background:#c2dfe3;"><td style="padding:4px 8px;">log_id</td><td style="padding:4px 8px;">int4</td><td style="padding:4px 8px;">FK to spw_users_log.id (CASCADE DELETE)</td></tr>
                <tr><td style="padding:4px 8px;">table_name</td><td style="padding:4px 8px;">varchar(100)</td><td style="padding:4px 8px;">Name of the affected table</td></tr>
                <tr><td style="padding:4px 8px;">record_id</td><td style="padding:4px 8px;">int4</td><td style="padding:4px 8px;">PK of the affected record</td></tr>
                <tr style="background:#c2dfe3;"><td style="padding:4px 8px;">snapshot</td><td style="padding:4px 8px;">jsonb</td><td style="padding:4px 8px;">Full record as JSON (row_to_json)</td></tr>
                <tr><td style="padding:4px 8px;">created_at</td><td style="padding:4px 8px;">timestamp</td><td style="padding:4px 8px;">When the snapshot was saved</td></tr>
            </tbody>
        </table>
    `;
    workspaceEl.appendChild(schemaSection);
}
