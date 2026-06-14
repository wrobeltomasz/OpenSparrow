import { attachCellEvents } from '../../grid_actions.js';
import { CellRenderer } from './registry.js';

function renderBooleanCell({ row, col, colCfg, isReadOnly }) {
    const td = document.createElement('td');
    const input = document.createElement('input');
    input.type = 'checkbox';
    const value = row[col + '__display'] ?? row[col] ?? '';
    // Accept Postgres (t/true/bool) and MySQL tinyint(1) (1/"1") truthy forms.
    input.checked = value === true || value === 't' || value === 'true'
        || value === 1 || value === '1';
    input.dataset.column = col;
    input.dataset.id = row['id'];

    if (colCfg.readonly || isReadOnly) input.disabled = true;
    if (!isReadOnly) attachCellEvents(input);

    td.appendChild(input);
    return td;
}

CellRenderer.register('boolean', renderBooleanCell);
export { renderBooleanCell };
