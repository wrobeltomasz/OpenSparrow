// admin/js/cron.js — Cron notifications management page
// Shows run log/stats and lets the admin trigger or purge cron via api.php (cron_log, cron_stats, cron_purge_log, run_cron_notifications). CSRF via apiFetch().
import { apiFetch } from '../../assets/js/util/api.js';
import { buildInnerTabs, createPageHeader } from './ui.js';

import { escHtml as cronEscHtml } from '../../assets/js/util/esc.js';

function statusBadge(status) {
    const cls = { success: 'ok', error: 'danger', running: 'warn' }[status] ?? 'muted';
    const b = document.createElement('span');
    b.className = `adm-badge adm-badge-${cls}`;
    b.textContent = status.toUpperCase();
    return b;
}

function cronMkTable() {
    const t = document.createElement('table');
    t.className = 'adm-tbl';
    return t;
}

function cronMkThead(table, cols) {
    const thead = table.createTHead();
    const tr = thead.insertRow();
    cols.forEach(h => {
        const th = document.createElement('th');
        th.className = 'adm-th';
        th.textContent = h;
        tr.appendChild(th);
    });
}

function cronTd(text, extra = '') {
    const el = document.createElement('td');
    el.className = 'adm-td';
    if (extra) el.style.cssText = extra.replace(/^[;\s]+/, '');
    el.textContent = text ?? '—';
    return el;
}

function cronTdEl(child, extra = '') {
    const el = document.createElement('td');
    el.className = 'adm-td';
    if (extra) el.style.cssText = extra.replace(/^[;\s]+/, '');
    if (child) el.appendChild(child);
    return el;
}

// ─── Section builder ─────────────────────────────────────────────────────────

function cronMakeSection(id, title, description) {
    const card = document.createElement('div');
    card.id = id;
    card.className = 'adm-sec-card';

    const hdr = document.createElement('div');
    hdr.className = 'adm-sec-hdr';
    hdr.style.display = 'block';

    const h3 = document.createElement('h3');
    h3.textContent = title;
    h3.style.cssText = 'margin:0 0 4px; ';
    const desc = document.createElement('p');
    desc.textContent = description;
    desc.style.cssText = 'margin:0; ';
    desc.className = 'c-muted';

    hdr.append(h3, desc);
    card.appendChild(hdr);

    const body = document.createElement('div');
    body.className = 'adm-sec-body';
    card.appendChild(body);

    return { card, body };
}

// ─── Section 1: Manual Run ────────────────────────────────────────────────────

