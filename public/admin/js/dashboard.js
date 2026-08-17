// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { createTextInput, createSelectInput, createColorInput, createCheckbox, renderGlobalSettings } from './ui.js';
import { WidgetRegistry } from '../../assets/js/dashboard/registry.js';
import { apiFetch } from '../../assets/js/util/api.js';

import '../../assets/js/dashboard/widgets/stat-card.js';
import '../../assets/js/dashboard/widgets/bar-chart.js';
import '../../assets/js/dashboard/widgets/vertical-bar-chart.js';
import '../../assets/js/dashboard/widgets/pie-chart.js';
import '../../assets/js/dashboard/widgets/line-chart.js';
import '../../assets/js/dashboard/widgets/list.js';

const CONDITION_OPS = [
    { value: '=',           label: '= (equals)' },
    { value: '!=',          label: '!= (not equal)' },
    { value: '<',           label: '< (less than)' },
    { value: '>',           label: '> (greater than)' },
    { value: '<=',          label: '<= (less or equal)' },
    { value: '>=',          label: '>= (greater or equal)' },
    { value: 'LIKE',        label: 'LIKE (matches pattern)' },
    { value: 'ILIKE',       label: 'ILIKE (case-insensitive match)' },
    { value: 'IS NULL',     label: 'IS NULL (empty)' },
    { value: 'IS NOT NULL', label: 'IS NOT NULL (not empty)' },
];

function renderConditionsBuilder(q, columnOptions) {
    if (!Array.isArray(q.conditions)) q.conditions = [];

    const wrap = document.createElement('div');
    wrap.className = 'form-group';

    const label = document.createElement('label');
    label.textContent = 'Filter Conditions (WHERE)';
    wrap.appendChild(label);

    const list = document.createElement('div');
    list.className = 'dash-cond-list';

    function rebuildList() {
        list.innerHTML = '';
        q.conditions.forEach((condition, index) => {
            const row = document.createElement('div');
            row.className = 'dash-cond-row';

            if (index > 0) {
                const logicSelect = document.createElement('select');
                logicSelect.className = 'adm-input w-70';
                ['AND', 'OR'].forEach(l => {
                    const o = document.createElement('option');
                    o.value = l; o.textContent = l;
                    if ((condition.logic || 'AND') === l) o.selected = true;
                    logicSelect.appendChild(o);
                });
                logicSelect.addEventListener('change', e => { condition.logic = e.target.value; });
                row.appendChild(logicSelect);
            } else {
                const spacer = document.createElement('span');
                spacer.className = 'dash-cond-where';
                spacer.textContent = 'WHERE';
                row.appendChild(spacer);
            }

            const columnSelect = document.createElement('select');
            columnSelect.className = 'adm-input flex-1';
            columnOptions.forEach(option => {
                const o = document.createElement('option');
                o.value = option.value; o.textContent = option.label;
                if (option.value === condition.col) o.selected = true;
                columnSelect.appendChild(o);
            });
            columnSelect.addEventListener('change', e => { condition.col = e.target.value; rebuildList(); });
            row.appendChild(columnSelect);

            const opSelect = document.createElement('select');
            opSelect.className = 'adm-input flex-1';
            CONDITION_OPS.forEach(option => {
                const o = document.createElement('option');
                o.value = option.value; o.textContent = option.label;
                if (option.value === (condition.op || '=')) o.selected = true;
                opSelect.appendChild(o);
            });
            opSelect.addEventListener('change', e => { condition.op = e.target.value; rebuildList(); });
            row.appendChild(opSelect);

            const noValue = ['IS NULL', 'IS NOT NULL'].includes(condition.op || '=');
            if (!noValue) {
                const valueInput = document.createElement('input');
                valueInput.type = 'text';
                valueInput.placeholder = 'value';
                valueInput.value = condition.val || '';
                valueInput.className = 'adm-input flex-1';
                valueInput.addEventListener('input', e => { condition.val = e.target.value; });
                row.appendChild(valueInput);
            }

            const rmButton = document.createElement('button');
            rmButton.type = 'button';
            rmButton.textContent = '✕';
            rmButton.className = 'btn btn-danger btn-xs';
            rmButton.addEventListener('click', () => {
                q.conditions.splice(index, 1);
                rebuildList();
            });
            row.appendChild(rmButton);

            list.appendChild(row);
        });
    }

    rebuildList();
    wrap.appendChild(list);

    const addButton = document.createElement('button');
    addButton.type = 'button';
    addButton.textContent = '+ Add condition';
    addButton.className = 'btn btn-secondary btn-sm';
    addButton.addEventListener('click', () => {
        const firstColumn = columnOptions[0]?.value || '';
        q.conditions.push({ col: firstColumn, op: '=', val: '' });
        rebuildList();
    });
    wrap.appendChild(addButton);

    return wrap;
}

