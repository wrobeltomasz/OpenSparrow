// admin/cron.js — Cron Notifications management page

function cronEscHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
}

function getCronCsrf() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
}

function cronBadge(text, bg, fg) {
    const b = document.createElement('span');
    b.textContent = text;
    b.style.cssText = `background:${bg}; color:${fg}; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap;`;
    return b;
}

function statusBadge(status) {
    const map = {
        success: ['#dcfce7', '#166534'],
        error:   ['#fee2e2', '#991b1b'],
        running: ['#fef3c7', '#92400e'],
    };
    const [bg, fg] = map[status] || ['#e2e8f0', '#0f172a'];
    return cronBadge(status.toUpperCase(), bg, fg);
}

function cronMkTable() {
    const t = document.createElement('table');
    t.style.cssText = 'width:100%; border-collapse:collapse; font-size:13px;';
    return t;
}

function cronMkThead(table, cols) {
    const thead = table.createTHead();
    const tr = thead.insertRow();
    cols.forEach(h => {
        const th = document.createElement('th');
        th.textContent = h;
        th.style.cssText = 'text-align:left; padding:8px 12px; background:#f8fafc; border-bottom:1px solid var(--border); color:var(--muted); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px;';
        tr.appendChild(th);
    });
}

function cronTd(text, extra = '') {
    const el = document.createElement('td');
    el.style.cssText = 'padding:8px 12px; border-bottom:1px solid #f1f5f9;' + extra;
    el.textContent = text ?? '—';
    return el;
}

function cronTdEl(child, extra = '') {
    const el = document.createElement('td');
    el.style.cssText = 'padding:8px 12px; border-bottom:1px solid #f1f5f9;' + extra;
    if (child) el.appendChild(child);
    return el;
}

// ─── Section builder ─────────────────────────────────────────────────────────

function cronMakeSection(id, title, description) {
    const card = document.createElement('div');
    card.id = id;
    card.style.cssText = 'border:1px solid var(--border); border-radius:8px; overflow:hidden; margin-bottom:20px;';

    const hdr = document.createElement('div');
    hdr.style.cssText = 'padding:14px 18px; background:var(--bg); border-bottom:1px solid var(--border);';

    const h3 = document.createElement('h3');
    h3.textContent = title;
    h3.style.cssText = 'margin:0 0 4px; font-size:15px;';
    const desc = document.createElement('p');
    desc.textContent = description;
    desc.style.cssText = 'margin:0; color:var(--muted); font-size:13px;';

    hdr.append(h3, desc);
    card.appendChild(hdr);

    const body = document.createElement('div');
    body.style.cssText = 'padding:18px;';
    card.appendChild(body);

    return { card, body };
}

// ─── Section 1: Manual Run ────────────────────────────────────────────────────

function buildManualRunSection() {
    const { card, body } = cronMakeSection('cron-section-0', 'Manual Run', 'Trigger the notification cron immediately outside the scheduler.');

    const runBtn = document.createElement('button');
    runBtn.className = 'btn-action';
    runBtn.style.cssText = 'padding:8px 20px; font-size:14px;';
    runBtn.textContent = 'Run Cron Now';

    const output = document.createElement('pre');
    output.style.cssText = 'margin-top:14px; padding:12px; background:#f8fafc; border:1px solid var(--border); border-radius:4px; font-size:12px; line-height:1.6; max-height:300px; overflow-y:auto; white-space:pre-wrap; display:none;';

    runBtn.addEventListener('click', async () => {
        runBtn.disabled = true;
        runBtn.textContent = 'Running…';
        output.style.display = '';
        output.textContent = 'Please wait…';
        output.style.color = '';

        try {
            const res = await fetch('api.php?action=run_cron_notifications', {
                method: 'POST',
                headers: { 'X-CSRF-Token': getCronCsrf() }
            });
            const data = await res.json();
            if (data.status === 'success') {
                output.textContent = data.output || '(no output)';
            } else {
                output.textContent = 'Error: ' + (data.error || 'unknown');
                output.style.color = '#991b1b';
            }
        } catch (e) {
            output.textContent = 'Request failed: ' + e.message;
            output.style.color = '#991b1b';
        }

        runBtn.disabled = false;
        runBtn.textContent = 'Run Cron Now';
    });

    body.append(runBtn, output);
    return card;
}

