// assets/js/grid/menu.js — buildMenu(): renders the table-list sidebar from schema.tables (initial table from ?table= or first) and handles table selection.

import { debugLog } from '../debug.js';

export function buildMenu(schema, menuEl, gridTitleEl, addRowBtn, onTableSelect) {
    const ul = document.createElement('ul');
    const urlParams = new URLSearchParams(window.location.search);
    const urlTable = urlParams.get('table');
    const firstTable = Object.keys(schema.tables)[0];
    const initialTable = (urlTable && schema.tables[urlTable]) ? urlTable : firstTable;

    for (const [t, cfg] of Object.entries(schema.tables)) {
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = '#';
        if (t === initialTable) a.classList.add('active');

        if (cfg.icon) {
            const img = document.createElement('img');
            img.src = cfg.icon;
            img.alt = '';
            a.appendChild(img);
        }

        const linkLabel = cfg.display_name || t;
        const textSpan = document.createElement('span');
        textSpan.className = 'menu-text';
        textSpan.textContent = linkLabel;
        a.appendChild(textSpan);
        a.title = linkLabel;
        a.setAttribute('aria-label', linkLabel);

        a.addEventListener('click', e => {
            e.preventDefault();
            menuEl.querySelectorAll('a').forEach(l => l.classList.remove('active'));
            a.classList.add('active');
            window.history.pushState({}, document.title, window.location.pathname);
            onTableSelect(t);
        });

        li.appendChild(a);
        ul.appendChild(li);
    }

    menuEl.replaceChildren(ul);
    debugLog('Menu built', Object.keys(schema.tables));
}