function renderCalculateButton(itemData) {
    const wrap = document.createElement('div');
    wrap.className = 'form-group';
    wrap.style.marginTop = '10px';

    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = 'Calculate (real data)';
    button.className = 'btn btn-secondary btn-sm';

    const out = document.createElement('pre');
    out.className = 'dash-calc-out';
    out.hidden = true;

    button.addEventListener('click', async () => {
        if (!itemData.table) {
            out.hidden = false;
            out.classList.add('c-error');
            out.textContent = 'Select a source table first.';
            return;
        }

        button.disabled = true;
        button.textContent = 'Calculating…';
        out.hidden = false;
        out.classList.remove('c-error');
        out.textContent = 'Please wait…';

        try {
            const result = await apiFetch('api.php?action=dashboard_calculate', {
                method: 'POST',
                body: {
                    table: itemData.table,
                    query: itemData.query || {},
                    display_columns: itemData.display_columns || [],
                },
            });
            const result = await result.json();
            if (result.status === 'success') {
                out.classList.remove('c-error');
                out.textContent = JSON.stringify(result.data, null, 2);
            } else {
                out.classList.add('c-error');
                out.textContent = 'Error: ' + (result.error || 'unknown');
            }
        } catch (e) {
            out.classList.add('c-error');
            out.textContent = 'Request failed: ' + e.message;
        }

        button.disabled = false;
        button.textContent = 'Calculate (real data)';
    });

    wrap.append(button, out);
    return wrap;
}

function getMockData(type, displayColumns) {
    if (type === 'stat_card') return 1337;
    if (['bar_chart', 'vertical_bar_chart', 'pie_chart'].includes(type)) {
        return [
            { label: 'Category A', value: 42 },
            { label: 'Category B', value: 28 },
            { label: 'Category C', value: 15 },
            { label: 'Other',      value: 8  },
        ];
    }
    if (type === 'line_chart') {
        return [
            { label: '2026-01-01', value: 12 },
            { label: '2026-02-01', value: 19 },
            { label: '2026-03-01', value: 14 },
            { label: '2026-04-01', value: 27 },
            { label: '2026-05-01', value: 22 },
            { label: '2026-06-01', value: 31 },
        ];
    }
    if (type === 'list') {
        const columns = Array.isArray(displayColumns) && displayColumns.length
            ? displayColumns
            : ['name', 'status', 'created_at'];
        const row = Object.fromEntries(columns.map(c => [c, 'Example']));
        return [{ ...row }, { ...row }, { ...row }];
    }
    return null;
}

