// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { CellRenderer } from './registry.js';
import { createInputCell } from './shared.js';

function renderEnumCell({ row, col: column, colCfg: columnConfig, isReadOnly }) {
    const value = row[column + '__display'] ?? row[column] ?? '';
    return createInputCell({
        row, col: column, colCfg: columnConfig, isReadOnly,
        makeControl: () => {
            const select = document.createElement('select');
            const applyColor = cellValue => {
                select.style.backgroundColor = columnConfig.enum_colors?.[cellValue] ?? '';
            };

            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = '-- Select --';
            select.appendChild(emptyOption);

            if (Array.isArray(columnConfig.options)) {
                columnConfig.options.forEach(optionValue => {
                    const option = document.createElement('option');
                    option.value = optionValue;
                    option.textContent = optionValue;
                    if (optionValue === value) option.selected = true;
                    if (columnConfig.enum_colors?.[optionValue]) option.style.backgroundColor = columnConfig.enum_colors[optionValue];
                    select.appendChild(option);
                });
            }

            applyColor(value);
            select.addEventListener('change', event => applyColor(event.target.value));
            return select;
        },
    });
}

CellRenderer.register('enum', renderEnumCell);
export { renderEnumCell };
