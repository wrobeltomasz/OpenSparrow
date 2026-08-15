// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { CellRenderer } from './registry.js';
import { createInputCell } from './shared.js';

function normalizeTimestampDisplay(value) {
    if (!value) return '';

    return String(value)
        .replace('T', ' ')
        .replace(/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.\d+/, '$1')
        .replace(/([+-]\d{2}(:\d{2})?|Z)$/, '')
        .trim();
}

function toDatetimeLocalValue(value) {
    if (!value) return '';
    return normalizeTimestampDisplay(value).replace(' ', 'T');
}

function renderTimestampCell({ row, col, colCfg, isReadOnly }) {
    return createInputCell({
        row, col, colCfg, isReadOnly,
        makeControl: () => {
            const input = document.createElement('input');
            input.type = 'datetime-local';
            input.step = '1';
            input.value = toDatetimeLocalValue(row[col + '__display'] ?? row[col] ?? '');
            return input;
        },
    });
}

CellRenderer.register('timestamp', renderTimestampCell);
export { renderTimestampCell, normalizeTimestampDisplay };
