// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { buildInnerTabs, createPageHeader, mkTable, mkThead, td, tdEl } from './ui.js';

import { escHtml } from '../../assets/js/util/esc.js';
import { apiFetch } from '../../assets/js/util/api.js';

function severityBadge(sev) {
    const badgeSpan = document.createElement('span');
    badgeSpan.className = `adm-badge adm-badge-${sev in { high:1, medium:1, low:1 } ? sev : 'muted'}`;
    badgeSpan.textContent = sev.toUpperCase();
    return badgeSpan;
}

function copyButton(getText, label = 'Copy SQL', small = true) {
    const button = document.createElement('button');
    button.className = small ? 'btn btn-primary btn-xs' : 'btn btn-primary btn-sm';
    button.textContent = label;
    button.addEventListener('click', () => {
        navigator.clipboard.writeText(getText()).then(() => {
            const original = button.textContent;
            button.textContent = 'Copied!';
            setTimeout(() => { button.textContent = original; }, 2000);
        });
    });
    return button;
}

function makeSection(title, description) {
    const card = document.createElement('div');
    card.className = 'adm-sec-card';

    const headerElement = document.createElement('div');
    headerElement.className = 'adm-sec-hdr';

    const hdrLeft = document.createElement('div');
    const h3 = document.createElement('h3');
    h3.textContent = title;
    h3.style.cssText = 'margin:0 0 4px; ';
    const desc = document.createElement('p');
    desc.textContent = description;
    desc.style.cssText = 'margin:0;  ';
    hdrLeft.appendChild(h3);
    hdrLeft.appendChild(desc);

    const button = document.createElement('button');
    button.className = 'btn btn-primary btn-sm';
    button.textContent = 'Scan';

    headerElement.appendChild(hdrLeft);
    headerElement.appendChild(button);
    card.appendChild(headerElement);

    const body = document.createElement('div');
    body.className = 'adm-sec-body';
    const placeholder = document.createElement('p');
    placeholder.className = 'c-muted';
    placeholder.style.cssText = ' margin:0;';
    placeholder.textContent = 'Click Scan to run analysis.';
    body.appendChild(placeholder);
    card.appendChild(body);

    return { card, btn: button, body };
}

function setBodyLoading(body) {
    body.replaceChildren();
    const paragraph = document.createElement('p');
    paragraph.style.cssText = '  margin:0;';
    paragraph.textContent = 'Scanning…';
    body.appendChild(paragraph);
}

function setBodyError(body, messageElement) {
    body.replaceChildren();
    const paragraph = document.createElement('p');
    paragraph.style.cssText = 'color:var(--error);  margin:0;';
    paragraph.textContent = messageElement;
    body.appendChild(paragraph);
}

function setBodyEmpty(body, messageElement) {
    body.replaceChildren();
    const paragraph = document.createElement('p');
    paragraph.style.cssText = 'color:var(--ok); font-weight:600;  margin:0;';
    paragraph.textContent = '✓ ' + messageElement;
    body.appendChild(paragraph);
}

