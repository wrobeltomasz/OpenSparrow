/* assets/js/views.js — Frontend custom-views renderer (views.php page)
   Fetches saved views from api/views.php and renders read-only tables with client-side sorting and per-cell colour rules (applyColorRules). */

import { sortRows } from './grid/state.js';
import { I18n } from './i18n.js';
import { apiJson as apiFetch } from './util/api.js';

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
let viewSortState  = { column: null, asc: true };
let viewSearchTerm = '';
let searchTimer    = null;
let _searchHandler = null;
let colFilters     = {};         // col -> { type: 'dict'|'bool'|'date'|'number', ... } (grid-identical)
let viewGroupBy    = '';         // column the rows are grouped by ('' = no grouping)
let collapsedGroups = new Set(); // group keys collapsed by the user (per view load)
let _applyFilters  = null;       // set per-view by renderView(); re-filters + re-renders rows
let _curRows       = [];         // rows of the currently rendered view level
let _curColumns    = {};         // columns config of the currently rendered view

const VIEW_FN_KEYS = { sum: 'views.fn_sum', avg: 'views.fn_avg', min: 'views.fn_min', max: 'views.fn_max', count: 'views.fn_count' };

/* ── conditional summary (SUMIF/COUNTIF): does a row match a summary_if condition? ── */
function summaryCondMatch(row, cond) {
    const raw = row[cond.column];
    const op  = cond.op ?? '==';
    if (op === 'contains') {
        return String(raw ?? '').toLowerCase().includes(String(cond.value ?? '').toLowerCase());
    }
    if (op === '==' || op === '!=') {
        const a  = parseFloat(raw);
        const b  = parseFloat(cond.value);
        const eq = (!isNaN(a) && !isNaN(b) && String(raw).trim() !== '' )
            ? a === b
            : String(raw ?? '') === String(cond.value ?? '');
        return op === '==' ? eq : !eq;
    }
    const n = parseFloat(raw);
    const v = parseFloat(cond.value);
    if (isNaN(n) || isNaN(v)) return false;
    if (op === '>')  return n > v;
    if (op === '>=') return n >= v;
    if (op === '<')  return n < v;
    if (op === '<=') return n <= v;
    return false;
}

/* ── DOM refs ── */
const breadcrumbEl = document.getElementById('viewBreadcrumb');
const containerEl  = document.getElementById('viewContainer');
const searchEl       = document.getElementById('globalSearch');
const columnFilterEl = document.getElementById('columnFilter');
const filterBarEl    = document.getElementById('filterBar');
const groupByEl      = document.getElementById('groupBy');

/* Active-filter pills container — same id/classes as the grid page, above the table */
const pillsEl = document.createElement('div');
pillsEl.id = 'filterPills';
breadcrumbEl.after(pillsEl);
let exportBtn  = null;
let actionsBar = null;

/* ── Clear filters: header button resets search + column filters ── */
const clearFiltersEl = document.getElementById('clearFilters');

function syncClearBtn() {
    if (clearFiltersEl) {
        clearFiltersEl.hidden = !(searchEl && searchEl.value) && Object.keys(colFilters).length === 0;
    }
}

if (clearFiltersEl && searchEl) {
    searchEl.addEventListener('input', syncClearBtn);
    clearFiltersEl.addEventListener('click', () => {
        searchEl.value = '';
        viewSearchTerm = '';
        colFilters = {};
        if (columnFilterEl) columnFilterEl.value = '';
        if (filterBarEl) filterBarEl.replaceChildren();
        if (_applyFilters) _applyFilters();
        syncClearBtn();
    });
}

