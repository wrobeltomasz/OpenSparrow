/* assets/js/views.js — frontend views module */

import { sortRows } from './grid/state.js';

/* ── colour rule engine ── */
function applyColorRules(rawValue, rules) {
    if (!Array.isArray(rules) || rules.length === 0) return null;
    const num = parseFloat(rawValue);
    if (isNaN(num)) return null;
    for (const rule of rules) {
        const v = parseFloat(rule.value);
        if (isNaN(v)) continue;
        if (rule.op === '>'  && num >  v) return rule.color;
        if (rule.op === '>=' && num >= v) return rule.color;
        if (rule.op === '<'  && num <  v) return rule.color;
        if (rule.op === '<=' && num <= v) return rule.color;
        if (rule.op === '==' && num === v) return rule.color;
    }
    return null;
}

/* ── module-level state ── */
let drillStack     = [];
let viewSortState  = { column: null, asc: true };
let viewSearchTerm = '';
let searchTimer    = null;
let _searchHandler = null;

/* ── DOM refs ── */
const breadcrumbEl = document.getElementById('viewBreadcrumb');
const containerEl  = document.getElementById('viewContainer');
const searchEl     = document.getElementById('globalSearch');
let exportBtn = null;

/* ── fetch wrapper ── */
async function apiFetch(url) {
    const res  = await fetch(url);
    const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error ?? `HTTP ${res.status}`);
    return data;
}

/* ── breadcrumb (drillStack entries only, hidden at view root) ── */
function renderBreadcrumb() {
    breadcrumbEl.innerHTML = '';
    if (drillStack.length <= 1) return;
    drillStack.forEach((entry, idx) => {
        if (idx > 0) {
            const sep = document.createElement('span');
            sep.className   = 'vw-breadcrumb-sep';
            sep.textContent = '/';
            breadcrumbEl.appendChild(sep);
        }
        if (idx < drillStack.length - 1) {
            const a = document.createElement('span');
            a.className   = 'vw-breadcrumb-item';
            a.textContent = entry.label ?? entry.viewName;
            a.addEventListener('click', () => drillTo(idx));
            breadcrumbEl.appendChild(a);
        } else {
            const cur = document.createElement('span');
            cur.className   = 'vw-breadcrumb-current';
            cur.textContent = entry.label ?? entry.viewName;
            breadcrumbEl.appendChild(cur);
        }
    });
}

/* ── disconnect search/export from current view ── */
function _clearHandlers() {
    if (searchEl && _searchHandler) {
        searchEl.removeEventListener('input', _searchHandler);
        _searchHandler = null;
    }
    if (exportBtn) { exportBtn.onclick = null; exportBtn.style.display = 'none'; }
}

/* ── back to selector ── */
function showSelector() {
    _clearHandlers();
    if (searchEl) searchEl.value = '';
    viewSearchTerm = '';
    drillStack = [];
    loadViewSelector();
}

/* ── navigate to a stack level ── */
function drillTo(idx) {
    drillStack = drillStack.slice(0, idx + 1);
    const entry = drillStack[idx];
    loadView(entry.viewName, entry.level, entry.filterCol, entry.filterVal);
}

/* ── drill down into a group ── */
function drillDown(viewName, level, filterCol, filterVal, displayLabel) {
    drillStack.push({ viewName, level: level + 1, filterCol, filterVal, label: `${filterCol}: ${displayLabel}` });
    loadView(viewName, level + 1, filterCol, filterVal);
}

/* ── drill up one level ── */
function drillUp() {
    if (drillStack.length > 1) {
        drillStack.pop();
        const entry = drillStack[drillStack.length - 1];
        loadView(entry.viewName, entry.level, entry.filterCol, entry.filterVal);
    } else {
        showSelector();
    }
}

/* ── load and render view data ── */
async function loadView(viewName, level, filterCol, filterVal) {
    _clearHandlers();
    clearTimeout(searchTimer);
    viewSortState = { column: null, asc: true };

    containerEl.innerHTML = '<div class="vw-loading">Loading…</div>';
    renderBreadcrumb();

    let url = `api_views.php?action=data&view=${encodeURIComponent(viewName)}&level=${level}`;
    if (filterCol) url += `&filter_col=${encodeURIComponent(filterCol)}&filter_val=${encodeURIComponent(filterVal ?? '')}`;

    try {
        const data = await apiFetch(url);
        renderView(data);
    } catch (err) {
        containerEl.innerHTML = `<div class="vw-error">Error: ${err.message}</div>`;
    }
}