function renderIndexAdvisor(body, data) {
    body.replaceChildren();
    const suggestions = data.suggestions || [];

    if (!suggestions.length) {
        setBodyEmpty(body, 'No missing indexes detected.');
        return;
    }

    const row = document.createElement('div');
    row.style.cssText = 'display:flex; align-items:center; gap:12px; margin-bottom:14px;';
    const high = suggestions.filter(suggestion => suggestion.priority === 'high').length;
    const sum = document.createElement('span');
    sum.style.cssText = '';
    sum.textContent = `${suggestions.length} suggestion${suggestions.length !== 1 ? 's' : ''} · ${high} high priority`;
    row.appendChild(sum);
    row.appendChild(copyButton(() => suggestions.map(suggestion => suggestion.sql).join('\n'), 'Copy All SQL', false));
    body.appendChild(row);

    const byTable = new Map();
    suggestions.forEach(suggestion => {
        const groupKey = `"${suggestion.schema}"."${suggestion.table}"`;
        if (!byTable.has(groupKey)) byTable.set(groupKey, []);
        byTable.get(groupKey).push(suggestion);
    });

    byTable.forEach((rows, tableKey) => {
        const group = document.createElement('div');
        group.style.cssText = 'margin-bottom:16px; border:1px solid var(--border); border-radius:6px; overflow:hidden;';

        const groupHeader = document.createElement('div');
        groupHeader.style.cssText = 'padding:8px 12px; background:var(--bg); border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; font-family:var(--font-mono);  font-weight:600;';
        const ghText = document.createElement('span');
        ghText.textContent = tableKey;
        groupHeader.appendChild(ghText);
        groupHeader.appendChild(copyButton(() => rows.map(suggestionRow => suggestionRow.sql).join('\n'), 'Copy table SQL'));
        group.appendChild(groupHeader);

        const tableElement = mkTable();
        mkThead(tableElement, ['Priority', 'Column', 'Reason(s)', 'SQL', '']);
        const tbody = tableElement.createTBody();
        rows.forEach(suggestion => {
            const tr = tbody.insertRow();
            tr.appendChild(tdEl(severityBadge(suggestion.priority)));
            tr.appendChild(td(suggestion.column, 'font-family:var(--font-mono); font-weight:600;'));
            tr.appendChild(td(suggestion.reasons.join(' · ')));
            const codeTd = document.createElement('td');
            codeTd.style.cssText = 'padding:8px 12px; border-bottom:1px solid var(--border); max-width:340px;';
            const code = document.createElement('code');
            code.style.cssText = ' background:var(--bg); padding:3px 6px; border-radius:4px; display:block; overflow-x:auto; white-space:nowrap;';
            code.textContent = suggestion.sql;
            codeTd.appendChild(code);
            tr.appendChild(codeTd);
            tr.appendChild(tdEl(copyButton(() => suggestion.sql)));
        });
        group.appendChild(tableElement);
        body.appendChild(group);
    });
}

function renderUnusedIndexes(body, data) {
    body.replaceChildren();
    const rows = data.rows || [];

    if (!rows.length) {
        setBodyEmpty(body, 'No unused indexes found. All indexes are being used.');
        return;
    }

    const warn = document.createElement('p');
    warn.style.cssText = '  background:var(--warn-light); padding:8px 12px; border-radius:6px; margin-bottom:14px;';
    warn.textContent = `⚠ ${rows.length} unused index${rows.length !== 1 ? 'es' : ''} found. Unused indexes waste storage and slow down writes. Verify before dropping.`;
    body.appendChild(warn);

    body.appendChild(copyButton(() => rows.map(suggestionRow => suggestionRow.drop_sql).join('\n'), 'Copy All DROP SQL', false));

    const tableElement = mkTable();
    tableElement.style.marginTop = '12px';
    mkThead(tableElement, ['Table', 'Index', 'Scans', 'Size', 'DROP SQL', '']);
    const tbody = tableElement.createTBody();
    rows.forEach(suggestionRow => {
        const tr = tbody.insertRow();
        tr.appendChild(td(`"${suggestionRow.schemaname}"."${suggestionRow.tablename}"`));
        tr.appendChild(td(suggestionRow.indexname, 'font-family:var(--font-mono);'));
        tr.appendChild(td(suggestionRow.idx_scan));
        tr.appendChild(td(suggestionRow.index_size));
        const codeTd = document.createElement('td');
        codeTd.style.cssText = 'padding:8px 12px; border-bottom:1px solid var(--border); max-width:300px;';
        const code = document.createElement('code');
        code.style.cssText = ' background:var(--error-light); padding:3px 6px; border-radius:4px; display:block; overflow-x:auto; white-space:nowrap;';
        code.textContent = suggestionRow.drop_sql;
        codeTd.appendChild(code);
        tr.appendChild(codeTd);
        tr.appendChild(tdEl(copyButton(() => suggestionRow.drop_sql)));
    });
    tableElement.appendChild(tbody);
    body.appendChild(tableElement);
}

