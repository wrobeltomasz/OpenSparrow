// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { state, reorderColumns } from '../state.js';

export function initColumnDnD(th, col, onReorder) {
    th.draggable = true;

    th.addEventListener('dragstart', e => {
        e.dataTransfer.setData('text/plain', col);
        e.dataTransfer.effectAllowed = 'move';
        setTimeout(() => th.classList.add('dragging'), 0);
    });

    th.addEventListener('dragend', () => th.classList.remove('dragging'));

    th.addEventListener('dragover', e => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        th.classList.add('drag-over');
    });

    th.addEventListener('dragleave', () => th.classList.remove('drag-over'));

    th.addEventListener('drop', e => {
        e.preventDefault();
        th.classList.remove('drag-over');
        const draggedCol = e.dataTransfer.getData('text/plain');
        if (!draggedCol || draggedCol === col) return;

        const fromIndex = state.displayedColumns.indexOf(draggedCol);
        const toIndex = state.displayedColumns.indexOf(col);
        if (fromIndex > -1 && toIndex > -1) {
            state.displayedColumns = reorderColumns(state.displayedColumns, fromIndex, toIndex);
            onReorder();
        }
    });
}
