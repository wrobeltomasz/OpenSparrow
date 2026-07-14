// assets/js/grid/cells/shared.js — createInputCell(): shared <td> wiring for simple
// input/select cell renderers (dataset.column/id, readonly, attachCellEvents).

import { attachCellEvents } from '../../grid_actions.js';

export function createInputCell({ row, col, colCfg, isReadOnly, makeControl }) {
    const td = document.createElement('td');
    const control = makeControl();
    control.dataset.column = col;
    control.dataset.id = row['id'];

    if (colCfg.readonly || isReadOnly) control.disabled = true;
    if (!isReadOnly) attachCellEvents(control);

    td.appendChild(control);
    return td;
}
