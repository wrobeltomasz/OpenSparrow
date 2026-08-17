// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { state } from '../state.js';
import { I18n } from '../../i18n.js';

export function buildExpandButton(row, schema, tr) {
    const tdExpand = document.createElement('td');
    const button = document.createElement('button');
    button.textContent = '>';
    button.className = 'c-expand-btn';

    const subtables = schema.tables[state.currentTable]?.subtables || [];
    const isReadOnly = window.USER_ROLE !== 'editor' && window.USER_ROLE !== 'admin';

    button.addEventListener('click', async () => {
        const next = tr.nextElementSibling;
        if (next?.classList.contains('drilldown-row')) {
            next.remove();
            button.textContent = '>';
            return;
        }

        button.textContent = 'v';
        const ddTr = document.createElement('tr');
        ddTr.className = 'drilldown-row';
        const ddTd = document.createElement('td');

        ddTd.colSpan = tr.cells.length || (state.displayedColumns.length + (isReadOnly ? 2 : 3));

        const loading = document.createElement('em');
        loading.textContent = I18n.t('common.loading');
        ddTd.appendChild(loading);
        ddTr.appendChild(ddTd);
        tr.after(ddTr);
        ddTd.replaceChildren();

        for (const sub of subtables) {
            ddTd.appendChild(await buildSubtableBlock(sub, row));
        }
    });

    tdExpand.dataset.expandRowId = String(row.id);
    tdExpand.appendChild(button);
    return tdExpand;
}

async function buildSubtableBlock(sub, row) {
    const wrapper = document.createElement('div');
    wrapper.className = 'drilldown-container';
    wrapper.style.marginBottom = '20px';

    const header = document.createElement('div');
    header.style.cssText = 'display:flex; align-items:center; gap:10px; margin-bottom:10px;';

    const title = document.createElement('h4');
    title.textContent = sub.label || sub.table;
    title.style.cssText = 'margin:0; font-size:14px; color:var(--text);';
    header.appendChild(title);

    const canWrite = window.USER_ROLE === 'editor' || window.USER_ROLE === 'admin';
    if (canWrite && sub.foreign_key) {
        const addButton = document.createElement('a');
        addButton.href = `create.php?table=${encodeURIComponent(sub.table)}&${encodeURIComponent(sub.foreign_key)}=${encodeURIComponent(row.id)}`;
        addButton.className = 'btn-action';
        addButton.textContent = '+';
        addButton.style.cssText = 'padding:1px 8px; font-size:16px; font-weight:bold; line-height:1.4; text-decoration:none;';
        addButton.title = I18n.t('grid.drilldown_add', { label: sub.label || sub.table });
        header.appendChild(addButton);
    }

    wrapper.appendChild(header);

    try {
        const result = await fetch(
            `api.php?api=list&table=${encodeURIComponent(sub.table)}&filter_col=${encodeURIComponent(sub.foreign_key)}&filter_val=${encodeURIComponent(row.id)}`,
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
        );
        const data = await result.json();
        const ul = document.createElement('ul');
        ul.className = 'drilldown-list';

        if (data.rows?.length > 0) {
            const columns = sub.columns_to_show?.length ? sub.columns_to_show : ['id'];
            data.rows.forEach(childRow => {
                const li = document.createElement('li');
                const textSpan = document.createElement('span');
                textSpan.textContent = columns.map(columnName => childRow[columnName + '__display'] ?? childRow[columnName] ?? '').join(' - ') || I18n.t('grid.drilldown_no_title');
                const badge = document.createElement('span');
                badge.className = 'badge';
                badge.textContent = I18n.t('grid.id_badge', { id: childRow.id });
                li.appendChild(textSpan);
                li.appendChild(badge);
                li.addEventListener('click', () => {
                    window.location.href = `edit.php?table=${sub.table}&id=${childRow.id}`;
                });
                ul.appendChild(li);
            });
        } else {
            const empty = document.createElement('li');
            empty.textContent = I18n.t('grid.drilldown_no_records');
            empty.style.cssText = 'justify-content:center; color:var(--muted);';
            ul.appendChild(empty);
        }
        wrapper.appendChild(ul);
    } catch {
        const error = document.createElement('p');
        error.style.cssText = 'color:var(--error); font-size:13px;';
        error.textContent = I18n.t('grid.drilldown_error');
        wrapper.appendChild(error);
    }

    return wrapper;
}
