// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { state, reorderColumns } from '../state.js';

export function initColumnDnD(th, column, onReorder) {
    th.draggable = true;

    th.addEventListener('dragstart', event => {
        event.dataTransfer.setData('text/plain', column);
        event.dataTransfer.effectAllowed = 'move';
        setTimeout(() => th.classList.add('dragging'), 0);
    });

    th.addEventListener('dragend', () => th.classList.remove('dragging'));

    th.addEventListener('dragover', event => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        th.classList.add('drag-over');
    });

    th.addEventListener('dragleave', () => th.classList.remove('drag-over'));

    th.addEventListener('drop', event => {
        event.preventDefault();
        th.classList.remove('drag-over');
        const draggedColumn = event.dataTransfer.getData('text/plain');
        if (!draggedColumn || draggedColumn === column) return;

        const fromIndex = state.displayedColumns.indexOf(draggedColumn);
        const toIndex = state.displayedColumns.indexOf(column);
        if (fromIndex > -1 && toIndex > -1) {
            state.displayedColumns = reorderColumns(state.displayedColumns, fromIndex, toIndex);
            onReorder();
        }
    });
}
