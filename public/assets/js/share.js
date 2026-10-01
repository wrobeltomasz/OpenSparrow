// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { state, setFilteredData, sortRows } from './grid/state.js';
import { renderThead } from './grid/header/render.js';
import { renderTbody, attachTableTooltip } from './grid/body/render.js';
import { attachCrosshair } from './grid/crosshair.js';
import { computeVirtual } from './grid/cells/virtual-cell.js';
import { setupPagination, getPageRows, initPageSize, resetPagination } from './pagination.js';
import { exportCSV } from './export_csv.js';

import './grid/cells/fk-cell.js';
import './grid/cells/enum-cell.js';
import './grid/cells/boolean-cell.js';
import './grid/cells/date-cell.js';
import './grid/cells/timestamp-cell.js';
import './grid/cells/text-cell.js';
import './grid/cells/virtual-cell.js';

const shareI18n = window.SHARE_I18N || {};
const shareTable = window.SHARE_TABLE || '';
const shareToken = window.SHARE_TOKEN || '';
const shareSchema = window.SHARE_SCHEMA || { tables: {} };

function shareT(key, vars = {}) {
    const template = shareI18n[key] || key;
    return String(template).replace(/\{(\w+)\}/g, (_, name) =>
        Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : `{${name}}`);
}

function shareFetch({ offset = 0, search = '', includeTotal = true, order = null } = {}) {
    const urlParameters = new URLSearchParams({ t: shareToken });
    if (offset > 0) urlParameters.set('offset', String(offset));
    if (search) urlParameters.set('search', search);
    if (!includeTotal) urlParameters.set('include_total', '0');
    if (order && order.length > 0) {
        const orderValue = order.map(rule => `${rule.column}:${rule.asc ? 'asc' : 'desc'}`).join(',');
        urlParameters.set('order', orderValue);
    }
    return fetch(`api/share.php?${urlParameters.toString()}`)
        .then(response => {
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return response.json();
        });
}

function applyVirtualColumns(rows) {
    const tableColumns = shareSchema.tables[shareTable]?.columns || {};
    for (const [columnName, columnConfig] of Object.entries(tableColumns)) {
        if (columnConfig.type !== 'virtual') continue;
        rows.forEach(row => { row[columnName] = computeVirtual(columnConfig.formula, row); });
    }
}

async function shareRenderGrid() {
    if (!state.currentTable) return;

    const onSortToggle = async () => {
        if (state.serverSearchMode) {
            state.serverSortActive = state.sortState.length > 0;
            resetPagination();
            await shareServerView({ keepSort: true });
            return;
        }
        state.filteredData = state.sortState.length > 0
            ? sortRows(state.unsortedFilteredData, state.sortState)
            : state.unsortedFilteredData.slice();
        await shareRenderGrid();
    };

    const table = document.createElement('table');
    table.appendChild(renderThead(shareSchema, true, shareRenderGrid, getPageRows, onSortToggle));
    const { tbody } = await renderTbody(shareSchema, true, getPageRows, shareLoadTable);
    table.appendChild(tbody);
    attachCrosshair(table);
    attachTableTooltip(table, shareSchema);

    const container = state.containerEl || document.getElementById('grid');
    container.replaceChildren(table);
    setupPagination(shareSchema);
}

