// assets/js/grid/cells/virtual-cell.js — Computed/virtual column: computeVirtual(formula, row) evaluates op over cols (used at load-time pre-compute and in the renderer); registers 'virtual'.

import { CellRenderer } from './registry.js';

/**
 * Compute a virtual column value from a row using a formula definition.
 * Called both at load time (pre-compute into row) and in the cell renderer.
 *
 * formula: { op, cols: string[], separator?: string }
 */
export function computeVirtual(formula, row) {
    if (!formula?.op || !Array.isArray(formula.cols) || formula.cols.length === 0) return '';

    const rawVals = formula.cols.map(c => row[c] ?? '');

    switch (formula.op) {
        case 'sum': {
            return rawVals.reduce((acc, v) => acc + (parseFloat(v) || 0), 0);
        }
        case 'subtract': {
            const [first, ...rest] = rawVals.map(v => parseFloat(v) || 0);
            return rest.reduce((acc, v) => acc - v, first ?? 0);
        }
        case 'multiply': {
            return rawVals.reduce((acc, v) => acc * (parseFloat(v) || 0), 1);
        }
        case 'divide': {
            const dividend = parseFloat(rawVals[0]) || 0;
            const divisor  = parseFloat(rawVals[1]);
            return divisor ? dividend / divisor : 0;
        }
        case 'average': {
            const nums = rawVals.map(v => parseFloat(v)).filter(v => !Number.isNaN(v));
            return nums.length ? nums.reduce((a, b) => a + b, 0) / nums.length : 0;
        }
        case 'concat': {
            const sep = formula.separator ?? ' ';
            return rawVals.filter(v => v !== '' && v !== null && v !== undefined).join(sep);
        }
        default:
            return '';
    }
}

function formatVirtualValue(value) {
    if (typeof value === 'number' && !Number.isNaN(value)) {
        return Number.isInteger(value) ? String(value) : value.toFixed(2);
    }
    return String(value ?? '');
}

function renderVirtualCell({ row, col, colCfg }) {
    const td = document.createElement('td');
    td.dataset.column = col;
    td.dataset.id = row['id'];

    // Value already pre-computed by loadTable; fall back to on-the-fly compute
    const value = row[col] !== undefined
        ? row[col]
        : computeVirtual(colCfg.formula, row);

    td.textContent = formatVirtualValue(value);
    td.style.color = 'var(--muted)';
    td.style.fontStyle = 'italic';

    return td;
}

CellRenderer.register('virtual', renderVirtualCell);
export { renderVirtualCell };
