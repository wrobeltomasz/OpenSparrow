// assets/js/grid/body/render.js — renderTbody(): builds grid rows, delegating each cell to its registered CellRenderer (imports all cell types so they self-register); adds row actions + expand button.

import { I18n } from '../../i18n.js';
import { deleteRow, duplicateRow } from '../../grid_actions.js';
import { state } from '../state.js';
import { CellRenderer } from '../cells/registry.js';
import { buildExpandButton } from './drilldown.js';
import { makeIconButton } from '../dom.js';
import { showRecordTooltip, hideRecordTooltip, rowsFromRecord } from '../../util/record-tooltip.js';

// Import cell renderers so they self-register
import '../cells/fk-cell.js';
import '../cells/enum-cell.js';
import '../cells/boolean-cell.js';
import '../cells/date-cell.js';
import '../cells/timestamp-cell.js';
import '../cells/text-cell.js';
import '../cells/virtual-cell.js';

function resolveCellType(colCfg, hasFk) {
    if (colCfg.type === 'virtual') return 'virtual';
    if (hasFk) return 'fk';
    const t = (colCfg.type || '').toLowerCase();
    if (t === 'enum') return 'enum';
    if (t.includes('boolean')) return 'boolean';
    if (t.includes('timestamp')) return 'timestamp';
    if (t.includes('date')) return 'date';
    return 'text';
}

function attachRowTooltip(td, row, schema) {
    const columns = schema.tables[state.currentTable]?.columns || {};
    td.style.cursor = 'default';

    td.addEventListener('mouseenter', () => {
        const firstCol = state.displayedColumns[0];
        const title = firstCol ? (row[firstCol + '__display'] ?? row[firstCol] ?? '') : '';
        showRecordTooltip(td, { title, rows: rowsFromRecord(row, columns) });
    });

    td.addEventListener('mouseleave', hideRecordTooltip);
}

export async function renderTbody(schema, isReadOnly, getPageRows, onTableReload) {
    const tbody = document.createElement('tbody');
    const pageRows = getPageRows();
    const subtables = schema.tables[state.currentTable]?.subtables || [];
    const hasSubtables = subtables.length > 0;

    for (const row of pageRows) {
        const tr = document.createElement('tr');

        if (!isReadOnly) {
            const tdSelect = document.createElement('td');
            tdSelect.className = 'td-select';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'row-select-cb';
            cb.setAttribute('aria-label', 'Select row');
            cb.dataset.id = String(row.id);
            cb.checked = state.selectedIds.has(row.id);
            cb.addEventListener('change', e => {
                e.stopPropagation();
                if (e.target.checked) state.selectedIds.add(row.id);
                else state.selectedIds.delete(row.id);
                document.dispatchEvent(new CustomEvent('selectionChanged'));
            });
            tdSelect.appendChild(cb);
            tr.appendChild(tdSelect);
        }

        if (hasSubtables) {
            tr.appendChild(buildExpandButton(row, schema, tr));
        }

        let firstDataTd = null;
        for (const col of state.displayedColumns) {
            const colCfg = schema.tables[state.currentTable].columns[col] || {};
            const hasFk = Boolean(schema.tables[state.currentTable].foreign_keys?.[col]);
            const type = resolveCellType(colCfg, hasFk);
            const td = await CellRenderer.render(type, { row, col, colCfg, schema, isReadOnly });
            if (!firstDataTd) firstDataTd = td;
            tr.appendChild(td);
        }

        if (firstDataTd) attachRowTooltip(firstDataTd, row, schema);

        // M2M columns — one TD per configured relationship, populated async by loader.js
        const m2mList = schema.tables[state.currentTable]?.many_to_many || [];
        for (let mi = 0; mi < m2mList.length; mi++) {
            const tdM2m = document.createElement('td');
            tdM2m.className = 'td-m2m';
            tdM2m.dataset.m2mRowId = String(row['id']);
            tdM2m.dataset.m2mIndex = String(mi);
            tdM2m.dataset.m2mLabel = m2mList[mi].label || 'Related';
            tr.appendChild(tdM2m);
        }

        // Actions column
        if (!isReadOnly) {
            tr.appendChild(buildActionsCell(row, schema, isReadOnly, onTableReload));
        }

        tbody.appendChild(tr);
    }

    return { tbody, pageRows };
}

function buildActionsCell(row, schema, isReadOnly, onTableReload) {
    const tdActions = document.createElement('td');
    tdActions.className = 'td-actions';
    tdActions.dataset.actionsRowId = String(row['id']);

    const menu = document.createElement('div');
    menu.className = 'td-actions-menu';

    const trigger = makeIconButton({
        cy: 'row-actions-toggle',
        title: I18n.t('grid.more_actions'),
        icon: 'assets/img/more_vert.png',
        onClick: e => {
            e.stopPropagation();
            closeAllActionMenus(menu);
            menu.classList.toggle('open');
        },
    });
    menu.appendChild(trigger);

    const panel = document.createElement('div');
    panel.className = 'td-actions-panel';

    panel.appendChild(makeIconButton({
        cy: 'row-edit',
        title: I18n.t('common.edit'),
        icon: 'assets/img/edit_square.png',
        onClick: () => {
            window.location.href = `edit.php?table=${state.currentTable}&id=${row['id']}`;
        },
    }));

    panel.appendChild(makeIconButton({
        cy: 'row-duplicate',
        title: I18n.t('grid.duplicate'),
        icon: 'assets/img/content_copy.png',
        onClick: async () => {
            const result = await duplicateRow(row['id']);
            if (result?.ok) await onTableReload();
        },
    }));

    panel.appendChild(makeIconButton({
        cy: 'row-delete',
        title: I18n.t('common.delete'),
        icon: 'assets/img/delete.png',
        className: 'btn-icon btn-icon-danger',
        onClick: async () => {
            if (!confirm(I18n.t('common.confirm_delete'))) return;
            const result = await deleteRow(row['id']);
            if (result?.ok) await onTableReload();
        },
    }));

    menu.appendChild(panel);
    tdActions.appendChild(menu);

    return tdActions;
}

function closeAllActionMenus(except) {
    document.querySelectorAll('.td-actions-menu.open').forEach(el => {
        if (el !== except) el.classList.remove('open');
    });
}

document.addEventListener('click', () => closeAllActionMenus());
