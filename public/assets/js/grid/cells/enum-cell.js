// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { CellRenderer } from './registry.js';
import { createInputCell } from './shared.js';

function renderEnumCell({ row, col, colCfg, isReadOnly }) {
    const value = row[col + '__display'] ?? row[col] ?? '';
    return createInputCell({
        row, col, colCfg, isReadOnly,
        makeControl: () => {
            const select = document.createElement('select');
            const applyColor = val => {
                select.style.backgroundColor = colCfg.enum_colors?.[val] ?? '';
            };

            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '-- Select --';
            select.appendChild(emptyOpt);

            if (Array.isArray(colCfg.options)) {
                colCfg.options.forEach(optVal => {
                    const opt = document.createElement('option');
                    opt.value = optVal;
                    opt.textContent = optVal;
                    if (optVal === value) opt.selected = true;
                    if (colCfg.enum_colors?.[optVal]) opt.style.backgroundColor = colCfg.enum_colors[optVal];
                    select.appendChild(opt);
                });
            }

            applyColor(value);
            select.addEventListener('change', e => applyColor(e.target.value));
            return select;
        },
    });
}

CellRenderer.register('enum', renderEnumCell);
export { renderEnumCell };
