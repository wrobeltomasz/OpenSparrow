// assets/js/grid/index.js — Data-grid core orchestrator (real logic behind the grid.js barrel)
// loadTable/renderGrid: fetches table data + foreign keys (api.js), renders thead/tbody, and wires comment counts/previews, subtable counts, many-to-many columns and virtual (computed) columns.

import { debugLog } from '../debug.js';
import { showToast } from '../toast.js';
import { I18n } from '../i18n.js';
import { state, getState, setFilteredData, resetFiltersState } from './state.js';
import { fetchTableData, preloadForeignKeys } from './api.js';
import { renderThead } from './header/render.js';
import { renderTbody } from './body/render.js';
import { loadCommentCounts } from './comments/counts.js';
import { loadSubtableCounts } from './body/subtable-counts.js';
import { initPreviewPopup, clearPreviewCache } from './comments/preview-popup.js';
import { loadM2mColumns, clearM2mStore } from './m2m/loader.js';
import { initM2mPopup } from './m2m/popup.js';
import { computeVirtual } from './cells/virtual-cell.js';

export { getState, setFilteredData };
export { clearSelection } from './state.js';

const userRole = window.USER_ROLE || 'viewer';
const isReadOnly = userRole !== 'editor';

export async function loadTable(schema, table, gridTitleEl, addRowBtn) {
    debugLog('Loading table', table);
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const data = await fetchTableData(table, urlParams);

        state.currentTable = table;
        state.fkCache = new Map();
        clearPreviewCache();
        clearM2mStore();
        state.fullData = data.rows || [];
        state.serverSearchMode = !!data.truncated;
        state.serverSearchActive = false;
        state.wasTruncated = !!data.truncated;
        state.loadedOffset = state.fullData.length;
        state.totalRows = data.total ?? state.fullData.length;
        if (data.truncated) {
            const total = state.totalRows;
            const loaded = state.fullData.length;
            const remaining = total > loaded ? (total - loaded).toLocaleString() : '?';
            showToast(
                I18n.t('grid.truncated_notice', {
                    loaded: loaded.toLocaleString(),
                    total: total.toLocaleString(),
                    remaining,
                }),
                'info',
                3000
            );
        }

        // Pre-compute virtual column values into each row so sort/filter work transparently
        const tableCols = schema.tables[table]?.columns || {};
        for (const [colName, colCfg] of Object.entries(tableCols)) {
            if (colCfg.type !== 'virtual') continue;
            state.fullData.forEach(row => {
                row[colName] = computeVirtual(colCfg.formula, row);
            });
        }

        // Build displayedColumns from schema key order — preserves user-defined position
        // for both real and virtual columns. Real columns must also be present in the
        // API response; virtual columns are always included (they were just pre-computed).
        const fetchedColSet = new Set(data.columns || []);
        state.displayedColumns = Object.keys(tableCols).filter(c => {
            if (c === 'id') return false;
            const cfg = tableCols[c];
            if (cfg.show_in_grid === false) return false;
            return fetchedColSet.has(c) || cfg.type === 'virtual';
        });
        state.filteredData = state.fullData.slice();
        state.unsortedFilteredData = state.filteredData.slice();
        const firstSort = schema.tables[table]?.default_sort?.[0];
        state.sortState = firstSort?.column
            ? { column: firstSort.column, asc: (firstSort.dir ?? 'asc').toLowerCase() !== 'desc' }
            : { column: null, asc: true };
        state.gridTitleEl = gridTitleEl;
        state.addRowBtn = addRowBtn;
        state.containerEl = document.getElementById('grid');

        // Keep legacy window.AppState in sync for grid_actions.js
        window.AppState = window.AppState || {};
        window.AppState.currentTable = table;

        const filterCol = urlParams.get('filter_col');
        const filterVal = urlParams.get('filter_val');
        const filterFrom = urlParams.get('filter_from');
        const filterTo = urlParams.get('filter_to');
        let displayTitle = data.table?.display_name || table;
        if (urlParams.get('table') === table && filterCol) {
            if (filterVal !== null) {
                displayTitle += ` (Filtered by ${filterCol}: ${filterVal})`;
            } else if (filterFrom !== null || filterTo !== null) {
                displayTitle += ` (Filtered by ${filterCol}: ${filterFrom ?? ''}…${filterTo ?? ''})`;
            }
        }
        gridTitleEl.textContent = displayTitle;

        if (addRowBtn) {
            if (isReadOnly) {
                addRowBtn.style.display = 'none';
            } else {
                addRowBtn.style.display = '';
                addRowBtn.disabled = false;
                addRowBtn.onclick = () => {
                    window.location.href = `create.php?table=${table}`;
                };
            }
        }

        await renderGrid(schema);
        document.dispatchEvent(new Event('tableLoaded'));
    } catch (err) {
        console.error('Failed to load table data:', err);
        showToast(I18n.t('grid.cannot_load_table', { table, msg: err.message }), 'error');
        if (gridTitleEl) gridTitleEl.textContent = I18n.t('grid.error_loading_table', { table });
    }
}

