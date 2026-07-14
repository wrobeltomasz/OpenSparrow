// assets/js/grid/cells/boolean-cell.js — Boolean cell: checkbox accepting Postgres (t/true) and MySQL tinyint (1/"1") truthy forms; registers 'boolean'.

import { CellRenderer } from './registry.js';
import { createInputCell } from './shared.js';

function renderBooleanCell({ row, col, colCfg, isReadOnly }) {
    const value = row[col + '__display'] ?? row[col] ?? '';
    return createInputCell({
        row, col, colCfg, isReadOnly,
        makeControl: () => {
            const input = document.createElement('input');
            input.type = 'checkbox';
            // Accept Postgres (t/true/bool) and MySQL tinyint(1) (1/"1") truthy forms.
            input.checked = value === true || value === 't' || value === 'true'
                || value === 1 || value === '1';
            return input;
        },
    });
}

CellRenderer.register('boolean', renderBooleanCell);
export { renderBooleanCell };
