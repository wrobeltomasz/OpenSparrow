// assets/js/grid/crosshair.js — row + column hover highlight (crosshair) for the data grid.
// The row half is pure CSS (tbody tr:hover td); the column half needs JS because CSS
// cannot express "cells sharing the hovered cell's index".

const COL_CLASS = 'col-hover';

export function attachCrosshair(table) {
    let lastIndex = -1;

    const clear = () => {
        if (lastIndex === -1) return;
        table.querySelectorAll('.' + COL_CLASS)
            .forEach(cell => cell.classList.remove(COL_CLASS));
        lastIndex = -1;
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

    table.addEventListener('mouseover', (e) => {
        const cell = e.target.closest('td, th');
        if (!cell || cell.closest('table') !== table) return clear();
        paint(cell.colSpan === 1 ? cell.cellIndex : -1);
    });

    table.addEventListener('mouseleave', clear);
}
