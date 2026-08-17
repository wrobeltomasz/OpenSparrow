// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export const state = {
    currentTable: null,
    fullData: [],
    displayedColumns: [],
    filteredData: [],
    unsortedFilteredData: [],
    sortState: { column: null, asc: true },
    fkCache: new Map(),
    searchTerm: '',
    containerEl: null,
    gridTitleEl: null,
    addRowBtn: null,
    selectedIds: new Set(),
    serverSearchMode: false,
    serverSearchActive: false,
    wasTruncated: false,
    loadedOffset: 0,
    totalRows: 0,
};

export function clearSelection() {
    state.selectedIds.clear();
}

export function getState() {
    return {
        currentTable: state.currentTable,
        fullData: state.fullData,
        filteredData: state.filteredData,
        displayedColumns: state.displayedColumns,
        sortState: state.sortState,
        serverSearchMode: state.serverSearchMode,
        serverSearchActive: state.serverSearchActive,
        wasTruncated: state.wasTruncated,
        loadedOffset: state.loadedOffset,
        totalRows: state.totalRows,
    };
}

export function setFilteredData(rows) {
    state.filteredData = rows;
    state.unsortedFilteredData = rows.slice();
    if (state.sortState.column) {
        state.filteredData = sortRows(state.filteredData, state.sortState);
    }
}

export function resetFiltersState() {
    state.filteredData = state.fullData.slice();
    state.unsortedFilteredData = state.fullData.slice();
    state.sortState = { column: null, asc: true };
    state.searchTerm = '';
}

export function sortRows(rows, sortState) {
    if (!sortState.column) return rows;
    const column = sortState.column;
    return [...rows].sort((a, b) => {
        const valueA = a[column + '__display'] ?? a[column] ?? '';
        const valueB = b[column + '__display'] ?? b[column] ?? '';
        const isNumberA = !isNaN(valueA) && valueA !== '';
        const isNumberB = !isNaN(valueB) && valueB !== '';
        if (isNumberA && isNumberB) {
            return sortState.asc ? Number(valueA) - Number(valueB) : Number(valueB) - Number(valueA);
        }
        const stringA = valueA.toString().toLowerCase();
        const stringB = valueB.toString().toLowerCase();
        if (stringA < stringB) return sortState.asc ? -1 : 1;
        if (stringA > stringB) return sortState.asc ? 1 : -1;
        return 0;
    });
}

export function reorderColumns(array, fromIndex, toIndex) {
    const next = array.slice();
    const [item] = next.splice(fromIndex, 1);
    next.splice(toIndex, 0, item);
    return next;
}
