// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const COL_CLASS = 'col-hover';
const CELL_CLASS = 'cell-hover';

export function attachCrosshair(table) {
    let lastIndex = -1;
    let lastCell = null;

    const clear = () => {
        if (lastIndex !== -1) {
            table.querySelectorAll('.' + COL_CLASS)
                .forEach(cell => cell.classList.remove(COL_CLASS));
            lastIndex = -1;
        }
        if (lastCell) {
            lastCell.classList.remove(CELL_CLASS);
            lastCell = null;
        }
    };

    const paint = (index) => {
        if (index === lastIndex) return;
        clear();
        if (index < 0) return;
        for (const row of table.rows) {
            const cell = row.cells[index];
            if (cell && cell.colSpan === 1) cell.classList.add(COL_CLASS);
        }
        lastIndex = index;
    };

    table.addEventListener('mouseover', (event) => {
        const cell = event.target.closest('td, th');
        if (!cell || cell.closest('table') !== table) return clear();
        paint(cell.colSpan === 1 ? cell.cellIndex : -1);
        if (cell !== lastCell) {
            if (lastCell) lastCell.classList.remove(CELL_CLASS);
            cell.classList.add(CELL_CLASS);
            lastCell = cell;
        }
    });

    table.addEventListener('mouseleave', clear);
}
