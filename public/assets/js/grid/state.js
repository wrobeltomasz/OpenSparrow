// assets/js/grid/state.js — Singleton grid state + helpers (sortRows, reorderColumns, selection)
// Shared mutable `state` (current table, full/filtered data, displayed columns, sort, fkCache, selectedIds, search mode) imported by all grid sub-modules.
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

// Public read-only snapshot used by external modules (pagination, export_csv, app.js)
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

// Pure sort — returns new array, never mutates
export function sortRows(rows, sortState) {
    if (!sortState.column) return rows;
    const col = sortState.column;
    return [...rows].sort((a, b) => {
        const valA = a[col + '__display'] ?? a[col] ?? '';
        const valB = b[col + '__display'] ?? b[col] ?? '';
        const isNumA = !isNaN(valA) && valA !== '';
        const isNumB = !isNaN(valB) && valB !== '';
        if (isNumA && isNumB) {
            return sortState.asc ? Number(valA) - Number(valB) : Number(valB) - Number(valA);
        }
        const strA = valA.toString().toLowerCase();
        const strB = valB.toString().toLowerCase();
        if (strA < strB) return sortState.asc ? -1 : 1;
        if (strA > strB) return sortState.asc ? 1 : -1;
        return 0;
    });
}

export function reorderColumns(arr, fromIndex, toIndex) {
    const next = arr.slice();
    const [item] = next.splice(fromIndex, 1);
    next.splice(toIndex, 0, item);
    return next;
}