/* ── column type detection from the loaded rows (views have no schema types) ── */
function detectColType(col) {
    const vals = _curRows.map(r => r[col]).filter(v => v !== null && v !== undefined && v !== '');
    if (vals.length === 0) return 'dict';
    const boolSet = new Set(['true', 'false', 't', 'f']);
    if (vals.every(v => typeof v === 'boolean' || boolSet.has(String(v).toLowerCase()))) return 'bool';
    if (vals.every(v => !isNaN(parseFloat(v)) && isFinite(v))) return 'number';
    if (vals.every(v => /^\d{4}-\d{2}-\d{2}/.test(String(v)))) return 'date';
    return 'dict';
}

function colDisplayName(col) {
    return _curColumns[col]?.display_name ?? col;
}

/* ── update cumulative filter state (grid-identical semantics) ── */
function updateColumnFilterState(col, type, data) {
    if (!data || data.empty) {
        delete colFilters[col];
    } else {
        colFilters[col] = { type, ...data };
    }
}

/* ── render the type-appropriate control into #filterBar for the selected column ── */
function handleColumnFilterChange() {
    if (!filterBarEl) return;
    filterBarEl.replaceChildren();
    const col = columnFilterEl ? columnFilterEl.value : '';
    if (!col) return;

    const type     = detectColType(col);
    const existing = colFilters[col] || {};
    const apply    = () => { if (_applyFilters) _applyFilters(); };

    if (type === 'dict') {
        const select = document.createElement('select');
        select.id = 'dictFilter';

        const optAll = document.createElement('option');
        optAll.value = '';
        optAll.textContent = `${colDisplayName(col)}: ${I18n.t('filter.all')}`;
        select.appendChild(optAll);

        const uniqueVals = [...new Set(
            _curRows.map(r => r[col]).filter(v => v !== null && v !== undefined && v !== '')
        )].sort();
        uniqueVals.forEach(val => {
            const o = document.createElement('option');
            o.value = String(val);
            o.textContent = String(val);
            if (existing.val !== undefined && String(existing.val) === String(val)) o.selected = true;
            select.appendChild(o);
        });

        select.addEventListener('change', () => {
            const selectedText = select.options[select.selectedIndex].text;
            updateColumnFilterState(col, 'dict', { val: select.value, label: selectedText, empty: select.value === '' });
            apply();
        });
        filterBarEl.appendChild(select);
    } else if (type === 'date') {
        const dateContainer = document.createElement('div');
        dateContainer.className = 'filter-range';

        const spanFrom = document.createElement('span');
        spanFrom.textContent = I18n.t('filter.from');
        const inputFrom = document.createElement('input');
        inputFrom.type = 'date';
        inputFrom.className = 'date-filter';
        if (existing.from) inputFrom.value = existing.from;

        const spanTo = document.createElement('span');
        spanTo.textContent = I18n.t('filter.to');
        const inputTo = document.createElement('input');
        inputTo.type = 'date';
        inputTo.className = 'date-filter';
        if (existing.to) inputTo.value = existing.to;

        const updateDateState = () => {
            updateColumnFilterState(col, 'date', {
                from: inputFrom.value,
                to: inputTo.value,
                empty: !inputFrom.value && !inputTo.value,
            });
            apply();
        };
        inputFrom.addEventListener('change', updateDateState);
        inputTo.addEventListener('change', updateDateState);

        dateContainer.appendChild(spanFrom);
        dateContainer.appendChild(inputFrom);
        dateContainer.appendChild(spanTo);
        dateContainer.appendChild(inputTo);
        filterBarEl.appendChild(dateContainer);
    } else if (type === 'number') {
        const numContainer = document.createElement('div');
        numContainer.className = 'filter-range';

        const spanMin = document.createElement('span');
        spanMin.textContent = I18n.t('filter.min');
        const inputMin = document.createElement('input');
        inputMin.type = 'number';
        inputMin.className = 'num-filter';
        if (existing.min !== undefined) inputMin.value = existing.min;

        const spanMax = document.createElement('span');
        spanMax.textContent = I18n.t('filter.max');
        const inputMax = document.createElement('input');
        inputMax.type = 'number';
        inputMax.className = 'num-filter';
        if (existing.max !== undefined) inputMax.value = existing.max;

        const updateNumState = () => {
            updateColumnFilterState(col, 'number', {
                min: inputMin.value,
                max: inputMax.value,
                empty: inputMin.value === '' && inputMax.value === '',
            });
            apply();
        };
        inputMin.addEventListener('input', updateNumState);
        inputMax.addEventListener('input', updateNumState);

        numContainer.appendChild(spanMin);
        numContainer.appendChild(inputMin);
        numContainer.appendChild(spanMax);
        numContainer.appendChild(inputMax);
        filterBarEl.appendChild(numContainer);
    } else {
        const select = document.createElement('select');
        select.id = 'boolFilter';

        const optAll = document.createElement('option');
        optAll.value = '';
        optAll.textContent = I18n.t('filter.all');
        const optTrue = document.createElement('option');
        optTrue.value = 'true';
        optTrue.textContent = I18n.t('filter.yes_true');
        const optFalse = document.createElement('option');
        optFalse.value = 'false';
        optFalse.textContent = I18n.t('filter.no_false');
        select.appendChild(optAll);
        select.appendChild(optTrue);
        select.appendChild(optFalse);
        if (existing.val !== undefined) select.value = existing.val;

        select.addEventListener('change', () => {
            const selectedText = select.options[select.selectedIndex].text;
            updateColumnFilterState(col, 'bool', { val: select.value, label: selectedText, empty: select.value === '' });
            apply();
        });
        filterBarEl.appendChild(select);
    }
}

