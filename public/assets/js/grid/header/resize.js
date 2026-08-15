// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export function initColumnResize(th) {
    const resizer = document.createElement('div');
    resizer.className = 'col-resizer';
    th.appendChild(resizer);

    resizer.addEventListener('mousedown', e => {
        e.preventDefault();
        e.stopPropagation();
        const startX = e.pageX;
        const startWidth = th.offsetWidth;

        const onMove = mv => {
            const w = startWidth + (mv.pageX - startX);
            if (w > 30) {
                th.style.width = `${w}px`;
                th.style.minWidth = `${w}px`;
                th.style.maxWidth = `${w}px`;
            }
        };
        const onUp = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });
}