function renderSlowQueries(body, data) {
    body.replaceChildren();

    if (data.status === 'unavailable') {
        const paragraph = document.createElement('p');
        paragraph.style.cssText = ' ';
        paragraph.textContent = data.message;
        const code = document.createElement('code');
        code.style.cssText = 'display:block; margin-top:8px; padding:8px 12px; background:var(--bg); border-radius:4px; ';
        code.textContent = 'CREATE EXTENSION pg_stat_statements;';
        body.appendChild(paragraph);
        body.appendChild(code);
        return;
    }

    const rows = data.rows || [];
    if (!rows.length) {
        setBodyEmpty(body, 'No query statistics available. pg_stat_statements may have just been reset.');
        return;
    }

    const tableElement = mkTable();
    mkThead(tableElement, ['Avg ms', 'Total ms', 'Calls', 'Rows/call', 'Query']);
    const tbody = tableElement.createTBody();
    rows.forEach(suggestionRow => {
        const tr = tbody.insertRow();
        const avgMs = parseFloat(suggestionRow.mean_ms);
        const color = avgMs > 500 ? 'var(--error)' : avgMs > 100 ? 'var(--muted)' : 'inherit';
        tr.appendChild(td(suggestionRow.mean_ms + ' ms', `font-weight:600; color:${color};`));
        tr.appendChild(td(suggestionRow.total_ms + ' ms'));
        tr.appendChild(td(suggestionRow.calls));
        tr.appendChild(td(suggestionRow.calls > 0 ? Math.round(suggestionRow.rows / suggestionRow.calls) : '—'));
        const qtd = document.createElement('td');
        qtd.style.cssText = 'padding:8px 12px; border-bottom:1px solid var(--border); max-width:420px;';
        const code = document.createElement('code');
        code.style.cssText = ' display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--text);';
        code.title = suggestionRow.query;
        code.textContent = suggestionRow.query;
        qtd.appendChild(code);
        tr.appendChild(qtd);
    });
    tableElement.appendChild(tbody);
    body.appendChild(tableElement);
}

function renderTableStatistics(body, data) {
    body.replaceChildren();
    const rows = data.rows || [];

    if (!rows.length) {
        setBodyEmpty(body, 'No table statistics found for configured tables.');
        return;
    }

    const tableElement = mkTable();
    mkThead(tableElement, ['Table', 'Est. rows', 'Dead rows', 'Bloat %', 'Seq scans', 'Idx scans', 'Size', 'Last AutoVacuum', 'Last AutoAnalyze', '']);
    const tbody = tableElement.createTBody();
    rows.forEach(suggestionRow => {
        const tr = tbody.insertRow();
        const deadPct = parseFloat(suggestionRow.dead_pct);
        const bloatColor = deadPct > 20 ? 'var(--error)' : deadPct > 10 ? 'var(--muted)' : 'inherit';
        const seqScan = parseInt(suggestionRow.seq_scan) || 0;
        const indexScan = parseInt(suggestionRow.idx_scan) || 0;
        const scanColor = seqScan > 100 && seqScan > indexScan * 2 ? 'var(--muted)' : 'inherit';

        tr.appendChild(td(suggestionRow.tablename));
        tr.appendChild(td(Number(suggestionRow.estimated_rows).toLocaleString()));
        tr.appendChild(td(Number(suggestionRow.n_dead_tup).toLocaleString()));
        tr.appendChild(td(suggestionRow.dead_pct + '%', `font-weight:600; color:${bloatColor};`));
        tr.appendChild(td(seqScan.toLocaleString(), `color:${scanColor};`));
        tr.appendChild(td(indexScan.toLocaleString()));
        tr.appendChild(td(suggestionRow.total_size));
        tr.appendChild(td(suggestionRow.last_autovacuum || 'never'));
        tr.appendChild(td(suggestionRow.last_autoanalyze || 'never'));

        const vacuumSql = `VACUUM ANALYZE "${suggestionRow.schemaname}"."${suggestionRow.tablename}";`;
        tr.appendChild(tdEl(deadPct > 10 ? copyButton(() => vacuumSql, 'VACUUM') : null));
    });
    tableElement.appendChild(tbody);
    body.appendChild(tableElement);
}