if (columnFilterEl) columnFilterEl.addEventListener('change', handleColumnFilterChange);

/* ── grouping: header dropdown selects the group-rows column ── */
if (groupByEl) {
    groupByEl.addEventListener('change', () => {
        viewGroupBy = groupByEl.value;
        collapsedGroups.clear();
        if (_applyFilters) _applyFilters();
    });
}

function populateGroupBy(allKeys) {
    if (!groupByEl) return;
    groupByEl.replaceChildren();
    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = I18n.t('views.group_by');
    groupByEl.appendChild(defaultOpt);
    allKeys.forEach(col => {
        const opt = document.createElement('option');
        opt.value = col;
        opt.textContent = colDisplayName(col);
        groupByEl.appendChild(opt);
    });
    groupByEl.value  = viewGroupBy;
    groupByEl.hidden = false;
}

/* ── active filters as removable pills (grid-identical) ── */
function renderFilterPills() {
    pillsEl.replaceChildren();
    let hasPills = false;

    const createPill = (label, onRemove) => {
        hasPills = true;
        const pill = document.createElement('div');
        pill.className = 'filter-pill';

        const textSpan = document.createElement('span');
        textSpan.textContent = label;

        const closeBtn = document.createElement('span');
        closeBtn.textContent = '×';
        closeBtn.className = 'filter-pill-remove';
        closeBtn.title = I18n.t('grid.clear_filters');
        closeBtn.addEventListener('click', () => {
            onRemove();
            handleColumnFilterChange();
            if (_applyFilters) _applyFilters();
        });

        pill.appendChild(textSpan);
        pill.appendChild(closeBtn);
        pillsEl.appendChild(pill);
    };

    if (viewSearchTerm) {
        createPill(`${I18n.t('grid.search_placeholder')}: "${viewSearchTerm}"`, () => {
            viewSearchTerm = '';
            if (searchEl) searchEl.value = '';
        });
    }

    for (const [col, filter] of Object.entries(colFilters)) {
        const colName = colDisplayName(col);
        let label = '';
        if (filter.type === 'dict' || filter.type === 'bool') {
            label = `${colName}: ${filter.label}`;
        } else if (filter.type === 'date') {
            if (filter.from && filter.to) label = `${colName}: ${filter.from} – ${filter.to}`;
            else if (filter.from) label = `${colName} ≥ ${filter.from}`;
            else if (filter.to) label = `${colName} ≤ ${filter.to}`;
        } else if (filter.type === 'number') {
            if (filter.min !== '' && filter.max !== '') label = `${colName}: ${filter.min} - ${filter.max}`;
            else if (filter.min !== '') label = `${colName} >= ${filter.min}`;
            else if (filter.max !== '') label = `${colName} <= ${filter.max}`;
        }

        if (label) {
            createPill(label, () => {
                delete colFilters[col];
                if (columnFilterEl && columnFilterEl.value === col) {
                    if (filterBarEl) filterBarEl.replaceChildren();
                    columnFilterEl.value = '';
                }
            });
        }
    }

    pillsEl.classList.toggle('active', hasPills);
}

