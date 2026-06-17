// assets/js/util/html.js — highlightInto(td, value, term): appends text into a cell, wrapping case-insensitive matches of the search term in <mark class="search-highlight">. XSS-safe (textContent, no innerHTML).

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
