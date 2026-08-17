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

function renderConditionsBuilder(query, columnOptions) {
    if (!Array.isArray(query.conditions)) query.conditions = [];

    const wrap = document.createElement('div');
    wrap.className = 'form-group';

    const labelElement = document.createElement('label');
    labelElement.textContent = 'Filter Conditions (WHERE)';
    wrap.appendChild(labelElement);

    const list = document.createElement('div');
    list.className = 'dash-cond-list';

    function rebuildList() {
        list.innerHTML = '';
        query.conditions.forEach((condition, index) => {
            const row = document.createElement('div');
            row.className = 'dash-cond-row';

            if (index > 0) {
                const logicSelect = document.createElement('select');
                logicSelect.className = 'adm-input w-70';
                ['AND', 'OR'].forEach(logicOperator => {
                    const optionElement = document.createElement('option');
                    optionElement.value = logicOperator; optionElement.textContent = logicOperator;
                    if ((condition.logic || 'AND') === logicOperator) optionElement.selected = true;
                    logicSelect.appendChild(optionElement);
                });
                logicSelect.addEventListener('change', event => { condition.logic = event.target.value; });
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
                const optionElement = document.createElement('option');
                optionElement.value = option.value; optionElement.textContent = option.label;
                if (option.value === condition.col) optionElement.selected = true;
                columnSelect.appendChild(optionElement);
            });
            columnSelect.addEventListener('change', event => { condition.col = event.target.value; rebuildList(); });
            row.appendChild(columnSelect);

            const opSelect = document.createElement('select');
            opSelect.className = 'adm-input flex-1';
            CONDITION_OPS.forEach(option => {
                const optionElement = document.createElement('option');
                optionElement.value = option.value; optionElement.textContent = option.label;
                if (option.value === (condition.op || '=')) optionElement.selected = true;
                opSelect.appendChild(optionElement);
            });
            opSelect.addEventListener('change', event => { condition.op = event.target.value; rebuildList(); });
            row.appendChild(opSelect);

            const noValue = ['IS NULL', 'IS NOT NULL'].includes(condition.op || '=');
            if (!noValue) {
                const valueInput = document.createElement('input');
                valueInput.type = 'text';
                valueInput.placeholder = 'value';
                valueInput.value = condition.val || '';
                valueInput.className = 'adm-input flex-1';
                valueInput.addEventListener('input', event => { condition.val = event.target.value; });
                row.appendChild(valueInput);
            }

            const rmButton = document.createElement('button');
            rmButton.type = 'button';
            rmButton.textContent = '✕';
            rmButton.className = 'btn btn-danger btn-xs';
            rmButton.addEventListener('click', () => {
                query.conditions.splice(index, 1);
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
        query.conditions.push({ col: firstColumn, op: '=', val: '' });
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

    const outputElement = document.createElement('pre');
    outputElement.className = 'dash-calc-out';
    outputElement.hidden = true;

    button.addEventListener('click', async () => {
        if (!itemData.table) {
            outputElement.hidden = false;
            outputElement.classList.add('c-error');
            outputElement.textContent = 'Select a source table first.';
            return;
        }

        button.disabled = true;
        button.textContent = 'Calculating…';
        outputElement.hidden = false;
        outputElement.classList.remove('c-error');
        outputElement.textContent = 'Please wait…';

        try {
            const response = await apiFetch('api.php?action=dashboard_calculate', {
                method: 'POST',
                body: {
                    table: itemData.table,
                    query: itemData.query || {},
                    display_columns: itemData.display_columns || [],
                },
            });
            const result = await response.json();
            if (result.status === 'success') {
                outputElement.classList.remove('c-error');
                outputElement.textContent = JSON.stringify(result.data, null, 2);
            } else {
                outputElement.classList.add('c-error');
                outputElement.textContent = 'Error: ' + (result.error || 'unknown');
            }
        } catch (event) {
            outputElement.classList.add('c-error');
            outputElement.textContent = 'Request failed: ' + event.message;
        }

        button.disabled = false;
        button.textContent = 'Calculate (real data)';
    });

    wrap.append(button, outputElement);
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
        const row = Object.fromEntries(columns.map(columnName => [columnName, 'Example']));
        return [{ ...row }, { ...row }, { ...row }];
    }
    return null;
}

function renderPreviewInto(container, widget) {
    container.replaceChildren();

    const headerElement = document.createElement('div');
    headerElement.className = 'dash-preview-hdr';
    headerElement.textContent = 'Live Preview';
    container.appendChild(headerElement);

    if (!widget.type) {
        const placeholderParagraph = document.createElement('p');
        placeholderParagraph.textContent = 'Select a widget type to preview.';
        container.appendChild(placeholderParagraph);
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

            workspaceElement.appendChild(createTextInput('layout_gap', 'Grid Gap (CSS)', currentConfig.layout.gap || '20px', value => currentConfig.layout.gap = value));
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

    workspaceElement.appendChild(createTextInput('id', 'Widget ID (Unique)', itemData.id, value => itemData.id = value));

    workspaceElement.appendChild(createSelectInput('type', 'Widget Type', WIDGET_TYPES, itemData.type || '', value => {
        itemData.type = value; itemData.query = {}; renderEditor(key, itemData, isArray);
    }));

    workspaceElement.appendChild(createTextInput('title', 'Widget Title', itemData.title, value => { itemData.title = value; renderSidebar(); }));
    workspaceElement.appendChild(createSelectInput('table', 'Source Table', getTableOptions(), itemData.table, value => {
        itemData.table = value; renderEditor(key, itemData, isArray);
    }));

    const queryBlock = document.createElement('div');
    queryBlock.className = 'dash-query-block';
    queryBlock.innerHTML = '<h4>Database Query Configuration</h4>';

    if (typeof itemData.query !== 'object' || itemData.query === null) itemData.query = {};
    const query = itemData.query;
    const columnOptions = getColumnOptionsForTable(itemData.table);

    if (itemData.type === 'stat_card') {
        query.type = query.type || 'count'; query.column = query.column || 'id';
        queryBlock.appendChild(createSelectInput('q_type', 'Aggregation Function', [{ value: 'count', label: 'Count' }, { value: 'sum', label: 'Sum' }, { value: 'avg', label: 'Average' }], query.type, value => query.type = value));
        queryBlock.appendChild(createSelectInput('q_col', 'Target Column', columnOptions, query.column, value => query.column = value));
    } else if (['bar_chart', 'vertical_bar_chart', 'pie_chart'].includes(itemData.type)) {
        query.type = 'group_by';
        queryBlock.appendChild(createSelectInput('q_group', 'Group By Column', columnOptions, query.group_column || '', value => query.group_column = value));
        queryBlock.appendChild(createSelectInput('q_agg_col', 'Aggregation Column', columnOptions, query.agg_column || 'id', value => query.agg_column = value));
        queryBlock.appendChild(createSelectInput('q_agg_type', 'Aggregation Function', [{ value: 'count', label: 'Count' }, { value: 'sum', label: 'Sum' }], query.agg_type || 'count', value => query.agg_type = value));
    } else if (itemData.type === 'line_chart') {
        query.type = 'time_series';
        queryBlock.appendChild(createSelectInput('q_x_col', 'Time Axis Column (X)', columnOptions, query.x_column || '', value => query.x_column = value));
        queryBlock.appendChild(createSelectInput('q_granularity', 'Time Granularity', [
            { value: 'day',   label: 'Day' },
            { value: 'week',  label: 'Week' },
            { value: 'month', label: 'Month' },
            { value: 'year',  label: 'Year' },
        ], query.granularity || 'month', value => query.granularity = value));
        queryBlock.appendChild(createSelectInput('q_agg_col', 'Aggregation Column (Y)', columnOptions, query.agg_column || 'id', value => query.agg_column = value));
        queryBlock.appendChild(createSelectInput('q_agg_type', 'Aggregation Function', [{ value: 'count', label: 'Count' }, { value: 'sum', label: 'Sum' }, { value: 'avg', label: 'Average' }], query.agg_type || 'count', value => query.agg_type = value));
        queryBlock.appendChild(createCheckbox('q_area', 'Fill area under line', query.area, value => query.area = value, false));
    } else if (itemData.type === 'list') {
        queryBlock.appendChild(createTextInput('q_limit', 'Limit Rows', query.limit || 5, value => query.limit = parseInt(value) || 5));
        queryBlock.appendChild(createSelectInput('q_order', 'Order By Column', columnOptions, query.order_by || 'id', value => query.order_by = value));
        queryBlock.appendChild(createSelectInput('q_dir', 'Order Direction', [{ value: 'DESC', label: 'Descending' }, { value: 'ASC', label: 'Ascending' }], query.dir || 'DESC', value => query.dir = value));
    }

    queryBlock.appendChild(renderConditionsBuilder(query, columnOptions));
    queryBlock.appendChild(renderCalculateButton(itemData));
    workspaceElement.appendChild(queryBlock);

    const sizeBlock = document.createElement('div');
    sizeBlock.className = 'dash-size-block';
    sizeBlock.appendChild(createSelectInput('width', 'Width', [
        { value: 1, label: '1/3' },
        { value: 2, label: '2/3' },
        { value: 3, label: '3/3 (full)' },
    ], itemData.width || 1, value => { itemData.width = parseInt(value); }));
    sizeBlock.appendChild(createSelectInput('height', 'Height', [
        { value: 1, label: 'Small' },
        { value: 2, label: 'Medium' },
        { value: 3, label: 'Large' },
    ], itemData.height || 1, value => { itemData.height = parseInt(value); }));
    workspaceElement.appendChild(sizeBlock);

    workspaceElement.appendChild(createColorInput('color', 'Accent Color', itemData.color, value => { itemData.color = value; refreshPreview(); }));

    if (itemData.type === 'list') {
        const columnsString = Array.isArray(itemData.display_columns) ? itemData.display_columns.join(', ') : '';
        workspaceElement.appendChild(createTextInput('display_columns', 'Columns to Display (Comma separated)', columnsString, value => {
            itemData.display_columns = value.split(',').map(displayColumn => displayColumn.trim()).filter(displayColumn => displayColumn);
        }));
    }

    refreshPreview();
}