function renderPreviewInto(container, widget) {
    container.replaceChildren();

    const hdr = document.createElement('div');
    hdr.className = 'dash-preview-hdr';
    hdr.textContent = 'Live Preview';
    container.appendChild(hdr);

    if (!widget.type) {
        const placeholder = document.createElement('p');
        placeholder.textContent = 'Select a widget type to preview.';
        container.appendChild(placeholder);
        return;
    }

    const mockWidget = {
        ...widget,
        data: getMockData(widget.type, widget.display_columns),
    };

    const widgetElement = document.createElement('div');
    widgetElement.className = 'dash-widget';
    widgetElement.dataset.w = widget.width || 1;
    widgetElement.dataset.h = widget.height || 1;
    widgetElement.style.pointerEvents = 'none';

    if (widget.type !== 'stat_card') {
        const title = document.createElement('h3');
        title.className = 'dash-title';
        title.textContent = widget.title || 'Widget Title';
        widgetElement.appendChild(title);
    }

    widgetElement.appendChild(WidgetRegistry.render(mockWidget));
    container.appendChild(widgetElement);
}

export const WIDGET_TYPES = [
    { value: 'stat_card',         label: 'Stat Card' },
    { value: 'bar_chart',         label: 'Bar Chart (Horizontal)' },
    { value: 'vertical_bar_chart',label: 'Bar Chart (Vertical)' },
    { value: 'pie_chart',         label: 'Pie Chart' },
    { value: 'line_chart',        label: 'Line Chart (Time Series)' },
    { value: 'list',              label: 'Data List' },
];

export function renderDashboardLayout(context) {
    renderGlobalSettings(context, {
        title: 'Dashboard Global Settings',
        defaultMenuName: 'Dashboard',
        includeHidden: true,
        onAfter: ({ workspaceEl: workspaceElement, currentConfig }) => {
            const layoutTitle = document.createElement('h4');
            layoutTitle.textContent = 'Grid Layout';
            layoutTitle.style.marginTop = '20px';
            workspaceElement.appendChild(layoutTitle);

            workspaceElement.appendChild(createTextInput('layout_gap', 'Grid Gap (CSS)', currentConfig.layout.gap || '20px', v => currentConfig.layout.gap = v));
        },
    });
}

