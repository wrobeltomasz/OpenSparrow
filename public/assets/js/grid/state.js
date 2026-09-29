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
    sortState: [],
    columnFilters: {},
    fkCache: new Map(),
    fkData: new Map(),
    searchTerm: '',
    containerEl: null,
    gridTitleEl: null,
    addRowBtn: null,
    selectedIds: new Set(),
    serverSearchMode: false,
    serverSearchActive: false,
    serverSortActive: false,
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
        columnFilters: state.columnFilters,
        serverSearchMode: state.serverSearchMode,
        serverSearchActive: state.serverSearchActive,
        wasTruncated: state.wasTruncated,
        loadedOffset: state.loadedOffset,
        totalRows: state.totalRows,
    };
}

export function setFilteredData(rows) {
    state.filteredData = rows.slice();
    state.unsortedFilteredData = rows.slice();
    if (state.sortState.length > 0 && !state.serverSortActive) {
        state.filteredData = sortRows(state.filteredData, state.sortState);
    }
}

export function resetFiltersState() {
    state.filteredData = state.fullData.slice();
    state.unsortedFilteredData = state.fullData.slice();
    state.sortState = [];
    state.searchTerm = '';
}

const MAX_SORT_RULES = 3;

export function compareByRule(left, right, rule) {
    const valueA = left[rule.column + '__display'] ?? left[rule.column] ?? '';
    const valueB = right[rule.column + '__display'] ?? right[rule.column] ?? '';
    const isNumberA = !isNaN(valueA) && valueA !== '';
    const isNumberB = !isNaN(valueB) && valueB !== '';
    if (isNumberA && isNumberB) {
        const difference = Number(valueA) - Number(valueB);
        return rule.asc ? difference : -difference;
    }
    const stringA = valueA.toString().toLowerCase();
    const stringB = valueB.toString().toLowerCase();
    if (stringA < stringB) return rule.asc ? -1 : 1;
    if (stringA > stringB) return rule.asc ? 1 : -1;
    return 0;
}

export function sortRows(rows, sortRules) {
    if (!Array.isArray(sortRules) || sortRules.length === 0) return rows;
    const rules = sortRules.slice(0, MAX_SORT_RULES);
    return [...rows].sort((left, right) => {
        for (const rule of rules) {
            const outcome = compareByRule(left, right, rule);
            if (outcome !== 0) return outcome;
        }
        return 0;
    });
}

export function reorderColumns(array, fromIndex, toIndex) {
    const next = array.slice();
    const [item] = next.splice(fromIndex, 1);
    next.splice(toIndex, 0, item);
    return next;
}
