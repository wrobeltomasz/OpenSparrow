// assets/js/grid.js — Barrel: re-exports the public data-grid API from the grid/ submodule (real logic lives in grid/index.js).
// External importers (app.js, pagination.js, export_csv.js) keep their existing import paths.
export { loadTable, renderGrid, getState, setFilteredData, resetFilters, buildMenu, injectPagination, appendMoreRows, serverSearchRows } from './grid/index.js';