export function renderDashboardEditor(key, itemData, isArray, context) {
    const { workspaceEl: containerElement, getTableOptions, getColumnOptionsForTable, renderEditor, renderSidebar } = context;

    const split = document.createElement('div');
    split.className = 'dash-editor-split';
    containerElement.appendChild(split);

    const workspaceElement = document.createElement('div');
    workspaceElement.className = 'dash-editor-form';

    const previewWrap = document.createElement('div');
    previewWrap.className = 'dash-editor-preview';

    split.append(workspaceElement, previewWrap);

    function refreshPreview() {
        renderPreviewInto(previewWrap, itemData);
    }

    workspaceElement.addEventListener('input',  refreshPreview);
    workspaceElement.addEventListener('change', refreshPreview);

    workspaceElement.appendChild(createTextInput('id', 'Widget ID (Unique)', itemData.id, v => itemData.id = v));

    workspaceElement.appendChild(createSelectInput('type', 'Widget Type', WIDGET_TYPES, itemData.type || '', v => {
        itemData.type = v; itemData.query = {}; renderEditor(key, itemData, isArray);
    }));

    workspaceElement.appendChild(createTextInput('title', 'Widget Title', itemData.title, v => { itemData.title = v; renderSidebar(); }));
    workspaceElement.appendChild(createSelectInput('table', 'Source Table', getTableOptions(), itemData.table, v => {
        itemData.table = v; renderEditor(key, itemData, isArray);
    }));

    const queryBlock = document.createElement('div');
    queryBlock.className = 'dash-query-block';
    queryBlock.innerHTML = '<h4>Database Query Configuration</h4>';

    if (typeof itemData.query !== 'object' || itemData.query === null) itemData.query = {};
    const q = itemData.query;
    const columnOptions = getColumnOptionsForTable(itemData.table);

    if (itemData.type === 'stat_card') {
        q.type = q.type || 'count'; q.column = q.column || 'id';
        queryBlock.appendChild(createSelectInput('q_type', 'Aggregation Function', [{ value: 'count', label: 'Count' }, { value: 'sum', label: 'Sum' }, { value: 'avg', label: 'Average' }], q.type, v => q.type = v));
        queryBlock.appendChild(createSelectInput('q_col', 'Target Column', columnOptions, q.column, v => q.column = v));
    } else if (['bar_chart', 'vertical_bar_chart', 'pie_chart'].includes(itemData.type)) {
        q.type = 'group_by';
        queryBlock.appendChild(createSelectInput('q_group', 'Group By Column', columnOptions, q.group_column || '', v => q.group_column = v));
        queryBlock.appendChild(createSelectInput('q_agg_col', 'Aggregation Column', columnOptions, q.agg_column || 'id', v => q.agg_column = v));
        queryBlock.appendChild(createSelectInput('q_agg_type', 'Aggregation Function', [{ value: 'count', label: 'Count' }, { value: 'sum', label: 'Sum' }], q.agg_type || 'count', v => q.agg_type = v));
    } else if (itemData.type === 'line_chart') {
        q.type = 'time_series';
        queryBlock.appendChild(createSelectInput('q_x_col', 'Time Axis Column (X)', columnOptions, q.x_column || '', v => q.x_column = v));
        queryBlock.appendChild(createSelectInput('q_granularity', 'Time Granularity', [
            { value: 'day',   label: 'Day' },
            { value: 'week',  label: 'Week' },
            { value: 'month', label: 'Month' },
            { value: 'year',  label: 'Year' },
        ], q.granularity || 'month', v => q.granularity = v));
        queryBlock.appendChild(createSelectInput('q_agg_col', 'Aggregation Column (Y)', columnOptions, q.agg_column || 'id', v => q.agg_column = v));
        queryBlock.appendChild(createSelectInput('q_agg_type', 'Aggregation Function', [{ value: 'count', label: 'Count' }, { value: 'sum', label: 'Sum' }, { value: 'avg', label: 'Average' }], q.agg_type || 'count', v => q.agg_type = v));
        queryBlock.appendChild(createCheckbox('q_area', 'Fill area under line', q.area, v => q.area = v, false));
    } else if (itemData.type === 'list') {
        queryBlock.appendChild(createTextInput('q_limit', 'Limit Rows', q.limit || 5, v => q.limit = parseInt(v) || 5));
        queryBlock.appendChild(createSelectInput('q_order', 'Order By Column', columnOptions, q.order_by || 'id', v => q.order_by = v));
        queryBlock.appendChild(createSelectInput('q_dir', 'Order Direction', [{ value: 'DESC', label: 'Descending' }, { value: 'ASC', label: 'Ascending' }], q.dir || 'DESC', v => q.dir = v));
    }

    queryBlock.appendChild(renderConditionsBuilder(q, columnOptions));
    queryBlock.appendChild(renderCalculateButton(itemData));
    workspaceElement.appendChild(queryBlock);

    const sizeBlock = document.createElement('div');
    sizeBlock.className = 'dash-size-block';
    sizeBlock.appendChild(createSelectInput('width', 'Width', [
        { value: 1, label: '1/3' },
        { value: 2, label: '2/3' },
        { value: 3, label: '3/3 (full)' },
    ], itemData.width || 1, v => { itemData.width = parseInt(v); }));
    sizeBlock.appendChild(createSelectInput('height', 'Height', [
        { value: 1, label: 'Small' },
        { value: 2, label: 'Medium' },
        { value: 3, label: 'Large' },
    ], itemData.height || 1, v => { itemData.height = parseInt(v); }));
    workspaceElement.appendChild(sizeBlock);

    workspaceElement.appendChild(createColorInput('color', 'Accent Color', itemData.color, v => { itemData.color = v; refreshPreview(); }));

    if (itemData.type === 'list') {
        const columnsString = Array.isArray(itemData.display_columns) ? itemData.display_columns.join(', ') : '';
        workspaceElement.appendChild(createTextInput('display_columns', 'Columns to Display (Comma separated)', columnsString, v => {
            itemData.display_columns = v.split(',').map(s => s.trim()).filter(s => s);
        }));
    }

    refreshPreview();
}
