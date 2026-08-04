// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// assets/js/grid/ai-context.js — builds the "current table data" context block for the AI assistant.
// Reads the grid DATA MODEL (state + pagination), not the rendered DOM, so the model gets raw
// values (unformatted numbers/dates), the real record counts and a schema-driven column choice.
// Published as window.CURRENT_GRID_CONTEXT by app.js; consumed by agent-panel.js.

import { getState } from '../grid.js';
import { getPageRows, getPageState } from '../pagination.js';

// Maximum grid rows and columns included in the page context sent to the model.
export const MAX_CONTEXT_ROWS = 50;
export const MAX_CONTEXT_COLS = 12;

// Column importance for the MAX_CONTEXT_COLS budget: lower rank wins.
// Position in the grid is only the tie-breaker, so reordering columns by drag-and-drop
// no longer changes which data the assistant sees.
function columnRank(col, colCfg, isFk) {
    if (col.toLowerCase() === 'id') return 0;
    if (colCfg.required) return 1;
    if (isFk || (colCfg.type || '').toLowerCase() === 'enum') return 2;
    const type = (colCfg.type || '').toLowerCase();
    if (type === 'virtual') return 5;
    if (type.includes('text') && !type.includes('varchar')) return 4;
    return 3;
}

function formatValue(row, col) {
    // Foreign keys carry a human-readable label; a bare id would be meaningless to the model.
    const raw = row[col + '__display'] ?? row[col];
    if (raw === null || raw === undefined) return '';
    if (typeof raw === 'boolean') return raw ? 'true' : 'false';
    if (typeof raw === 'object') return JSON.stringify(raw);
    return String(raw).replace(/\s+/g, ' ').trim();
}

// Returns the context text, or '' when no grid data is on screen.
export function buildGridContext() {
    const { currentTable, displayedColumns, filteredData, totalRows, wasTruncated } = getState();
    if (!currentTable || !Array.isArray(displayedColumns) || displayedColumns.length === 0) return '';

    const pageRows = getPageRows();
    if (pageRows.length === 0) return '';

    const tableCfg = window.schema?.tables?.[currentTable] || {};
    const colCfgs  = tableCfg.columns || {};
    const fks      = tableCfg.foreign_keys || {};

    let columns  = displayedColumns.slice();
    const hiddenCols = Math.max(0, columns.length - MAX_CONTEXT_COLS);
    if (hiddenCols > 0) {
        columns = columns
            .map((col, pos) => ({ col, pos, rank: columnRank(col, colCfgs[col] || {}, !!fks[col]) }))
            .sort((a, b) => (a.rank - b.rank) || (a.pos - b.pos))
            .slice(0, MAX_CONTEXT_COLS)
            .sort((a, b) => a.pos - b.pos)   // restore left-to-right order for readability
            .map(c => c.col);
    }

    const rows       = pageRows.slice(0, MAX_CONTEXT_ROWS);
    const hiddenRows = pageRows.length - rows.length;

    const { currentPage, pageSize } = getPageState();
    const filteredTotal = filteredData.length;
    const totalPages    = Math.max(1, Math.ceil(filteredTotal / pageSize));
    const from          = (currentPage - 1) * pageSize + 1;
    const to            = from + rows.length - 1;

    // The header is binding for the model (see the COUNTING section of the prompt): it either
    // declares the block a complete set — every matching record is here, so totals are fair
    // game — or ONE PAGE of a larger set, where aggregate questions must be refused.
    const isCompleteSet = rows.length === filteredTotal
        && totalPages === 1
        && hiddenRows === 0
        && !(wasTruncated && totalRows > filteredTotal);

    let header;
    if (isCompleteSet) {
        header = `table: ${currentTable} — COMPLETE SET: all ${filteredTotal} matching record(s) are`
            + ' included below (current filters applied, page 1 of 1). No rows are missing, so you MAY'
            + ' count, sum and average over these rows.';
    } else {
        header = `table: ${currentTable} — CURRENT PAGE ONLY: rows ${from}-${to} of ${filteredTotal} matching record(s), `
            + `page ${currentPage} of ${totalPages}`;
        if (wasTruncated && totalRows > filteredTotal) {
            header += `; ${totalRows} record(s) exist in the database beyond the loaded set`;
        }
        header += '. Rows outside this page are NOT included — never compute totals, counts or averages'
            + ' over the whole table from this excerpt.';
    }

    let text = header + '\n' + columns.join(' | ') + '\n';
    rows.forEach(row => {
        text += columns.map(col => formatValue(row, col)).join(' | ') + '\n';
    });
    if (hiddenRows > 0) text += `...(${hiddenRows} more rows on this page not shown)\n`;
    if (hiddenCols > 0) text += `...(${hiddenCols} more columns not shown)\n`;
    return text;
}
