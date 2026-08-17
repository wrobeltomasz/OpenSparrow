// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { CellRenderer } from './registry.js';

export function computeVirtual(formula, row) {
    if (!formula?.op || !Array.isArray(formula.cols) || formula.cols.length === 0) return '';

    const rawValues = formula.cols.map(c => row[c] ?? '');

    switch (formula.op) {
        case 'sum': {
            return rawValues.reduce((acc, v) => acc + (parseFloat(v) || 0), 0);
        }
        case 'subtract': {
            const [first, ...rest] = rawValues.map(v => parseFloat(v) || 0);
            return rest.reduce((acc, v) => acc - v, first ?? 0);
        }
        case 'multiply': {
            return rawValues.reduce((acc, v) => acc * (parseFloat(v) || 0), 1);
        }
        case 'divide': {
            const dividend = parseFloat(rawValues[0]) || 0;
            const divisor  = parseFloat(rawValues[1]);
            return divisor ? dividend / divisor : 0;
        }
        case 'average': {
            const nums = rawValues.map(v => parseFloat(v)).filter(v => !Number.isNaN(v));
            return nums.length ? nums.reduce((a, b) => a + b, 0) / nums.length : 0;
        }
        case 'concat': {
            const sep = formula.separator ?? ' ';
            return rawValues.filter(v => v !== '' && v !== null && v !== undefined).join(sep);
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

function renderVirtualCell({ row, col: column, colCfg: columnConfig }) {
    const td = document.createElement('td');
    td.dataset.column = column;
    td.dataset.id = row['id'];

    const value = row[column] !== undefined
        ? row[column]
        : computeVirtual(columnConfig.formula, row);

    td.textContent = formatVirtualValue(value);
    td.style.color = 'var(--muted)';
    td.style.fontStyle = 'italic';

    return td;
}

CellRenderer.register('virtual', renderVirtualCell);
export { renderVirtualCell };
