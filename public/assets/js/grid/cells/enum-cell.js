// assets/js/grid/cells/enum-cell.js — Enum cell: <select> of allowed values with optional per-value background colour; registers 'enum'.

import { attachCellEvents } from '../../grid_actions.js';
import { CellRenderer } from './registry.js';

function renderEnumCell({ row, col, colCfg, isReadOnly }) {
    const td = document.createElement('td');
    const select = document.createElement('select');
    select.dataset.column = col;
    select.dataset.id = row['id'];

    const applyColor = val => {
        select.style.backgroundColor = colCfg.enum_colors?.[val] ?? '';
    };

    const emptyOpt = document.createElement('option');
    emptyOpt.value = '';
    emptyOpt.textContent = '-- Select --';
    select.appendChild(emptyOpt);

    const value = row[col + '__display'] ?? row[col] ?? '';

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
    if (colCfg.readonly || isReadOnly) select.disabled = true;
    select.addEventListener('change', e => applyColor(e.target.value));
    if (!isReadOnly) attachCellEvents(select);

    td.appendChild(select);
    return td;
}

CellRenderer.register('enum', renderEnumCell);
export { renderEnumCell };
