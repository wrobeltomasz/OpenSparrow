// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { escHtml } from '../../assets/js/util/esc.js';
import { apiFetch } from '../../assets/js/util/api.js';

function ovFormatBytes(bytes) {
    const byteCount = Number(bytes);
    if (byteCount >= 1073741824) return (byteCount / 1073741824).toFixed(1) + ' GB';
    if (byteCount >= 1048576)    return (byteCount / 1048576).toFixed(1) + ' MB';
    if (byteCount >= 1024)       return (byteCount / 1024).toFixed(1) + ' KB';
    return byteCount + ' B';
}

function ovFormatNumber(n) {
    return Number(n).toLocaleString();
}

function navigateToTab(dataFile) {
    const tab = document.querySelector('.admin-tab[data-file="' + dataFile + '"]');
    if (!tab) return;
    const section = tab.closest('.nav-section');
    if (section && !section.classList.contains('open')) {
        section.classList.add('open');
    }
    tab.click();
}

function ovStatCard(icon, label, value, sub, dataFile) {
    const card = document.createElement('div');
    card.className = 'ov-stat-card';
    if (dataFile) {
        card.classList.add('ov-stat-clickable');
        card.addEventListener('click', () => navigateToTab(dataFile));
    }

    const iconElement = document.createElement('img');
    iconElement.src = '../../assets/icons/' + icon;
    iconElement.alt = '';
    iconElement.className = 'ov-stat-icon';
    card.appendChild(iconElement);

    const body = document.createElement('div');
    body.className = 'ov-stat-body';

    const valueElement = document.createElement('div');
    valueElement.className = 'ov-stat-value';
    valueElement.textContent = value;
    body.appendChild(valueElement);

    const labelElement = document.createElement('div');
    labelElement.className = 'ov-stat-label';
    labelElement.textContent = label;
    body.appendChild(labelElement);

    if (sub) {
        const subElement = document.createElement('div');
        subElement.className = 'ov-stat-sub';
        subElement.textContent = sub;
        body.appendChild(subElement);
    }

    card.appendChild(body);
    return card;
}

function ovSection(title) {
    const element = document.createElement('div');
    element.className = 'ov-section-title';
    element.textContent = title;
    return element;
}

function ovStatusRow(label, isOk, detail) {
    const row = document.createElement('div');
    row.className = 'ov-status-row';

    const badge = document.createElement('span');
    badge.className = 'ov-status-badge ' + (isOk ? 'ov-badge-ok' : 'ov-badge-warn');
    badge.textContent = isOk ? 'OK' : 'WARN';
    row.appendChild(badge);

    const labelElement = document.createElement('span');
    labelElement.className = 'ov-status-label';
    labelElement.textContent = label;
    row.appendChild(labelElement);

    if (detail) {
        const detailSpan = document.createElement('span');
        detailSpan.className = 'ov-status-detail';
        detailSpan.textContent = detail;
        row.appendChild(detailSpan);
    }

    return row;
}

function ovAuditRow(entry) {
    const row = document.createElement('div');
    row.className = 'ov-feed-row';

    const time = document.createElement('span');
    time.className = 'ov-feed-time';
    time.textContent = entry.created_at ?? '';
    row.appendChild(time);

    const user = document.createElement('span');
    user.className = 'ov-feed-user';
    user.textContent = entry.username ?? '—';
    row.appendChild(user);

    const action = document.createElement('span');
    action.className = 'ov-feed-action';
    action.textContent = entry.action ?? '';
    row.appendChild(action);

    if (entry.target_table) {
        const table = document.createElement('span');
        table.className = 'ov-feed-table';
        table.textContent = entry.target_table;
        row.appendChild(table);
    }

    return row;
}

