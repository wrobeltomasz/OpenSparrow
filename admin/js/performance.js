// admin/performance.js — Performance & Index Advisor
import { buildInnerTabs } from './ui.js';

function escHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
}

function severityBadge(sev) {
    const b = document.createElement('span');
    b.className = `adm-badge adm-badge-${sev in { high:1, medium:1, low:1 } ? sev : 'muted'}`;
    b.textContent = sev.toUpperCase();
    return b;
}

function copyBtn(getText, label = 'Copy SQL', small = true) {
    const btn = document.createElement('button');
    btn.className = 'btn-action';
    btn.style.cssText = small ? 'padding:3px 10px; font-size:12px; white-space:nowrap;' : 'padding:6px 16px; font-size:13px;';
    btn.textContent = label;
    btn.addEventListener('click', () => {
        navigator.clipboard.writeText(getText()).then(() => {
            const orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(() => { btn.textContent = orig; }, 2000);
        });
    });
    return btn;
}

function mkThead(table, cols) {
    const thead = table.createTHead();
    const tr = thead.insertRow();
    cols.forEach(h => {
        const th = document.createElement('th');
        th.className = 'adm-th';
        th.textContent = h;
        tr.appendChild(th);
    });
}

function mkTable() {
    const t = document.createElement('table');
    t.className = 'adm-tbl';
    return t;
}

function td(text, extra = '') {
    const el = document.createElement('td');
    el.className = 'adm-td';
    if (extra) el.style.cssText = extra.replace(/^[;\s]+/, '');
    el.textContent = text ?? '—';
    return el;
}

function tdEl(child, extra = '') {
    const el = document.createElement('td');
    el.className = 'adm-td';
    if (extra) el.style.cssText = extra.replace(/^[;\s]+/, '');
    if (child) el.appendChild(child);
    return el;
}

// ─── Section builder ────────────────────────────────────────────────────────

function makeSection(title, description) {
    const card = document.createElement('div');
    card.className = 'adm-sec-card';

    const hdr = document.createElement('div');
    hdr.className = 'adm-sec-hdr';

    const hdrLeft = document.createElement('div');
    const h3 = document.createElement('h3');
    h3.textContent = title;
    h3.style.cssText = 'margin:0 0 4px; font-size:15px;';
    const desc = document.createElement('p');
    desc.textContent = description;
    desc.style.cssText = 'margin:0; font-size:12px; color:var(--muted);';
    hdrLeft.appendChild(h3);
    hdrLeft.appendChild(desc);

    const btn = document.createElement('button');
    btn.className = 'btn-action';
    btn.style.cssText = 'padding:6px 16px; white-space:nowrap;';
    btn.textContent = 'Scan';

    hdr.appendChild(hdrLeft);
    hdr.appendChild(btn);
    card.appendChild(hdr);

    const body = document.createElement('div');
    body.className = 'adm-sec-body';
    const placeholder = document.createElement('p');
    placeholder.className = 'c-muted';
    placeholder.style.cssText = 'font-size:13px; margin:0;';
    placeholder.textContent = 'Click Scan to run analysis.';
    body.appendChild(placeholder);
    card.appendChild(body);

    return { card, btn, body };
}

function setBodyLoading(body) {
    body.replaceChildren();
    const p = document.createElement('p');
    p.style.cssText = 'color:var(--muted); font-size:13px; margin:0;';
    p.textContent = 'Scanning…';
    body.appendChild(p);
}

function setBodyError(body, msg) {
    body.replaceChildren();
    const p = document.createElement('p');
    p.style.cssText = 'color:var(--danger); font-size:13px; margin:0;';
    p.textContent = msg;
    body.appendChild(p);
}

function setBodyEmpty(body, msg) {
    body.replaceChildren();
    const p = document.createElement('p');
    p.style.cssText = 'color:#2b9348; font-weight:600; font-size:13px; margin:0;';
    p.textContent = '�s� ' + msg;
    body.appendChild(p);
}

