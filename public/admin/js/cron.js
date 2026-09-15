// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { buildInnerTabs, createPageHeader, mkTable, mkThead, td, tdEl, buildSectionCard } from './ui.js';

import { escHtml } from '../../assets/js/util/esc.js';

function statusBadge(status) {
    const cssClass = { success: 'ok', error: 'danger', running: 'warn' }[status] ?? 'muted';
    const badgeSpan = document.createElement('span');
    badgeSpan.className = `adm-badge adm-badge-${cssClass}`;
    badgeSpan.textContent = status.toUpperCase();
    return badgeSpan;
}

function cronMakeSection(id, title, description) {
    return buildSectionCard(title, description, id);
}

function buildManualRunSection() {
    const { card, body } = cronMakeSection('cron-section-0', 'Manual Run', 'Trigger the notification cron immediately outside the scheduler.');

    const runButton = document.createElement('button');
    runButton.className = 'btn btn-primary';
    runButton.textContent = 'Run Cron Now';

    const output = document.createElement('pre');
    output.style.cssText = 'margin-top:14px; padding:12px; background:var(--bg); border:1px solid var(--border); border-radius:4px;  line-height:1.6; max-height:300px; overflow-y:auto; white-space:pre-wrap; display:none;';

    runButton.addEventListener('click', async () => {
        runButton.disabled = true;
        runButton.textContent = 'Running…';
        output.style.display = '';
        output.textContent = 'Please wait…';
        output.style.color = '';

        try {
            const response = await apiFetch('api.php?action=run_cron_notifications', {
                method: 'POST',
            });
            const data = await response.json();
            if (data.status === 'success') {
                output.innerHTML = data.output || '(no output)';
            } else {
                output.textContent = 'Error: ' + (data.error || 'unknown');
                output.style.color = 'var(--error)';
            }
        } catch (error) {
            output.textContent = 'Request failed: ' + error.message;
            output.style.color = 'var(--error)';
        }

        runButton.disabled = false;
        runButton.textContent = 'Run Cron Now';
    });

    body.append(runButton, output);
    return card;
}

function buildRunHistorySection() {
    const { card, body } = cronMakeSection('cron-section-1', 'Run History', 'Last 50 cron executions from spw_users_notifications_log.');

    const loadButton = document.createElement('button');
    loadButton.className = 'btn btn-primary';
    loadButton.textContent = 'Load History';

    const container = document.createElement('div');
    container.style.marginTop = '14px';

    loadButton.addEventListener('click', async () => {
        loadButton.disabled = true;
        loadButton.textContent = 'Loading…';
        container.textContent = '';

        try {
            const response = await apiFetch('api.php?action=cron_log');
            const data = await response.json();

            if (data.status !== 'success') {
                container.textContent = 'Error: ' + (data.error || 'unknown');
                return;
            }

            if (!data.rows || data.rows.length === 0) {
                container.textContent = 'No runs recorded yet.';
                return;
            }

            const tableElement = mkTable();
            mkThead(tableElement, ['#', 'Status', 'Triggered By', 'Started At', 'Duration', 'Sources', 'Notifications', 'Error']);

            const tbody = tableElement.createTBody();
            data.rows.forEach(logRow => {
                const tr = tbody.insertRow();
                tr.appendChild(td(logRow.id));
                tr.appendChild(tdEl(statusBadge(logRow.status)));
                tr.appendChild(td(logRow.triggered_by));
                tr.appendChild(td(logRow.started_at ? logRow.started_at.replace('T', ' ').substring(0, 19) : ''));
                const duration = logRow.duration_sec !== null ? Number(logRow.duration_sec).toFixed(2) + 's' : '—';
                tr.appendChild(td(duration));
                tr.appendChild(td(logRow.sources_processed));
                tr.appendChild(td(logRow.notifications_created));
                tr.appendChild(td(logRow.error_message, 'color:var(--error); max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;'));
            });

            container.innerHTML = '';
            container.appendChild(tableElement);
        } catch (error) {
            container.textContent = 'Request failed: ' + error.message;
        }

        loadButton.disabled = false;
        loadButton.textContent = 'Refresh';
    });

    body.append(loadButton, container);
    return card;
}

