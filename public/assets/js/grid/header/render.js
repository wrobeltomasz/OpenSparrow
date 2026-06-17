// assets/js/grid/header/render.js — renderThead(): builds the header row (select-all + columns) and wires per-column sort, resize and drag-and-drop reordering.

import { state } from '../state.js';
import { toggleSortState } from './sort.js';
import { initColumnResize } from './resize.js';
import { initColumnDnD } from './dnd.js';

export function renderThead(schema, isReadOnly, onRerender) {
    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');
    const subtables = schema.tables[state.currentTable]?.subtables || [];

    if (!isReadOnly) {
        const thSelect = document.createElement('th');
        thSelect.className = 'th-select';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'select-all-cb';
        cb.setAttribute('aria-label', 'Select all rows');
        cb.title = 'Select / deselect all';
        cb.addEventListener('change', e => {
            const allIds = state.filteredData.map(r => r.id);
            if (e.target.checked) {
                allIds.forEach(id => state.selectedIds.add(id));
            } else {
                allIds.forEach(id => state.selectedIds.delete(id));
            }
            document.querySelectorAll('.row-select-cb').forEach(rowCb => {
                rowCb.checked = e.target.checked;
            });
            document.dispatchEvent(new CustomEvent('selectionChanged'));
        });
        thSelect.appendChild(cb);
        headRow.appendChild(thSelect);
    }

    if (subtables.length > 0) {
        const th = document.createElement('th');
        th.style.width = '30px';
        headRow.appendChild(th);
    }

    for (const col of state.displayedColumns) {
        const th = document.createElement('th');
        const colCfg = schema.tables[state.currentTable].columns[col] || {};
        th.dataset.col = col;

        const thLabel = document.createElement('span');
        thLabel.className = 'th-label';
        if (colCfg.description) {
            th.title = colCfg.description;
            thLabel.style.borderBottom = '1px dotted currentColor';
            thLabel.style.cursor = 'help';
        }

        if (colCfg.type === 'virtual') {
            const badge = document.createElement('span');
            badge.className = 'th-virtual-badge';
            badge.textContent = 'f(x)';
            thLabel.appendChild(badge);
        }

        let labelText = colCfg.display_name || col;
        if (state.sortState.column === col) {
            labelText += state.sortState.asc ? ' ↑' : ' ↓';
        }
        thLabel.appendChild(document.createTextNode(labelText));
        th.appendChild(thLabel);

        th.style.cursor = 'pointer';
        th.addEventListener('click', e => {
            if (e.target.classList.contains('col-resizer')) return;
            toggleSortState(col);
            onRerender();
        });

        initColumnResize(th);
        initColumnDnD(th, col, onRerender);
        headRow.appendChild(th);
    }

    // M2M columns — one TH per configured relationship
    const m2mList = schema.tables[state.currentTable]?.many_to_many || [];
    for (const cfg of m2mList) {
        const thM2m = document.createElement('th');
        thM2m.className = 'th-m2m';
        const thM2mLabel = document.createElement('span');
        thM2mLabel.className = 'th-label';
        thM2mLabel.textContent = cfg.label || 'Related';
        thM2m.appendChild(thM2mLabel);
        headRow.appendChild(thM2m);
    }

    if (!isReadOnly) {
        const thActions = document.createElement('th');
        thActions.className = 'th-actions';
        headRow.appendChild(thActions);
    }

    thead.appendChild(headRow);
    return thead;
}