/* ── render the view table (grid-identical structure) ── */
function renderView(data) {
    containerEl.innerHTML = '';
    const { view, display_name, level, max_level, group_by, drill_enabled, rows, columns, icon } = data;

    /* ── branded header bar ── */
    const hdr = document.createElement('div');
    hdr.className = 'vw-header';

    if (icon) {
        const img = document.createElement('img');
        img.src = icon; img.alt = '';
        hdr.appendChild(img);
    }
    const titleEl = document.createElement('h2');
    titleEl.className   = 'vw-title';
    titleEl.textContent = display_name;
    hdr.appendChild(titleEl);

    const meta = document.createElement('span');
    meta.className   = 'vw-meta';
    meta.textContent = `${rows.length} row${rows.length !== 1 ? 's' : ''}`;
    hdr.appendChild(meta);

    const backBtn = document.createElement('button');
    backBtn.className        = 'vw-drill-up';
    backBtn.style.marginLeft = 'auto';
    backBtn.textContent      = '◀ Back';
    backBtn.addEventListener('click', drillUp);
    hdr.appendChild(backBtn);

    containerEl.appendChild(hdr);
    renderBreadcrumb();

    if (rows.length === 0) {
        containerEl.insertAdjacentHTML('beforeend', '<div class="vw-empty">No data found.</div>');
        return;
    }

    const allKeys      = Object.keys(rows[0]);
    const canDrillDown = drill_enabled && level < max_level && group_by != null;
    let currentFilteredRows = [];

    /* ── table — same HTML as grid ── */
    const tableWrap = document.createElement('div');
    tableWrap.className = 'vw-table-wrap';

    const table = document.createElement('table');

    const thead     = document.createElement('thead');
    const headerRow = document.createElement('tr');

    function updateThLabels() {
        headerRow.childNodes.forEach(th => {
            if (th.nodeType !== Node.ELEMENT_NODE) return;
            const k       = th.dataset.col;
            const lbl     = columns[k]?.display_name ?? k;
            const ind     = viewSortState.column === k ? (viewSortState.asc ? ' ↑' : ' ↓') : '';
            const thLabel = th.querySelector('.th-label');
            if (thLabel) thLabel.textContent = lbl + ind;
        });
    }

    allKeys.forEach(key => {
        const th = document.createElement('th');
        th.dataset.col  = key;
        th.style.cursor = 'pointer';
        th.title        = 'Click to sort';

        const thLabel = document.createElement('span');
        thLabel.className   = 'th-label';
        thLabel.textContent = columns[key]?.display_name ?? key;
        th.appendChild(thLabel);

        th.addEventListener('click', () => {
            if (viewSortState.column === key) {
                if (viewSortState.asc) viewSortState.asc = false;
                else { viewSortState.column = null; viewSortState.asc = true; }
            } else {
                viewSortState.column = key;
                viewSortState.asc = true;
            }
            updateThLabels();
            applyViewFilters();
        });
        headerRow.appendChild(th);
    });

    thead.appendChild(headerRow);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    table.appendChild(tbody);
    tableWrap.appendChild(table);
    containerEl.appendChild(tableWrap);

    /* ── export button below table, left-aligned ── */
    exportBtn = document.createElement('button');
    exportBtn.id        = 'exportCsv';
    exportBtn.className = 'vw-export-btn';
    exportBtn.textContent = 'Export CSV';
    containerEl.appendChild(exportBtn);

    /* ── filter + sort + populate tbody ── */
    function applyViewFilters() {
        let result = rows;
        if (viewSearchTerm) {
            const term = viewSearchTerm.toLowerCase();
            result = result.filter(row =>
                Object.values(row).some(v => String(v ?? '').toLowerCase().includes(term))
            );
        }
        result = sortRows(result, viewSortState);
        currentFilteredRows = result;

        tbody.innerHTML = '';
        result.forEach(row => {
            const tr = document.createElement('tr');
            if (canDrillDown) tr.classList.add('vw-drillable');

            allKeys.forEach(key => {
                const td     = document.createElement('td');
                const rawVal = row[key];
                const colCfg = columns[key];
                const rules  = colCfg?.color_rules ?? [];
                const color  = applyColorRules(rawVal, rules);

                if (color) {
                    const chip = document.createElement('span');
                    chip.className        = 'vw-value-chip';
                    chip.style.background = color;
                    chip.textContent      = rawVal ?? '';
                    td.appendChild(chip);
                } else {
                    td.textContent = rawVal ?? '';
                }
                tr.appendChild(td);
            });

            if (canDrillDown) {
                tr.addEventListener('click', () => {
                    const drillVal = row[group_by];
                    drillDown(view, level, group_by, drillVal, String(drillVal));
                });
            }
            tbody.appendChild(tr);
        });

        const filteredCount = viewSearchTerm ? ` (filtered from ${rows.length})` : '';
        meta.textContent = `${result.length} row${result.length !== 1 ? 's' : ''}${filteredCount}`;
    }

    /* ── wire #globalSearch ── */
    if (searchEl) {
        searchEl.value   = viewSearchTerm;
        _searchHandler   = () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                viewSearchTerm = searchEl.value;
                applyViewFilters();
            }, 300);
        };
        searchEl.addEventListener('input', _searchHandler);
    }

    /* ── wire #exportCsv ── */
    if (exportBtn) {
        exportBtn.style.display = '';
        exportBtn.onclick = () => {
            const headers = allKeys.map(k => columns[k]?.display_name ?? k);
            const escape  = v => JSON.stringify(String(v ?? ''));
            const lines   = [
                headers.map(escape).join(','),
                ...currentFilteredRows.map(row => allKeys.map(k => escape(row[k])).join(',')),
            ];
            const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href = url; a.download = `${view}.csv`; a.click();
            URL.revokeObjectURL(url);
        };
    }

    applyViewFilters();
}

