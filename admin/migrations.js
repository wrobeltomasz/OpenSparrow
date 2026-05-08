export async function renderMigrationsPage(ctx) {
    const { workspaceEl } = ctx;

    workspaceEl.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.style.cssText = 'padding:24px; max-width:860px;';

    const heading = document.createElement('h2');
    heading.style.cssText = 'margin:0 0 6px; font-size:20px; color:#0f172a;';
    heading.textContent = 'Database Migrations';

    const sub = document.createElement('p');
    sub.style.cssText = 'margin:0 0 24px; font-size:13px; color:#64748b;';
    sub.textContent = 'Each migration runs once and is recorded in spw_migrations. Running "Apply Migrations" is safe to repeat.';

    const runBtn = document.createElement('button');
    runBtn.id = 'mig-run-btn';
    runBtn.style.cssText = 'background:#3b82f6; color:#fff; border:none; padding:9px 20px; border-radius:4px; font-weight:600; font-size:13px; cursor:pointer; margin-bottom:24px;';
    runBtn.textContent = 'Apply Pending Migrations';

    const statusEl = document.createElement('p');
    statusEl.id = 'mig-status';
    statusEl.style.cssText = 'font-size:13px; margin:0 0 20px; min-height:18px;';

    const tableWrap = document.createElement('div');
    tableWrap.id = 'mig-table';
    tableWrap.innerHTML = '<p style="color:#94a3b8; font-size:13px;">Loading…</p>';

    wrap.append(heading, sub, runBtn, statusEl, tableWrap);
    workspaceEl.appendChild(wrap);

    await loadMigrations(tableWrap);

    runBtn.addEventListener('click', async () => {
        if (!confirm('Apply all pending migrations now?')) return;

        runBtn.disabled   = true;
        runBtn.textContent = 'Applying…';
        statusEl.style.color = '#64748b';
        statusEl.textContent = '';

        try {
            const csrf   = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const res    = await fetch('api.php?action=init_db', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            });
            const data = await res.json();

            if (data.status === 'success') {
                statusEl.style.color = '#10b981';
                statusEl.textContent = '✓ ' + data.message;
                await loadMigrations(tableWrap);
            } else {
                statusEl.style.color = '#ef4444';
                statusEl.textContent = '✗ ' + (data.error || 'Unknown error.');
            }
        } catch {
            statusEl.style.color = '#ef4444';
            statusEl.textContent = '✗ Network error.';
        } finally {
            runBtn.disabled   = false;
            runBtn.textContent = 'Apply Pending Migrations';
        }
    });
}

async function loadMigrations(container) {
    container.innerHTML = '<p style="color:#94a3b8; font-size:13px;">Loading…</p>';

    let data;
    try {
        const res = await fetch('api.php?action=migrations_list');
        data = await res.json();
    } catch {
        container.innerHTML = '<p style="color:#ef4444; font-size:13px;">Failed to load migrations.</p>';
        return;
    }

    if (data.status !== 'success') {
        container.innerHTML = `<p style="color:#ef4444; font-size:13px;">Error: ${data.error}</p>`;
        return;
    }

    const migrations = data.migrations;
    const pending    = migrations.filter(m => m.status === 'pending');
    const applied    = migrations.filter(m => m.status === 'applied');

    const table = document.createElement('table');
    table.style.cssText = 'width:100%; border-collapse:collapse; font-size:13px;';

    const thead = document.createElement('thead');
    thead.innerHTML = `
        <tr style="border-bottom:2px solid #e2e8f0; background:#f8fafc; text-align:left;">
            <th style="padding:10px 12px; color:#475569;">Migration</th>
            <th style="padding:10px 12px; color:#475569;">Status</th>
            <th style="padding:10px 12px; color:#475569;">Applied at</th>
        </tr>`;
    table.appendChild(thead);

    const tbody = document.createElement('tbody');

    migrations.forEach(m => {
        const tr = document.createElement('tr');
        tr.style.cssText = 'border-bottom:1px solid #f1f5f9;';

        const isPending = m.status === 'pending';
        const badge     = isPending
            ? '<span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600;">PENDING</span>'
            : '<span style="background:#dcfce7; color:#166534; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600;">APPLIED</span>';

        const appliedAt = m.applied_at
            ? new Date(m.applied_at).toLocaleString()
            : '—';

        tr.innerHTML = `
            <td style="padding:10px 12px; font-family:monospace; color:#0f172a;">${m.name}</td>
            <td style="padding:10px 12px;">${badge}</td>
            <td style="padding:10px 12px; color:#64748b;">${appliedAt}</td>`;
        tbody.appendChild(tr);
    });

    table.appendChild(tbody);

    const summary = document.createElement('p');
    summary.style.cssText = 'font-size:12px; color:#94a3b8; margin-top:12px;';
    summary.textContent = `Total: ${migrations.length} | Applied: ${applied.length} | Pending: ${pending.length}`;

    container.innerHTML = '';
    container.append(table, summary);
}
