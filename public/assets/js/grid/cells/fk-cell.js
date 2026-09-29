// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { attachCellEvents } from '../../grid_actions.js';
import { state } from '../state.js';
import { CellRenderer } from './registry.js';

function buildSharedDatalist(column, cacheKey) {
    const datalistId = `spw_fk_${cacheKey}`;
    let datalist = document.getElementById(datalistId);
    if (datalist) return datalistId;

    datalist = document.createElement('datalist');
    datalist.id = datalistId;

    const fkData = state.fkData.get(cacheKey);
    if (fkData) {
        fkData.options.forEach(option => {
            const optionElement = document.createElement('option');
            optionElement.value = option.value;
            optionElement.dataset.realId = option.realId;
            datalist.appendChild(optionElement);
        });
    }

    document.body.appendChild(datalist);
    return datalistId;
}

export function clearSharedFkDatalists() {
    document.querySelectorAll('datalist[id^="spw_fk_"]').forEach(element => element.remove());
}

function renderFkCell({ row, col: column, colCfg: columnConfig, isReadOnly }) {
    const td = document.createElement('td');
    const input = document.createElement('input');
    input.type = 'search';

    const cacheKey = `${state.currentTable}_${column}`;
    const datalistId = buildSharedDatalist(column, cacheKey);
    input.setAttribute('list', datalistId);
    input.dataset.column = column;
    input.dataset.id = row['id'];

    if (columnConfig.readonly || isReadOnly) input.disabled = true;

    const fkData = state.fkData.get(cacheKey);
    const displayById = new Map(fkData?.options.map(option => [option.realId, option.value]) || []);
    let currentDisplay = displayById.get(String(row[column])) ?? '';

    input.value = currentDisplay;

    input.addEventListener('focus', () => setTimeout(() => input.select(), 0));
    input.addEventListener('blur', () => {
        const isValid = fkData ? fkData.labels.has(input.value) : input.value === '';
        if (!isValid && input.value !== '') {
            input.value = currentDisplay;
        } else if (isValid) {
            currentDisplay = input.value;
        }
    });

    if (!isReadOnly) attachCellEvents(input);
    td.appendChild(input);
    return td;
}

CellRenderer.register('fk', renderFkCell);
export { renderFkCell };