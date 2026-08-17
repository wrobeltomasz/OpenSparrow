// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export function highlightInto(td, value, term) {
    const string = String(value);
    if (!term) { td.textContent = string; return; }
    const lower = string.toLowerCase();
    const lowerTerm = term.toLowerCase();
    let position = 0;
    let start;
    let found = false;
    while ((start = lower.indexOf(lowerTerm, position)) !== -1) {
        found = true;
        if (start > position) td.append(string.slice(position, start));
        const mark = document.createElement('mark');
        mark.className = 'search-highlight';
        mark.textContent = string.slice(start, start + term.length);
        td.append(mark);
        position = start + term.length;
    }
    if (!found) { td.textContent = string; return; }
    if (position < string.length) td.append(string.slice(position));
}