function buildStatisticsSection() {
    const { card, body } = cronMakeSection('cron-section-2', 'Notification Stats', 'Current totals from spw_users_notifications, top unread per user.');

    const loadButton = document.createElement('button');
    loadButton.className = 'btn btn-primary';
    loadButton.textContent = 'Load Stats';

    const container = document.createElement('div');
    container.style.marginTop = '14px';

    loadButton.addEventListener('click', async () => {
        loadButton.disabled = true;
        loadButton.textContent = 'Loading…';
        container.textContent = '';

        try {
            const response = await apiFetch('api.php?action=cron_stats');
            const data = await response.json();

            if (data.status !== 'success') {
                container.textContent = 'Error: ' + (data.error || 'unknown');
                return;
            }

            const tableElement = data.totals || {};
            const lastRun = data.last_run;

            const kpiGrid = document.createElement('div');
            kpiGrid.style.cssText = 'display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:18px;';

            const kpis = [
                ['Total Notifications', tableElement.total ?? '—', 'var(--muted)'],
                ['Unread',              tableElement.unread ?? '—', 'var(--error)'],
                ['Due Today (unread)',  tableElement.due_today ?? '—', 'var(--warn)'],
                ['Upcoming Unread',     tableElement.upcoming_unread ?? '—', 'var(--muted)'],
            ];
            kpis.forEach(([label, value, color]) => {
                const kpiElement = document.createElement('div');
                kpiElement.style.cssText = `padding:14px 16px; border-left:4px solid ${color}; background:#fff; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,.07);`;
                const number = document.createElement('div');
                number.textContent = value;
                number.style.cssText = ` font-weight:var(--font-weight-bold); color:${color};`;
                const labelElement = document.createElement('div');
                labelElement.textContent = label;
                labelElement.style.cssText = '  margin-top:2px;';
                kpiElement.append(number, labelElement);
                kpiGrid.appendChild(kpiElement);
            });
            container.appendChild(kpiGrid);

            if (lastRun) {
                const lastRunElement = document.createElement('p');
                lastRunElement.style.cssText = '  margin-bottom:14px;';
                const badge = statusBadge(lastRun.status);
                badge.style.marginLeft = '6px';
                lastRunElement.textContent = 'Last run: ' + (lastRun.started_at || '').substring(0, 19).replace('T', ' ') + ' ';
                lastRunElement.appendChild(badge);
                container.appendChild(lastRunElement);
            }

            if (data.per_user && data.per_user.length > 0) {
                const h4 = document.createElement('h4');
                h4.textContent = 'Top Unread per User';
                h4.style.cssText = 'margin:0 0 10px;    ';
                container.appendChild(h4);

                const table = mkTable();
                mkThead(table, ['Username', 'Email', 'Unread Count']);
                const tbody = table.createTBody();
                data.per_user.forEach(logRow => {
                    const tr = tbody.insertRow();
                    tr.appendChild(td(logRow.username));
                    tr.appendChild(td(logRow.email));
                    tr.appendChild(td(logRow.unread_count));
                });
                container.appendChild(table);
            } else {
                const paragraph = document.createElement('p');
                paragraph.textContent = 'No unread notifications found.';
                container.appendChild(paragraph);
            }
        } catch (error) {
            container.textContent = 'Request failed: ' + error.message;
        }

        loadButton.disabled = false;
        loadButton.textContent = 'Refresh';
    });

    body.append(loadButton, container);
    return card;
}