function renderDbHealth(body, data) {
    body.replaceChildren();

    const databaseInfo        = data.db;
    const maxConn   = data.max_conn;
    const activeConn = data.active_conn;
    const cacheHit  = parseFloat(databaseInfo.cache_hit_ratio);
    const connPct   = maxConn > 0 ? Math.round(100 * activeConn / maxConn) : 0;

    const grid = document.createElement('div');
    grid.style.cssText = 'display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:20px;';

    function kpi(label, value, sub = '', color = 'inherit') {
        const card = document.createElement('div');
        card.style.cssText = 'border:1px solid var(--border); border-radius:6px; padding:14px 16px;';
        const valueDiv = document.createElement('div');
        valueDiv.style.cssText = ` font-weight:700; color:${color};`;
        valueDiv.textContent = value;
        const labelDiv = document.createElement('div');
        labelDiv.style.cssText = '  margin-top:2px;';
        labelDiv.textContent = label;
        card.appendChild(valueDiv);
        card.appendChild(labelDiv);
        if (sub) {
            const suggestion = document.createElement('div');
            suggestion.style.cssText = '  margin-top:4px;';
            suggestion.textContent = sub;
            card.appendChild(suggestion);
        }
        return card;
    }

    grid.appendChild(kpi(
        'Cache Hit Ratio',
        cacheHit + '%',
        'target: > 99%',
        cacheHit >= 99 ? 'var(--ok)' : cacheHit >= 95 ? 'var(--warn)' : 'var(--error)'
    ));
    grid.appendChild(kpi(
        'Active Connections',
        activeConn + ' / ' + maxConn,
        connPct + '% of max_connections',
        connPct > 80 ? 'var(--error)' : connPct > 60 ? 'var(--warn)' : 'var(--ok)'
    ));
    grid.appendChild(kpi('DB Size', databaseInfo.db_size));
    grid.appendChild(kpi('Committed Txns', Number(databaseInfo.xact_commit).toLocaleString()));
    grid.appendChild(kpi(
        'Deadlocks',
        databaseInfo.deadlocks,
        '',
        parseInt(databaseInfo.deadlocks) > 0 ? 'var(--error)' : 'var(--ok)'
    ));
    grid.appendChild(kpi('Rollbacks', Number(databaseInfo.xact_rollback).toLocaleString()));

    body.appendChild(grid);

    if (data.pg_version) {
        const versionParagraph = document.createElement('p');
        versionParagraph.style.cssText = '  margin:0;';
        versionParagraph.textContent = data.pg_version;
        body.appendChild(versionParagraph);
    }
}

function renderSchemaWarnings(body, data) {
    body.replaceChildren();
    const warnings = data.warnings || [];

    if (!warnings.length) {
        setBodyEmpty(body, 'No schema configuration issues detected.');
        return;
    }

    const sum = document.createElement('p');
    sum.style.cssText = ' margin-bottom:12px;';
    sum.textContent = `${warnings.length} warning${warnings.length !== 1 ? 's' : ''} found.`;
    body.appendChild(sum);

    const tableElement = mkTable();
    mkThead(tableElement, ['Severity', 'Category', 'Table', 'Issue']);
    const tbody = tableElement.createTBody();
    warnings.forEach(warning => {
        const tr = tbody.insertRow();
        tr.appendChild(tdEl(severityBadge(warning.severity)));
        tr.appendChild(td(warning.category, 'white-space:nowrap;'));
        tr.appendChild(td(warning.display || warning.table, 'font-weight:600; white-space:nowrap;'));
        tr.appendChild(td(warning.message));
    });
    tableElement.appendChild(tbody);
    body.appendChild(tableElement);
}

