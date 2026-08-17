// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { CellRenderer } from './registry.js';
import { createInputCell } from './shared.js';

function normalizeDateValue(value) {
    if (!value) return '';
    if (typeof value === 'string') {
        const dbMatch = value.match(/^(\d{4}-\d{2}-\d{2})/);
        if (dbMatch) return dbMatch[1];
        const iso = value.includes('T') ? value.split('T')[0] : value;
        const m = iso.match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
        if (m) return `${m[3]}-${m[2]}-${m[1]}`;
        return iso;
    }
    return '';
}

function renderDateCell({ row, col: column, colCfg: columnConfig, isReadOnly }) {
    return createInputCell({
        row, col: column, colCfg: columnConfig, isReadOnly,
        makeControl: () => {
            const input = document.createElement('input');
            input.type = 'date';
            input.value = normalizeDateValue(row[column + '__display'] ?? row[column] ?? '');
            return input;
        },
    });
}

CellRenderer.register('date', renderDateCell);
export { renderDateCell, normalizeDateValue };