function buildSetupSection() {
    const { card, body } = cronMakeSection('cron-section-3', 'Cron Setup', 'How to schedule automatic notification dispatch on your server.');

    const cronPath = window.location.origin + '/cron/cron_notifications.php';

    const content = document.createElement('div');
    content.style.cssText = 'display:grid; gap:16px;';

    function guideBlock(heading, code, note) {
        const wrap = document.createElement('div');
        wrap.style.cssText = 'background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:14px;';

        const headingElement = document.createElement('strong');
        headingElement.textContent = heading;
        headingElement.style.cssText = 'display:block; margin-bottom:8px; ';

        const preElement = document.createElement('pre');
        preElement.style.cssText = 'margin:0 0 6px;  background:var(--accent-dark); color:var(--accent-light); padding:10px 12px; border-radius:4px; overflow-x:auto; white-space:pre-wrap;';
        preElement.textContent = code;

        wrap.append(headingElement, preElement);

        if (note) {
            const paragraph = document.createElement('p');
            paragraph.textContent = note;
            paragraph.style.cssText = 'margin:6px 0 0;  ';
            wrap.appendChild(paragraph);
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

    const button = document.createElement('button');
    button.className = 'btn btn-danger';
    button.textContent = 'Purge Old Logs';

    const result = document.createElement('p');
    result.style.cssText = 'margin-top:12px;  display:none;';

    button.addEventListener('click', async () => {
        const days = parseInt(input.value, 10);
        if (!days || days < 1) {
            result.textContent = 'Enter a valid number of days.';
            result.style.color = 'var(--error)';
            result.style.display = '';
            return;
        }

        if (!confirm(`Delete all cron log entries older than ${days} day(s)? This cannot be undone.`)) return;

        button.disabled = true;
        button.textContent = 'Purging…';
        result.style.display = 'none';

        try {
            const response = await apiFetch('api.php?action=cron_purge_log', {
                method: 'POST',
                body: JSON.stringify({ days })
            });
            const data = await response.json();
            if (data.status === 'success') {
                result.textContent = `Deleted ${data.deleted} log row(s).`;
                result.style.color = 'var(--ok)';
            } else {
                result.textContent = 'Error: ' + (data.error || 'unknown');
                result.style.color = 'var(--error)';
            }
        } catch (error) {
            result.textContent = 'Request failed: ' + error.message;
            result.style.color = 'var(--error)';
        }

        result.style.display = '';
        button.disabled = false;
        button.textContent = 'Purge Old Logs';
    });

    row.append(label, input, unit, button);
    body.append(row, result);
    return card;
}

function cronField(labelText, inputElement) {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'margin-bottom:14px;';
    const labelElement = document.createElement('label');
    labelElement.textContent = labelText;
    labelElement.className = 'adm-field-label';
    if (inputElement.id) labelElement.htmlFor = inputElement.id;
    wrap.append(labelElement, inputElement);
    return wrap;
}

function buildEmailSection() {
    const { card, body } = cronMakeSection('cron-section-5', 'Email Delivery', 'Delivery settings for queued automation emails (spw_automation_emails). By default OpenSparrow uses the server\'s PHP mail() — enable SMTP below to send through an authenticated mail server instead.');

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

    const smtpRow = document.createElement('div');
    smtpRow.style.cssText = 'display:flex; align-items:center; gap:10px; margin-bottom:16px;';
    const smtpCheckbox = document.createElement('input');
    smtpCheckbox.type = 'checkbox';
    smtpCheckbox.id = 'cron-smtp-enabled';
    smtpCheckbox.className = 'adm-check';
    const smtpLabel = document.createElement('label');
    smtpLabel.htmlFor = 'cron-smtp-enabled';
    smtpLabel.textContent = 'Send via SMTP (instead of PHP mail())';
    smtpLabel.style.cssText = 'cursor:pointer;';
    smtpRow.append(smtpCheckbox, smtpLabel);
    body.appendChild(smtpRow);

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
    [['tls', 'STARTTLS (587)'], ['ssl', 'SSL/TLS (465)'], ['none', 'None']].forEach(([value, text]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = text;
        encSelect.appendChild(option);
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

    const passRow = document.createElement('div');
    passRow.style.cssText = 'display:flex; gap:8px; align-items:center; margin-bottom:6px;';
    const passInput = document.createElement('input');
    passInput.type = 'password';
    passInput.id = 'cron-smtp-password';
    passInput.placeholder = 'Leave blank to keep the current password';
    passInput.autocomplete = 'new-password';
    passInput.className = 'adm-input flex-1';
    const passClearButton = document.createElement('button');
    passClearButton.type = 'button';
    passClearButton.className = 'btn btn-secondary btn-sm';
    passClearButton.textContent = 'Clear password';
    passClearButton.style.flexShrink = '0';
    passRow.append(passInput, passClearButton);

    const passStatus = document.createElement('div');
    passStatus.className = 'c-muted';
    passStatus.style.cssText = 'margin-bottom:16px;';

    let passClearRequested = false;
    function renderPassStatus(configured) {
        passStatus.textContent = configured ? 'Password configured.' : 'No password set.';
    }
    passClearButton.addEventListener('click', () => {
        passClearRequested = true;
        passInput.value = '';
        renderPassStatus(false);
    });
    passInput.addEventListener('input', () => {
        if (passInput.value !== '') passClearRequested = false;
    });

    body.append(cronField('Password', passRow), passStatus);

    const actionRow = document.createElement('div');
    actionRow.style.cssText = 'display:flex; gap:10px; align-items:center; margin-top:6px;';

    const saveButton = document.createElement('button');
    saveButton.className = 'btn btn-primary';
    saveButton.textContent = 'Save';

    const testButton = document.createElement('button');
    testButton.type = 'button';
    testButton.className = 'btn btn-secondary';
    testButton.textContent = 'Test SMTP Connection';

    actionRow.append(saveButton, testButton);

    const result = document.createElement('p');
    result.style.cssText = 'margin-top:12px; display:none;';

    body.append(actionRow, result);

    async function load() {
        try {
            const response = await apiFetch('api.php?action=get_automation_email_setting');
            const data = await response.json();
            fromInput.value = data.from || '';
            if (data.locked_by_env) {
                fromInput.disabled = true;
                lockNote.style.display = '';
            }
            smtpCheckbox.checked = !!data.smtp_enabled;
            hostInput.value = data.smtp_host || '';
            portInput.value = data.smtp_port || 587;
            encSelect.value = data.smtp_encryption || 'tls';
            userInput.value = data.smtp_username || '';
            renderPassStatus(!!data.smtp_password_configured);
        } catch (error) {
            result.textContent = 'Request failed: ' + error.message;
            result.style.color = 'var(--error)';
            result.style.display = '';
        }
    }

    function buildPayload() {
        const payload = {
            from: fromInput.value.trim(),
            smtp_enabled: smtpCheckbox.checked,
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

    saveButton.addEventListener('click', async () => {
        saveButton.disabled = true;
        saveButton.textContent = 'Saving…';
        result.style.display = 'none';

        try {
            const response = await apiFetch('api.php?action=set_automation_email_setting', {
                method: 'POST',
                body: JSON.stringify(buildPayload())
            });
            const data = await response.json();
            if (data.status === 'success') {
                result.textContent = 'Saved.';
                result.style.color = 'var(--ok)';
                passInput.value = '';
                passClearRequested = false;
                await load();
            } else {
                result.textContent = 'Error: ' + (data.error || 'unknown');
                result.style.color = 'var(--error)';
            }
        } catch (error) {
            result.textContent = 'Request failed: ' + error.message;
            result.style.color = 'var(--error)';
        }

        result.style.display = '';
        saveButton.disabled = false;
        saveButton.textContent = 'Save';
    });

    testButton.addEventListener('click', async () => {
        testButton.disabled = true;
        testButton.textContent = 'Testing…';
        result.style.display = 'none';

        try {
            const response = await apiFetch('api.php?action=test_smtp_connection', {
                method: 'POST',
                body: JSON.stringify({
                    smtp_host: hostInput.value.trim(),
                    smtp_port: parseInt(portInput.value, 10) || 587,
                    smtp_encryption: encSelect.value,
                    smtp_username: userInput.value.trim(),
                    smtp_password: passInput.value,
                })
            });
            const data = await response.json();
            if (data.status === 'success') {
                result.textContent = 'Connection successful.';
                result.style.color = 'var(--ok)';
            } else {
                result.textContent = 'Error: ' + (data.error || 'unknown');
                result.style.color = 'var(--error)';
            }
        } catch (error) {
            result.textContent = 'Request failed: ' + error.message;
            result.style.color = 'var(--error)';
        }

        result.style.display = '';
        testButton.disabled = false;
        testButton.textContent = 'Test SMTP Connection';
    });

    load();
    return card;
}

function emailStatusBadge(status) {
    const cssClass = { sent: 'ok', error: 'danger', pending: 'warn' }[status] ?? 'muted';
    const badgeSpan = document.createElement('span');
    badgeSpan.className = `adm-badge adm-badge-${cssClass}`;
    badgeSpan.textContent = status.toUpperCase();
    return badgeSpan;
}

function buildEmailQueueSection() {
    const { card, body } = cronMakeSection('cron-section-6', 'Email Queue', 'Outgoing automation emails in spw_automation_emails. Requeue failed messages or delete them.');

    const toolbar = document.createElement('div');
    toolbar.style.cssText = 'display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px;';

    const statusSelect = document.createElement('select');
    statusSelect.className = 'adm-input w-160';
    [['', 'All statuses'], ['pending', 'Pending'], ['sent', 'Sent'], ['error', 'Error']].forEach(([value, label]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        statusSelect.appendChild(option);
    });

    const refreshButton = document.createElement('button');
    refreshButton.className = 'btn btn-primary';
    refreshButton.textContent = 'Refresh';

    const countsElement = document.createElement('span');
    countsElement.className = 'c-muted';

    toolbar.append(statusSelect, refreshButton, countsElement);
    body.appendChild(toolbar);

    const bulkRow = document.createElement('div');
    bulkRow.style.cssText = 'display:flex; gap:10px; align-items:center; margin-bottom:14px;';
    const requeueSelectedButton = document.createElement('button');
    requeueSelectedButton.className = 'btn btn-secondary';
    requeueSelectedButton.textContent = 'Requeue selected';
    const deleteSelectedButton = document.createElement('button');
    deleteSelectedButton.className = 'btn btn-danger';
    deleteSelectedButton.textContent = 'Delete selected';
    bulkRow.append(requeueSelectedButton, deleteSelectedButton);
    body.appendChild(bulkRow);

    const container = document.createElement('div');
    body.appendChild(container);

    let selectedIds = new Set();

    function selectedIdList() {
        return Array.from(selectedIds).map(Number);
    }

    function renderCounts(counts) {
        const pending = counts.pending ?? 0;
        const sent = counts.sent ?? 0;
        const error = counts.error ?? 0;
        countsElement.textContent = `Pending: ${pending} · Sent: ${sent} · Error: ${error}`;
    }

    async function loadQueue() {
        container.textContent = 'Loading…';
        const status = statusSelect.value;
        const query = status ? `&status=${encodeURIComponent(status)}` : '';
        try {
            const response = await apiFetch(`api.php?action=automation_emails_list${query}`);
            const data = await response.json();
            if (data.status !== 'success') {
                container.textContent = 'Error: ' + (data.error || 'unknown');
                return;
            }
            renderCounts(data.counts ?? {});
            selectedIds = new Set();
            renderTable(data.rows ?? []);
        } catch (error) {
            container.textContent = 'Request failed: ' + error.message;
        }
    }

    function renderTable(rows) {
        container.innerHTML = '';
        if (rows.length === 0) {
            container.textContent = 'No emails in the queue.';
            return;
        }

        const tableElement = mkTable();
        mkThead(tableElement, ['', '#', 'Recipient', 'Subject', 'Status', 'Attempts', 'Error', 'Created', 'Sent', 'Actions']);

        const tbody = tableElement.createTBody();
        rows.forEach(emailRow => {
            const tr = tbody.insertRow();

            const checkboxCell = document.createElement('td');
            checkboxCell.className = 'adm-td';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = selectedIds.has(Number(emailRow.id));
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) selectedIds.add(Number(emailRow.id));
                else selectedIds.delete(Number(emailRow.id));
            });
            checkboxCell.appendChild(checkbox);
            tr.appendChild(checkboxCell);

            tr.appendChild(td(emailRow.id));
            tr.appendChild(td(emailRow.recipient));
            tr.appendChild(td(emailRow.subject, 'max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;'));
            tr.appendChild(tdEl(emailStatusBadge(emailRow.status)));
            tr.appendChild(td(emailRow.attempts));
            tr.appendChild(td(emailRow.error_msg, 'color:var(--error); max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;'));
            tr.appendChild(td(emailRow.created_at));
            tr.appendChild(td(emailRow.sent_at));

            const actionsCell = document.createElement('td');
            actionsCell.className = 'adm-td';
            actionsCell.style.cssText = 'white-space:nowrap;';

            const requeueButton = document.createElement('button');
            requeueButton.type = 'button';
            requeueButton.className = 'btn btn-sm';
            requeueButton.textContent = 'Requeue';
            requeueButton.addEventListener('click', () => requeueIds([Number(emailRow.id)], requeueButton));

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'btn btn-sm btn-danger';
            deleteButton.textContent = 'Delete';
            deleteButton.addEventListener('click', () => deleteIds([Number(emailRow.id)], deleteButton));

            actionsCell.append(requeueButton, deleteButton);
            tr.appendChild(actionsCell);
        });

        container.appendChild(tableElement);
    }

    async function requeueIds(ids, anchorElement) {
        if (ids.length === 0) return;
        if (!confirm(`Requeue ${ids.length} email(s)?`)) return;
        try {
            const response = await apiFetch('api.php?action=automation_emails_requeue', {
                method: 'POST',
                body: JSON.stringify({ ids }),
            });
            const data = await response.json();
            if (data.status === 'success') {
                await loadQueue();
            } else {
                alert('Error: ' + (data.error || 'unknown'));
            }
        } catch (error) {
            alert('Request failed: ' + error.message);
        }
    }

    async function deleteIds(ids, anchorElement) {
        if (ids.length === 0) return;
        if (!confirm(`Delete ${ids.length} email(s)? This cannot be undone.`)) return;
        try {
            const response = await apiFetch('api.php?action=automation_emails_delete', {
                method: 'POST',
                body: JSON.stringify({ ids }),
            });
            const data = await response.json();
            if (data.status === 'success') {
                await loadQueue();
            } else {
                alert('Error: ' + (data.error || 'unknown'));
            }
        } catch (error) {
            alert('Request failed: ' + error.message);
        }
    }

    statusSelect.addEventListener('change', loadQueue);
    refreshButton.addEventListener('click', loadQueue);
    requeueSelectedButton.addEventListener('click', () => requeueIds(selectedIdList(), requeueSelectedButton));
    deleteSelectedButton.addEventListener('click', () => deleteIds(selectedIdList(), deleteSelectedButton));

    loadQueue();
    return { card, loadQueue };
}

function buildPurgeQueueSection(onPurged) {
    const { card, body } = cronMakeSection('cron-section-6b', 'Purge Queue', 'Delete queued emails by status, optionally only those older than a number of days.');

    const purgeRow = document.createElement('div');
    purgeRow.style.cssText = 'display:flex; align-items:center; gap:10px; flex-wrap:wrap;';

    const purgeStatusSelect = document.createElement('select');
    purgeStatusSelect.className = 'adm-input w-160';
    [['sent', 'Sent'], ['error', 'Error'], ['pending', 'Pending']].forEach(([value, label]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        purgeStatusSelect.appendChild(option);
    });

    const purgeDaysInput = document.createElement('input');
    purgeDaysInput.type = 'number';
    purgeDaysInput.min = '1';
    purgeDaysInput.max = '3650';
    purgeDaysInput.placeholder = 'all ages';
    purgeDaysInput.className = 'adm-input w-120';

    const purgeButton = document.createElement('button');
    purgeButton.className = 'btn btn-danger';
    purgeButton.textContent = 'Purge';

    const purgeResult = document.createElement('p');
    purgeResult.style.cssText = 'margin-top:12px; display:none;';

    purgeRow.append(purgeStatusSelect, purgeDaysInput, purgeButton);
    body.append(purgeRow, purgeResult);

    purgeButton.addEventListener('click', async () => {
        const daysValue = purgeDaysInput.value.trim();
        const days = daysValue === '' ? null : parseInt(daysValue, 10);
        if (days !== null && (!days || days < 1)) {
            purgeResult.textContent = 'Enter a valid number of days, or leave empty for all ages.';
            purgeResult.style.color = 'var(--error)';
            purgeResult.style.display = '';
            return;
        }
        const status = purgeStatusSelect.value;
        const label = days !== null ? `older than ${days} day(s)` : 'of all ages';
        if (!confirm(`Delete all "${status}" emails ${label}? This cannot be undone.`)) return;

        purgeButton.disabled = true;
        purgeButton.textContent = 'Purging…';
        purgeResult.style.display = 'none';

        try {
            const payload = { status };
            if (days !== null) payload.days = days;
            const response = await apiFetch('api.php?action=automation_emails_purge', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const data = await response.json();
            if (data.status === 'success') {
                purgeResult.textContent = `Deleted ${data.deleted} email(s).`;
                purgeResult.style.color = 'var(--ok)';
                onPurged();
            } else {
                purgeResult.textContent = 'Error: ' + (data.error || 'unknown');
                purgeResult.style.color = 'var(--error)';
            }
        } catch (error) {
            purgeResult.textContent = 'Request failed: ' + error.message;
            purgeResult.style.color = 'var(--error)';
        }

        purgeResult.style.display = '';
        purgeButton.disabled = false;
        purgeButton.textContent = 'Purge';
    });

    return card;
}

export function renderCronPage(context) {
    const { workspaceEl: workspaceElement } = context;

    workspaceElement.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    workspaceElement.appendChild(wrap);

    wrap.appendChild(createPageHeader(
        'Cron & Notifications',
        'Run scheduled notification jobs, review run history and statistics, and manage cleanup of old log entries.'
    ));

    const [p0, p1, p2, p3, p4, p5, p6] = buildInnerTabs(wrap, [
        { label: 'Run', icon: 'material/autorenew.svg' },
        { label: 'History', icon: 'material/manage_history.svg' },
        { label: 'Statistics', icon: 'material/bar_chart.svg' },
        { label: 'Setup', icon: 'material/terminal.svg' },
        { label: 'Cleanup', icon: 'material/delete_sweep.svg' },
        { label: 'Email', icon: 'material/mail.svg' },
        { label: 'Email Queue', icon: 'material/inbox.svg' },
    ]);

    p0.appendChild(buildManualRunSection());
    p1.appendChild(buildRunHistorySection());
    p2.appendChild(buildStatisticsSection());
    p3.appendChild(buildSetupSection());
    p4.appendChild(buildCleanupSection());
    p5.appendChild(buildEmailSection());
    const emailQueue = buildEmailQueueSection();
    p6.append(emailQueue.card, buildPurgeQueueSection(() => { emailQueue.loadQueue(); }));
}
