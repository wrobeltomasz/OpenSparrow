// assets/js/grid/cells/text-cell.js — Text cell: contentEditable td (unless readonly) with search-term highlighting; registers 'text' (the default renderer).

import { attachCellEvents } from '../../grid_actions.js';
import { highlightInto } from '../../util/html.js';
import { state } from '../state.js';
import { CellRenderer } from './registry.js';
import { makeInlineLink } from '../dom.js';

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
        td.appendChild(makeInlineLink(strVal, strVal, {
            newTab: true,
            onClick: e => { e.preventDefault(); window.open(strVal, '_blank'); },
        }));
    } else if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(strVal)) {
        td.appendChild(makeInlineLink(`mailto:${strVal}`, strVal, {
            onClick: e => e.stopPropagation(),
        }));
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