async function shareLoadTable() {
    const gridTitleElement = document.getElementById('gridTitle');
    try {
        const data = await shareFetch();
        state.currentTable = shareTable;
        state.fkCache = new Map();
        state.fkData = new Map();
        state.columnFilters = {};
        state.serverSortActive = false;
        state.fullData = data.rows || [];
        state.serverSearchMode = !!data.truncated;
        state.serverSearchActive = false;
        state.wasTruncated = !!data.truncated;
        state.loadedOffset = state.fullData.length;
        state.totalRows = data.total ?? state.fullData.length;
        state.containerEl = document.getElementById('grid');
        state.gridTitleEl = gridTitleElement;
        state.addRowBtn = null;

        applyVirtualColumns(state.fullData);

        const tableColumns = shareSchema.tables[shareTable]?.columns || {};
        const fetchedColumnSet = new Set(data.columns || []);
        state.displayedColumns = Object.keys(tableColumns).filter(columnKey => {
            if (columnKey === 'id') return false;
            const config = tableColumns[columnKey];
            if (config.show_in_grid === false) return false;
            return fetchedColumnSet.has(columnKey) || config.type === 'virtual';
        });
        state.filteredData = state.fullData.slice();
        state.unsortedFilteredData = state.filteredData.slice();
        const defaultSort = shareSchema.tables[shareTable]?.default_sort ?? [];
        state.sortState = defaultSort
            .filter(rule => rule?.column)
            .slice(0, 3)
            .map(rule => ({ column: rule.column, asc: (rule.dir ?? 'asc').toLowerCase() !== 'desc' }));

        if (gridTitleElement && data.table?.display_name) {
            gridTitleElement.textContent = data.table.display_name;
        }

        initPageSize(shareSchema);
        await shareRenderGrid();
    } catch (error) {
        if (gridTitleElement) {
            gridTitleElement.textContent = shareT('grid.cannot_load_table', { table: shareTable, msg: error.message });
        }
    }
}

let searchTimeout;
let activeSearchTerm = '';

function bindSearch() {
    const searchElement = document.getElementById('globalSearch');
    if (!searchElement) return;
    searchElement.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(async () => {
            activeSearchTerm = searchElement.value;
            state.searchTerm = activeSearchTerm.trim();
            state.serverSearchActive = state.searchTerm !== '';
            resetPagination();
            if (state.serverSearchMode) {
                await shareServerView();
                return;
            }
            const searchTerm = state.searchTerm.toLowerCase();
            const rows = state.fullData.filter(row => {
                if (!searchTerm) return true;
                return state.displayedColumns.some(columnName => {
                    const raw = String(row[columnName] ?? '').toLowerCase();
                    const display = (row[columnName + '__display'] ?? '').toString().toLowerCase();
                    return raw.includes(searchTerm) || display.includes(searchTerm);
                });
            });
            setFilteredData(rows);
            await shareRenderGrid();
        }, 300);
    });
}

async function shareServerView({ keepSort = false } = {}) {
    try {
        const data = await shareFetch({
            search: activeSearchTerm,
            order: state.sortState,
        });
        applyVirtualColumns(data.rows || []);
        state.fullData = data.rows || [];
        state.loadedOffset = state.fullData.length;
        state.wasTruncated = !!data.truncated;
        state.totalRows = data.total ?? state.fullData.length;
        if (!keepSort) {
            state.serverSortActive = state.sortState.length > 0 && state.serverSearchMode;
        }
        setFilteredData(state.fullData.slice());
        await shareRenderGrid();
    } catch (error) {
        console.error('Share view apply failed:', error);
    }
}

async function shareAppendMoreRows() {
    try {
        const data = await shareFetch({
            offset: state.loadedOffset,
            search: activeSearchTerm,
            includeTotal: false,
            order: state.sortState,
        });
        applyVirtualColumns(data.rows || []);
        state.fullData = [...state.fullData, ...(data.rows || [])];
        state.loadedOffset = state.fullData.length;
        state.wasTruncated = !!data.truncated;
        setFilteredData(state.fullData.slice());
        await shareRenderGrid();
    } catch (error) {
        console.error('Share load more failed:', error);
    }
}

function bindLoadMore() {
    document.addEventListener('grid:loadMore', shareAppendMoreRows);
}

function bindExport() {
    const exportButton = document.getElementById('exportCsv');
    if (exportButton) exportButton.addEventListener('click', exportCSV);
}

document.addEventListener('DOMContentLoaded', () => {
    bindSearch();
    bindLoadMore();
    bindExport();
    shareLoadTable();
});