// ─── Section 2: Run History ───────────────────────────────────────────────────

function buildRunHistorySection() {
    const { card, body } = cronMakeSection('cron-section-1', 'Run History', 'Last 50 cron executions from spw_users_notifications_log.');

    const loadBtn = document.createElement('button');
    loadBtn.className = 'btn-action';
    loadBtn.textContent = 'Load History';

    const container = document.createElement('div');
    container.style.marginTop = '14px';

    loadBtn.addEventListener('click', async () => {
        loadBtn.disabled = true;
        loadBtn.textContent = 'Loading…';
        container.textContent = '';

        try {
            const res = await fetch('api.php?action=cron_log');
            const data = await res.json();

            if (data.status !== 'success') {
                container.textContent = 'Error: ' + (data.error || 'unknown');
                return;
            }

            if (!data.rows || data.rows.length === 0) {
                container.textContent = 'No runs recorded yet.';
                return;
            }

            const t = cronMkTable();
            cronMkThead(t, ['#', 'Status', 'Triggered By', 'Started At', 'Duration', 'Sources', 'Notifications', 'Error']);

            const tbody = t.createTBody();
            data.rows.forEach(r => {
                const tr = tbody.insertRow();
                tr.appendChild(cronTd(r.id));
                tr.appendChild(cronTdEl(statusBadge(r.status)));
                tr.appendChild(cronTd(r.triggered_by));
                tr.appendChild(cronTd(r.started_at ? r.started_at.replace('T', ' ').substring(0, 19) : ''));
                const dur = r.duration_sec !== null ? Number(r.duration_sec).toFixed(2) + 's' : '—';
                tr.appendChild(cronTd(dur));
                tr.appendChild(cronTd(r.sources_processed));
                tr.appendChild(cronTd(r.notifications_created));
                tr.appendChild(cronTd(r.error_message, 'color:#991b1b; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;'));
            });

            container.innerHTML = '';
            container.appendChild(t);
        } catch (e) {
            container.textContent = 'Request failed: ' + e.message;
        }

        loadBtn.disabled = false;
        loadBtn.textContent = 'Refresh';
    });

    body.append(loadBtn, container);
    return card;
}

// ─── Section 3: Notification Stats ───────────────────────────────────────────

