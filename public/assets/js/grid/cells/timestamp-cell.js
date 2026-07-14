// assets/js/grid/cells/timestamp-cell.js — Timestamp cell: normalizes display (T->space, strips milliseconds + timezone); registers 'timestamp'.

import { CellRenderer } from './registry.js';
import { createInputCell } from './shared.js';

function normalizeTimestampDisplay(value) {
    if (!value) return '';
    // Replace T separator, strip milliseconds and timezone
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
