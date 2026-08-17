// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { applyDrillDown } from '../drill-down.js';
import { formatCellValue } from '../../util/format-value.js';

export function renderBars(widget, orientation) {
    const data = widget.data || [];
    if (data.length === 0) {
        const paragraph = document.createElement('p');
        paragraph.textContent = window.I18n.t('dashboard.no_data');
        return paragraph;
    }

    const maxValue = Math.max(...data.map(dataPoint => parseFloat(dataPoint.value)));
    const groupColumn = widget.query?.group_column;
    const columnType = widget.column_type;
    const wrapper = orientation === 'horizontal'
        ? createHorizontalWrapper()
        : createVerticalWrapper();

    data.forEach(row => {
        const percent = maxValue > 0 ? (row.value / maxValue) * 100 : 0;
        const element = orientation === 'horizontal'
            ? buildHorizontalBar(row, percent, widget.color, columnType)
            : buildVerticalBar(row, percent, widget.color, columnType);
        applyDrillDown(element, widget.table, groupColumn, row.label);
        wrapper.appendChild(element);
    });

    return wrapper;
}

function createHorizontalWrapper() {
    const wrapper = document.createElement('div');
    wrapper.className = 'bar-chart';
    return wrapper;
}

function createVerticalWrapper() {
    const wrapper = document.createElement('div');
    wrapper.className = 'dash-vbar-chart';
    return wrapper;
}

function buildHorizontalBar(row, percent, color, columnType) {
    const rowElement = document.createElement('div');
    rowElement.className = 'bar-row';

    const label = document.createElement('div');
    label.className = 'bar-label';
    const displayLabel = formatCellValue(row.label || 'None', columnType);
    label.textContent = displayLabel;

    const track = document.createElement('div');
    track.className = 'bar-track';

    const bar = document.createElement('div');
    bar.className = 'bar-fill';
    if (color) bar.style.backgroundColor = color;
    setTimeout(() => { bar.style.width = `${percent}%`; }, 50);

    const value = document.createElement('div');
    value.className = 'bar-value';
    value.textContent = row.value;

    track.appendChild(bar);
    rowElement.append(label, track, value);
    return rowElement;
}

function buildVerticalBar(row, percent, color, columnType) {
    const columnElement = document.createElement('div');
    columnElement.className = 'dash-vbar-col';

    const value = document.createElement('div');
    value.className = 'dash-vbar-value';
    value.textContent = row.value;

    const track = document.createElement('div');
    track.className = 'dash-vbar-track';

    const bar = document.createElement('div');
    bar.className = 'dash-vbar-fill';
    bar.style.backgroundColor = color || 'var(--accent)';
    setTimeout(() => { bar.style.height = `${percent}%`; }, 50);

    const label = document.createElement('div');
    label.className = 'dash-vbar-label';
    const displayLabel = formatCellValue(row.label || 'None', columnType);
    label.textContent = displayLabel;

    track.appendChild(bar);
    columnElement.append(value, track, label);
    return columnElement;
}
