// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// assets/js/grid/cells/fk-cell.js — Foreign-key cell: searchable input backed by a <datalist> of related records; registers 'fk'.

import { attachCellEvents } from '../../grid_actions.js';
import { state } from '../state.js';
import { CellRenderer } from './registry.js';

async function renderFkCell({ row, col, colCfg, schema, isReadOnly }) {
    const td = document.createElement('td');
    const input = document.createElement('input');
    input.type = 'search';

    const dlId = `fk_${state.currentTable}_${col}_${row['id']}`;
    input.setAttribute('list', dlId);
    input.dataset.column = col;
    input.dataset.id = row['id'];

    if (colCfg.readonly || isReadOnly) input.disabled = true;

    const datalist = document.createElement('datalist');
    datalist.id = dlId;

    const fkCfg = schema.tables[state.currentTable].foreign_keys[col];
    const dispCols = Array.isArray(fkCfg.display_column)
        ? fkCfg.display_column
        : [fkCfg.display_column || 'id'];
    const cacheKey = `${state.currentTable}_${col}`;
    let currentDisplay = '';

    if (state.fkCache.has(cacheKey)) {
        const refData = await state.fkCache.get(cacheKey);
        refData.forEach(r => {
            const option = document.createElement('option');
            const displayValue = dispCols.map(c => r[c + '__display'] ?? r[c] ?? '').join(' - ') || r['id'];
            option.value = displayValue;
            option.dataset.realId = r['id'];
            if (String(r['id']) === String(row[col])) currentDisplay = displayValue;
            datalist.appendChild(option);
        });
    }

    input.value = currentDisplay;

    // Select all text on focus so typing immediately replaces it — deferred to a
    // microtask because a mouse click's default caret placement runs after focus
    // and would otherwise collapse the selection right back to a cursor position.
    input.addEventListener('focus', () => setTimeout(() => input.select(), 0));
    input.addEventListener('blur', () => {
        const isValid = Array.from(datalist.options).some(o => o.value === input.value);
        if (!isValid && input.value !== '') {
            input.value = currentDisplay;
        } else if (isValid) {
            currentDisplay = input.value;
        }
    });

    if (!isReadOnly) attachCellEvents(input);
    td.appendChild(input);
    td.appendChild(datalist);
    return td;
}

CellRenderer.register('fk', renderFkCell);
export { renderFkCell };
