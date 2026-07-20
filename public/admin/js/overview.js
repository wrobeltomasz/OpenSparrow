// admin/js/overview.js — Admin home / overview page
// Summary cards (record counts, DB size, etc.) via api.php (overview). Local HTML-escape + byte-format helpers.

import { escHtml as ovEsc } from '../../assets/js/util/esc.js';

function ovFmtBytes(bytes) {
    const b = Number(bytes);
    if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
    if (b >= 1048576)    return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024)       return (b / 1024).toFixed(1) + ' KB';
    return b + ' B';
}

function ovFmtNum(n) {
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

    const iconEl = document.createElement('img');
    iconEl.src = '../../assets/icons/' + icon;
    iconEl.alt = '';
    iconEl.className = 'ov-stat-icon';
    card.appendChild(iconEl);

    const body = document.createElement('div');
    body.className = 'ov-stat-body';

    const valEl = document.createElement('div');
    valEl.className = 'ov-stat-value';
    valEl.textContent = value;
    body.appendChild(valEl);

    const labelEl = document.createElement('div');
    labelEl.className = 'ov-stat-label';
    labelEl.textContent = label;
    body.appendChild(labelEl);

    if (sub) {
        const subEl = document.createElement('div');
        subEl.className = 'ov-stat-sub';
        subEl.textContent = sub;
        body.appendChild(subEl);
    }

    card.appendChild(body);
    return card;
}

function ovSection(title) {
    const el = document.createElement('div');
    el.className = 'ov-section-title';
    el.textContent = title;
    return el;
}