/* ── apply the cumulative column filters to a row (grid-identical semantics) ── */
function rowPassesColFilters(row) {
    for (const [col, filter] of Object.entries(colFilters)) {
        if (filter.type === 'dict') {
            if (String(row[col]) !== String(filter.val)) return false;
        } else if (filter.type === 'bool') {
            const rowBool = (row[col] === true || row[col] === 't' || row[col] === 'true' || row[col] === 1);
            if (rowBool !== (filter.val === 'true')) return false;
        } else if (filter.type === 'date') {
            const rowDateStr = String(row[col] || '').substring(0, 10);
            if (!rowDateStr) return false;
            const rowTime = new Date(rowDateStr).getTime();
            if (filter.from && rowTime < new Date(filter.from).getTime()) return false;
            if (filter.to && rowTime > new Date(filter.to).getTime()) return false;
        } else if (filter.type === 'number') {
            const rowNum = Number(row[col]);
            if (isNaN(rowNum)) return false;
            if (filter.min !== '' && rowNum < Number(filter.min)) return false;
            if (filter.max !== '' && rowNum > Number(filter.max)) return false;
        }
    }
    return true;
}

/* ── (re)populate the header column dropdown for the current view ── */
function populateColumnFilter(allKeys) {
    if (!columnFilterEl) return;
    columnFilterEl.replaceChildren();
    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = I18n.t('grid.select_column');
    columnFilterEl.appendChild(defaultOpt);
    allKeys.forEach(col => {
        const opt = document.createElement('option');
        opt.value = col;
        opt.textContent = colDisplayName(col);
        columnFilterEl.appendChild(opt);
    });
    columnFilterEl.hidden = false;
}

/* ── disconnect search/export from current view ── */
function _clearHandlers() {
    if (searchEl && _searchHandler) {
        searchEl.removeEventListener('input', _searchHandler);
        _searchHandler = null;
    }
    if (exportBtn) { exportBtn.onclick = null; }
    if (actionsBar) { actionsBar.style.display = 'none'; }
    colFilters    = {};
    _applyFilters = null;
    _curRows      = [];
    _curColumns   = {};
    viewGroupBy   = '';
    collapsedGroups.clear();
    if (groupByEl) {
        groupByEl.replaceChildren();
        groupByEl.value  = '';
        groupByEl.hidden = true;
    }
    if (columnFilterEl) {
        columnFilterEl.replaceChildren();
        columnFilterEl.value  = '';
        columnFilterEl.hidden = true;
    }
    if (filterBarEl) filterBarEl.replaceChildren();
    pillsEl.replaceChildren();
    pillsEl.classList.remove('active');
    syncClearBtn();
    // No table is on screen while at the selector — agent-panel.js must not offer "current table data".
    window.CURRENT_VIEW = null;
}

/* ── back to selector ── */
function showSelector() {
    _clearHandlers();
    if (searchEl) searchEl.value = '';
    viewSearchTerm = '';
    loadViewSelector();
}