// ─── 1. Index Advisor ───────────────────────────────────────────────────────

function renderIndexAdvisor(body, data) {
    body.replaceChildren();
    const suggestions = data.suggestions || [];

    if (!suggestions.length) {
        setBodyEmpty(body, 'No missing indexes detected.');
        return;
    }

    const row = document.createElement('div');
    row.style.cssText = 'display:flex; align-items:center; gap:12px; margin-bottom:14px;';
    const high = suggestions.filter(s => s.priority === 'high').length;
    const sum = document.createElement('span');
    sum.style.cssText = 'font-size:13px;';
    sum.textContent = `${suggestions.length} suggestion${suggestions.length !== 1 ? 's' : ''} · ${high} high priority`;
    row.appendChild(sum);
    row.appendChild(copyBtn(() => suggestions.map(s => s.sql).join('\n'), 'Copy All SQL', false));
    body.appendChild(row);

    const byTable = new Map();
    suggestions.forEach(s => {
        const k = `"${s.schema}"."${s.table}"`;
        if (!byTable.has(k)) byTable.set(k, []);
        byTable.get(k).push(s);
    });

    byTable.forEach((rows, tableKey) => {
        const grp = document.createElement('div');
        grp.style.cssText = 'margin-bottom:16px; border:1px solid var(--border); border-radius:6px; overflow:hidden;';

        const gh = document.createElement('div');
        gh.style.cssText = 'padding:8px 12px; background:#c2dfe3; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; font-family:monospace; font-size:13px; font-weight:600;';
        const ghText = document.createElement('span');
        ghText.textContent = tableKey;
        gh.appendChild(ghText);
        gh.appendChild(copyBtn(() => rows.map(r => r.sql).join('\n'), 'Copy table SQL'));
        grp.appendChild(gh);

        const t = mkTable();
        mkThead(t, ['Priority', 'Column', 'Reason(s)', 'SQL', '']);
        const tbody = t.createTBody();
        rows.forEach(s => {
            const tr = tbody.insertRow();
            tr.appendChild(tdEl(severityBadge(s.priority)));
            tr.appendChild(td(s.column, 'font-family:monospace; font-weight:600;'));
            tr.appendChild(td(s.reasons.join(' · ')));
            const codeTd = document.createElement('td');
            codeTd.style.cssText = 'padding:8px 12px; border-bottom:1px solid #c2dfe3; max-width:340px;';
            const code = document.createElement('code');
            code.style.cssText = 'font-size:11px; background:#c2dfe3; padding:3px 6px; border-radius:4px; display:block; overflow-x:auto; white-space:nowrap;';
            code.textContent = s.sql;
            codeTd.appendChild(code);
            tr.appendChild(codeTd);
            tr.appendChild(tdEl(copyBtn(() => s.sql)));
        });
        grp.appendChild(t);
        body.appendChild(grp);
    });
}

// ─── 2. Unused Indexes ──────────────────────────────────────────────────────

function renderUnusedIndexes(body, data) {
    body.replaceChildren();
    const rows = data.rows || [];

    if (!rows.length) {
        setBodyEmpty(body, 'No unused indexes found. All indexes are being used.');
        return;
    }

    const warn = document.createElement('p');
    warn.style.cssText = 'font-size:13px; color:#5c6b73; background:rgba(255,195,0,0.12); padding:8px 12px; border-radius:6px; margin-bottom:14px;';
    warn.textContent = `⚠ ${rows.length} unused index${rows.length !== 1 ? 'es' : ''} found. Unused indexes waste storage and slow down writes. Verify before dropping.`;
    body.appendChild(warn);

    body.appendChild(copyBtn(() => rows.map(r => r.drop_sql).join('\n'), 'Copy All DROP SQL', false));

    const t = mkTable();
    t.style.marginTop = '12px';
    mkThead(t, ['Table', 'Index', 'Scans', 'Size', 'DROP SQL', '']);
    const tbody = t.createTBody();
    rows.forEach(r => {
        const tr = tbody.insertRow();
        tr.appendChild(td(`"${r.schemaname}"."${r.tablename}"`));
        tr.appendChild(td(r.indexname, 'font-family:monospace;'));
        tr.appendChild(td(r.idx_scan));
        tr.appendChild(td(r.index_size));
        const codeTd = document.createElement('td');
        codeTd.style.cssText = 'padding:8px 12px; border-bottom:1px solid #c2dfe3; max-width:300px;';
        const code = document.createElement('code');
        code.style.cssText = 'font-size:11px; background:rgba(208,0,0,0.08); padding:3px 6px; border-radius:4px; display:block; overflow-x:auto; white-space:nowrap;';
        code.textContent = r.drop_sql;
        codeTd.appendChild(code);
        tr.appendChild(codeTd);
        tr.appendChild(tdEl(copyBtn(() => r.drop_sql)));
    });
    t.appendChild(tbody);
    body.appendChild(t);
}