function buildStatsSection() {
    const { card, body } = cronMakeSection('cron-section-2', 'Notification Stats', 'Current totals from spw_users_notifications, top unread per user.');

    const loadBtn = document.createElement('button');
    loadBtn.className = 'btn-action';
    loadBtn.textContent = 'Load Stats';

    const container = document.createElement('div');
    container.style.marginTop = '14px';

    loadBtn.addEventListener('click', async () => {
        loadBtn.disabled = true;
        loadBtn.textContent = 'Loading…';
        container.textContent = '';

        try {
            const res = await fetch('api.php?action=cron_stats');
            const data = await res.json();

            if (data.status !== 'success') {
                container.textContent = 'Error: ' + (data.error || 'unknown');
                return;
            }

            const t = data.totals || {};
            const lastRun = data.last_run;

            const kpiGrid = document.createElement('div');
            kpiGrid.style.cssText = 'display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:18px;';

            const kpis = [
                ['Total Notifications', t.total ?? '—', '#3b82f6'],
                ['Unread',              t.unread ?? '—', '#ef4444'],
                ['Due Today (unread)',  t.due_today ?? '—', '#f59e0b'],
                ['Upcoming Unread',     t.upcoming_unread ?? '—', '#8b5cf6'],
            ];
            kpis.forEach(([label, val, color]) => {
                const kpi = document.createElement('div');
                kpi.style.cssText = `padding:14px 16px; border-left:4px solid ${color}; background:#fff; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,.07);`;
                const num = document.createElement('div');
                num.textContent = val;
                num.style.cssText = `font-size:26px; font-weight:700; color:${color};`;
                const lbl = document.createElement('div');
                lbl.textContent = label;
                lbl.style.cssText = 'font-size:12px; color:var(--muted); margin-top:2px;';
                kpi.append(num, lbl);
                kpiGrid.appendChild(kpi);
            });
            container.appendChild(kpiGrid);

            if (lastRun) {
                const lastRunEl = document.createElement('p');
                lastRunEl.style.cssText = 'font-size:13px; color:var(--muted); margin-bottom:14px;';
                const badge = statusBadge(lastRun.status);
                badge.style.marginLeft = '6px';
                lastRunEl.textContent = 'Last run: ' + (lastRun.started_at || '').substring(0, 19).replace('T', ' ') + ' ';
                lastRunEl.appendChild(badge);
                container.appendChild(lastRunEl);
            }

            if (data.per_user && data.per_user.length > 0) {
                const h4 = document.createElement('h4');
                h4.textContent = 'Top Unread per User';
                h4.style.cssText = 'margin:0 0 10px; font-size:13px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted);';
                container.appendChild(h4);

                const tbl = cronMkTable();
                cronMkThead(tbl, ['Username', 'Email', 'Unread Count']);
                const tbody = tbl.createTBody();
                data.per_user.forEach(r => {
                    const tr = tbody.insertRow();
                    tr.appendChild(cronTd(r.username));
                    tr.appendChild(cronTd(r.email));
                    tr.appendChild(cronTd(r.unread_count));
                });
                container.appendChild(tbl);
            } else {
                const p = document.createElement('p');
                p.textContent = 'No unread notifications found.';
                p.style.color = 'var(--muted)';
                container.appendChild(p);
            }
        } catch (e) {
            container.textContent = 'Request failed: ' + e.message;
        }

        loadBtn.disabled = false;
        loadBtn.textContent = 'Refresh';
    });

    body.append(loadBtn, container);
    return card;
}

// ─── Section 4: Cron Setup Guide ─────────────────────────────────────────────

function buildSetupSection() {
    const { card, body } = cronMakeSection('cron-section-3', 'Cron Setup', 'How to schedule automatic notification dispatch on your server.');

    const cronPath = window.location.origin + '/cron/cron_notifications.php';

    const content = document.createElement('div');
    content.style.cssText = 'display:grid; gap:16px;';

    function guideBlock(heading, code, note) {
        const wrap = document.createElement('div');
        wrap.style.cssText = 'background:#f8fafc; border:1px solid var(--border); border-radius:6px; padding:14px;';

        const h = document.createElement('strong');
        h.textContent = heading;
        h.style.cssText = 'display:block; margin-bottom:8px; font-size:13px;';

        const pre = document.createElement('pre');
        pre.style.cssText = 'margin:0 0 6px; font-size:12px; background:#1e293b; color:#e2e8f0; padding:10px 12px; border-radius:4px; overflow-x:auto; white-space:pre-wrap;';
        pre.textContent = code;

        wrap.append(h, pre);

        if (note) {
            const p = document.createElement('p');
            p.textContent = note;
            p.style.cssText = 'margin:6px 0 0; font-size:12px; color:var(--muted);';
            wrap.appendChild(p);
        }

        return wrap;
    }

    content.appendChild(guideBlock(
        'Linux / macOS — crontab (every 15 minutes)',
        `*/15 * * * * php ${cronPath}`,
        'Run: crontab -e  then paste the line above.'
    ));

    content.appendChild(guideBlock(
        'Linux / macOS — crontab (every hour)',
        `0 * * * * php ${cronPath}`,
        null
    ));

    content.appendChild(guideBlock(
        'Windows — Task Scheduler (every 15 min)',
        `schtasks /create /tn "OpenSparrow Cron" /tr "php ${cronPath}" /sc minute /mo 15`,
        'Run as the same user Apache/PHP runs under.'
    ));

    content.appendChild(guideBlock(
        'Docker — add to docker-compose.yml',
        `services:\n  cron:\n    image: php:8.1-cli\n    volumes:\n      - .:/var/www/html\n    command: sh -c "while true; do php /var/www/html/cron/cron_notifications.php; sleep 900; done"`,
        'Adjust sleep interval (seconds) as needed.'
    ));

    const note = document.createElement('p');
    note.style.cssText = 'font-size:13px; color:var(--muted); margin-top:4px;';
    note.textContent = 'The script logs each run to spw_users_notifications_log. Use Manual Run (above) to test immediately.';

    body.append(content, note);
    return card;
}

