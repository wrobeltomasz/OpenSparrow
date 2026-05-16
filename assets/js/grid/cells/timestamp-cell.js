import { attachCellEvents } from '../../grid_actions.js';
import { CellRenderer } from './registry.js';

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
    const td = document.createElement('td');
    const rawVal = row[col + '__display'] ?? row[col] ?? '';

    const input = document.createElement('input');
    input.type = 'datetime-local';
    input.step = '1';
    input.value = toDatetimeLocalValue(rawVal);
    input.dataset.column = col;
    input.dataset.id = row['id'];

    if (colCfg.readonly || isReadOnly) {
        input.disabled = true;
    } else {
        attachCellEvents(input);
    }

    td.appendChild(input);
    return td;
}

CellRenderer.register('timestamp', renderTimestampCell);
export { renderTimestampCell, normalizeTimestampDisplay };