// ─── 3. Slow Queries ────────────────────────────────────────────────────────

function renderSlowQueries(body, data) {
    body.replaceChildren();

    if (data.status === 'unavailable') {
        const p = document.createElement('p');
        p.style.cssText = 'font-size:13px; color:var(--muted);';
        p.textContent = data.message;
        const code = document.createElement('code');
        code.style.cssText = 'display:block; margin-top:8px; padding:8px 12px; background:#c2dfe3; border-radius:4px; font-size:12px;';
        code.textContent = 'CREATE EXTENSION pg_stat_statements;';
        body.appendChild(p);
        body.appendChild(code);
        return;
    }

    const rows = data.rows || [];
    if (!rows.length) {
        setBodyEmpty(body, 'No query statistics available. pg_stat_statements may have just been reset.');
        return;
    }

    const t = mkTable();
    mkThead(t, ['Avg ms', 'Total ms', 'Calls', 'Rows/call', 'Query']);
    const tbody = t.createTBody();
    rows.forEach(r => {
        const tr = tbody.insertRow();
        const avgMs = parseFloat(r.mean_ms);
        const color = avgMs > 500 ? '#a80000' : avgMs > 100 ? '#5c6b73' : 'inherit';
        tr.appendChild(td(r.mean_ms + ' ms', `font-weight:600; color:${color};`));
        tr.appendChild(td(r.total_ms + ' ms'));
        tr.appendChild(td(r.calls));
        tr.appendChild(td(r.calls > 0 ? Math.round(r.rows / r.calls) : '—'));
        const qtd = document.createElement('td');
        qtd.style.cssText = 'padding:8px 12px; border-bottom:1px solid #c2dfe3; max-width:420px;';
        const code = document.createElement('code');
        code.style.cssText = 'font-size:11px; display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--text);';
        code.title = r.query;
        code.textContent = r.query;
        qtd.appendChild(code);
        tr.appendChild(qtd);
    });
    t.appendChild(tbody);
    body.appendChild(t);
}

// ─── 4. Table Statistics & Bloat ────────────────────────────────────────────

function renderTableStats(body, data) {
    body.replaceChildren();
    const rows = data.rows || [];

    if (!rows.length) {
        setBodyEmpty(body, 'No table statistics found for schema.json tables.');
        return;
    }

    const t = mkTable();
    mkThead(t, ['Table', 'Est. rows', 'Dead rows', 'Bloat %', 'Seq scans', 'Idx scans', 'Size', 'Last AutoVacuum', 'Last AutoAnalyze', '']);
    const tbody = t.createTBody();
    rows.forEach(r => {
        const tr = tbody.insertRow();
        const deadPct = parseFloat(r.dead_pct);
        const bloatColor = deadPct > 20 ? '#a80000' : deadPct > 10 ? '#5c6b73' : 'inherit';
        const seqScan = parseInt(r.seq_scan) || 0;
        const idxScan = parseInt(r.idx_scan) || 0;
        const scanColor = seqScan > 100 && seqScan > idxScan * 2 ? '#5c6b73' : 'inherit';

        tr.appendChild(td(r.tablename));
        tr.appendChild(td(Number(r.estimated_rows).toLocaleString()));
        tr.appendChild(td(Number(r.n_dead_tup).toLocaleString()));
        tr.appendChild(td(r.dead_pct + '%', `font-weight:600; color:${bloatColor};`));
        tr.appendChild(td(seqScan.toLocaleString(), `color:${scanColor};`));
        tr.appendChild(td(idxScan.toLocaleString()));
        tr.appendChild(td(r.total_size));
        tr.appendChild(td(r.last_autovacuum || 'never'));
        tr.appendChild(td(r.last_autoanalyze || 'never'));

        const vacuumSql = `VACUUM ANALYZE "${r.schemaname}"."${r.tablename}";`;
        tr.appendChild(tdEl(deadPct > 10 ? copyBtn(() => vacuumSql, 'VACUUM') : null));
    });
    t.appendChild(tbody);
    body.appendChild(t);
}