/* ── load and render view data ── */
async function loadView(viewName, level, filterCol, filterVal) {
    _clearHandlers();
    clearTimeout(searchTimer);
    viewSortState = { column: null, asc: true };

    const loadEl = document.createElement('div');
    loadEl.className = 'vw-loading';
    loadEl.textContent = I18n.t('common.loading');
    containerEl.replaceChildren(loadEl);

    let url = `api/views.php?action=data&view=${encodeURIComponent(viewName)}&level=${level}`;
    if (filterCol) url += `&filter_col=${encodeURIComponent(filterCol)}&filter_val=${encodeURIComponent(filterVal ?? '')}`;

    try {
        const data = await apiFetch(url);
        renderView(data);
    } catch (err) {
        containerEl.innerHTML = '';
        const errDiv1 = document.createElement('div');
        errDiv1.className = 'vw-error';
        errDiv1.textContent = I18n.t('views.error', { message: err.message });
        containerEl.appendChild(errDiv1);
    }
}

/* ── render the view table (grid-identical structure) ── */
function renderView(data) {
    containerEl.innerHTML = '';
    const { view, level, max_level, group_by, drill_enabled, rows, columns, group_rows, display_name } = data;

    if (rows.length === 0) {
        containerEl.insertAdjacentHTML('beforeend', `<div class="vw-empty">${I18n.t('views.no_data')}</div>`);
        // No table rendered — agent-panel.js must not offer "current table data".
        window.CURRENT_VIEW = null;
        return;
    }

    // Exposed for agent-panel.js's "current table data" opt-in (views have no ?table= URL param).
    window.CURRENT_VIEW = { name: view, display: display_name ?? view };

    const allKeys      = Object.keys(rows[0]);
    const canDrillDown = drill_enabled && level < max_level && group_by != null;
    const drillColCount = canDrillDown ? 1 : 0;
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

    if (canDrillDown) headerRow.appendChild(document.createElement('th'));

    allKeys.forEach(key => {
        const th = document.createElement('th');
        th.dataset.col  = key;
        th.style.cursor = 'pointer';
        th.title        = I18n.t('views.click_sort');

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

    /* ── summary engine (shared by the tfoot Σ row and per-group subtotal rows) ── */
    const summaryFns   = {};
    const summaryConds = {};   // key -> valid summary_if condition (SUMIF/COUNTIF), or absent
    allKeys.forEach(key => {
        const fn = (columns[key]?.summary ?? '').toLowerCase();
        if (fn && fn !== 'none') summaryFns[key] = fn;
        const cond = columns[key]?.summary_if;
        if (cond && cond.column && allKeys.includes(cond.column)) summaryConds[key] = cond;
    });
    const hasSummary = Object.keys(summaryFns).length > 0;

    function summaryValue(fn, rowsArr, key) {
        const cond = summaryConds[key];
        if (cond) rowsArr = rowsArr.filter(r => summaryCondMatch(r, cond));
        if (fn === 'count') return rowsArr.length;
        const nums = rowsArr.map(r => parseFloat(r[key])).filter(n => !isNaN(n));
        if (!nums.length) return null;
        if (fn === 'sum') return nums.reduce((a, b) => a + b, 0);
        if (fn === 'avg') return nums.reduce((a, b) => a + b, 0) / nums.length;
        if (fn === 'min') return Math.min(...nums);
        if (fn === 'max') return Math.max(...nums);
        return null;
    }

    function fillSummaryCell(td, fn, rowsArr, key) {
        td.replaceChildren();
        const value = summaryValue(fn, rowsArr, key);
        if (value === null) {
            td.textContent = '—';
            return;
        }
        const strong = document.createElement('strong');
        strong.textContent = value.toLocaleString(undefined, { maximumFractionDigits: 2 });
        const badge = document.createElement('span');
        badge.className   = 'vw-summary-fn';
        badge.textContent = VIEW_FN_KEYS[fn] ? I18n.t(VIEW_FN_KEYS[fn]) : (fn.charAt(0).toUpperCase() + fn.slice(1));
        const cond = summaryConds[key];
        if (cond) {
            badge.classList.add('cond');
            badge.textContent += ' ƒ';
            td.title = `${badge.textContent.trim()}: ${colDisplayName(cond.column)} ${cond.op ?? '=='} ${cond.value ?? ''}`;
        }
        td.appendChild(strong);
        td.appendChild(badge);
    }

    /* ── summary tfoot ── */
    const tfoot         = document.createElement('tfoot');
    const summaryTr     = document.createElement('tr');
    summaryTr.className = 'vw-summary-row';
    const summaryUpdaters = {};

    if (canDrillDown) {
        const drillTd = document.createElement('td');
        drillTd.className   = 'vw-summary-label-cell';
        drillTd.textContent = 'Σ';
        summaryTr.appendChild(drillTd);
    }

    allKeys.forEach((key, colIdx) => {
        const td = document.createElement('td');
        const fn = summaryFns[key];

        if (fn) {
            td.className = 'vw-summary-cell';
            summaryUpdaters[key] = (filteredRows) => fillSummaryCell(td, fn, filteredRows, key);
        } else if (colIdx === 0 && !canDrillDown) {
            td.className   = 'vw-summary-label-cell';
            td.textContent = 'Σ';
        }
        summaryTr.appendChild(td);
    });

    tfoot.appendChild(summaryTr);
    if (!hasSummary) tfoot.style.display = 'none';
    table.appendChild(tfoot);

    tableWrap.appendChild(table);
    containerEl.appendChild(tableWrap);

    /* ── grid-style actions bar below the table ── */
    actionsBar = document.createElement('div');
    actionsBar.className = 'actions';
    const actionsLeft = document.createElement('div');
    actionsLeft.className = 'left';
    exportBtn = document.createElement('button');
    exportBtn.id          = 'exportCsv';
    exportBtn.textContent = I18n.t('grid.export_csv');
    actionsLeft.appendChild(exportBtn);
    actionsBar.appendChild(actionsLeft);
    containerEl.appendChild(actionsBar);

    /* ── filter + sort + populate tbody ── */
    function applyViewFilters() {
        let result = rows;
        if (viewSearchTerm) {
            const term = viewSearchTerm.toLowerCase();
            result = result.filter(row =>
                Object.values(row).some(v => String(v ?? '').toLowerCase().includes(term))
            );
        }
        if (Object.keys(colFilters).length > 0) result = result.filter(rowPassesColFilters);
        renderFilterPills();
        syncClearBtn();
        result = sortRows(result, viewSortState);
        currentFilteredRows = result;
        Object.values(summaryUpdaters).forEach(fn => fn(result));

        tbody.innerHTML = '';

        const makeRow = (row) => {
            const tr = document.createElement('tr');
            let arrowEl = null;

            if (canDrillDown) {
                tr.classList.add('vw-drillable');
                const arrowTd = document.createElement('td');
                arrowEl = document.createElement('span');
                arrowEl.className   = 'vw-drill-arrow';
                arrowEl.textContent = '▸';
                arrowTd.appendChild(arrowEl);
                tr.appendChild(arrowTd);
            }

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
                    toggleNestedDrill(tr, arrowEl, view, level, group_by, drillVal, allKeys.length + drillColCount);
                });
            }
            return tr;
        };

        if (!viewGroupBy || !allKeys.includes(viewGroupBy)) {
            result.forEach(row => tbody.appendChild(makeRow(row)));
            return;
        }

        /* ── grouped rendering: collapsible sections + per-group subtotals ── */
        const groups = new Map();
        result.forEach(row => {
            const k = String(row[viewGroupBy] ?? '');
            if (!groups.has(k)) groups.set(k, []);
            groups.get(k).push(row);
        });
        // No explicit sort → order groups by key; otherwise keep sort-derived appearance order
        const groupKeys = [...groups.keys()];
        if (!viewSortState.column) groupKeys.sort();

        groupKeys.forEach(groupKey => {
            const groupRows = groups.get(groupKey);
            const collapsed = collapsedGroups.has(groupKey);

            const headerTr = document.createElement('tr');
            headerTr.className = 'vw-group-header';
            const headerTd = document.createElement('td');
            headerTd.colSpan = allKeys.length + drillColCount;

            const arrow = document.createElement('span');
            arrow.className   = 'vw-group-arrow';
            arrow.textContent = collapsed ? '▸' : '▾';

            const labelSpan = document.createElement('span');
            labelSpan.className   = 'vw-group-label';
            labelSpan.textContent = `${colDisplayName(viewGroupBy)}: ${groupKey === '' ? '—' : groupKey}`;

            const countSpan = document.createElement('span');
            countSpan.className   = 'vw-group-count';
            countSpan.textContent = `(${groupRows.length})`;

            headerTd.appendChild(arrow);
            headerTd.appendChild(labelSpan);
            headerTd.appendChild(countSpan);
            headerTr.appendChild(headerTd);
            headerTr.addEventListener('click', () => {
                if (collapsedGroups.has(groupKey)) collapsedGroups.delete(groupKey);
                else collapsedGroups.add(groupKey);
                applyViewFilters();
            });
            tbody.appendChild(headerTr);

            if (collapsed) {
                // Collapsed: keep the subtotal visible so the group still reads as a summary line
                if (hasSummary) tbody.appendChild(makeSubtotalRow(groupRows));
                return;
            }

            groupRows.forEach(row => tbody.appendChild(makeRow(row)));
            if (hasSummary) tbody.appendChild(makeSubtotalRow(groupRows));
        });

        function makeSubtotalRow(groupRows) {
            const tr = document.createElement('tr');
            tr.className = 'vw-group-subtotal';
            if (canDrillDown) {
                const drillTd = document.createElement('td');
                drillTd.className   = 'vw-summary-label-cell';
                drillTd.textContent = 'Σ';
                tr.appendChild(drillTd);
            }
            allKeys.forEach((key, colIdx) => {
                const td = document.createElement('td');
                const fn = summaryFns[key];
                if (fn) {
                    td.className = 'vw-summary-cell';
                    fillSummaryCell(td, fn, groupRows, key);
                } else if (colIdx === 0 && !canDrillDown) {
                    td.className   = 'vw-summary-label-cell';
                    td.textContent = 'Σ';
                }
                tr.appendChild(td);
            });
            return tr;
        }
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

    /* ── wire header column filter to this view's data ── */
    _curRows      = rows;
    _curColumns   = columns;
    _applyFilters = applyViewFilters;
    populateColumnFilter(allKeys);
    handleColumnFilterChange();

    /* default grouping from config (views.json "group_rows"), only at the root level */
    if (!viewGroupBy && level === 0 && group_rows && allKeys.includes(group_rows)) {
        viewGroupBy = group_rows;
    }
    populateGroupBy(allKeys);

    applyViewFilters();
}

