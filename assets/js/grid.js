// Barrel — re-exports public API from grid/ submodule.
// External importers (app.js, pagination.js, export_csv.js) keep their existing import paths.
export { loadTable, renderGrid, getState, setFilteredData, resetFilters, buildMenu, injectPagination, appendMoreRows, serverSearchRows } from './grid/index.js';
