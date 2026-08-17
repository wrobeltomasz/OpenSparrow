// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { applyDrillDown } from '../drill-down.js';
import { WidgetRegistry } from '../registry.js';
import { formatCellValue } from '../../util/format-value.js';

const COLORS = ['#003366', '#1B4C7E', '#356696', '#5081AE', '#6C9CC6', '#8AB6DA', '#AACFE8', '#CBE2F1'];

function renderPieChart(widget) {
    const wrapper = document.createElement('div');
    wrapper.className = 'dash-pie-wrapper';

    const data = widget.data || [];
    if (data.length === 0) { wrapper.textContent = window.I18n.t('dashboard.no_data'); return wrapper; }

    const total = data.reduce((sum, d) => sum + parseFloat(d.value), 0);
    if (total === 0) { wrapper.textContent = window.I18n.t('dashboard.sum_zero'); return wrapper; }

    const groupColumn = widget.query?.group_column;
    const columnType = widget.column_type;
    const legend = document.createElement('div');
    legend.className = 'dash-pie-legend';

    let conicStops = [];
    let currentAngle = 0;

    data.forEach((row, index) => {
        const value = parseFloat(row.value);
        const percent = (value / total) * 100;
        const degrees = (value / total) * 360;
        const color = COLORS[index % COLORS.length];
        conicStops.push(`${color} ${currentAngle}deg ${currentAngle + degrees}deg`);
        currentAngle += degrees;

        const item = document.createElement('div');
        item.className = 'dash-pie-legend-item';

        const box = document.createElement('div');
        box.className = 'dash-pie-color-box';
        box.style.backgroundColor = color;

        const displayLabel = formatCellValue(row.label || 'None', columnType);
        const labelElement = document.createElement('span');
        labelElement.textContent = `${displayLabel} - ${value} (${percent.toFixed(1)}%)`;

        item.append(box, labelElement);
        applyDrillDown(item, widget.table, groupColumn, row.label);
        legend.appendChild(item);
    });

    const pieElement = document.createElement('div');
    pieElement.className = 'dash-pie-chart';
    pieElement.style.background = `conic-gradient(${conicStops.join(', ')})`;

    wrapper.append(pieElement, legend);
    return wrapper;
}

WidgetRegistry.register('pie_chart', renderPieChart);
export { renderPieChart };