/* ── inline drill-down: expand/collapse a group's next level beneath its row, recursively ── */
function buildLevelTable(data) {
    const { view, level, max_level, group_by, drill_enabled, rows, columns } = data;

    if (!rows.length) {
        const empty = document.createElement('div');
        empty.className = 'vw-empty';
        empty.textContent = I18n.t('views.no_data');
        return empty;
    }

    const keys         = Object.keys(rows[0]);
    const canDrill      = drill_enabled && level < max_level && group_by != null;
    const drillColCount = canDrill ? 1 : 0;

    const table = document.createElement('table');
    table.className = 'vw-nested-table';

    const thead     = document.createElement('thead');
    const headerRow = document.createElement('tr');
    if (canDrill) headerRow.appendChild(document.createElement('th'));
    keys.forEach(key => {
        const th = document.createElement('th');
        th.textContent = columns[key]?.display_name ?? key;
        headerRow.appendChild(th);
    });
    thead.appendChild(headerRow);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    rows.forEach(row => {
        const tr = document.createElement('tr');
        let arrowEl = null;

        if (canDrill) {
            tr.classList.add('vw-drillable');
            const arrowTd = document.createElement('td');
            arrowEl = document.createElement('span');
            arrowEl.className   = 'vw-drill-arrow';
            arrowEl.textContent = '▸';
            arrowTd.appendChild(arrowEl);
            tr.appendChild(arrowTd);
        }

        keys.forEach(key => {
            const td     = document.createElement('td');
            const rawVal = row[key];
            const rules  = columns[key]?.color_rules ?? [];
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

        if (canDrill) {
            tr.addEventListener('click', () => {
                const drillVal = row[group_by];
                toggleNestedDrill(tr, arrowEl, view, level, group_by, drillVal, keys.length + drillColCount);
            });
        }
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);

    return table;
}

async function toggleNestedDrill(tr, arrowEl, view, level, filterCol, filterVal, colSpan) {
    const next = tr.nextElementSibling;
    if (next && next.classList.contains('vw-drill-nested')) {
        next.remove();
        arrowEl.textContent = '▸';
        return;
    }

    arrowEl.textContent = '▾';
    const nestedTr = document.createElement('tr');
    nestedTr.className = 'vw-drill-nested';
    const nestedTd = document.createElement('td');
    nestedTd.colSpan  = colSpan;
    nestedTd.className = 'vw-drill-nested-cell';
    const loading = document.createElement('div');
    loading.className   = 'vw-loading';
    loading.textContent = I18n.t('common.loading');
    nestedTd.appendChild(loading);
    nestedTr.appendChild(nestedTd);
    tr.after(nestedTr);

    try {
        const url = `api/views.php?action=data&view=${encodeURIComponent(view)}&level=${level + 1}`
            + `&filter_col=${encodeURIComponent(filterCol)}&filter_val=${encodeURIComponent(filterVal ?? '')}`;
        const data = await apiFetch(url);
        nestedTd.replaceChildren();
        nestedTd.appendChild(buildLevelTable(data));
    } catch (err) {
        nestedTd.replaceChildren();
        const errDiv = document.createElement('div');
        errDiv.className = 'vw-error';
        errDiv.textContent = I18n.t('views.error', { message: err.message });
        nestedTd.appendChild(errDiv);
    }
}

/* ── load list of all views and show selector ── */
async function loadViewSelector() {
    const loadEl = document.createElement('div');
    loadEl.className = 'vw-loading';
    loadEl.textContent = I18n.t('views.loading');
    containerEl.replaceChildren(loadEl);
    try {
        const data = await apiFetch('api/views.php?action=list');
        if (!data.views || data.views.length === 0) {
            containerEl.innerHTML = '<div class="vw-empty">No views configured. Ask an administrator to set up views.</div>';
            return;
        }
        renderSelector(data.views);
    } catch (err) {
        containerEl.innerHTML = '';
        const errDiv2 = document.createElement('div');
        errDiv2.className = 'vw-error';
        errDiv2.textContent = I18n.t('views.error', { message: err.message });
        containerEl.appendChild(errDiv2);
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
        cardDesc.textContent = v.description || I18n.t('views.click_open');

        const footer = document.createElement('div');
        footer.style.cssText = 'display:flex; align-items:center; justify-content:flex-end; margin-top:auto; padding-top:16px; border-top:1px solid var(--border-light);';
        const openLink = document.createElement('span');
        openLink.style.cssText = 'font-size:13.5px; color:var(--accent); font-weight:600;';
        openLink.textContent = I18n.t('views.open');
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
    loadView(viewName, 0, null, null);
}

/* ── entry point ── */
document.addEventListener('DOMContentLoaded', async () => {
    await I18n.load();
    const initial = window.VIEWS_INITIAL;
    if (initial) {
        initView(initial);
    } else {
        showSelector();
    }
});
