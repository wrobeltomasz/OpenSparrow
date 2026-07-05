// admin/js/migrations.js — Migrations page (renderMigrationsPage): scan for schema drift and apply pending migrations via admin/api_migrations.php (scan/apply); inner tabs.
import { buildInnerTabs } from './ui.js';

export async function renderMigrationsPage(ctx) {
    const { workspaceEl } = ctx;

    workspaceEl.innerHTML = '';

    const outer = document.createElement('div');
    outer.className = 'admin-page';
    workspaceEl.appendChild(outer);

    const [panel0, panel1] = buildInnerTabs(outer, [
        { label: 'Database Migrations' },
        { label: 'Release Migrations' },
    ]);

    // --- Tab 0: DB migrations ---
    const sub = document.createElement('p');
    sub.style.cssText = 'margin:0 0 20px; font-size:13px; color:var(--muted);';
    sub.textContent = 'Each migration runs once and is recorded in spw_migrations. Running "Apply Migrations" is safe to repeat.';

    const runBtn = document.createElement('button');
    runBtn.id = 'mig-run-btn';
    runBtn.className = 'btn btn-primary';
    runBtn.style.marginBottom = '24px';
    runBtn.textContent = 'Apply Pending Migrations';

    const statusEl = document.createElement('p');
    statusEl.id = 'mig-status';
    statusEl.style.cssText = 'font-size:13px; margin:0 0 20px; min-height:18px;';

    const tableWrap = document.createElement('div');
    tableWrap.id = 'mig-table';
    tableWrap.innerHTML = '<p style="color:var(--muted); font-size:13px;">Loading…</p>';

    panel0.append(sub, runBtn, statusEl, tableWrap);

    // --- Tab 1: Release migrations ---
    const relSub = document.createElement('p');
    relSub.style.cssText = 'margin:0 0 20px; font-size:13px; color:var(--muted);';
    relSub.textContent = 'File and config cleanup tasks defined in config/migrations.json. Run after upgrading to a new version.';

    const relContainer = document.createElement('div');
    relContainer.id = 'mig-release-container';
    relContainer.innerHTML = '<p style="color:var(--muted); font-size:13px;">Loading…</p>';

    panel1.append(relSub, relContainer);

    // Append ALL DOM to workspace synchronously before any await
    // (outer already appended above)

    // Event listener before async work
    runBtn.addEventListener('click', async () => {
        if (!confirm('Apply all pending migrations now?')) return;

        runBtn.disabled    = true;
        runBtn.textContent = 'Applying…';
        statusEl.style.color = 'var(--muted)';
        statusEl.textContent = '';

        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const res  = await fetch('api.php?action=init_db', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            });
            const data = await res.json();

            if (data.status === 'success') {
                statusEl.style.color = 'var(--ok)';
                statusEl.textContent = '✓ ' + data.message;
                await loadMigrations(tableWrap);
            } else {
                statusEl.style.color = 'var(--danger)';
                statusEl.textContent = '✗ ' + (data.error || 'Unknown error.');
            }
        } catch {
            statusEl.style.color = 'var(--danger)';
            statusEl.textContent = '✗ Network error.';
        } finally {
            runBtn.disabled    = false;
            runBtn.textContent = 'Apply Pending Migrations';
        }
    });

    // Async data loading fills pre-created containers
    await loadMigrations(tableWrap);
    loadReleaseMigrations(relContainer);
}

