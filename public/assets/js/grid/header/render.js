// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { state } from '../state.js';
import { I18n } from '../../i18n.js';
import { toggleSortState } from './sort.js';
import { initColumnResize } from './resize.js';
import { initColumnDnD } from './dnd.js';

export function renderThead(schema, isReadOnly, onRerender, getPageRows) {
    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');
    const subtables = schema.tables[state.currentTable]?.subtables || [];

    if (!isReadOnly) {
        const thSelect = document.createElement('th');
        thSelect.className = 'th-select';
        const callback = document.createElement('input');
        callback.type = 'checkbox';
        callback.className = 'select-all-cb';
        callback.setAttribute('aria-label', I18n.t('grid.select_all_rows'));
        callback.title = I18n.t('grid.select_all_toggle');
        callback.addEventListener('change', e => {
            const pageIds = getPageRows().map(r => r.id);
            if (e.target.checked) {
                pageIds.forEach(id => state.selectedIds.add(id));
            } else {
                pageIds.forEach(id => state.selectedIds.delete(id));
            }
            document.querySelectorAll('.row-select-cb').forEach(rowCallback => {
                rowCallback.checked = e.target.checked;
            });
            document.dispatchEvent(new CustomEvent('selectionChanged'));
        });
        thSelect.appendChild(callback);
        headRow.appendChild(thSelect);
    }

    if (subtables.length > 0) {
        const th = document.createElement('th');
        th.style.width = '30px';
        headRow.appendChild(th);
    }

    for (const column of state.displayedColumns) {
        const th = document.createElement('th');
        const columnConfig = schema.tables[state.currentTable].columns[column] || {};
        th.dataset.col = column;

        const thLabel = document.createElement('span');
        thLabel.className = 'th-label';
        if (columnConfig.description) {
            th.title = columnConfig.description;
        }

        if (columnConfig.type === 'virtual') {
            const badge = document.createElement('span');
            badge.className = 'th-virtual-badge';
            badge.textContent = 'f(x)';
            thLabel.appendChild(badge);
        }

        let labelText = columnConfig.display_name || column;
        if (state.sortState.column === column) {
            labelText += state.sortState.asc ? ' ↑' : ' ↓';
        }
        thLabel.appendChild(document.createTextNode(labelText));
        th.appendChild(thLabel);

        th.style.cursor = 'pointer';
        th.addEventListener('click', e => {
            if (e.target.classList.contains('col-resizer')) return;
            toggleSortState(column);
            onRerender();
        });

        initColumnResize(th);
        initColumnDnD(th, column, onRerender);
        headRow.appendChild(th);
    }

    const m2mList = schema.tables[state.currentTable]?.many_to_many || [];
    for (const config of m2mList) {
        const thM2m = document.createElement('th');
        thM2m.className = 'th-m2m';
        const thM2mLabel = document.createElement('span');
        thM2mLabel.className = 'th-label';
        thM2mLabel.textContent = config.label || 'Related';
        thM2m.appendChild(thM2mLabel);
        headRow.appendChild(thM2m);
    }

    const imagesConfig = schema.tables[state.currentTable]?.images;
    if (imagesConfig?.enabled && imagesConfig.show_in_grid) {
        const thImage = document.createElement('th');
        thImage.className = 'th-images';
        const thImageLabel = document.createElement('span');
        thImageLabel.className = 'th-label';
        thImageLabel.textContent = imagesConfig.label || I18n.t('images.label');
        thImage.appendChild(thImageLabel);
        headRow.appendChild(thImage);
    }

    if (!isReadOnly) {
        const thActions = document.createElement('th');
        thActions.className = 'th-actions';
        headRow.appendChild(thActions);
    }

    thead.appendChild(headRow);
    return thead;
}
