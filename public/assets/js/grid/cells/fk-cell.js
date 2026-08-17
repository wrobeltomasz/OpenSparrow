// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { attachCellEvents } from '../../grid_actions.js';
import { state } from '../state.js';
import { CellRenderer } from './registry.js';

async function renderFkCell({ row, col: column, colCfg: columnConfig, schema, isReadOnly }) {
    const td = document.createElement('td');
    const input = document.createElement('input');
    input.type = 'search';

    const dlId = `fk_${state.currentTable}_${column}_${row['id']}`;
    input.setAttribute('list', dlId);
    input.dataset.column = column;
    input.dataset.id = row['id'];

    if (columnConfig.readonly || isReadOnly) input.disabled = true;

    const datalist = document.createElement('datalist');
    datalist.id = dlId;

    const fkConfig = schema.tables[state.currentTable].foreign_keys[column];
    const dispColumns = Array.isArray(fkConfig.display_column)
        ? fkConfig.display_column
        : [fkConfig.display_column || 'id'];
    const cacheKey = `${state.currentTable}_${column}`;
    let currentDisplay = '';

    if (state.fkCache.has(cacheKey)) {
        const referenceData = await state.fkCache.get(cacheKey);
        referenceData.forEach(r => {
            const option = document.createElement('option');
            const displayValue = dispColumns.map(c => r[c + '__display'] ?? r[c] ?? '').join(' - ') || r['id'];
            option.value = displayValue;
            option.dataset.realId = r['id'];
            if (String(r['id']) === String(row[column])) currentDisplay = displayValue;
            datalist.appendChild(option);
        });
    }

    input.value = currentDisplay;

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