function ovStatusRow(label, isOk, detail) {
    const row = document.createElement('div');
    row.className = 'ov-status-row';

    const badge = document.createElement('span');
    badge.className = 'ov-status-badge ' + (isOk ? 'ov-badge-ok' : 'ov-badge-warn');
    badge.textContent = isOk ? 'OK' : 'WARN';
    row.appendChild(badge);

    const lbl = document.createElement('span');
    lbl.className = 'ov-status-label';
    lbl.textContent = label;
    row.appendChild(lbl);

    if (detail) {
        const det = document.createElement('span');
        det.className = 'ov-status-detail';
        det.textContent = detail;
        row.appendChild(det);
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
        const tbl = document.createElement('span');
        tbl.className = 'ov-feed-table';
        tbl.textContent = entry.target_table;
        row.appendChild(tbl);
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

    const by = document.createElement('span');
    by.className = 'ov-feed-table';
    by.textContent = 'via ' + (entry.triggered_by ?? 'cron');
    row.appendChild(by);

    return row;
}

export async function renderOverviewPage(ctx) {
    const { workspaceEl } = ctx;

    workspaceEl._renderId = (workspaceEl._renderId || 0) + 1;
    const myId = workspaceEl._renderId;

    workspaceEl.innerHTML = '';

    const loading = document.createElement('p');
    loading.className = 'ov-loading';
    loading.textContent = 'Loading dashboard…';
    workspaceEl.appendChild(loading);

    let data;
    try {
        const res = await fetch('api.php?action=overview');
        data = await res.json();
    } catch (e) {
        if (workspaceEl._renderId !== myId) return;
        workspaceEl.innerHTML = '<p style="color:#a80000;">Failed to load dashboard data. Check server logs.</p>';
        return;
    }

    if (workspaceEl._renderId !== myId) return;
    workspaceEl.innerHTML = '';

    if (data.status === 'error') {
        const err = document.createElement('p');
        err.style.color = '#a80000';
        err.textContent = 'Error: ' + ovEsc(data.error ?? 'Unknown error');
        workspaceEl.appendChild(err);
        return;
    }

    // ── Welcome bar ────────────────────────────────────────────────────────────
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
    versionBadge.textContent = 'v' + ovEsc(data.app_version ?? '');
    welcomeLeft.appendChild(versionBadge);

    welcomeBar.appendChild(welcomeLeft);
    workspaceEl.appendChild(welcomeBar);

    // ── Stat cards ─────────────────────────────────────────────────────────────
    const statsRow = document.createElement('div');
    statsRow.className = 'ov-stats-row';

    statsRow.appendChild(ovStatCard(
        'fact_check.png', 'Anonymization',
        ovFmtNum(data.anonymization_rule_count),
        data.anonymization_enabled ? 'enabled' : 'disabled',
        'anonymization'
    ));
    statsRow.appendChild(ovStatCard(
        'automation.png', 'Automations',
        ovFmtNum(data.automation_count),
        'rules',
        'automations'
    ));
    statsRow.appendChild(ovStatCard(
        'database.png', 'ETL Jobs',
        ovFmtNum(data.etl_job_count),
        'configured',
        'etl'
    ));
    statsRow.appendChild(ovStatCard(
        'upload.png', 'Files',
        ovFmtNum(data.file_count),
        ovFmtBytes(data.file_size_bytes),
        'files'
    ));
    const lastCronRaw  = data.last_cron_run ?? null;
    const lastCronTime = lastCronRaw ? lastCronRaw.slice(11) : 'Never';
    const lastCronDate = lastCronRaw ? lastCronRaw.slice(0, 10) : '';
    statsRow.appendChild(ovStatCard(
        'manage_history.png', 'Last Cron',
        lastCronTime,
        lastCronDate,
        'cron'
    ));
    statsRow.appendChild(ovStatCard(
        'picture_as_pdf.png', 'Printouts',
        ovFmtNum(data.print_count),
        'templates',
        'print'
    ));
    statsRow.appendChild(ovStatCard(
        'docs.png', 'RAG Docs',
        ovFmtNum(data.rag_count),
        'documents',
        'rag'
    ));
    statsRow.appendChild(ovStatCard(
        'database.png', 'Records',
        ovFmtNum(data.total_records),
        data.table_count + ' tables',
        'schema'
    ));
    statsRow.appendChild(ovStatCard(
        'data_table.png', 'Tables',
        ovFmtNum(data.table_count),
        'in schema',
        'schema'
    ));
    statsRow.appendChild(ovStatCard(
        'user_attributes.png', 'Users',
        ovFmtNum(data.user_total),
        data.user_active + ' active',
        'users'
    ));
    statsRow.appendChild(ovStatCard(
        'table_chart_view.png', 'Views',
        ovFmtNum(data.view_count),
        'configured',
        'views'
    ));
    statsRow.appendChild(ovStatCard(
        'build.png', 'Workflows',
        ovFmtNum(data.workflow_count),
        'configured',
        'workflows'
    ));

    workspaceEl.appendChild(statsRow);

    // ── Middle row: activity feed + system status ───────────────────────────
    const midRow = document.createElement('div');
    midRow.className = 'ov-mid-row';

    // Activity feed
    const feedPanel = document.createElement('div');
    feedPanel.className = 'ov-panel';

    feedPanel.appendChild(ovSection('Recent Activity'));

    // Merge cron + audit into a unified feed sorted by time desc (show 8 total)
    const feedItems = [];
    (data.cron_recent ?? []).forEach(c => {
        feedItems.push({ ts: c.started_at, type: 'cron', data: c });
    });
    (data.audit_recent ?? []).forEach(a => {
        feedItems.push({ ts: a.created_at, type: 'audit', data: a });
    });
    feedItems.sort((a, b) => (b.ts ?? '').localeCompare(a.ts ?? ''));
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

    // System status
    const statusPanel = document.createElement('div');
    statusPanel.className = 'ov-panel';

    statusPanel.appendChild(ovSection('System Status'));

    statusPanel.appendChild(ovStatusRow(
        'PHP ' + ovEsc(data.php_version ?? ''),
        data.php_ok,
        data.php_ok ? '' : 'PHP 8.1+ required'
    ));
    statusPanel.appendChild(ovStatusRow(
        'PostgreSQL ' + ovEsc(data.pg_version ?? ''),
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
    const dbVal = document.createElement('span');
    dbVal.className = 'ov-status-detail';
    dbVal.textContent = ovFmtBytes(data.db_size_bytes);
    dbSizeRow.appendChild(dbLabel);
    dbSizeRow.appendChild(dbVal);
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
    const sessionVal = document.createElement('span');
    sessionVal.className = 'ov-status-detail';
    const sessionHours = data.session_lifetime ? (Number(data.session_lifetime) / 3600).toFixed(1) + ' h' : '—';
    sessionVal.textContent = sessionHours;
    sessionRow.appendChild(sessionLabel);
    sessionRow.appendChild(sessionVal);
    statusPanel.appendChild(sessionRow);

    const memoryRow = document.createElement('div');
    memoryRow.className = 'ov-status-row';
    const memoryLabel = document.createElement('span');
    memoryLabel.className = 'ov-status-label';
    memoryLabel.textContent = 'PHP memory limit';
    const memoryVal = document.createElement('span');
    memoryVal.className = 'ov-status-detail';
    memoryVal.textContent = ovEsc(data.memory_limit ?? '—');
    memoryRow.appendChild(memoryLabel);
    memoryRow.appendChild(memoryVal);
    statusPanel.appendChild(memoryRow);

    const uploadRow = document.createElement('div');
    uploadRow.className = 'ov-status-row';
    const uploadLabel = document.createElement('span');
    uploadLabel.className = 'ov-status-label';
    uploadLabel.textContent = 'Upload max filesize';
    const uploadVal = document.createElement('span');
    uploadVal.className = 'ov-status-detail';
    uploadVal.textContent = ovEsc(data.upload_max_filesize ?? '—');
    uploadRow.appendChild(uploadLabel);
    uploadRow.appendChild(uploadVal);
    statusPanel.appendChild(uploadRow);

    midRow.appendChild(statusPanel);
    workspaceEl.appendChild(midRow);

    // ── Table records grid ─────────────────────────────────────────────────────
    if ((data.tables ?? []).length > 0) {
        const tablesSection = document.createElement('div');
        tablesSection.className = 'ov-panel ov-tables-panel';

        tablesSection.appendChild(ovSection('Table Record Counts'));

        const grid = document.createElement('div');
        grid.className = 'ov-tables-grid';

        const maxCount = Math.max(1, ...(data.tables.map(t => t.count)));

        data.tables.forEach(t => {
            const item = document.createElement('div');
            item.className = 'ov-table-item';

            const nameEl = document.createElement('div');
            nameEl.className = 'ov-table-name';
            nameEl.textContent = t.label ?? t.name;
            item.appendChild(nameEl);

            const barWrap = document.createElement('div');
            barWrap.className = 'ov-bar-wrap';

            const bar = document.createElement('div');
            bar.className = 'ov-bar';
            bar.style.width = Math.round((t.count / maxCount) * 100) + '%';
            barWrap.appendChild(bar);
            item.appendChild(barWrap);

            const countEl = document.createElement('div');
            countEl.className = 'ov-table-count';
            countEl.textContent = ovFmtNum(t.count);
            item.appendChild(countEl);

            grid.appendChild(item);
        });

        tablesSection.appendChild(grid);
        workspaceEl.appendChild(tablesSection);
    }
}
