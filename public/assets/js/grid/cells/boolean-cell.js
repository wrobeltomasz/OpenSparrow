// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { CellRenderer } from './registry.js';
import { createInputCell } from './shared.js';

function renderBooleanCell({ row, col: column, colCfg: columnConfig, isReadOnly }) {
    const value = row[column + '__display'] ?? row[column] ?? '';
    return createInputCell({
        row, col: column, colCfg: columnConfig, isReadOnly,
        makeControl: () => {
            const input = document.createElement('input');
            input.type = 'checkbox';

            input.checked = value === true || value === 't' || value === 'true';
            return input;
        },
    });
}

CellRenderer.register('boolean', renderBooleanCell);
export { renderBooleanCell };
