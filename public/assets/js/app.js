// assets/js/app.js — Main grid-page controller (ES module entry for index.php / template.php)
// Wires grid.js with pagination, CSV export, workflows, data-cleanup, keyboard nav and mass-edit; owns the global filter/search state and the table menu, add-row, global search, column filter and clear-filters controls.

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

// Break circular dependency: grid/index.js cannot import pagination.js because
// pagination.js imports renderGrid from grid.js. We wire them together here.
injectPagination(getPageRows, setupPagination);
initDataCleanup();
initGridKeyboard();
initMassEdit();

const menuEl = document.getElementById('menu');
const gridTitleEl = document.getElementById('gridTitle');
const addRowBtn = document.getElementById('addRow');
const searchEl = document.getElementById('globalSearch');
const columnFilterEl = document.getElementById('columnFilter');
const clearFiltersBtn = document.getElementById('clearFilters');
let searchTimeout;

// Store cumulative active filters globally
let activeFilters = {
    search: '',
    columns: {}
};

// Initialize application on DOM load
document.addEventListener('DOMContentLoaded', async () => {
    try {
        await I18n.load();

        // Fetch secure schema dynamically via API instead of reading from HTML
        const schemaRes = await fetch('api/schema.php', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        
        if (!schemaRes.ok) throw new Error('Failed to load secure schema');
        
        const schemaData = await schemaRes.json();
        
        // Define globally so other functions and modules can access it
        window.schema = schemaData;
        initPageSize(schemaData);
        window.AppState = window.AppState || {};
        window.AppState.schema = schemaData;

        if (Object.keys(window.schema.tables).length > 0) {

            // Read URL params to determine initial table
            const urlParams = new URLSearchParams(window.location.search);
            const urlTable  = urlParams.get('table');
            let initialTableName = Object.keys(window.schema.tables)[0];
            if (urlTable && window.schema.tables[urlTable]) {
                initialTableName = urlTable;
            }

            // Menu is PHP-rendered; wire SPA click handlers onto [data-table] links
            const navList = menuEl?.querySelector('ul') || menuEl;
            if (menuEl) {
                menuEl.querySelectorAll('a[data-table]').forEach(a => {
                    a.addEventListener('click', e => {
                        e.preventDefault();
                        menuEl.querySelectorAll('a').forEach(l => l.classList.remove('active'));
                        a.classList.add('active');
                        window.history.pushState({}, document.title, window.location.pathname);
                        loadTable(window.schema, a.dataset.table, gridTitleEl, addRowBtn);
                    });
                });
            }

            // Container for active filter pills
            const gridSection = document.getElementById('gridSection');
            if (gridSection) {
                const pillsContainer = document.createElement('div');
                pillsContainer.id = 'filterPills';
                gridTitleEl.after(pillsContainer);
            }

            const gridContainerEl = document.getElementById('grid');
            let workflowsHandled = false;
            if (gridContainerEl) {
                workflowsHandled = await initWorkflows(navList, gridContainerEl, gridTitleEl);
            }
            setupPagination(window.schema);
            if (!workflowsHandled) {
                loadTable(window.schema, initialTableName, gridTitleEl, addRowBtn);
            }
        }
    } catch (error) {
        console.error("Initialization error:", error);
    }
});

// Populate column filter dropdown dynamically
function populateColumnFilter() {
    const { displayedColumns, currentTable } = getState();
    
    columnFilterEl.innerHTML = '';
    const defaultOpt = document.createElement("option");
    defaultOpt.value = "";
    defaultOpt.textContent = I18n.t('grid.select_column');
    columnFilterEl.appendChild(defaultOpt);
    
    displayedColumns.forEach(col => {
        const opt = document.createElement("option");
        opt.value = col; 
        
        let displayName = col; 
        if (currentTable && window.schema.tables[currentTable]?.columns[col]?.display_name) {
            displayName = window.schema.tables[currentTable].columns[col].display_name;
        } else {
            for (const tKey in window.schema.tables) {
                if (window.schema.tables[tKey].columns[col]?.display_name) {
                    displayName = window.schema.tables[tKey].columns[col].display_name;
                    break;
                }
            }
        }
        opt.textContent = displayName; 
        columnFilterEl.appendChild(opt);
    });
}

// Update the global activeFilters state
function updateColumnFilterState(col, type, data) {
    if (!data || data.empty) {
        delete activeFilters.columns[col];
    } else {
        activeFilters.columns[col] = { type, ...data };
    }
}

// Shared "From/To" range-input pair (used for both date and number column filters).
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

// Render dynamic filters based on column type
function handleColumnFilterChange() {
    const { currentTable, fullData } = getState();
    const col = columnFilterEl.value;
    const filterBar = document.getElementById('filterBar');
    
    filterBar.innerHTML = '';
    if (!col || !currentTable || !window.schema.tables[currentTable]) return;

    const colCfg = window.schema.tables[currentTable].columns[col] || {};
    const type = (colCfg.type || '').toLowerCase();
    const isFK = window.schema.tables[currentTable].foreign_keys && window.schema.tables[currentTable].foreign_keys[col];

    const existingFilter = activeFilters.columns[col] || {};

    if (isFK || type === 'enum') {
        const select = document.createElement('select');
        select.id = 'dictFilter';
        const displayName = colCfg.display_name || col;
        
        const optAll = document.createElement('option');
        optAll.value = '';
        optAll.textContent = `${displayName}: All`;
        select.appendChild(optAll);
        
        let options = [];
        if (type === 'enum' && Array.isArray(colCfg.options)) {
            options = colCfg.options.map(opt => ({ val: opt, label: opt }));
        } else {
            const uniqueVals = new Map();
            fullData.forEach(row => {
                const val = row[col];
                if (val !== null && val !== undefined && val !== '') {
                    const label = row[col + '__display'] ?? val;
                    if (!uniqueVals.has(val)) {
                        uniqueVals.set(val, label);
                    }
                }
            });
            options = Array.from(uniqueVals.entries()).map(([v, l]) => ({ val: v, label: l }));
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
            updateColumnFilterState(col, 'dict', { val: select.value, label: selectedText, empty: select.value === '' });
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
            onUpdate: (fromVal, toVal) => {
                updateColumnFilterState(col, 'date', { from: fromVal, to: toVal, empty: !fromVal && !toVal });
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
            onUpdate: (minVal, maxVal) => {
                updateColumnFilterState(col, 'number', { min: minVal, max: maxVal, empty: minVal === '' && maxVal === '' });
                applySearch();
            },
        }));
    } else if (type.includes('bool')) {
        const select = document.createElement('select');
        select.id = 'boolFilter';
        
        const optAll = document.createElement('option');
        optAll.value = '';
        optAll.textContent = 'All';
        const optTrue = document.createElement('option');
        optTrue.value = 'true';
        optTrue.textContent = 'Yes / True';
        const optFalse = document.createElement('option');
        optFalse.value = 'false';
        optFalse.textContent = 'No / False';
        
        select.appendChild(optAll);
        select.appendChild(optTrue);
        select.appendChild(optFalse);
        
        if (existingFilter.val !== undefined) select.value = existingFilter.val;
        
        select.addEventListener('change', () => {
            const selectedText = select.options[select.selectedIndex].text;
            updateColumnFilterState(col, 'bool', { val: select.value, label: selectedText, empty: select.value === '' });
            applySearch();
        });
        filterBar.appendChild(select);
    }
}