async function runSection(apiAction, renderFn, button, body) {
    button.disabled = true;
    button.textContent = 'Scanning…';
    setBodyLoading(body);
    try {
        const result = await apiFetch(`api.php?action=${apiAction}`);
        if (!result.ok) throw new Error(`HTTP ${result.status}`);
        const data = await result.json();
        if (data.status === 'error') throw new Error(data.error || 'Server error');
        renderFn(body, data);
    } catch (error) {
        setBodyError(body, 'Error: ' + error.message);
    } finally {
        button.disabled = false;
        button.textContent = 'Scan';
    }
}

export function renderPerformancePage(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.replaceChildren();

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    workspaceElement.appendChild(wrap);

    wrap.appendChild(createPageHeader('Performance', 'Scan for missing/unused indexes, slow queries, table bloat, database health, and schema configuration warnings.'));

    const topRow = document.createElement('div');
    topRow.style.cssText = 'display:flex; align-items:center; gap:14px; margin-bottom:20px;';

    const buttonAll = document.createElement('button');
    buttonAll.className = 'btn btn-primary';
    buttonAll.textContent = 'Run All';
    topRow.appendChild(buttonAll);
    wrap.appendChild(topRow);

    const sections = [
        {
            label:  'Index Advisor',
            icon:   'search.png',
            title:  '1. Missing Index Advisor',
            desc:   'Detects columns needing indexes: foreign keys, subtable joins, default sort, widget filters.',
            action: 'performance_check',
            render: renderIndexAdvisor,
        },
        {
            label:  'Unused Indexes',
            icon:   'data_thresholding.png',
            title:  '2. Unused Indexes',
            desc:   'Finds existing indexes with zero scans — candidates for removal to speed up writes.',
            action: 'performance_unused_indexes',
            render: renderUnusedIndexes,
        },
        {
            label:  'Slow Queries',
            icon:   'watch_screentime.png',
            title:  '3. Slow Query Analyzer',
            desc:   'Top 15 slowest queries by avg execution time (requires pg_stat_statements extension).',
            action: 'performance_slow_queries',
            render: renderSlowQueries,
        },
        {
            label:  'Table Stats',
            icon:   'data_table.png',
            title:  '4. Table Statistics & Bloat',
            desc:   'Dead row ratio, seq vs index scans, last vacuum/analyze per table.',
            action: 'performance_table_stats',
            render: renderTableStatistics,
        },
        {
            label:  'DB Health',
            icon:   'health_and_safety.png',
            title:  '5. Database Health',
            desc:   'Cache hit ratio, connection usage, deadlocks, committed transactions.',
            action: 'performance_db_health',
            render: renderDbHealth,
        },
        {
            label:  'Schema Warnings',
            icon:   'checklist_rtl.png',
            title:  '6. Schema Configuration Warnings',
            desc:   'Tables missing load limits, widgets without row caps, subtables without column lists.',
            action: 'performance_schema_warnings',
            render: renderSchemaWarnings,
        },
    ];

    const panels = buildInnerTabs(wrap, sections);

    const built = sections.map((suggestion, i) => {
        const { card, btn: button, body } = makeSection(suggestion.title, suggestion.desc);
        card.id = `perf-section-${i}`;
        button.addEventListener('click', () => runSection(suggestion.action, suggestion.render, button, body));
        panels[i].appendChild(card);
        return { btn: button, body, ...suggestion };
    });

    buttonAll.addEventListener('click', async () => {
        buttonAll.disabled = true;
        buttonAll.textContent = 'Running…';
        await Promise.all(built.map(suggestion => runSection(suggestion.action, suggestion.render, suggestion.btn, suggestion.body)));
        buttonAll.disabled = false;
        buttonAll.textContent = 'Run All';
    });
}