// Injected by app.js after all modules load to break circular dependency
// pagination.js imports renderGrid from grid.js, so grid/index.js must not import pagination.js
let _getPageRows = () => state.filteredData.slice(0, 10);
let _setupPagination = () => {};

export function injectPagination(getPageRows, setupPagination) {
    _getPageRows = getPageRows;
    _setupPagination = setupPagination;
}

export async function renderGrid(schema) {
    if (!state.currentTable) return;

    await preloadForeignKeys(schema);

    const onRerender = () => renderGrid(schema);
    const onTableReload = () => loadTable(
        schema, state.currentTable, state.gridTitleEl, state.addRowBtn
    );

    const table = document.createElement('table');
    table.appendChild(renderThead(schema, isReadOnly, onRerender));

    const { tbody, pageRows } = await renderTbody(schema, isReadOnly, _getPageRows, onTableReload);
    table.appendChild(tbody);

    const container = state.containerEl || document.getElementById('grid');
    container.replaceChildren(table);

    _setupPagination(schema);
    debugLog('Grid rendered', { rows: pageRows.length });
    loadCommentCounts(pageRows);
    loadSubtableCounts(pageRows, schema);
    loadM2mColumns(pageRows, schema);
}

export async function resetFilters(schema) {
    resetFiltersState();
    await renderGrid(schema);
}

function applyVirtualCols(schema, rows) {
    const tableCols = schema.tables[state.currentTable]?.columns || {};
    for (const [colName, colCfg] of Object.entries(tableCols)) {
        if (colCfg.type !== 'virtual') continue;
        rows.forEach(row => { row[colName] = computeVirtual(colCfg.formula, row); });
    }
}

export async function appendMoreRows(schema, search = '') {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const data = await fetchTableData(state.currentTable, urlParams, {
            offset: state.loadedOffset,
            search,
        });
        const newRows = data.rows || [];
        applyVirtualCols(schema, newRows);
        state.fullData = [...state.fullData, ...newRows];
        state.loadedOffset = state.fullData.length;
        state.wasTruncated = !!data.truncated;
        state.totalRows = data.total ?? state.fullData.length;
        setFilteredData(state.fullData.slice());
        await renderGrid(schema);
    } catch (err) {
        console.error('Failed to load more rows:', err);
        showToast(I18n.t('grid.cannot_load_more', { msg: err.message }), 'error');
    }
}

export async function serverSearchRows(schema, search) {
    try {
        state.loadedOffset = 0;
        const urlParams = new URLSearchParams(window.location.search);
        const data = await fetchTableData(state.currentTable, urlParams, { search });
        const rows = data.rows || [];
        applyVirtualCols(schema, rows);
        state.fullData = rows;
        state.loadedOffset = rows.length;
        state.wasTruncated = !!data.truncated;
        state.totalRows = data.total ?? rows.length;
        state.serverSearchActive = true;
        setFilteredData(rows);
        await renderGrid(schema);
    } catch (err) {
        console.error('Server search failed:', err);
        showToast(I18n.t('grid.search_failed', { msg: err.message }), 'error');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initPreviewPopup();
    initM2mPopup();
});