// Render active filters as removable pills
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

        const closeBtn = document.createElement('span');
        closeBtn.textContent = '×';
        closeBtn.className = 'filter-pill-remove';
        closeBtn.title = "Remove filter";
        closeBtn.onclick = () => {
            onRemove();
            handleColumnFilterChange();
            applySearch();
        };
        
        pill.appendChild(textSpan);
        pill.appendChild(closeBtn);
        pillsContainer.appendChild(pill);
    };

    if (activeFilters.search) {
        createPill(`Search: "${activeFilters.search}"`, () => {
            activeFilters.search = '';
            searchEl.value = '';
        });
    }

    for (const [col, filter] of Object.entries(activeFilters.columns)) {
        let colName = col;
        if (currentTable && window.schema.tables[currentTable]?.columns[col]?.display_name) {
            colName = window.schema.tables[currentTable].columns[col].display_name;
        }

        let label = '';
        if (filter.type === 'dict' || filter.type === 'bool') {
            label = `${colName}: ${filter.label}`;
        } else if (filter.type === 'date') {
            if (filter.from && filter.to) label = `${colName}: ${filter.from} to ${filter.to}`;
            else if (filter.from) label = `${colName} from ${filter.from}`;
            else if (filter.to) label = `${colName} to ${filter.to}`;
        } else if (filter.type === 'number') {
            if (filter.min && filter.max) label = `${colName}: ${filter.min} - ${filter.max}`;
            else if (filter.min) label = `${colName} >= ${filter.min}`;
            else if (filter.max) label = `${colName} <= ${filter.max}`;
        }

        if (label) {
            createPill(label, () => {
                delete activeFilters.columns[col];
                if (columnFilterEl.value === col) {
                    const filterBar = document.getElementById('filterBar');
                    if(filterBar) filterBar.innerHTML = '';
                    columnFilterEl.value = '';
                }
            });
        }
    }

    pillsContainer.classList.toggle('active', hasPills);
}

