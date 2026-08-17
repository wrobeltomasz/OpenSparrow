// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { attachCellEvents } from '../../grid_actions.js';
import { highlightInto } from '../../util/html.js';
import { state } from '../state.js';
import { CellRenderer } from './registry.js';
import { makeInlineLink } from '../dom.js';

function renderTextCell({ row, col: column, colCfg: columnConfig, isReadOnly }) {
    const td = document.createElement('td');
    const value = row[column + '__display'] ?? row[column] ?? '';

    if (!columnConfig.readonly && !isReadOnly) {
        td.contentEditable = 'true';
        td.classList.add('editable');
    }
    td.dataset.column = column;
    td.dataset.id = row['id'];

    if (columnConfig.validation_regexp) {
        td.dataset.pattern = columnConfig.validation_regexp;
        td.dataset.message = columnConfig.validation_message || 'Invalid format';
    }

    const stringValue = String(value).trim();

    if (/^https?:\/\//i.test(stringValue)) {
        td.appendChild(makeInlineLink(stringValue, stringValue, {
            newTab: true,
            onClick: e => { e.preventDefault(); window.open(stringValue, '_blank'); },
        }));
    } else if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(stringValue)) {
        td.appendChild(makeInlineLink(`mailto:${stringValue}`, stringValue, {
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
