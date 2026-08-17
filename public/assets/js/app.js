// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from './i18n.js';
import { loadTable, renderGrid, getState, setFilteredData, resetFilters, injectPagination, appendMoreRows, serverSearchRows } from './grid.js';
import { state as gridState } from './grid/state.js';
import { exportCSV } from './export_csv.js';
import { debugLog } from './debug.js';
import { setupPagination, getPageRows, initPageSize, resetPagination } from './pagination.js';
import { initWorkflows } from './workflows.js';
import { initDataCleanup } from './data_cleanup.js';
import { initGridKeyboard } from './grid/keyboard.js';
import { initMassEdit } from './grid/mass_edit.js';
import { buildGridContext } from './grid/ai-context.js';

injectPagination(getPageRows, setupPagination);
initDataCleanup();
initGridKeyboard();
initMassEdit();

window.CURRENT_GRID_CONTEXT = buildGridContext;

window.CURRENT_GRID_TABLE = () => getState().currentTable;

const menuElement = document.getElementById('menu');
const gridTitleElement = document.getElementById('gridTitle');
const addRowButton = document.getElementById('addRow');
const searchElement = document.getElementById('globalSearch');
const columnFilterElement = document.getElementById('columnFilter');
const clearFiltersButton = document.getElementById('clearFilters');
let searchTimeout;

let activeFilters = {
    search: '',
    columns: {}
};

