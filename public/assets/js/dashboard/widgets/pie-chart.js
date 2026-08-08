// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// assets/js/dashboard/widgets/pie-chart.js — Registers the 'pie_chart' widget; renders an SVG pie with a colour palette, legend and drill-down.

import { applyDrillDown } from '../drill-down.js';
import { WidgetRegistry } from '../registry.js';
import { formatCellValue } from '../../util/format-value.js';

// Sequential ramp off --c-navy (#003366). One hue, eight steps: slices stay
// distinguishable without a second colour. Literal hex - feeds a conic-gradient.
const COLORS = ['#003366', '#1B4C7E', '#356696', '#5081AE', '#6C9CC6', '#8AB6DA', '#AACFE8', '#CBE2F1'];

function renderPieChart(widget) {
    const wrapper = document.createElement('div');
    wrapper.className = 'dash-pie-wrapper';

    const data = widget.data || [];
    if (data.length === 0) { wrapper.textContent = window.I18n.t('dashboard.no_data'); return wrapper; }

    const total = data.reduce((sum, d) => sum + parseFloat(d.value), 0);
    if (total === 0) { wrapper.textContent = window.I18n.t('dashboard.sum_zero'); return wrapper; }

    const groupCol = widget.query?.group_column;
    const columnType = widget.column_type;
    const legend = document.createElement('div');
    legend.className = 'dash-pie-legend';

    let conicStops = [];
    let currentAngle = 0;

    data.forEach((row, idx) => {
        const val = parseFloat(row.value);
        const percent = (val / total) * 100;
        const deg = (val / total) * 360;
        const color = COLORS[idx % COLORS.length];
        conicStops.push(`${color} ${currentAngle}deg ${currentAngle + deg}deg`);
        currentAngle += deg;

        const item = document.createElement('div');
        item.className = 'dash-pie-legend-item';

        const box = document.createElement('div');
        box.className = 'dash-pie-color-box';
        box.style.backgroundColor = color;

        const displayLabel = formatCellValue(row.label || 'None', columnType);
        const lbl = document.createElement('span');
        lbl.textContent = `${displayLabel} - ${val} (${percent.toFixed(1)}%)`;

        item.append(box, lbl);
        applyDrillDown(item, widget.table, groupCol, row.label);
        legend.appendChild(item);
    });

    const pie = document.createElement('div');
    pie.className = 'dash-pie-chart';
    pie.style.background = `conic-gradient(${conicStops.join(', ')})`;

    wrapper.append(pie, legend);
    return wrapper;
}

WidgetRegistry.register('pie_chart', renderPieChart);
export { renderPieChart };
