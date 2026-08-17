// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { sortRows } from './grid/state.js';
import { I18n } from './i18n.js';
import { apiJson as apiFetch } from './util/api.js';

function applyColorRules(rawValue, rules) {
    if (!Array.isArray(rules) || rules.length === 0) return null;
    const number = parseFloat(rawValue);
    if (isNaN(number)) return null;
    for (const rule of rules) {
        const v = parseFloat(rule.value);
        if (isNaN(v)) continue;
        if (rule.op === '>'  && number >  v) return rule.color;
        if (rule.op === '>=' && number >= v) return rule.color;
        if (rule.op === '<'  && number <  v) return rule.color;
        if (rule.op === '<=' && number <= v) return rule.color;
        if (rule.op === '==' && number === v) return rule.color;
    }
    return null;
}

let viewSortState  = { column: null, asc: true };
let viewSearchTerm = '';
let searchTimer    = null;
let _searchHandler = null;
let columnFilters     = {};
let viewGroupBy    = '';
let collapsedGroups = new Set();
let _applyFilters  = null;
let _curRows       = [];
let _curColumns    = {};

const VIEW_FN_KEYS = { sum: 'views.fn_sum', avg: 'views.fn_avg', min: 'views.fn_min', max: 'views.fn_max', count: 'views.fn_count' };

function summaryConditionMatch(row, condition) {
    const raw = row[condition.column];
    const op  = condition.op ?? '==';
    if (op === 'contains') {
        return String(raw ?? '').toLowerCase().includes(String(condition.value ?? '').toLowerCase());
    }
    if (op === '==' || op === '!=') {
        const a  = parseFloat(raw);
        const b  = parseFloat(condition.value);
        const equals = (!isNaN(a) && !isNaN(b) && String(raw).trim() !== '' )
            ? a === b
            : String(raw ?? '') === String(condition.value ?? '');
        return op === '==' ? equals : !equals;
    }
    const n = parseFloat(raw);
    const v = parseFloat(condition.value);
    if (isNaN(n) || isNaN(v)) return false;
    if (op === '>')  return n > v;
    if (op === '>=') return n >= v;
    if (op === '<')  return n < v;
    if (op === '<=') return n <= v;
    return false;
}

const breadcrumbElement = document.getElementById('viewBreadcrumb');
const containerElement  = document.getElementById('viewContainer');
const searchElement       = document.getElementById('globalSearch');
const columnFilterElement = document.getElementById('columnFilter');
const filterBarElement    = document.getElementById('filterBar');
const groupByElement      = document.getElementById('groupBy');

const pillsElement = document.createElement('div');
pillsElement.id = 'filterPills';
breadcrumbElement.after(pillsElement);
let exportButton  = null;
let actionsBar = null;

const clearFiltersElement = document.getElementById('clearFilters');

function syncClearButton() {
    if (clearFiltersElement) {
        clearFiltersElement.hidden = !(searchElement && searchElement.value) && Object.keys(columnFilters).length === 0;
    }
}

if (clearFiltersElement && searchElement) {
    searchElement.addEventListener('input', syncClearButton);
    clearFiltersElement.addEventListener('click', () => {
        searchElement.value = '';
        viewSearchTerm = '';
        columnFilters = {};
        if (columnFilterElement) columnFilterElement.value = '';
        if (filterBarElement) filterBarElement.replaceChildren();
        if (_applyFilters) _applyFilters();
        syncClearButton();
    });
}

function detectColumnType(column) {
    const values = _curRows.map(r => r[column]).filter(v => v !== null && v !== undefined && v !== '');
    if (values.length === 0) return 'dict';
    const boolSet = new Set(['true', 'false', 't', 'f']);
    if (values.every(v => typeof v === 'boolean' || boolSet.has(String(v).toLowerCase()))) return 'bool';
    if (values.every(v => !isNaN(parseFloat(v)) && isFinite(v))) return 'number';
    if (values.every(v => /^\d{4}-\d{2}-\d{2}/.test(String(v)))) return 'date';
    return 'dict';
}

function columnDisplayName(column) {
    return _curColumns[column]?.display_name ?? column;
}

function updateColumnFilterState(column, type, data) {
    if (!data || data.empty) {
        delete columnFilters[column];
    } else {
        columnFilters[column] = { type, ...data };
    }
}

