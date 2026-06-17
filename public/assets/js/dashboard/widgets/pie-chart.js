// assets/js/dashboard/widgets/pie-chart.js — Registers the 'pie_chart' widget; renders an SVG pie with a colour palette, legend and drill-down.

import { applyDrillDown } from '../drill-down.js';
import { WidgetRegistry } from '../registry.js';
import { formatCellValue } from '../../util/format-value.js';

const COLORS = ['#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];

function renderPieChart(widget) {
    const wrapper = document.createElement('div');
    wrapper.className = 'dash-pie-wrapper';

    const data = widget.data || [];
    if (data.length === 0) { wrapper.textContent = 'No data'; return wrapper; }

    const total = data.reduce((sum, d) => sum + parseFloat(d.value), 0);
    if (total === 0) { wrapper.textContent = 'Sum is zero'; return wrapper; }

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