// Shared column-filter predicate (dict/bool/date/number) — used by both
// applyColumnFiltersOnly() below and the client-side branch of applySearch().
function rowMatchesColumnFilters(row, filters) {
    for (const [col, filter] of Object.entries(filters)) {
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

// Apply only column filters to a row set (no text search). Used after server search
// and after load-more to avoid re-triggering a server round-trip.
function applyColumnFiltersOnly(rows) {
    return rows.filter(row => rowMatchesColumnFilters(row, activeFilters.columns));
}

// Apply global search and column filters
async function applySearch() {
    const { fullData, displayedColumns, serverSearchMode } = getState();
    const q = activeFilters.search.toLowerCase();

    if (serverSearchMode && q) {
        // Large table text search → server. Column filters applied client-side on results.
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

    // Client-side filter: small tables (text+column), or large table column-only filter.
    // When serverSearchMode=true and q="", fullData holds whatever was last loaded
    // (original rows or server search results). Column filters work on that set.
    let rows = fullData.filter(row => {
        if (!rowMatchesColumnFilters(row, activeFilters.columns)) return false;

        if (q) {
            const matchesText = displayedColumns.some(colName => {
                const raw = String(row[colName] ?? '').toLowerCase();
                const display = (row[colName + '__display'] ?? '').toString().toLowerCase();
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

// Show global Reset button
function updateClearFiltersVisibility() {
    const hasSearch = activeFilters.search !== '';
    const hasColumns = Object.keys(activeFilters.columns).length > 0;
    clearFiltersBtn.style.display = (hasSearch || hasColumns) ? 'inline-block' : 'none';
}

// Clear filter state globally
clearFiltersBtn.addEventListener('click', async () => {
    activeFilters = { search: '', columns: {} };
    searchEl.value = '';
    columnFilterEl.value = '';

    renderFilterPills();
    updateClearFiltersVisibility();

    const { serverSearchMode, serverSearchActive } = getState();
    if (serverSearchMode && serverSearchActive) {
        // fullData currently holds server search results — reload original
        await loadTable(window.schema, gridState.currentTable, gridState.gridTitleEl, gridState.addRowBtn);
    } else {
        handleColumnFilterChange();
        await resetFilters(window.schema);
    }
});

// Sync search input
searchEl.addEventListener('input', () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        activeFilters.search = searchEl.value;
        gridState.searchTerm = searchEl.value.trim();
        applySearch();
    }, 300);
});

columnFilterEl.addEventListener('change', handleColumnFilterChange);

document.addEventListener('grid:loadMore', async () => {
    await appendMoreRows(window.schema, activeFilters.search);
    // Re-apply column filters on expanded fullData without triggering another server call
    if (Object.keys(activeFilters.columns).length > 0) {
        const filtered = applyColumnFiltersOnly(getState().fullData);
        setFilteredData(filtered);
        await renderGrid(window.schema);
    }
});

// Export CSV button (wired here to avoid circular grid.js ↔ export_csv.js import)
document.addEventListener('DOMContentLoaded', () => {
    const exportBtn = document.getElementById('exportCsv');
    if (exportBtn) exportBtn.addEventListener('click', exportCSV);
});

// Re-init column filter dropdown on every table load
document.addEventListener("tableLoaded", () => {
    activeFilters = { search: '', columns: {} };
    searchEl.value = '';
    const filterBar = document.getElementById('filterBar');
    if(filterBar) filterBar.innerHTML = '';
    
    populateColumnFilter();
    renderFilterPills();
    updateClearFiltersVisibility();
});