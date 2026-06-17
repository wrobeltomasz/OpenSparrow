// assets/js/grid/cells/text-cell.js — Text cell: contentEditable td (unless readonly) with search-term highlighting; registers 'text' (the default renderer).

import { attachCellEvents } from '../../grid_actions.js';
import { highlightInto } from '../../util/html.js';
import { state } from '../state.js';
import { CellRenderer } from './registry.js';

function renderTextCell({ row, col, colCfg, isReadOnly }) {
    const td = document.createElement('td');
    const value = row[col + '__display'] ?? row[col] ?? '';

    if (!colCfg.readonly && !isReadOnly) {
        td.contentEditable = 'true';
        td.classList.add('editable');
    }
    td.dataset.column = col;
    td.dataset.id = row['id'];

    if (colCfg.validation_regexp) {
        td.dataset.pattern = colCfg.validation_regexp;
        td.dataset.message = colCfg.validation_message || 'Invalid format';
    }

    const strVal = String(value).trim();

    if (/^https?:\/\//i.test(strVal)) {
        const a = document.createElement('a');
        a.href = strVal;
        a.target = '_blank';
        a.textContent = strVal;
        a.style.color = 'var(--accent)';
        a.style.textDecoration = 'underline';
        a.style.cursor = 'pointer';
        a.addEventListener('click', e => { e.preventDefault(); window.open(strVal, '_blank'); });
        td.appendChild(a);
    } else if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(strVal)) {
        const a = document.createElement('a');
        a.href = `mailto:${strVal}`;
        a.textContent = strVal;
        a.style.color = 'var(--accent)';
        a.style.textDecoration = 'underline';
        a.style.cursor = 'pointer';
        a.addEventListener('click', e => e.stopPropagation());
        td.appendChild(a);
    } else {
        highlightInto(td, value, state.searchTerm);
    }

    if (!isReadOnly) attachCellEvents(td);

    td.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); td.blur(); }
    });
    td.addEventListener('paste', e => {
        e.preventDefault();
        const text = (e.originalEvent || e).clipboardData.getData('text/plain');
        document.execCommand('insertText', false, text);
    });

    return td;
}

CellRenderer.register('text', renderTextCell);
export { renderTextCell };
