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
            onClick: event => { event.preventDefault(); window.open(stringValue, '_blank'); },
        }));
    } else if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(stringValue)) {
        td.appendChild(makeInlineLink(`mailto:${stringValue}`, stringValue, {
            onClick: event => event.stopPropagation(),
        }));
    } else {
        highlightInto(td, value, state.searchTerm);
    }

    if (!isReadOnly) attachCellEvents(td);

    td.addEventListener('keydown', event => {
        if (event.key === 'Enter') { event.preventDefault(); td.blur(); }
    });
    td.addEventListener('paste', event => {
        event.preventDefault();
        const text = (event.originalEvent || event).clipboardData.getData('text/plain');
        document.execCommand('insertText', false, text);
    });

    return td;
}

CellRenderer.register('text', renderTextCell);
export { renderTextCell };
