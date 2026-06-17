// assets/js/dashboard/widgets/list.js — Registers the 'list' widget; compact table of the configured display_columns rows.

import { WidgetRegistry } from '../registry.js';
import { formatCellValue } from '../../util/format-value.js';

function renderList(widget) {
    const wrapper = document.createElement('div');
    wrapper.className = 'dash-list';
    if (widget.color) wrapper.style.borderTop = `3px solid ${widget.color}`;

    const cols = Array.isArray(widget.display_columns) ? widget.display_columns : [];
    if (cols.length === 0) {
        wrapper.textContent = 'List widget misconfigured: missing display_columns.';
        return wrapper;
    }

    const data = widget.data || [];
    if (data.length === 0) { wrapper.textContent = 'No data'; return wrapper; }

    const colTypes = widget.column_types || {};
    const ul = document.createElement('ul');
    data.forEach(row => {
        const li = document.createElement('li');
        const displayParts = cols.map(col => {
            const raw = row[col] || '';
            const colType = colTypes[col];
            return formatCellValue(raw, colType);
        });
        li.textContent = displayParts.join(' - ');
        if (row.id) {
            li.style.cursor = 'pointer';
            li.title = 'Click to edit record';
            li.addEventListener('click', () => {
                window.location.href = `edit.php?table=${encodeURIComponent(widget.table)}&id=${row.id}`;
            });
            li.addEventListener('mouseenter', () => { li.style.color = '#3b82f6'; });
            li.addEventListener('mouseleave', () => { li.style.color = ''; });
        }
        ul.appendChild(li);
    });

    wrapper.appendChild(ul);
    return wrapper;
}

WidgetRegistry.register('list', renderList);
export { renderList };