function handleColumnFilterChange() {
    if (!filterBarElement) return;
    filterBarElement.replaceChildren();
    const column = columnFilterElement ? columnFilterElement.value : '';
    if (!column) return;

    const type     = detectColumnType(column);
    const existing = columnFilters[column] || {};
    const apply    = () => { if (_applyFilters) _applyFilters(); };

    if (type === 'dict') {
        const select = document.createElement('select');
        select.id = 'dictFilter';

        const optionAll = document.createElement('option');
        optionAll.value = '';
        optionAll.textContent = `${columnDisplayName(column)}: ${I18n.t('filter.all')}`;
        select.appendChild(optionAll);

        const uniqueValues = [...new Set(
            _curRows.map(r => r[column]).filter(v => v !== null && v !== undefined && v !== '')
        )].sort();
        uniqueValues.forEach(value => {
            const o = document.createElement('option');
            o.value = String(value);
            o.textContent = String(value);
            if (existing.val !== undefined && String(existing.val) === String(value)) o.selected = true;
            select.appendChild(o);
        });

        select.addEventListener('change', () => {
            const selectedText = select.options[select.selectedIndex].text;
            updateColumnFilterState(column, 'dict', { val: select.value, label: selectedText, empty: select.value === '' });
            apply();
        });
        filterBarElement.appendChild(select);
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
            updateColumnFilterState(column, 'date', {
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
        filterBarElement.appendChild(dateContainer);
    } else if (type === 'number') {
        const numberContainer = document.createElement('div');
        numberContainer.className = 'filter-range';

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

        const updateNumberState = () => {
            updateColumnFilterState(column, 'number', {
                min: inputMin.value,
                max: inputMax.value,
                empty: inputMin.value === '' && inputMax.value === '',
            });
            apply();
        };
        inputMin.addEventListener('input', updateNumberState);
        inputMax.addEventListener('input', updateNumberState);

        numberContainer.appendChild(spanMin);
        numberContainer.appendChild(inputMin);
        numberContainer.appendChild(spanMax);
        numberContainer.appendChild(inputMax);
        filterBarElement.appendChild(numberContainer);
    } else {
        const select = document.createElement('select');
        select.id = 'boolFilter';

        const optionAll = document.createElement('option');
        optionAll.value = '';
        optionAll.textContent = I18n.t('filter.all');
        const optionTrue = document.createElement('option');
        optionTrue.value = 'true';
        optionTrue.textContent = I18n.t('filter.yes_true');
        const optionFalse = document.createElement('option');
        optionFalse.value = 'false';
        optionFalse.textContent = I18n.t('filter.no_false');
        select.appendChild(optionAll);
        select.appendChild(optionTrue);
        select.appendChild(optionFalse);
        if (existing.val !== undefined) select.value = existing.val;

        select.addEventListener('change', () => {
            const selectedText = select.options[select.selectedIndex].text;
            updateColumnFilterState(column, 'bool', { val: select.value, label: selectedText, empty: select.value === '' });
            apply();
        });
        filterBarElement.appendChild(select);
    }
}

if (columnFilterElement) columnFilterElement.addEventListener('change', handleColumnFilterChange);

if (groupByElement) {
    groupByElement.addEventListener('change', () => {
        viewGroupBy = groupByElement.value;
        collapsedGroups.clear();
        if (_applyFilters) _applyFilters();
    });
}

function populateGroupBy(allKeys) {
    if (!groupByElement) return;
    groupByElement.replaceChildren();
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = I18n.t('views.group_by');
    groupByElement.appendChild(defaultOption);
    allKeys.forEach(column => {
        const option = document.createElement('option');
        option.value = column;
        option.textContent = columnDisplayName(column);
        groupByElement.appendChild(option);
    });
    groupByElement.value  = viewGroupBy;
    groupByElement.hidden = false;
}

function renderFilterPills() {
    pillsElement.replaceChildren();
    let hasPills = false;

    const createPill = (label, onRemove) => {
        hasPills = true;
        const pill = document.createElement('div');
        pill.className = 'filter-pill';

        const textSpan = document.createElement('span');
        textSpan.textContent = label;

        const closeButton = document.createElement('span');
        closeButton.textContent = '×';
        closeButton.className = 'filter-pill-remove';
        closeButton.title = I18n.t('grid.clear_filters');
        closeButton.addEventListener('click', () => {
            onRemove();
            handleColumnFilterChange();
            if (_applyFilters) _applyFilters();
        });

        pill.appendChild(textSpan);
        pill.appendChild(closeButton);
        pillsElement.appendChild(pill);
    };

    if (viewSearchTerm) {
        createPill(`${I18n.t('grid.search_placeholder')}: "${viewSearchTerm}"`, () => {
            viewSearchTerm = '';
            if (searchElement) searchElement.value = '';
        });
    }

    for (const [column, filter] of Object.entries(columnFilters)) {
        const columnName = columnDisplayName(column);
        let label = '';
        if (filter.type === 'dict' || filter.type === 'bool') {
            label = `${columnName}: ${filter.label}`;
        } else if (filter.type === 'date') {
            if (filter.from && filter.to) label = `${columnName}: ${filter.from} – ${filter.to}`;
            else if (filter.from) label = `${columnName} ≥ ${filter.from}`;
            else if (filter.to) label = `${columnName} ≤ ${filter.to}`;
        } else if (filter.type === 'number') {
            if (filter.min !== '' && filter.max !== '') label = `${columnName}: ${filter.min} - ${filter.max}`;
            else if (filter.min !== '') label = `${columnName} >= ${filter.min}`;
            else if (filter.max !== '') label = `${columnName} <= ${filter.max}`;
        }

        if (label) {
            createPill(label, () => {
                delete columnFilters[column];
                if (columnFilterElement && columnFilterElement.value === column) {
                    if (filterBarElement) filterBarElement.replaceChildren();
                    columnFilterElement.value = '';
                }
            });
        }
    }

    pillsElement.classList.toggle('active', hasPills);
}

function rowPassesColumnFilters(row) {
    for (const [column, filter] of Object.entries(columnFilters)) {
        if (filter.type === 'dict') {
            if (String(row[column]) !== String(filter.val)) return false;
        } else if (filter.type === 'bool') {
            const rowBool = (row[column] === true || row[column] === 't' || row[column] === 'true' || row[column] === 1);
            if (rowBool !== (filter.val === 'true')) return false;
        } else if (filter.type === 'date') {
            const rowDateString = String(row[column] || '').substring(0, 10);
            if (!rowDateString) return false;
            const rowTime = new Date(rowDateString).getTime();
            if (filter.from && rowTime < new Date(filter.from).getTime()) return false;
            if (filter.to && rowTime > new Date(filter.to).getTime()) return false;
        } else if (filter.type === 'number') {
            const rowNumber = Number(row[column]);
            if (isNaN(rowNumber)) return false;
            if (filter.min !== '' && rowNumber < Number(filter.min)) return false;
            if (filter.max !== '' && rowNumber > Number(filter.max)) return false;
        }
    }
    return true;
}

function populateColumnFilter(allKeys) {
    if (!columnFilterElement) return;
    columnFilterElement.replaceChildren();
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = I18n.t('grid.select_column');
    columnFilterElement.appendChild(defaultOption);
    allKeys.forEach(column => {
        const option = document.createElement('option');
        option.value = column;
        option.textContent = columnDisplayName(column);
        columnFilterElement.appendChild(option);
    });
    columnFilterElement.hidden = false;
}

function _clearHandlers() {
    if (searchElement && _searchHandler) {
        searchElement.removeEventListener('input', _searchHandler);
        _searchHandler = null;
    }
    if (exportButton) { exportButton.onclick = null; }
    if (actionsBar) { actionsBar.style.display = 'none'; }
    columnFilters    = {};
    _applyFilters = null;
    _curRows      = [];
    _curColumns   = {};
    viewGroupBy   = '';
    collapsedGroups.clear();
    if (groupByElement) {
        groupByElement.replaceChildren();
        groupByElement.value  = '';
        groupByElement.hidden = true;
    }
    if (columnFilterElement) {
        columnFilterElement.replaceChildren();
        columnFilterElement.value  = '';
        columnFilterElement.hidden = true;
    }
    if (filterBarElement) filterBarElement.replaceChildren();
    pillsElement.replaceChildren();
    pillsElement.classList.remove('active');
    syncClearButton();

    window.CURRENT_VIEW = null;
}

function showSelector() {
    _clearHandlers();
    if (searchElement) searchElement.value = '';
    viewSearchTerm = '';
    loadViewSelector();
}

async function loadView(viewName, level, filterColumn, filterValue) {
    _clearHandlers();
    clearTimeout(searchTimer);
    viewSortState = { column: null, asc: true };

    const loadElement = document.createElement('div');
    loadElement.className = 'vw-loading';
    loadElement.textContent = I18n.t('common.loading');
    containerElement.replaceChildren(loadElement);

    let url = `api/views.php?action=data&view=${encodeURIComponent(viewName)}&level=${level}`;
    if (filterColumn) url += `&filter_col=${encodeURIComponent(filterColumn)}&filter_val=${encodeURIComponent(filterValue ?? '')}`;

    try {
        const data = await apiFetch(url);
        renderView(data);
    } catch (error) {
        containerElement.innerHTML = '';
        const errorDiv1 = document.createElement('div');
        errorDiv1.className = 'vw-error';
        errorDiv1.textContent = I18n.t('views.error', { message: error.message });
        containerElement.appendChild(errorDiv1);
    }
}

function renderView(data) {
    containerElement.innerHTML = '';
    const { view, level, max_level, group_by, drill_enabled, rows, columns, group_rows, display_name } = data;

    if (rows.length === 0) {
        containerElement.insertAdjacentHTML('beforeend', `<div class="vw-empty">${I18n.t('views.no_data')}</div>`);

        window.CURRENT_VIEW = null;
        return;
    }

    window.CURRENT_VIEW = { name: view, display: display_name ?? view };

    const allKeys      = Object.keys(rows[0]);
    const canDrillDown = drill_enabled && level < max_level && group_by != null;
    const drillColumnCount = canDrillDown ? 1 : 0;
    let currentFilteredRows = [];

    const tableWrap = document.createElement('div');
    tableWrap.className = 'vw-table-wrap';

    const table = document.createElement('table');

    const thead     = document.createElement('thead');
    const headerRow = document.createElement('tr');

    function updateThLabels() {
        headerRow.childNodes.forEach(th => {
            if (th.nodeType !== Node.ELEMENT_NODE) return;
            const k       = th.dataset.col;
            const label     = columns[k]?.display_name ?? k;
            const ind     = viewSortState.column === k ? (viewSortState.asc ? ' ↑' : ' ↓') : '';
            const thLabel = th.querySelector('.th-label');
            if (thLabel) thLabel.textContent = label + ind;
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

    const summaryFns   = {};
    const summaryConds = {};
    allKeys.forEach(key => {
        const fn = (columns[key]?.summary ?? '').toLowerCase();
        if (fn && fn !== 'none') summaryFns[key] = fn;
        const condition = columns[key]?.summary_if;
        if (condition && condition.column && allKeys.includes(condition.column)) summaryConds[key] = condition;
    });
    const hasSummary = Object.keys(summaryFns).length > 0;

    function summaryValue(fn, rowsArr, key) {
        const condition = summaryConds[key];
        if (condition) rowsArr = rowsArr.filter(r => summaryConditionMatch(r, condition));
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
        const condition = summaryConds[key];
        if (condition) {
            badge.classList.add('cond');
            badge.textContent += ' ƒ';
            td.title = `${badge.textContent.trim()}: ${columnDisplayName(condition.column)} ${condition.op ?? '=='} ${condition.value ?? ''}`;
        }
        td.appendChild(strong);
        td.appendChild(badge);
    }

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

    allKeys.forEach((key, columnIndex) => {
        const td = document.createElement('td');
        const fn = summaryFns[key];

        if (fn) {
            td.className = 'vw-summary-cell';
            summaryUpdaters[key] = (filteredRows) => fillSummaryCell(td, fn, filteredRows, key);
        } else if (columnIndex === 0 && !canDrillDown) {
            td.className   = 'vw-summary-label-cell';
            td.textContent = 'Σ';
        }
        summaryTr.appendChild(td);
    });

    tfoot.appendChild(summaryTr);
    if (!hasSummary) tfoot.style.display = 'none';
    table.appendChild(tfoot);

    tableWrap.appendChild(table);
    containerElement.appendChild(tableWrap);

    actionsBar = document.createElement('div');
    actionsBar.className = 'actions';
    const actionsLeft = document.createElement('div');
    actionsLeft.className = 'left';
    exportButton = document.createElement('button');
    exportButton.id          = 'exportCsv';
    exportButton.textContent = I18n.t('grid.export_csv');
    actionsLeft.appendChild(exportButton);
    actionsBar.appendChild(actionsLeft);
    containerElement.appendChild(actionsBar);

    function applyViewFilters() {
        let result = rows;
        if (viewSearchTerm) {
            const term = viewSearchTerm.toLowerCase();
            result = result.filter(row =>
                Object.values(row).some(v => String(v ?? '').toLowerCase().includes(term))
            );
        }
        if (Object.keys(columnFilters).length > 0) result = result.filter(rowPassesColumnFilters);
        renderFilterPills();
        syncClearButton();
        result = sortRows(result, viewSortState);
        currentFilteredRows = result;
        Object.values(summaryUpdaters).forEach(fn => fn(result));

        tbody.innerHTML = '';

        const makeRow = (row) => {
            const tr = document.createElement('tr');
            let arrowElement = null;

            if (canDrillDown) {
                tr.classList.add('vw-drillable');
                const arrowTd = document.createElement('td');
                arrowElement = document.createElement('span');
                arrowElement.className   = 'vw-drill-arrow';
                arrowElement.textContent = '▸';
                arrowTd.appendChild(arrowElement);
                tr.appendChild(arrowTd);
            }

            allKeys.forEach(key => {
                const td     = document.createElement('td');
                const rawValue = row[key];
                const columnConfig = columns[key];
                const rules  = columnConfig?.color_rules ?? [];
                const color  = applyColorRules(rawValue, rules);

                if (color) {
                    const chip = document.createElement('span');
                    chip.className        = 'vw-value-chip';
                    chip.style.background = color;
                    chip.textContent      = rawValue ?? '';
                    td.appendChild(chip);
                } else {
                    td.textContent = rawValue ?? '';
                }
                tr.appendChild(td);
            });

            if (canDrillDown) {
                tr.addEventListener('click', () => {
                    const drillValue = row[group_by];
                    toggleNestedDrill(tr, arrowElement, view, level, group_by, drillValue, allKeys.length + drillColumnCount);
                });
            }
            return tr;
        };

        if (!viewGroupBy || !allKeys.includes(viewGroupBy)) {
            result.forEach(row => tbody.appendChild(makeRow(row)));
            return;
        }

        const groups = new Map();
        result.forEach(row => {
            const k = String(row[viewGroupBy] ?? '');
            if (!groups.has(k)) groups.set(k, []);
            groups.get(k).push(row);
        });

        const groupKeys = [...groups.keys()];
        if (!viewSortState.column) groupKeys.sort();

        groupKeys.forEach(groupKey => {
            const groupRows = groups.get(groupKey);
            const collapsed = collapsedGroups.has(groupKey);

            const headerTr = document.createElement('tr');
            headerTr.className = 'vw-group-header';
            const headerTd = document.createElement('td');
            headerTd.colSpan = allKeys.length + drillColumnCount;

            const arrow = document.createElement('span');
            arrow.className   = 'vw-group-arrow';
            arrow.textContent = collapsed ? '▸' : '▾';

            const labelSpan = document.createElement('span');
            labelSpan.className   = 'vw-group-label';
            labelSpan.textContent = `${columnDisplayName(viewGroupBy)}: ${groupKey === '' ? '—' : groupKey}`;

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
            allKeys.forEach((key, columnIndex) => {
                const td = document.createElement('td');
                const fn = summaryFns[key];
                if (fn) {
                    td.className = 'vw-summary-cell';
                    fillSummaryCell(td, fn, groupRows, key);
                } else if (columnIndex === 0 && !canDrillDown) {
                    td.className   = 'vw-summary-label-cell';
                    td.textContent = 'Σ';
                }
                tr.appendChild(td);
            });
            return tr;
        }
    }

    if (searchElement) {
        searchElement.value   = viewSearchTerm;
        _searchHandler   = () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                viewSearchTerm = searchElement.value;
                applyViewFilters();
            }, 300);
        };
        searchElement.addEventListener('input', _searchHandler);
    }

    if (exportButton) {
        exportButton.onclick = () => {
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

    _curRows      = rows;
    _curColumns   = columns;
    _applyFilters = applyViewFilters;
    populateColumnFilter(allKeys);
    handleColumnFilterChange();

    if (!viewGroupBy && level === 0 && group_rows && allKeys.includes(group_rows)) {
        viewGroupBy = group_rows;
    }
    populateGroupBy(allKeys);

    applyViewFilters();
}

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
    const drillColumnCount = canDrill ? 1 : 0;

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
        let arrowElement = null;

        if (canDrill) {
            tr.classList.add('vw-drillable');
            const arrowTd = document.createElement('td');
            arrowElement = document.createElement('span');
            arrowElement.className   = 'vw-drill-arrow';
            arrowElement.textContent = '▸';
            arrowTd.appendChild(arrowElement);
            tr.appendChild(arrowTd);
        }

        keys.forEach(key => {
            const td     = document.createElement('td');
            const rawValue = row[key];
            const rules  = columns[key]?.color_rules ?? [];
            const color  = applyColorRules(rawValue, rules);

            if (color) {
                const chip = document.createElement('span');
                chip.className        = 'vw-value-chip';
                chip.style.background = color;
                chip.textContent      = rawValue ?? '';
                td.appendChild(chip);
            } else {
                td.textContent = rawValue ?? '';
            }
            tr.appendChild(td);
        });

        if (canDrill) {
            tr.addEventListener('click', () => {
                const drillValue = row[group_by];
                toggleNestedDrill(tr, arrowElement, view, level, group_by, drillValue, keys.length + drillColumnCount);
            });
        }
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);

    return table;
}

async function toggleNestedDrill(tr, arrowElement, view, level, filterColumn, filterValue, columnSpan) {
    const next = tr.nextElementSibling;
    if (next && next.classList.contains('vw-drill-nested')) {
        next.remove();
        arrowElement.textContent = '▸';
        return;
    }

    arrowElement.textContent = '▾';
    const nestedTr = document.createElement('tr');
    nestedTr.className = 'vw-drill-nested';
    const nestedTd = document.createElement('td');
    nestedTd.colSpan  = columnSpan;
    nestedTd.className = 'vw-drill-nested-cell';
    const loading = document.createElement('div');
    loading.className   = 'vw-loading';
    loading.textContent = I18n.t('common.loading');
    nestedTd.appendChild(loading);
    nestedTr.appendChild(nestedTd);
    tr.after(nestedTr);

    try {
        const url = `api/views.php?action=data&view=${encodeURIComponent(view)}&level=${level + 1}`
            + `&filter_col=${encodeURIComponent(filterColumn)}&filter_val=${encodeURIComponent(filterValue ?? '')}`;
        const data = await apiFetch(url);
        nestedTd.replaceChildren();
        nestedTd.appendChild(buildLevelTable(data));
    } catch (error) {
        nestedTd.replaceChildren();
        const errorDiv = document.createElement('div');
        errorDiv.className = 'vw-error';
        errorDiv.textContent = I18n.t('views.error', { message: error.message });
        nestedTd.appendChild(errorDiv);
    }
}

async function loadViewSelector() {
    const loadElement = document.createElement('div');
    loadElement.className = 'vw-loading';
    loadElement.textContent = I18n.t('views.loading');
    containerElement.replaceChildren(loadElement);
    try {
        const data = await apiFetch('api/views.php?action=list');
        if (!data.views || data.views.length === 0) {
            containerElement.innerHTML = '<div class="vw-empty">No views configured. Ask an administrator to set up views.</div>';
            return;
        }
        renderSelector(data.views);
    } catch (error) {
        containerElement.innerHTML = '';
        const errorDiv2 = document.createElement('div');
        errorDiv2.className = 'vw-error';
        errorDiv2.textContent = I18n.t('views.error', { message: error.message });
        containerElement.appendChild(errorDiv2);
    }
}

function renderSelector(views) {
    containerElement.innerHTML = '';
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
            const image = document.createElement('img');
            image.src = v.icon; image.alt = '';
            image.style.cssText = 'width:22px; height:22px; object-fit:contain;';
            iconWrapper.appendChild(image);
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

        const cardDescription = document.createElement('p');
        cardDescription.style.cssText = 'color:var(--muted); font-size:14px; margin:0 0 20px; line-height:1.5; flex-grow:1;';
        cardDescription.textContent = v.description || I18n.t('views.click_open');

        const footer = document.createElement('div');
        footer.style.cssText = 'display:flex; align-items:center; justify-content:flex-end; margin-top:auto; padding-top:16px; border-top:1px solid var(--border-light);';
        const openLink = document.createElement('span');
        openLink.style.cssText = 'font-size:13.5px; color:var(--accent); font-weight:600;';
        openLink.textContent = I18n.t('views.open');
        footer.appendChild(openLink);

        card.appendChild(header);
        card.appendChild(cardDescription);
        card.appendChild(footer);

        card.addEventListener('click', () => initView(v.name));
        grid.appendChild(card);
    });

    containerElement.appendChild(grid);
}

function initView(viewName) {
    viewSearchTerm = '';
    if (searchElement) searchElement.value = '';
    loadView(viewName, 0, null, null);
}

document.addEventListener('DOMContentLoaded', async () => {
    await I18n.load();
    const initial = window.VIEWS_INITIAL;
    if (initial) {
        initView(initial);
    } else {
        showSelector();
    }
});