async function loadMigrations(container) {
    container.innerHTML = '<p style="color:var(--muted); font-size:13px;">Loading…</p>';

    let data;
    try {
        const res = await fetch('api.php?action=migrations_list');
        data = await res.json();
    } catch {
        container.innerHTML = '<p style="color:var(--danger); font-size:13px;">Failed to load migrations.</p>';
        return;
    }

    if (data.status !== 'success') {
        container.innerHTML = `<p style="color:var(--danger); font-size:13px;">Error: ${escRelMig(data.error)}</p>`;
        return;
    }

    const migrations = data.migrations;
    const pending    = migrations.filter(m => m.status === 'pending');
    const applied    = migrations.filter(m => m.status === 'applied');

    const table = document.createElement('table');
    table.style.cssText = 'width:100%; border-collapse:collapse; font-size:13px;';

    const thead = document.createElement('thead');
    thead.innerHTML = `
        <tr style="border-bottom:2px solid var(--accent-mid); background:var(--bg); text-align:left;">
            <th style="padding:10px 12px; color:var(--muted);">Migration</th>
            <th style="padding:10px 12px; color:var(--muted);">Status</th>
            <th style="padding:10px 12px; color:var(--muted);">Applied at</th>
        </tr>`;
    table.appendChild(thead);

    const tbody = document.createElement('tbody');

    migrations.forEach(m => {
        const tr = document.createElement('tr');
        tr.style.cssText = 'border-bottom:1px solid var(--border);';

        const isPending = m.status === 'pending';
        const badge = isPending
            ? '<span class="adm-badge adm-badge-warn">PENDING</span>'
            : '<span class="adm-badge adm-badge-ok">APPLIED</span>';

        const appliedAt = m.applied_at
            ? new Date(m.applied_at).toLocaleString()
            : '—';

        tr.innerHTML = `
            <td style="padding:10px 12px; font-family:monospace; color:var(--text);">${m.name}</td>
            <td style="padding:10px 12px;">${badge}</td>
            <td style="padding:10px 12px; color:var(--muted);">${appliedAt}</td>`;
        tbody.appendChild(tr);
    });

    table.appendChild(tbody);

    const summary = document.createElement('p');
    summary.style.cssText = 'font-size:12px; color:var(--muted); margin-top:12px;';
    summary.textContent = `Total: ${migrations.length} | Applied: ${applied.length} | Pending: ${pending.length}`;

    container.innerHTML = '';
    container.append(table, summary);
}

async function loadReleaseMigrations(container) {
    container.innerHTML = '<p style="color:var(--muted); font-size:13px;">Loading…</p>';

    let data;
    try {
        const res = await fetch('api_migrations.php?action=scan');
        data = await res.json();
    } catch {
        container.innerHTML = '<p style="color:var(--danger); font-size:13px;">Failed to load release migrations.</p>';
        return;
    }

    if (data.status !== 'success') {
        container.innerHTML = `<p style="color:var(--danger); font-size:13px;">Error: ${escRelMig(data.error || 'Unknown')}</p>`;
        return;
    }

    container.innerHTML = '';

    const versions = data.versions || [];
    if (versions.length === 0) {
        container.innerHTML = '<p style="color:var(--muted); font-size:13px;">No release migrations defined in config/migrations.json.</p>';
        return;
    }

    versions.forEach(v => renderVersionCard(v, container));
}

