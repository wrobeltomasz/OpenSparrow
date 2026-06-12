import { I18n } from '../../i18n.js';
import { deleteRow, duplicateRow } from '../../grid_actions.js';
import { state } from '../state.js';
import { CellRenderer } from '../cells/registry.js';
import { buildExpandButton } from './drilldown.js';

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

function initRowTooltip() {
    let tooltip = document.getElementById('grid-row-tooltip');
    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.id = 'grid-row-tooltip';
        tooltip.style.cssText = 'position:absolute;display:none;background:#fff;border:1px solid #ddd;padding:12px;border-radius:6px;box-shadow:0 5px 15px rgba(0,0,0,0.2);font-size:13px;z-index:10000;pointer-events:none;min-width:220px;max-width:340px;max-height:400px;overflow-y:auto;color:#333;';
        document.body.appendChild(tooltip);
    }
    return tooltip;
}

function attachRowTooltip(td, row, schema) {
    const tooltip = initRowTooltip();
    const tableCfg = schema.tables[state.currentTable] || {};
    const columns = tableCfg.columns || {};

    td.style.cursor = 'default';

    td.addEventListener('mouseenter', () => {
        tooltip.innerHTML = '';

        const firstCol = state.displayedColumns[0];
        const titleVal = firstCol ? (row[firstCol + '__display'] ?? row[firstCol] ?? '') : '';
        if (titleVal !== '') {
            const headerDiv = document.createElement('div');
            headerDiv.style.cssText = 'font-weight:bold;font-size:14px;margin-bottom:8px;border-bottom:1px solid #eee;padding-bottom:5px;';
            headerDiv.textContent = String(titleVal);
            tooltip.appendChild(headerDiv);
        }

        for (const [key, colCfg] of Object.entries(columns)) {
            if (key === 'id') continue;

            const val = row[key + '__display'] ?? row[key];
            if (val === null || val === undefined || val === '') continue;

            const label = colCfg.display_name || key;

            const rowDiv = document.createElement('div');
            rowDiv.style.marginBottom = '4px';

            const strong = document.createElement('strong');
            strong.style.color = '#555';
            strong.textContent = label + ': ';

            const enumColor = (colCfg.type || '').toLowerCase() === 'enum'
                ? (colCfg.enum_colors?.[String(val)] ?? null)
                : null;

            if (enumColor) {
                const swatch = document.createElement('span');
                swatch.style.cssText = `display:inline-block;width:10px;height:10px;border-radius:2px;background:${enumColor};margin-right:4px;vertical-align:middle;`;
                rowDiv.appendChild(strong);
                rowDiv.appendChild(swatch);
            } else {
                rowDiv.appendChild(strong);
            }

            const spanVal = document.createElement('span');
            spanVal.style.color = '#111';
            spanVal.textContent = String(val);

            rowDiv.appendChild(spanVal);
            tooltip.appendChild(rowDiv);
        }

        tooltip.style.display = 'block';

        const rect = td.getBoundingClientRect();
        let topPos = rect.bottom + window.scrollY + 5;
        if (topPos + tooltip.offsetHeight > window.innerHeight + window.scrollY) {
            topPos = rect.top + window.scrollY - tooltip.offsetHeight - 5;
        }
        tooltip.style.left = (rect.left + window.scrollX) + 'px';
        tooltip.style.top = topPos + 'px';
    });

    td.addEventListener('mouseleave', () => {
        tooltip.style.display = 'none';
    });
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

    const editBtn = document.createElement('button');
    editBtn.className = 'btn-icon';
    editBtn.dataset.cy = 'row-edit';
    editBtn.title = I18n.t('common.edit');
    const editImg = document.createElement('img');
    editImg.src = 'assets/img/edit_square.png';
    editImg.alt = I18n.t('common.edit');
    editBtn.appendChild(editImg);
    editBtn.addEventListener('click', () => {
        window.location.href = `edit.php?table=${state.currentTable}&id=${row['id']}`;
    });
    tdActions.appendChild(editBtn);

    const copyBtn = document.createElement('button');
    copyBtn.className = 'btn-icon';
    copyBtn.dataset.cy = 'row-duplicate';
    copyBtn.title = I18n.t('grid.duplicate');
    const copyImg = document.createElement('img');
    copyImg.src = 'assets/img/content_copy.png';
    copyImg.alt = I18n.t('grid.duplicate');
    copyBtn.appendChild(copyImg);
    copyBtn.addEventListener('click', async () => {
        const result = await duplicateRow(row['id']);
        if (result?.ok) await onTableReload();
    });
    tdActions.appendChild(copyBtn);

    const delBtn = document.createElement('button');
    delBtn.className = 'btn-icon btn-icon-danger';
    delBtn.dataset.cy = 'row-delete';
    delBtn.title = I18n.t('common.delete');
    const delImg = document.createElement('img');
    delImg.src = 'assets/img/delete.png';
    delImg.alt = I18n.t('common.delete');
    delBtn.appendChild(delImg);
    delBtn.addEventListener('click', async () => {
        if (!confirm(I18n.t('common.confirm_delete'))) return;
        const result = await deleteRow(row['id']);
        if (result?.ok) await onTableReload();
    });
    tdActions.appendChild(delBtn);

    return tdActions;
}