// ─── 5. DB Health ───────────────────────────────────────────────────────────

function renderDbHealth(body, data) {
    body.replaceChildren();

    const db        = data.db;
    const maxConn   = data.max_conn;
    const activeConn = data.active_conn;
    const cacheHit  = parseFloat(db.cache_hit_ratio);
    const connPct   = maxConn > 0 ? Math.round(100 * activeConn / maxConn) : 0;

    const grid = document.createElement('div');
    grid.style.cssText = 'display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:20px;';

    function kpi(label, value, sub = '', color = 'inherit') {
        const card = document.createElement('div');
        card.style.cssText = 'border:1px solid var(--border); border-radius:6px; padding:14px 16px;';
        const v = document.createElement('div');
        v.style.cssText = `font-size:22px; font-weight:700; color:${color};`;
        v.textContent = value;
        const l = document.createElement('div');
        l.style.cssText = 'font-size:12px; color:var(--muted); margin-top:2px;';
        l.textContent = label;
        card.appendChild(v);
        card.appendChild(l);
        if (sub) {
            const s = document.createElement('div');
            s.style.cssText = 'font-size:11px; color:var(--muted); margin-top:4px;';
            s.textContent = sub;
            card.appendChild(s);
        }
        return card;
    }

    grid.appendChild(kpi(
        'Cache Hit Ratio',
        cacheHit + '%',
        'target: > 99%',
        cacheHit >= 99 ? '#2b9348' : cacheHit >= 95 ? '#ffc300' : '#d00000'
    ));
    grid.appendChild(kpi(
        'Active Connections',
        activeConn + ' / ' + maxConn,
        connPct + '% of max_connections',
        connPct > 80 ? '#d00000' : connPct > 60 ? '#ffc300' : '#2b9348'
    ));
    grid.appendChild(kpi('DB Size', db.db_size));
    grid.appendChild(kpi('Committed Txns', Number(db.xact_commit).toLocaleString()));
    grid.appendChild(kpi(
        'Deadlocks',
        db.deadlocks,
        '',
        parseInt(db.deadlocks) > 0 ? '#d00000' : '#2b9348'
    ));
    grid.appendChild(kpi('Rollbacks', Number(db.xact_rollback).toLocaleString()));

    body.appendChild(grid);

    if (data.pg_version) {
        const ver = document.createElement('p');
        ver.style.cssText = 'font-size:12px; color:var(--muted); margin:0;';
        ver.textContent = data.pg_version;
        body.appendChild(ver);
    }
}

// ─── 6. Schema Warnings ─────────────────────────────────────────────────────

