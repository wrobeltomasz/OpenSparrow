// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export function highlightInto(td, value, term) {
    const str = String(value);
    if (!term) { td.textContent = str; return; }
    const lower = str.toLowerCase();
    const lowerTerm = term.toLowerCase();
    let pos = 0;
    let start;
    let found = false;
    while ((start = lower.indexOf(lowerTerm, pos)) !== -1) {
        found = true;
        if (start > pos) td.append(str.slice(pos, start));
        const mark = document.createElement('mark');
        mark.className = 'search-highlight';
        mark.textContent = str.slice(start, start + term.length);
        td.append(mark);
        pos = start + term.length;
    }
    if (!found) { td.textContent = str; return; }
    if (pos < str.length) td.append(str.slice(pos));
}