function renderVersionCard(v, container) {
    const isPending  = v.status === 'pending';
    const hasActions = v.actions.some(a => a.type !== 'file_deprecated');

    const card = document.createElement('div');
    card.style.cssText = `border:1px solid ${isPending ? 'var(--warn)' : 'var(--accent-mid)'}; border-radius:6px; padding:16px 20px; margin-bottom:16px; background:${isPending ? 'rgba(255,195,0,0.08)' : 'var(--accent-mid)'};`;

    const headerRow = document.createElement('div');
    headerRow.style.cssText = 'display:flex; align-items:center; gap:12px; margin-bottom:8px;';

    const verSpan = document.createElement('span');
    verSpan.style.cssText = 'font-family:monospace; font-size:15px; font-weight:700; color:var(--text);';
    verSpan.textContent = 'v' + v.version;

    const badge = document.createElement('span');
    badge.className = `adm-badge ${isPending ? 'adm-badge-warn' : 'adm-badge-ok'}`;
    badge.textContent = isPending ? 'PENDING' : 'APPLIED';

    headerRow.append(verSpan, badge);
    card.appendChild(headerRow);

    if (v.notes) {
        const notes = document.createElement('p');
        notes.style.cssText = 'font-size:13px; color:var(--muted); margin:0 0 12px;';
        notes.textContent = v.notes;
        card.appendChild(notes);
    }

    const checkboxes = [];

    if (isPending && v.actions.length > 0) {
        const actionsLabel = document.createElement('p');
        actionsLabel.style.cssText = 'font-size:12px; font-weight:600; color:var(--muted); margin:0 0 8px; text-transform:uppercase; letter-spacing:.5px;';
        actionsLabel.textContent = 'Actions';
        card.appendChild(actionsLabel);

        v.actions.forEach((a, idx) => {
            const row = document.createElement('label');
            row.style.cssText = 'display:flex; align-items:center; gap:8px; font-size:13px; color:var(--muted); margin-bottom:6px; cursor:pointer;';

            const cb = document.createElement('input');
            cb.type = 'checkbox';
            if (a.type !== 'file_deprecated') {
                cb.checked = true;
                cb.dataset.idx = idx;
                checkboxes.push(cb);
            } else {
                cb.disabled = true;
                cb.title = 'Informational only — no action taken';
            }

            const lbl = document.createElement('span');
            const typeTag = a.type === 'file_deprecated'
                ? '<span style="color:var(--muted); font-size:11px;">[info]</span> '
                : '';
            const existTag = (a.type === 'file_remove' && !a.exists)
                ? ' <span style="color:var(--muted); font-size:11px;">(file not found — will skip)</span>'
                : (a.type === 'config_key_remove' && !a.present)
                    ? ' <span style="color:var(--muted); font-size:11px;">(key not found — will skip)</span>'
                    : '';
            lbl.innerHTML = typeTag + escRelMig(a.label) + existTag;

            row.append(cb, lbl);
            card.appendChild(row);
        });
    } else if (isPending && v.actions.length === 0) {
        const none = document.createElement('p');
        none.style.cssText = 'font-size:13px; color:var(--muted); margin:0 0 12px;';
        none.textContent = 'No file or config changes required for this release.';
        card.appendChild(none);
    }

    if (!isPending && v.applied_data) {
        const ad = v.applied_data;
        const hist = document.createElement('p');
        hist.style.cssText = 'font-size:12px; color:var(--muted); margin:4px 0 0;';
        hist.textContent = 'Applied: ' + new Date(ad.applied_at).toLocaleString();
        card.appendChild(hist);

        if (ad.actions && ad.actions.length > 0) {
            const actList = document.createElement('ul');
            actList.style.cssText = 'margin:8px 0 0; padding-left:18px; font-size:12px; color:var(--muted);';
            ad.actions.forEach(a => {
                const li = document.createElement('li');
                if (a.status === 'done' && a.backup) {
                    li.innerHTML = escRelMig(a.type + ': ' + (a.path || a.file)) +
                        ' <span style="color:var(--muted);">— backup: ' + escRelMig(a.backup) + '</span>';
                } else {
                    li.textContent = a.type + ': ' + (a.path || a.file || '') + ' [' + a.status + ']';
                }
                actList.appendChild(li);
            });
            card.appendChild(actList);
        }
    }

    if (isPending) {
        const btnRow = document.createElement('div');
        btnRow.style.cssText = 'margin-top:14px;';

        const applyBtn = document.createElement('button');
        applyBtn.className = 'btn btn-primary btn-sm';
        applyBtn.textContent = hasActions ? 'Apply selected' : 'Mark as applied';

        const statusMsg = document.createElement('span');
        statusMsg.style.cssText = 'margin-left:12px; font-size:13px;';

        applyBtn.addEventListener('click', async () => {
            const selected = checkboxes.filter(cb => cb.checked).map(cb => parseInt(cb.dataset.idx, 10));

            if (!confirm('Apply release migration v' + v.version + '? This will run the selected actions and cannot be undone.')) return;

            applyBtn.disabled    = true;
            applyBtn.textContent = 'Applying…';
            statusMsg.style.color = 'var(--muted)';
            statusMsg.textContent = '';

            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const res  = await fetch('api_migrations.php?action=apply', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ version: v.version, selected }),
                });
                const data = await res.json();

                if (data.status === 'success') {
                    statusMsg.style.color = 'var(--ok)';
                    statusMsg.textContent = '✓ Applied.';
                    const relContainer = document.getElementById('mig-release-container');
                    if (relContainer) loadReleaseMigrations(relContainer);
                    const banner = document.getElementById('mig-pending-banner');
                    if (banner) banner.style.display = 'none';
                } else {
                    statusMsg.style.color = 'var(--danger)';
                    statusMsg.textContent = '✗ ' + (data.error || 'Unknown error.');
                    applyBtn.disabled    = false;
                    applyBtn.textContent = hasActions ? 'Apply selected' : 'Mark as applied';
                }
            } catch {
                statusMsg.style.color = 'var(--danger)';
                statusMsg.textContent = '✗ Network error.';
                applyBtn.disabled    = false;
                applyBtn.textContent = hasActions ? 'Apply selected' : 'Mark as applied';
            }
        });

        btnRow.append(applyBtn, statusMsg);
        card.appendChild(btnRow);
    }

    container.appendChild(card);
}

function escRelMig(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