function renderSchemaWarnings(body, data) {
    body.replaceChildren();
    const warnings = data.warnings || [];

    if (!warnings.length) {
        setBodyEmpty(body, 'No schema configuration issues detected.');
        return;
    }

    const sum = document.createElement('p');
    sum.style.cssText = 'font-size:13px; margin-bottom:12px;';
    sum.textContent = `${warnings.length} warning${warnings.length !== 1 ? 's' : ''} found.`;
    body.appendChild(sum);

    const t = mkTable();
    mkThead(t, ['Severity', 'Category', 'Table', 'Issue']);
    const tbody = t.createTBody();
    warnings.forEach(w => {
        const tr = tbody.insertRow();
        tr.appendChild(tdEl(severityBadge(w.severity)));
        tr.appendChild(td(w.category, 'white-space:nowrap;'));
        tr.appendChild(td(w.display || w.table, 'font-weight:600; white-space:nowrap;'));
        tr.appendChild(td(w.message));
    });
    t.appendChild(tbody);
    body.appendChild(t);
}

// ─── Main render ────────────────────────────────────────────────────────────

async function runSection(apiAction, renderFn, btn, body) {
    btn.disabled = true;
    btn.textContent = 'Scanning…';
    setBodyLoading(body);
    try {
        const res = await fetch(`api.php?action=${apiAction}`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (data.status === 'error') throw new Error(data.error || 'Server error');
        renderFn(body, data);
    } catch (err) {
        setBodyError(body, 'Error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Scan';
    }
}

export function renderPerformancePage(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.replaceChildren();

    const h2 = document.createElement('h2');
    h2.textContent = 'Performance';
    h2.style.marginTop = '0';
    workspaceEl.appendChild(h2);

    const topRow = document.createElement('div');
    topRow.style.cssText = 'display:flex; align-items:center; gap:14px; margin-bottom:20px;';

    const btnAll = document.createElement('button');
    btnAll.className = 'btn-action';
    btnAll.style.cssText = 'padding:8px 20px; font-size:14px;';
    btnAll.textContent = 'Run All';
    topRow.appendChild(btnAll);
    workspaceEl.appendChild(topRow);

    const tabContainer = document.createElement('div');
    workspaceEl.appendChild(tabContainer);

    const sections = [
        {
            label:  'Index Advisor',
            title:  '1. Missing Index Advisor',
            desc:   'Detects columns needing indexes: foreign keys, subtable joins, default sort, widget filters.',
            action: 'performance_check',
            render: renderIndexAdvisor,
        },
        {
            label:  'Unused Indexes',
            title:  '2. Unused Indexes',
            desc:   'Finds existing indexes with zero scans — candidates for removal to speed up writes.',
            action: 'performance_unused_indexes',
            render: renderUnusedIndexes,
        },
        {
            label:  'Slow Queries',
            title:  '3. Slow Query Analyzer',
            desc:   'Top 15 slowest queries by avg execution time (requires pg_stat_statements extension).',
            action: 'performance_slow_queries',
            render: renderSlowQueries,
        },
        {
            label:  'Table Stats',
            title:  '4. Table Statistics & Bloat',
            desc:   'Dead row ratio, seq vs index scans, last vacuum/analyze per table.',
            action: 'performance_table_stats',
            render: renderTableStats,
        },
        {
            label:  'DB Health',
            title:  '5. Database Health',
            desc:   'Cache hit ratio, connection usage, deadlocks, committed transactions.',
            action: 'performance_db_health',
            render: renderDbHealth,
        },
        {
            label:  'Schema Warnings',
            title:  '6. Schema Configuration Warnings',
            desc:   'Tables missing load limits, widgets without row caps, subtables without column lists.',
            action: 'performance_schema_warnings',
            render: renderSchemaWarnings,
        },
    ];

    const panels = buildInnerTabs(tabContainer, sections);

    const built = sections.map((s, i) => {
        const { card, btn, body } = makeSection(s.title, s.desc);
        card.id = `perf-section-${i}`;
        btn.addEventListener('click', () => runSection(s.action, s.render, btn, body));
        panels[i].appendChild(card);
        return { btn, body, ...s };
    });

    btnAll.addEventListener('click', async () => {
        btnAll.disabled = true;
        btnAll.textContent = 'Running…';
        await Promise.all(built.map(s => runSection(s.action, s.render, s.btn, s.body)));
        btnAll.disabled = false;
        btnAll.textContent = 'Run All';
    });
}
