// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

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
import { loadImageColumn, clearImageStore } from './images/loader.js';
import { initImagePopup } from './images/popup.js';
import { computeVirtual } from './cells/virtual-cell.js';
import { attachCrosshair } from './crosshair.js';

export { getState, setFilteredData };
export { clearSelection } from './state.js';

const userRole = window.USER_ROLE || 'viewer';
const isReadOnly = userRole !== 'editor';

export async function loadTable(schema, table, gridTitleElement, addRowButton) {
    debugLog('Loading table', table);
    try {
        const urlParameters = new URLSearchParameters(window.location.search);
        const data = await fetchTableData(table, urlParameters);

        state.currentTable = table;
        state.fkCache = new Map();
        clearPreviewCache();
        clearM2mStore();
        clearImageStore();
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

        const tableColumns = schema.tables[table]?.columns || {};
        for (const [columnName, columnConfig] of Object.entries(tableColumns)) {
            if (columnConfig.type !== 'virtual') continue;
            state.fullData.forEach(row => {
                row[columnName] = computeVirtual(columnConfig.formula, row);
            });
        }

        const fetchedColumnSet = new Set(data.columns || []);
        state.displayedColumns = Object.keys(tableColumns).filter(c => {
            if (c === 'id') return false;
            const config = tableColumns[c];
            if (config.show_in_grid === false) return false;
            return fetchedColumnSet.has(c) || config.type === 'virtual';
        });
        state.filteredData = state.fullData.slice();
        state.unsortedFilteredData = state.filteredData.slice();
        const firstSort = schema.tables[table]?.default_sort?.[0];
        state.sortState = firstSort?.column
            ? { column: firstSort.column, asc: (firstSort.dir ?? 'asc').toLowerCase() !== 'desc' }
            : { column: null, asc: true };
        state.gridTitleEl = gridTitleElement;
        state.addRowBtn = addRowButton;
        state.containerEl = document.getElementById('grid');

        window.AppState = window.AppState || {};
        window.AppState.currentTable = table;

        const filterColumn = urlParameters.get('filter_col');
        const filterValue = urlParameters.get('filter_val');
        const filterFrom = urlParameters.get('filter_from');
        const filterTo = urlParameters.get('filter_to');
        let displayTitle = data.table?.display_name || table;
        if (urlParameters.get('table') === table && filterColumn) {
            if (filterValue !== null) {
                displayTitle += ` (Filtered by ${filterColumn}: ${filterValue})`;
            } else if (filterFrom !== null || filterTo !== null) {
                displayTitle += ` (Filtered by ${filterColumn}: ${filterFrom ?? ''}…${filterTo ?? ''})`;
            }
        }
        gridTitleElement.textContent = displayTitle;

        if (addRowButton) {
            if (isReadOnly) {
                addRowButton.style.display = 'none';
            } else {
                addRowButton.style.display = '';
                addRowButton.disabled = false;
                addRowButton.onclick = () => {
                    window.location.href = `create.php?table=${table}`;
                };
            }
        }

        await renderGrid(schema);
        document.dispatchEvent(new Event('tableLoaded'));
    } catch (error) {
        console.error('Failed to load table data:', error);
        showToast(I18n.t('grid.cannot_load_table', { table, msg: error.message }), 'error');
        if (gridTitleElement) gridTitleElement.textContent = I18n.t('grid.error_loading_table', { table });
    }
}

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
    table.appendChild(renderThead(schema, isReadOnly, onRerender, _getPageRows));

    const { tbody, pageRows } = await renderTbody(schema, isReadOnly, _getPageRows, onTableReload);
    table.appendChild(tbody);

    attachCrosshair(table);

    const container = state.containerEl || document.getElementById('grid');
    container.replaceChildren(table);

    _setupPagination(schema);
    debugLog('Grid rendered', { rows: pageRows.length });
    loadCommentCounts(pageRows);
    loadSubtableCounts(pageRows, schema);
    loadM2mColumns(pageRows, schema);
    loadImageColumn(pageRows, schema);
}

export async function resetFilters(schema) {
    resetFiltersState();
    await renderGrid(schema);
}

function applyVirtualColumns(schema, rows) {
    const tableColumns = schema.tables[state.currentTable]?.columns || {};
    for (const [columnName, columnConfig] of Object.entries(tableColumns)) {
        if (columnConfig.type !== 'virtual') continue;
        rows.forEach(row => { row[columnName] = computeVirtual(columnConfig.formula, row); });
    }
}

export async function appendMoreRows(schema, search = '') {
    try {
        const urlParameters = new URLSearchParameters(window.location.search);
        const data = await fetchTableData(state.currentTable, urlParameters, {
            offset: state.loadedOffset,
            search,
        });
        const newRows = data.rows || [];
        applyVirtualColumns(schema, newRows);
        state.fullData = [...state.fullData, ...newRows];
        state.loadedOffset = state.fullData.length;
        state.wasTruncated = !!data.truncated;
        state.totalRows = data.total ?? state.fullData.length;
        setFilteredData(state.fullData.slice());
        await renderGrid(schema);
    } catch (error) {
        console.error('Failed to load more rows:', error);
        showToast(I18n.t('grid.cannot_load_more', { msg: error.message }), 'error');
    }
}

export async function serverSearchRows(schema, search) {
    try {
        state.loadedOffset = 0;
        const urlParameters = new URLSearchParameters(window.location.search);
        const data = await fetchTableData(state.currentTable, urlParameters, { search });
        const rows = data.rows || [];
        applyVirtualColumns(schema, rows);
        state.fullData = rows;
        state.loadedOffset = rows.length;
        state.wasTruncated = !!data.truncated;
        state.totalRows = data.total ?? rows.length;
        state.serverSearchActive = true;
        setFilteredData(rows);
        await renderGrid(schema);
    } catch (error) {
        console.error('Server search failed:', error);
        showToast(I18n.t('grid.search_failed', { msg: error.message }), 'error');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initPreviewPopup();
    initM2mPopup();
    initImagePopup();
});