/* ── load list of all views and show selector ── */
async function loadViewSelector() {
    containerEl.innerHTML = '<div class="vw-loading">Loading views…</div>';
    renderBreadcrumb();
    try {
        const data = await apiFetch('api_views.php?action=list');
        if (!data.views || data.views.length === 0) {
            containerEl.innerHTML = '<div class="vw-empty">No views configured. Ask an administrator to set up views.</div>';
            return;
        }
        renderSelector(data.views);
    } catch (err) {
        containerEl.innerHTML = `<div class="vw-error">Error: ${err.message}</div>`;
    }
}

/* ── render view selector cards (workflows-style) ── */
function renderSelector(views) {
    containerEl.innerHTML = '';
    const grid = document.createElement('div');
    grid.style.cssText = 'display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:24px; padding:24px;';

    views.forEach(v => {
        const card = document.createElement('div');
        card.className = 'vw-selector-card';

        const header = document.createElement('div');
        header.style.cssText = 'display:flex; align-items:center; gap:14px; margin-bottom:14px;';

        const iconWrapper = document.createElement('div');
        iconWrapper.style.cssText = 'display:flex; align-items:center; justify-content:center; width:42px; height:42px; background:var(--accent-light); border-radius:8px; flex-shrink:0;';
        if (v.icon) {
            const img = document.createElement('img');
            img.src = v.icon; img.alt = '';
            img.style.cssText = 'width:22px; height:22px; object-fit:contain;';
            iconWrapper.appendChild(img);
        } else {
            const dot = document.createElement('div');
            dot.style.cssText = 'width:22px; height:22px; background:var(--accent); border-radius:50%;';
            iconWrapper.appendChild(dot);
        }

        const cardTitle = document.createElement('h3');
        cardTitle.style.cssText = 'margin:0; color:var(--accent-dark); font-size:1.1rem; font-weight:600;';
        cardTitle.textContent = v.display_name ?? v.name;

        header.appendChild(iconWrapper);
        header.appendChild(cardTitle);

        const cardDesc = document.createElement('p');
        cardDesc.style.cssText = 'color:var(--muted); font-size:14px; margin:0 0 20px; line-height:1.5; flex-grow:1;';
        cardDesc.textContent = v.description || 'Click to open this view.';

        const footer = document.createElement('div');
        footer.style.cssText = 'display:flex; align-items:center; justify-content:flex-end; margin-top:auto; padding-top:16px; border-top:1px solid var(--border-light);';
        const openLink = document.createElement('span');
        openLink.style.cssText = 'font-size:13.5px; color:var(--accent); font-weight:600;';
        openLink.textContent = 'Open View →';
        footer.appendChild(openLink);

        card.appendChild(header);
        card.appendChild(cardDesc);
        card.appendChild(footer);

        card.addEventListener('click', () => initView(v.name));
        grid.appendChild(card);
    });

    containerEl.appendChild(grid);
}

/* ── initialise a specific view (resets search) ── */
function initView(viewName) {
    viewSearchTerm = '';
    if (searchEl) searchEl.value = '';
    drillStack = [{ viewName, level: 0, filterCol: null, filterVal: null, label: viewName }];
    loadView(viewName, 0, null, null);
}

/* ── entry point ── */
document.addEventListener('DOMContentLoaded', () => {
    const initial = window.VIEWS_INITIAL;
    if (initial) {
        initView(initial);
    } else {
        showSelector();
    }
});