function ovCronRow(entry) {
    const row = document.createElement('div');
    row.className = 'ov-feed-row';

    const time = document.createElement('span');
    time.className = 'ov-feed-time';
    time.textContent = entry.started_at ?? '';
    row.appendChild(time);

    const badge = document.createElement('span');
    const isOk = entry.status === 'success';
    badge.className = 'ov-status-badge ' + (isOk ? 'ov-badge-ok' : 'ov-badge-warn');
    badge.textContent = (entry.status ?? '').toUpperCase();
    row.appendChild(badge);

    const sent = document.createElement('span');
    sent.className = 'ov-feed-action';
    sent.textContent = Number(entry.sent) + ' sent';
    row.appendChild(sent);

    const authorSpan = document.createElement('span');
    authorSpan.className = 'ov-feed-table';
    authorSpan.textContent = 'via ' + (entry.triggered_by ?? 'cron');
    row.appendChild(authorSpan);

    return row;
}

export async function renderOverviewPage(context) {
    const { workspaceEl: workspaceElement } = context;

    workspaceElement._renderId = (workspaceElement._renderId || 0) + 1;
    const myId = workspaceElement._renderId;

    workspaceElement.innerHTML = '';

    const loading = document.createElement('p');
    loading.className = 'ov-loading';
    loading.textContent = 'Loading dashboard…';
    workspaceElement.appendChild(loading);

    let data;
    try {
        const result = await apiFetch('api.php?action=overview');
        data = await result.json();
    } catch (error) {
        if (workspaceElement._renderId !== myId) return;
        workspaceElement.innerHTML = '<p style="color:var(--error);">Failed to load dashboard data. Check server logs.</p>';
        return;
    }

    if (workspaceElement._renderId !== myId) return;
    workspaceElement.innerHTML = '';

    if (data.status === 'error') {
        const error = document.createElement('p');
        error.style.color = 'var(--error)';
        error.textContent = 'Error: ' + escHtml(data.error ?? 'Unknown error');
        workspaceElement.appendChild(error);
        return;
    }

    const welcomeBar = document.createElement('div');
    welcomeBar.className = 'ov-welcome-bar';

    const welcomeLeft = document.createElement('div');
    welcomeLeft.className = 'ov-welcome-left';

    const welcomeTitle = document.createElement('h2');
    welcomeTitle.className = 'ov-welcome-title';
    welcomeTitle.textContent = 'Admin Overview';
    welcomeLeft.appendChild(welcomeTitle);

    const versionBadge = document.createElement('span');
    versionBadge.className = 'ov-version-badge';
    versionBadge.textContent = 'v' + escHtml(data.app_version ?? '');
    welcomeLeft.appendChild(versionBadge);

    welcomeBar.appendChild(welcomeLeft);
    workspaceElement.appendChild(welcomeBar);

    const statisticsRow = document.createElement('div');
    statisticsRow.className = 'ov-stats-row';

    statisticsRow.appendChild(ovStatCard(
        'fact_check.png', 'Anonymization',
        ovFormatNumber(data.anonymization_rule_count),
        data.anonymization_enabled ? 'enabled' : 'disabled',
        'anonymization'
    ));
    statisticsRow.appendChild(ovStatCard(
        'automation.png', 'Automations',
        ovFormatNumber(data.automation_count),
        'rules',
        'automations'
    ));
    statisticsRow.appendChild(ovStatCard(
        'database.png', 'ETL Jobs',
        ovFormatNumber(data.etl_job_count),
        'configured',
        'etl'
    ));
    statisticsRow.appendChild(ovStatCard(
        'upload.png', 'Files',
        ovFormatNumber(data.file_count),
        ovFormatBytes(data.file_size_bytes),
        'files'
    ));
    const lastCronRaw  = data.last_cron_run ?? null;
    const lastCronTime = lastCronRaw ? lastCronRaw.slice(11) : 'Never';
    const lastCronDate = lastCronRaw ? lastCronRaw.slice(0, 10) : '';
    statisticsRow.appendChild(ovStatCard(
        'manage_history.png', 'Last Cron',
        lastCronTime,
        lastCronDate,
        'cron'
    ));
    statisticsRow.appendChild(ovStatCard(
        'picture_as_pdf.png', 'Printouts',
        ovFormatNumber(data.print_count),
        'templates',
        'print'
    ));
    statisticsRow.appendChild(ovStatCard(
        'docs.png', 'RAG Docs',
        ovFormatNumber(data.rag_count),
        'documents',
        'rag'
    ));
    statisticsRow.appendChild(ovStatCard(
        'database.png', 'Records',
        ovFormatNumber(data.total_records),
        data.table_count + ' tables',
        'schema'
    ));
    statisticsRow.appendChild(ovStatCard(
        'data_table.png', 'Tables',
        ovFormatNumber(data.table_count),
        'in schema',
        'schema'
    ));
    statisticsRow.appendChild(ovStatCard(
        'user_attributes.png', 'Users',
        ovFormatNumber(data.user_total),
        data.user_active + ' active',
        'users'
    ));
    statisticsRow.appendChild(ovStatCard(
        'table_chart_view.png', 'Views',
        ovFormatNumber(data.view_count),
        'configured',
        'views'
    ));
    statisticsRow.appendChild(ovStatCard(
        'build.png', 'Workflows',
        ovFormatNumber(data.workflow_count),
        'configured',
        'workflows'
    ));

    workspaceElement.appendChild(statisticsRow);

    const midRow = document.createElement('div');
    midRow.className = 'ov-mid-row';

    const feedPanel = document.createElement('div');
    feedPanel.className = 'ov-panel';

    feedPanel.appendChild(ovSection('Recent Activity'));

    const feedItems = [];
    (data.cron_recent ?? []).forEach(cronEntry => {
        feedItems.push({ ts: cronEntry.started_at, type: 'cron', data: cronEntry });
    });
    (data.audit_recent ?? []).forEach(auditEntry => {
        feedItems.push({ ts: auditEntry.created_at, type: 'audit', data: auditEntry });
    });
    feedItems.sort((auditEntry, byteCount) => (byteCount.ts ?? '').localeCompare(auditEntry.ts ?? ''));
    const topFeed = feedItems.slice(0, 10);

    if (topFeed.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'ov-empty';
        empty.textContent = 'No activity recorded yet.';
        feedPanel.appendChild(empty);
    } else {
        topFeed.forEach(item => {
            if (item.type === 'cron') {
                feedPanel.appendChild(ovCronRow(item.data));
            } else {
                feedPanel.appendChild(ovAuditRow(item.data));
            }
        });
    }

    midRow.appendChild(feedPanel);

    const statusPanel = document.createElement('div');
    statusPanel.className = 'ov-panel';

    statusPanel.appendChild(ovSection('System Status'));

    statusPanel.appendChild(ovStatusRow(
        'PHP ' + escHtml(data.php_version ?? ''),
        data.php_ok,
        data.php_ok ? '' : 'PHP 8.1+ required'
    ));
    statusPanel.appendChild(ovStatusRow(
        'PostgreSQL ' + escHtml(data.pg_version ?? ''),
        true,
        ''
    ));
    statusPanel.appendChild(ovStatusRow(
        'display_errors',
        data.display_errors_ok,
        data.display_errors_ok ? 'Off (correct)' : 'On — disable in production'
    ));
    statusPanel.appendChild(ovStatusRow(
        'Pending migrations',
        data.pending_migrations === 0,
        data.pending_migrations === 0 ? 'None' : data.pending_migrations + ' pending'
    ));

    const dbSizeRow = document.createElement('div');
    dbSizeRow.className = 'ov-status-row';
    const dbLabel = document.createElement('span');
    dbLabel.className = 'ov-status-label';
    dbLabel.textContent = 'Database size';
    const dbValue = document.createElement('span');
    dbValue.className = 'ov-status-detail';
    dbValue.textContent = ovFormatBytes(data.db_size_bytes);
    dbSizeRow.appendChild(dbLabel);
    dbSizeRow.appendChild(dbValue);
    statusPanel.appendChild(dbSizeRow);

    statusPanel.appendChild(ovStatusRow(
        'Secure cookies',
        data.secure_cookies_ok,
        data.secure_cookies_ok ? 'Enabled' : 'Disabled — enable in production'
    ));
    statusPanel.appendChild(ovStatusRow(
        'IP hash salt',
        data.ip_hash_salt_ok,
        data.ip_hash_salt_ok ? 'Configured' : 'Not set — required in production'
    ));

    const sessionRow = document.createElement('div');
    sessionRow.className = 'ov-status-row';
    const sessionLabel = document.createElement('span');
    sessionLabel.className = 'ov-status-label';
    sessionLabel.textContent = 'Session lifetime';
    const sessionValue = document.createElement('span');
    sessionValue.className = 'ov-status-detail';
    const sessionHours = data.session_lifetime ? (Number(data.session_lifetime) / 3600).toFixed(1) + ' h' : '—';
    sessionValue.textContent = sessionHours;
    sessionRow.appendChild(sessionLabel);
    sessionRow.appendChild(sessionValue);
    statusPanel.appendChild(sessionRow);

    const memoryRow = document.createElement('div');
    memoryRow.className = 'ov-status-row';
    const memoryLabel = document.createElement('span');
    memoryLabel.className = 'ov-status-label';
    memoryLabel.textContent = 'PHP memory limit';
    const memoryValue = document.createElement('span');
    memoryValue.className = 'ov-status-detail';
    memoryValue.textContent = escHtml(data.memory_limit ?? '—');
    memoryRow.appendChild(memoryLabel);
    memoryRow.appendChild(memoryValue);
    statusPanel.appendChild(memoryRow);

    const uploadRow = document.createElement('div');
    uploadRow.className = 'ov-status-row';
    const uploadLabel = document.createElement('span');
    uploadLabel.className = 'ov-status-label';
    uploadLabel.textContent = 'Upload max filesize';
    const uploadValue = document.createElement('span');
    uploadValue.className = 'ov-status-detail';
    uploadValue.textContent = escHtml(data.upload_max_filesize ?? '—');
    uploadRow.appendChild(uploadLabel);
    uploadRow.appendChild(uploadValue);
    statusPanel.appendChild(uploadRow);

    midRow.appendChild(statusPanel);
    workspaceElement.appendChild(midRow);

    if ((data.tables ?? []).length > 0) {
        const tablesSection = document.createElement('div');
        tablesSection.className = 'ov-panel ov-tables-panel';

        tablesSection.appendChild(ovSection('Table Record Counts'));

        const grid = document.createElement('div');
        grid.className = 'ov-tables-grid';

        const maxCount = Math.max(1, ...(data.tables.map(tableEntry => tableEntry.count)));

        data.tables.forEach(tableEntry => {
            const item = document.createElement('div');
            item.className = 'ov-table-item';

            const nameElement = document.createElement('div');
            nameElement.className = 'ov-table-name';
            nameElement.textContent = tableEntry.label ?? tableEntry.name;
            item.appendChild(nameElement);

            const barWrap = document.createElement('div');
            barWrap.className = 'ov-bar-wrap';

            const bar = document.createElement('div');
            bar.className = 'ov-bar';
            bar.style.width = Math.round((tableEntry.count / maxCount) * 100) + '%';
            barWrap.appendChild(bar);
            item.appendChild(barWrap);

            const countElement = document.createElement('div');
            countElement.className = 'ov-table-count';
            countElement.textContent = ovFormatNumber(tableEntry.count);
            item.appendChild(countElement);

            grid.appendChild(item);
        });

        tablesSection.appendChild(grid);
        workspaceElement.appendChild(tablesSection);
    }
}
