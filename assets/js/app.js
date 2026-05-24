import { I18n } from './i18n.js';
import { loadTable, renderGrid, getState, setFilteredData, resetFilters, injectPagination } from './grid.js';
import { state as gridState } from './grid/state.js';
import { exportCSV } from './export_csv.js';
import { debugLog } from './debug.js';
import { setupPagination, getPageRows, initPageSize } from './pagination.js';
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
        const schemaRes = await fetch('api_schema.php', {
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
                pillsContainer.style.cssText = 'display: none; gap: 8px; flex-wrap: wrap; padding: 0 1.25rem; background: var(--panel); border-bottom: 1px solid var(--border-light);';
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
        const dateContainer = document.createElement('div');
        dateContainer.style.cssText = 'display: flex; gap: 8px; align-items: center;';
        
        const spanFrom = document.createElement('span');
        spanFrom.textContent = 'From:';
        spanFrom.style.cssText = 'font-size: 13px; color: #ffffff;';
        const inputFrom = document.createElement('input');
        inputFrom.type = 'date';
        inputFrom.className = 'date-filter';
        if (existingFilter.from) inputFrom.value = existingFilter.from;
        
        const spanTo = document.createElement('span');
        spanTo.textContent = 'To:';
        spanTo.style.cssText = 'font-size: 13px; color: #ffffff;';
        const inputTo = document.createElement('input');
        inputTo.type = 'date';
        inputTo.className = 'date-filter';
        if (existingFilter.to) inputTo.value = existingFilter.to;
        
        const updateDateState = () => {
            const fromVal = inputFrom.value;
            const toVal = inputTo.value;
            updateColumnFilterState(col, 'date', { from: fromVal, to: toVal, empty: !fromVal && !toVal });
            applySearch();
        };
        
        inputFrom.addEventListener('change', updateDateState);
        inputTo.addEventListener('change', updateDateState);
        
        dateContainer.appendChild(spanFrom);
        dateContainer.appendChild(inputFrom);
        dateContainer.appendChild(spanTo);
        dateContainer.appendChild(inputTo);
        filterBar.appendChild(dateContainer);
    } else if (type.includes('int') || type.includes('dec') || type.includes('num') || type.includes('float')) {
        const numContainer = document.createElement('div');
        numContainer.style.cssText = 'display: flex; gap: 8px; align-items: center;';
        
        const spanFrom = document.createElement('span');
        spanFrom.textContent = 'Min:';
        spanFrom.style.cssText = 'font-size: 13px; color: #ffffff;';
        const inputFrom = document.createElement('input');
        inputFrom.type = 'number';
        inputFrom.placeholder = '0';
        inputFrom.style.cssText = 'padding: 4px; border-radius: 4px; border: 1px solid #ccc; max-width: 80px;';
        if (existingFilter.min !== undefined) inputFrom.value = existingFilter.min;
        
        const spanTo = document.createElement('span');
        spanTo.textContent = 'Max:';
        spanTo.style.cssText = 'font-size: 13px; color: #ffffff;';
        const inputTo = document.createElement('input');
        inputTo.type = 'number';
        inputTo.placeholder = '100';
        inputTo.style.cssText = 'padding: 4px; border-radius: 4px; border: 1px solid #ccc; max-width: 80px;';
        if (existingFilter.max !== undefined) inputTo.value = existingFilter.max;
        
        const updateNumState = () => {
            const minVal = inputFrom.value;
            const maxVal = inputTo.value;
            updateColumnFilterState(col, 'number', { min: minVal, max: maxVal, empty: minVal === '' && maxVal === '' });
            applySearch();
        };
        
        inputFrom.addEventListener('input', updateNumState);
        inputTo.addEventListener('input', updateNumState);
        
        numContainer.appendChild(spanFrom);
        numContainer.appendChild(inputFrom);
        numContainer.appendChild(spanTo);
        numContainer.appendChild(inputTo);
        filterBar.appendChild(numContainer);
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
        pill.style.cssText = 'background: var(--accent-light); color: var(--accent-dark); border: 1px solid var(--accent-mid); border-radius: 12px; padding: 4px 10px; font-size: 12px; display: inline-flex; align-items: center; gap: 8px; font-weight: 500; margin-top: 10px; margin-bottom: 10px;';
        
        const textSpan = document.createElement('span');
        textSpan.textContent = label;
        
        const closeBtn = document.createElement('span');
        closeBtn.textContent = '×';
        closeBtn.style.cssText = 'cursor: pointer; color: var(--danger); font-size: 16px; font-weight: bold; line-height: 1; padding-left: 4px;';
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

    pillsContainer.style.display = hasPills ? 'flex' : 'none';
}

// Apply global search and column filters
async function applySearch() {
    const { fullData, displayedColumns } = getState();
    const q = activeFilters.search.toLowerCase();

    let rows = fullData.filter(row => {
        for (const [col, filter] of Object.entries(activeFilters.columns)) {
            if (filter.type === 'dict') {
                if (String(row[col]) !== String(filter.val)) return false;
            } else if (filter.type === 'bool') {
                const rowBool = (row[col] === true || row[col] === 't' || row[col] === 'true' || row[col] === 1);
                const targetBool = (filter.val === 'true');
                if (rowBool !== targetBool) return false;
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
    
    handleColumnFilterChange();
    renderFilterPills();
    updateClearFiltersVisibility();
    
    await resetFilters(window.schema);
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