function buildManualRunSection() {
    const { card, body } = cronMakeSection('cron-section-0', 'Manual Run', 'Trigger the notification cron immediately outside the scheduler.');

    const runBtn = document.createElement('button');
    runBtn.className = 'btn btn-primary';
    runBtn.textContent = 'Run Cron Now';

    const output = document.createElement('pre');
    output.style.cssText = 'margin-top:14px; padding:12px; background:var(--bg); border:1px solid var(--border); border-radius:4px;  line-height:1.6; max-height:300px; overflow-y:auto; white-space:pre-wrap; display:none;';

    runBtn.addEventListener('click', async () => {
        runBtn.disabled = true;
        runBtn.textContent = 'Running…';
        output.style.display = '';
        output.textContent = 'Please wait…';
        output.style.color = '';

        try {
            const res = await apiFetch('api.php?action=run_cron_notifications', {
                method: 'POST',
            });
            const data = await res.json();
            if (data.status === 'success') {
                output.innerHTML = data.output || '(no output)';
            } else {
                output.textContent = 'Error: ' + (data.error || 'unknown');
                output.style.color = '#a80000';
            }
        } catch (e) {
            output.textContent = 'Request failed: ' + e.message;
            output.style.color = '#a80000';
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
    loadBtn.className = 'btn btn-primary';
    loadBtn.textContent = 'Load History';

    const container = document.createElement('div');
    container.style.marginTop = '14px';

    loadBtn.addEventListener('click', async () => {
        loadBtn.disabled = true;
        loadBtn.textContent = 'Loading…';
        container.textContent = '';

        try {
            const res = await apiFetch('api.php?action=cron_log');
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
                tr.appendChild(cronTd(r.error_message, 'color:#a80000; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;'));
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
    loadBtn.className = 'btn btn-primary';
    loadBtn.textContent = 'Load Stats';

    const container = document.createElement('div');
    container.style.marginTop = '14px';

    loadBtn.addEventListener('click', async () => {
        loadBtn.disabled = true;
        loadBtn.textContent = 'Loading…';
        container.textContent = '';

        try {
            const res = await apiFetch('api.php?action=cron_stats');
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
                ['Total Notifications', t.total ?? '—', 'var(--muted)'],
                ['Unread',              t.unread ?? '—', 'var(--danger)'],
                ['Due Today (unread)',  t.due_today ?? '—', 'var(--warn)'],
                ['Upcoming Unread',     t.upcoming_unread ?? '—', 'var(--muted)'],
            ];
            kpis.forEach(([label, val, color]) => {
                const kpi = document.createElement('div');
                kpi.style.cssText = `padding:14px 16px; border-left:4px solid ${color}; background:#fff; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,.07);`;
                const num = document.createElement('div');
                num.textContent = val;
                num.style.cssText = ` font-weight:700; color:${color};`;
                const lbl = document.createElement('div');
                lbl.textContent = label;
                lbl.style.cssText = '  margin-top:2px;';
                kpi.append(num, lbl);
                kpiGrid.appendChild(kpi);
            });
            container.appendChild(kpiGrid);

            if (lastRun) {
                const lastRunEl = document.createElement('p');
                lastRunEl.style.cssText = '  margin-bottom:14px;';
                const badge = statusBadge(lastRun.status);
                badge.style.marginLeft = '6px';
                lastRunEl.textContent = 'Last run: ' + (lastRun.started_at || '').substring(0, 19).replace('T', ' ') + ' ';
                lastRunEl.appendChild(badge);
                container.appendChild(lastRunEl);
            }

            if (data.per_user && data.per_user.length > 0) {
                const h4 = document.createElement('h4');
                h4.textContent = 'Top Unread per User';
                h4.style.cssText = 'margin:0 0 10px;    ';
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
        wrap.style.cssText = 'background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:14px;';

        const h = document.createElement('strong');
        h.textContent = heading;
        h.style.cssText = 'display:block; margin-bottom:8px; ';

        const pre = document.createElement('pre');
        pre.style.cssText = 'margin:0 0 6px;  background:var(--accent-dark); color:var(--accent-light); padding:10px 12px; border-radius:4px; overflow-x:auto; white-space:pre-wrap;';
        pre.textContent = code;

        wrap.append(h, pre);

        if (note) {
            const p = document.createElement('p');
            p.textContent = note;
            p.style.cssText = 'margin:6px 0 0;  ';
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
    note.style.cssText = '  margin-top:4px;';
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
    label.style.cssText = ' ';
    label.textContent = 'Delete runs older than';

    const input = document.createElement('input');
    input.type = 'number';
    input.value = '30';
    input.min = '1';
    input.max = '3650';
    input.className = 'adm-input w-80';

    const unit = document.createElement('span');
    unit.textContent = 'days';
    unit.style.cssText = ' ';

    const btn = document.createElement('button');
    btn.className = 'btn btn-danger';
    btn.textContent = 'Purge Old Logs';

    const result = document.createElement('p');
    result.style.cssText = 'margin-top:12px;  display:none;';

    btn.addEventListener('click', async () => {
        const days = parseInt(input.value, 10);
        if (!days || days < 1) {
            result.textContent = 'Enter a valid number of days.';
            result.style.color = '#a80000';
            result.style.display = '';
            return;
        }

        if (!confirm(`Delete all cron log entries older than ${days} day(s)? This cannot be undone.`)) return;

        btn.disabled = true;
        btn.textContent = 'Purging…';
        result.style.display = 'none';

        try {
            const res = await apiFetch('api.php?action=cron_purge_log', {
                method: 'POST',
                body: JSON.stringify({ days })
            });
            const data = await res.json();
            if (data.status === 'success') {
                result.textContent = `Deleted ${data.deleted} log row(s).`;
                result.style.color = 'var(--ok)';
            } else {
                result.textContent = 'Error: ' + (data.error || 'unknown');
                result.style.color = '#a80000';
            }
        } catch (e) {
            result.textContent = 'Request failed: ' + e.message;
            result.style.color = '#a80000';
        }

        result.style.display = '';
        btn.disabled = false;
        btn.textContent = 'Purge Old Logs';
    });

    row.append(label, input, unit, btn);
    body.append(row, result);
    return card;
}

// ─── Section 6: Email Delivery ───────────────────────────────────────────────

function cronField(labelText, inputEl) {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'margin-bottom:14px;';
    const lbl = document.createElement('label');
    lbl.textContent = labelText;
    lbl.className = 'adm-field-label';
    if (inputEl.id) lbl.htmlFor = inputEl.id;
    wrap.append(lbl, inputEl);
    return wrap;
}

function buildEmailSection() {
    const { card, body } = cronMakeSection('cron-section-5', 'Email Delivery', 'Delivery settings for queued automation emails (spw_automation_emails). By default OpenSparrow uses the server\'s PHP mail() — enable SMTP below to send through an authenticated mail server instead.');

    // ── From address ──────────────────────────────────────────────────────
    const fromInput = document.createElement('input');
    fromInput.type = 'email';
    fromInput.id = 'cron-email-from';
    fromInput.placeholder = 'noreply@example.com';
    fromInput.className = 'adm-input w-260';

    const lockNote = document.createElement('p');
    lockNote.className = 'c-muted';
    lockNote.style.cssText = 'margin:-8px 0 14px; display:none;';
    lockNote.textContent = 'Controlled by the AUTOMATION_EMAIL_FROM environment variable — cannot be changed here.';

    body.append(cronField('From address', fromInput), lockNote);

    // ── SMTP enabled toggle ───────────────────────────────────────────────
    const smtpRow = document.createElement('div');
    smtpRow.style.cssText = 'display:flex; align-items:center; gap:10px; margin-bottom:16px;';
    const smtpChk = document.createElement('input');
    smtpChk.type = 'checkbox';
    smtpChk.id = 'cron-smtp-enabled';
    smtpChk.className = 'adm-check';
    const smtpLbl = document.createElement('label');
    smtpLbl.htmlFor = 'cron-smtp-enabled';
    smtpLbl.textContent = 'Send via SMTP (instead of PHP mail())';
    smtpLbl.style.cssText = 'cursor:pointer;';
    smtpRow.append(smtpChk, smtpLbl);
    body.appendChild(smtpRow);

    // ── SMTP fields ────────────────────────────────────────────────────────
    const smtpFields = document.createElement('div');
    smtpFields.style.cssText = 'display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:0 16px;';

    const hostInput = document.createElement('input');
    hostInput.type = 'text';
    hostInput.id = 'cron-smtp-host';
    hostInput.placeholder = 'smtp.example.com';
    hostInput.className = 'adm-input w-full';

    const portInput = document.createElement('input');
    portInput.type = 'number';
    portInput.id = 'cron-smtp-port';
    portInput.min = '1';
    portInput.max = '65535';
    portInput.value = '587';
    portInput.className = 'adm-input w-full';

    const encSelect = document.createElement('select');
    encSelect.id = 'cron-smtp-encryption';
    encSelect.className = 'adm-input w-full';
    [['tls', 'STARTTLS (587)'], ['ssl', 'SSL/TLS (465)'], ['none', 'None']].forEach(([val, text]) => {
        const opt = document.createElement('option');
        opt.value = val;
        opt.textContent = text;
        encSelect.appendChild(opt);
    });

    const userInput = document.createElement('input');
    userInput.type = 'text';
    userInput.id = 'cron-smtp-username';
    userInput.autocomplete = 'off';
    userInput.className = 'adm-input w-full';

    smtpFields.append(
        cronField('Host', hostInput),
        cronField('Port', portInput),
        cronField('Encryption', encSelect),
        cronField('Username', userInput)
    );
    body.appendChild(smtpFields);

    // Password — write-only, same pattern as the RAG Ollama API key field.
    const passRow = document.createElement('div');
    passRow.style.cssText = 'display:flex; gap:8px; align-items:center; margin-bottom:6px;';
    const passInput = document.createElement('input');
    passInput.type = 'password';
    passInput.id = 'cron-smtp-password';
    passInput.placeholder = 'Leave blank to keep the current password';
    passInput.autocomplete = 'new-password';
    passInput.className = 'adm-input flex-1';
    const passClearBtn = document.createElement('button');
    passClearBtn.type = 'button';
    passClearBtn.className = 'btn btn-secondary btn-sm';
    passClearBtn.textContent = 'Clear password';
    passClearBtn.style.flexShrink = '0';
    passRow.append(passInput, passClearBtn);

    const passStatus = document.createElement('div');
    passStatus.className = 'c-muted';
    passStatus.style.cssText = 'margin-bottom:16px;';

    let passClearRequested = false;
    function renderPassStatus(configured) {
        passStatus.textContent = configured ? 'Password configured.' : 'No password set.';
    }
    passClearBtn.addEventListener('click', () => {
        passClearRequested = true;
        passInput.value = '';
        renderPassStatus(false);
    });
    passInput.addEventListener('input', () => {
        if (passInput.value !== '') passClearRequested = false;
    });

    body.append(cronField('Password', passRow), passStatus);

    // ── Actions ────────────────────────────────────────────────────────────
    const actionRow = document.createElement('div');
    actionRow.style.cssText = 'display:flex; gap:10px; align-items:center; margin-top:6px;';

    const saveBtn = document.createElement('button');
    saveBtn.className = 'btn btn-primary';
    saveBtn.textContent = 'Save';

    const testBtn = document.createElement('button');
    testBtn.type = 'button';
    testBtn.className = 'btn btn-secondary';
    testBtn.textContent = 'Test SMTP Connection';

    actionRow.append(saveBtn, testBtn);

    const result = document.createElement('p');
    result.style.cssText = 'margin-top:12px; display:none;';

    body.append(actionRow, result);

    async function load() {
        try {
            const res = await apiFetch('api.php?action=get_automation_email_setting');
            const data = await res.json();
            fromInput.value = data.from || '';
            if (data.locked_by_env) {
                fromInput.disabled = true;
                lockNote.style.display = '';
            }
            smtpChk.checked = !!data.smtp_enabled;
            hostInput.value = data.smtp_host || '';
            portInput.value = data.smtp_port || 587;
            encSelect.value = data.smtp_encryption || 'tls';
            userInput.value = data.smtp_username || '';
            renderPassStatus(!!data.smtp_password_configured);
        } catch (e) {
            result.textContent = 'Request failed: ' + e.message;
            result.style.color = '#a80000';
            result.style.display = '';
        }
    }

    function buildPayload() {
        const payload = {
            from: fromInput.value.trim(),
            smtp_enabled: smtpChk.checked,
            smtp_host: hostInput.value.trim(),
            smtp_port: parseInt(portInput.value, 10) || 587,
            smtp_encryption: encSelect.value,
            smtp_username: userInput.value.trim(),
        };
        if (passInput.value !== '') {
            payload.smtp_password = passInput.value;
        } else if (passClearRequested) {
            payload.smtp_password_clear = true;
        }
        return payload;
    }

    saveBtn.addEventListener('click', async () => {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        result.style.display = 'none';

        try {
            const res = await apiFetch('api.php?action=set_automation_email_setting', {
                method: 'POST',
                body: JSON.stringify(buildPayload())
            });
            const data = await res.json();
            if (data.status === 'success') {
                result.textContent = 'Saved.';
                result.style.color = 'var(--ok)';
                passInput.value = '';
                passClearRequested = false;
                await load();
            } else {
                result.textContent = 'Error: ' + (data.error || 'unknown');
                result.style.color = '#a80000';
            }
        } catch (e) {
            result.textContent = 'Request failed: ' + e.message;
            result.style.color = '#a80000';
        }

        result.style.display = '';
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
    });

    testBtn.addEventListener('click', async () => {
        testBtn.disabled = true;
        testBtn.textContent = 'Testing…';
        result.style.display = 'none';

        try {
            const res = await apiFetch('api.php?action=test_smtp_connection', {
                method: 'POST',
                body: JSON.stringify({
                    smtp_host: hostInput.value.trim(),
                    smtp_port: parseInt(portInput.value, 10) || 587,
                    smtp_encryption: encSelect.value,
                    smtp_username: userInput.value.trim(),
                    smtp_password: passInput.value,
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                result.textContent = 'Connection successful.';
                result.style.color = 'var(--ok)';
            } else {
                result.textContent = 'Error: ' + (data.error || 'unknown');
                result.style.color = '#a80000';
            }
        } catch (e) {
            result.textContent = 'Request failed: ' + e.message;
            result.style.color = '#a80000';
        }

        result.style.display = '';
        testBtn.disabled = false;
        testBtn.textContent = 'Test SMTP Connection';
    });

    load();
    return card;
}

// ─── Main render ──────────────────────────────────────────────────────────────

export function renderCronPage(ctx) {
    const { workspaceEl } = ctx;

    workspaceEl.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    workspaceEl.appendChild(wrap);

    wrap.appendChild(createPageHeader(
        'Cron & Notifications',
        'Run scheduled notification jobs, review run history and statistics, and manage cleanup of old log entries.'
    ));

    const [p0, p1, p2, p3, p4, p5] = buildInnerTabs(wrap, [
        { label: 'Run', icon: 'autorenew.png' },
        { label: 'History', icon: 'manage_history.png' },
        { label: 'Statistics', icon: 'bar_chart.png' },
        { label: 'Setup', icon: 'car_gear.png' },
        { label: 'Cleanup', icon: 'folder_zip.png' },
        { label: 'Email', icon: 'mail.png' },
    ]);

    p0.appendChild(buildManualRunSection());
    p1.appendChild(buildRunHistorySection());
    p2.appendChild(buildStatsSection());
    p3.appendChild(buildSetupSection());
    p4.appendChild(buildCleanupSection());
    p5.appendChild(buildEmailSection());
}