document.addEventListener('DOMContentLoaded', async () => {
    try {
        await I18n.load();

        const schemaResult = await fetch('api/schema.php', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (!schemaResult.ok) throw new Error('Failed to load secure schema');

        const schemaData = await schemaResult.json();

        window.schema = schemaData;
        initPageSize(schemaData);
        window.AppState = window.AppState || {};
        window.AppState.schema = schemaData;

        if (Object.keys(window.schema.tables).length > 0) {
            const urlParameters = new URLSearchParameters(window.location.search);
            const urlTable  = urlParameters.get('table');
            let initialTableName = Object.keys(window.schema.tables)[0];
            if (urlTable && window.schema.tables[urlTable]) {
                initialTableName = urlTable;
            }

            const navList = menuElement?.querySelector('ul') || menuElement;
            if (menuElement) {
                menuElement.querySelectorAll('a[data-table]').forEach(a => {
                    a.addEventListener('click', e => {
                        e.preventDefault();
                        menuElement.querySelectorAll('a').forEach(l => l.classList.remove('active'));
                        a.classList.add('active');
                        window.history.pushState({}, document.title, window.location.pathname);
                        loadTable(window.schema, a.dataset.table, gridTitleElement, addRowButton);
                    });
                });
            }

            const gridSection = document.getElementById('gridSection');
            if (gridSection) {
                const pillsContainer = document.createElement('div');
                pillsContainer.id = 'filterPills';
                gridTitleElement.after(pillsContainer);
            }

            const gridContainerElement = document.getElementById('grid');
            let workflowsHandled = false;
            if (gridContainerElement) {
                workflowsHandled = await initWorkflows(navList, gridContainerElement, gridTitleElement);
            }
            setupPagination(window.schema);
            if (!workflowsHandled) {
                loadTable(window.schema, initialTableName, gridTitleElement, addRowButton);
            }
        }
    } catch (error) {
        console.error("Initialization error:", error);
    }
});

function populateColumnFilter() {
    const { displayedColumns, currentTable } = getState();

    columnFilterElement.innerHTML = '';
    const defaultOption = document.createElement("option");
    defaultOption.value = "";
    defaultOption.textContent = I18n.t('grid.select_column');
    columnFilterElement.appendChild(defaultOption);

    displayedColumns.forEach(column => {
        const option = document.createElement("option");
        option.value = column;

        let displayName = column;
        if (currentTable && window.schema.tables[currentTable]?.columns[column]?.display_name) {
            displayName = window.schema.tables[currentTable].columns[column].display_name;
        } else {
            for (const tKey in window.schema.tables) {
                if (window.schema.tables[tKey].columns[column]?.display_name) {
                    displayName = window.schema.tables[tKey].columns[column].display_name;
                    break;
                }
            }
        }
        option.textContent = displayName;
        columnFilterElement.appendChild(option);
    });
}

function updateColumnFilterState(column, type, data) {
    if (!data || data.empty) {
        delete activeFilters.columns[column];
    } else {
        activeFilters.columns[column] = { type, ...data };
    }
}

function buildRangeFilter({ fromLabel, toLabel, inputType, inputClass, placeholderFrom, placeholderTo, existingFrom, existingTo, changeEvent, onUpdate }) {
    const container = document.createElement('div');
    container.className = 'filter-range';

    const spanFrom = document.createElement('span');
    spanFrom.textContent = fromLabel;
    const inputFrom = document.createElement('input');
    inputFrom.type = inputType;
    inputFrom.className = inputClass;
    if (placeholderFrom !== undefined) inputFrom.placeholder = placeholderFrom;
    if (existingFrom !== undefined) inputFrom.value = existingFrom;

    const spanTo = document.createElement('span');
    spanTo.textContent = toLabel;
    const inputTo = document.createElement('input');
    inputTo.type = inputType;
    inputTo.className = inputClass;
    if (placeholderTo !== undefined) inputTo.placeholder = placeholderTo;
    if (existingTo !== undefined) inputTo.value = existingTo;

    const handleUpdate = () => onUpdate(inputFrom.value, inputTo.value);
    inputFrom.addEventListener(changeEvent, handleUpdate);
    inputTo.addEventListener(changeEvent, handleUpdate);

    container.append(spanFrom, inputFrom, spanTo, inputTo);
    return container;
}

function handleColumnFilterChange() {
    const { currentTable, fullData } = getState();
    const column = columnFilterElement.value;
    const filterBar = document.getElementById('filterBar');

    filterBar.innerHTML = '';
    if (!column || !currentTable || !window.schema.tables[currentTable]) return;

    const columnConfig = window.schema.tables[currentTable].columns[column] || {};
    const type = (columnConfig.type || '').toLowerCase();
    const isFK = window.schema.tables[currentTable].foreign_keys && window.schema.tables[currentTable].foreign_keys[column];

    const existingFilter = activeFilters.columns[column] || {};

    if (isFK || type === 'enum') {
        const select = document.createElement('select');
        select.id = 'dictFilter';
        const displayName = columnConfig.display_name || column;

        const optionAll = document.createElement('option');
        optionAll.value = '';
        optionAll.textContent = `${displayName}: All`;
        select.appendChild(optionAll);

        let options = [];
        if (type === 'enum' && Array.isArray(columnConfig.options)) {
            options = columnConfig.options.map(option => ({ val: option, label: option }));
        } else {
            const uniqueValues = new Map();
            fullData.forEach(row => {
                const value = row[column];
                if (value !== null && value !== undefined && value !== '') {
                    const label = row[column + '__display'] ?? value;
                    if (!uniqueValues.has(value)) {
                        uniqueValues.set(value, label);
                    }
                }
            });
            options = Array.from(uniqueValues.entries()).map(([v, l]) => ({ val: v, label: l }));
        }

        options.forEach(oData => {
            const o = document.createElement('option');
            o.value = oData.val;
            o.textContent = oData.label;
            if (existingFilter.val !== undefined && String(existingFilter.val) === String(oData.val)) o.selected = true;
            select.appendChild(o);
        });

        select.addEventListener('change', () => {
            const selectedText = select.options[select.selectedIndex].text;
            updateColumnFilterState(column, 'dict', { val: select.value, label: selectedText, empty: select.value === '' });
            applySearch();
        });

        filterBar.appendChild(select);
    } else if (type.includes('date')) {
        filterBar.appendChild(buildRangeFilter({
            fromLabel: 'From:',
            toLabel: 'To:',
            inputType: 'date',
            inputClass: 'date-filter',
            existingFrom: existingFilter.from,
            existingTo: existingFilter.to,
            changeEvent: 'change',
            onUpdate: (fromValue, toValue) => {
                updateColumnFilterState(column, 'date', { from: fromValue, to: toValue, empty: !fromValue && !toValue });
                applySearch();
            },
        }));
    } else if (type.includes('int') || type.includes('dec') || type.includes('num') || type.includes('float')) {
        filterBar.appendChild(buildRangeFilter({
            fromLabel: 'Min:',
            toLabel: 'Max:',
            inputType: 'number',
            inputClass: 'num-filter',
            placeholderFrom: '0',
            placeholderTo: '100',
            existingFrom: existingFilter.min,
            existingTo: existingFilter.max,
            changeEvent: 'input',
            onUpdate: (minValue, maxValue) => {
                updateColumnFilterState(column, 'number', { min: minValue, max: maxValue, empty: minValue === '' && maxValue === '' });
                applySearch();
            },
        }));
    } else if (type.includes('bool')) {
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

        if (existingFilter.val !== undefined) select.value = existingFilter.val;

        select.addEventListener('change', () => {
            const selectedText = select.options[select.selectedIndex].text;
            updateColumnFilterState(column, 'bool', { val: select.value, label: selectedText, empty: select.value === '' });
            applySearch();
        });
        filterBar.appendChild(select);
    }
}

function renderFilterPills() {
    const pillsContainer = document.getElementById('filterPills');
    if (!pillsContainer) return;

    pillsContainer.innerHTML = '';
    let hasPills = false;
    const { currentTable } = getState();

    const createPill = (label, onRemove) => {
        hasPills = true;
        const pill = document.createElement('div');
        pill.className = 'filter-pill';

        const textSpan = document.createElement('span');
        textSpan.textContent = label;

        const closeButton = document.createElement('span');
        closeButton.textContent = '×';
        closeButton.className = 'filter-pill-remove';
        closeButton.title = I18n.t('common.remove_filter');
        closeButton.onclick = () => {
            onRemove();
            handleColumnFilterChange();
            applySearch();
        };

        pill.appendChild(textSpan);
        pill.appendChild(closeButton);
        pillsContainer.appendChild(pill);
    };

    if (activeFilters.search) {
        createPill(`Search: "${activeFilters.search}"`, () => {
            activeFilters.search = '';
            searchElement.value = '';
        });
    }

    for (const [column, filter] of Object.entries(activeFilters.columns)) {
        let columnName = column;
        if (currentTable && window.schema.tables[currentTable]?.columns[column]?.display_name) {
            colName: columnName = window.schema.tables[currentTable].columns[column].display_name;
        }

        let label = '';
        if (filter.type === 'dict' || filter.type === 'bool') {
            label = `${columnName}: ${filter.label}`;
        } else if (filter.type === 'date') {
            if (filter.from && filter.to) label = `${columnName}: ${filter.from} to ${filter.to}`;
            else if (filter.from) label = `${columnName} from ${filter.from}`;
            else if (filter.to) label = `${columnName} to ${filter.to}`;
        } else if (filter.type === 'number') {
            if (filter.min && filter.max) label = `${columnName}: ${filter.min} - ${filter.max}`;
            else if (filter.min) label = `${columnName} >= ${filter.min}`;
            else if (filter.max) label = `${columnName} <= ${filter.max}`;
        }

        if (label) {
            createPill(label, () => {
                delete activeFilters.columns[column];
                if (columnFilterElement.value === column) {
                    const filterBar = document.getElementById('filterBar');
                    if(filterBar) filterBar.innerHTML = '';
                    columnFilterElement.value = '';
                }
            });
        }
    }

    pillsContainer.classList.toggle('active', hasPills);
}

function rowMatchesColumnFilters(row, filters) {
    for (const [column, filter] of Object.entries(filters)) {
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

function applyColumnFiltersOnly(rows) {
    return rows.filter(row => rowMatchesColumnFilters(row, activeFilters.columns));
}

async function applySearch() {
    const { fullData, displayedColumns, serverSearchMode } = getState();
    const q = activeFilters.search.toLowerCase();

    if (serverSearchMode && q) {
        resetPagination();
        await serverSearchRows(window.schema, activeFilters.search);
        if (Object.keys(activeFilters.columns).length > 0) {
            const filtered = applyColumnFiltersOnly(getState().fullData);
            setFilteredData(filtered);
            await renderGrid(window.schema);
        }
        renderFilterPills();
        updateClearFiltersVisibility();
        return;
    }

    let rows = fullData.filter(row => {
        if (!rowMatchesColumnFilters(row, activeFilters.columns)) return false;

        if (q) {
            const matchesText = displayedColumns.some(columnName => {
                const raw = String(row[columnName] ?? '').toLowerCase();
                const display = (row[columnName + '__display'] ?? '').toString().toLowerCase();
                return raw.includes(q) || display.includes(q);
            });
            if (!matchesText) return false;
        }

        return true;
    });

    setFilteredData(rows);
    await renderGrid(window.schema);
    renderFilterPills();
    updateClearFiltersVisibility();
    debugLog("Search Applied", { activeFilters, results: rows.length });
}

function updateClearFiltersVisibility() {
    const hasSearch = activeFilters.search !== '';
    const hasColumns = Object.keys(activeFilters.columns).length > 0;
    clearFiltersButton.style.display = (hasSearch || hasColumns) ? 'inline-block' : 'none';
}

clearFiltersButton.addEventListener('click', async () => {
    activeFilters = { search: '', columns: {} };
    searchElement.value = '';
    columnFilterElement.value = '';

    renderFilterPills();
    updateClearFiltersVisibility();

    const { serverSearchMode, serverSearchActive } = getState();
    if (serverSearchMode && serverSearchActive) {
        await loadTable(window.schema, gridState.currentTable, gridState.gridTitleEl, gridState.addRowBtn);
    } else {
        handleColumnFilterChange();
        await resetFilters(window.schema);
    }
});

searchElement.addEventListener('input', () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        activeFilters.search = searchElement.value;
        gridState.searchTerm = searchElement.value.trim();
        applySearch();
    }, 300);
});

columnFilterElement.addEventListener('change', handleColumnFilterChange);

document.addEventListener('grid:loadMore', async () => {
    await appendMoreRows(window.schema, activeFilters.search);

    if (Object.keys(activeFilters.columns).length > 0) {
        const filtered = applyColumnFiltersOnly(getState().fullData);
        setFilteredData(filtered);
        await renderGrid(window.schema);
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const exportButton = document.getElementById('exportCsv');
    if (exportButton) exportButton.addEventListener('click', exportCSV);
});

document.addEventListener("tableLoaded", () => {
    activeFilters = { search: '', columns: {} };
    searchElement.value = '';
    const filterBar = document.getElementById('filterBar');
    if(filterBar) filterBar.innerHTML = '';

    populateColumnFilter();
    renderFilterPills();
    updateClearFiltersVisibility();
});