// ─── Section 5: Log Cleanup ───────────────────────────────────────────────────

function buildCleanupSection() {
    const { card, body } = cronMakeSection('cron-section-4', 'Log Cleanup', 'Delete old cron run entries from spw_users_notifications_log.');

    const row = document.createElement('div');
    row.style.cssText = 'display:flex; align-items:center; gap:12px; flex-wrap:wrap;';

    const label = document.createElement('label');
    label.style.cssText = 'font-size:13px; color:var(--muted);';
    label.textContent = 'Delete runs older than';

    const input = document.createElement('input');
    input.type = 'number';
    input.value = '30';
    input.min = '1';
    input.max = '3650';
    input.style.cssText = 'width:80px; padding:6px 8px; border:1px solid var(--border); border-radius:4px; font-size:13px;';

    const unit = document.createElement('span');
    unit.textContent = 'days';
    unit.style.cssText = 'font-size:13px; color:var(--muted);';

    const btn = document.createElement('button');
    btn.className = 'btn-remove';
    btn.style.cssText = 'padding:6px 16px; font-size:13px; float:none;';
    btn.textContent = 'Purge Old Logs';

    const result = document.createElement('p');
    result.style.cssText = 'margin-top:12px; font-size:13px; display:none;';

    btn.addEventListener('click', async () => {
        const days = parseInt(input.value, 10);
        if (!days || days < 1) {
            result.textContent = 'Enter a valid number of days.';
            result.style.color = '#991b1b';
            result.style.display = '';
            return;
        }

        if (!confirm(`Delete all cron log entries older than ${days} day(s)? This cannot be undone.`)) return;

        btn.disabled = true;
        btn.textContent = 'Purging…';
        result.style.display = 'none';

        try {
            const res = await fetch('api.php?action=cron_purge_log', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCronCsrf()
                },
                body: JSON.stringify({ days })
            });
            const data = await res.json();
            if (data.status === 'success') {
                result.textContent = `Deleted ${data.deleted} log row(s).`;
                result.style.color = '#166534';
            } else {
                result.textContent = 'Error: ' + (data.error || 'unknown');
                result.style.color = '#991b1b';
            }
        } catch (e) {
            result.textContent = 'Request failed: ' + e.message;
            result.style.color = '#991b1b';
        }

        result.style.display = '';
        btn.disabled = false;
        btn.textContent = 'Purge Old Logs';
    });

    row.append(label, input, unit, btn);
    body.append(row, result);
    return card;
}

// ─── Main render ──────────────────────────────────────────────────────────────

export function renderCronPage(ctx) {
    const { workspaceEl } = ctx;

    const wrap = document.createElement('div');

    const hdr = document.createElement('div');
    hdr.style.cssText = 'display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; flex-wrap:wrap; gap:10px;';

    const titleEl = document.createElement('div');
    const h2 = document.createElement('h3');
    h2.textContent = 'Notification Cron';
    h2.style.margin = '0 0 4px';
    const sub = document.createElement('p');
    sub.textContent = 'Schedule, monitor, and manage the notification dispatch cron job.';
    sub.style.cssText = 'margin:0; color:var(--muted); font-size:14px;';
    titleEl.append(h2, sub);

    hdr.appendChild(titleEl);
    wrap.appendChild(hdr);

    wrap.appendChild(buildManualRunSection());
    wrap.appendChild(buildRunHistorySection());
    wrap.appendChild(buildStatsSection());
    wrap.appendChild(buildSetupSection());
    wrap.appendChild(buildCleanupSection());

    workspaceEl.innerHTML = '';
    workspaceEl.appendChild(wrap);
}
