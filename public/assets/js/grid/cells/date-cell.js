// assets/js/grid/cells/date-cell.js — Date cell: <input type=date>; normalizes DB/ISO/dd.mm.yyyy values to yyyy-mm-dd; registers 'date'.

import { attachCellEvents } from '../../grid_actions.js';
import { CellRenderer } from './registry.js';

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

function renderDateCell({ row, col, colCfg, isReadOnly }) {
    const td = document.createElement('td');
    const input = document.createElement('input');
    input.type = 'date';
    input.value = normalizeDateValue(row[col + '__display'] ?? row[col] ?? '');
    input.dataset.column = col;
    input.dataset.id = row['id'];

    if (colCfg.readonly || isReadOnly) input.disabled = true;
    if (!isReadOnly) attachCellEvents(input);

    td.appendChild(input);
    return td;
}

CellRenderer.register('date', renderDateCell);
export { renderDateCell, normalizeDateValue };
