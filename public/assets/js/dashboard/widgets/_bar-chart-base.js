// assets/js/dashboard/widgets/_bar-chart-base.js — renderBars(widget, orientation): shared renderer for horizontal + vertical bar charts; supports drill-down and value formatting. Not registered directly.

import { applyDrillDown } from '../drill-down.js';
import { formatCellValue } from '../../util/format-value.js';

export function renderBars(widget, orientation) {
    const data = widget.data || [];
    if (data.length === 0) {
        const p = document.createElement('p');
        p.textContent = window.I18n.t('dashboard.no_data');
        return p;
    }

    const maxVal = Math.max(...data.map(d => parseFloat(d.value)));
    const groupCol = widget.query?.group_column;
    const columnType = widget.column_type;
    const wrapper = orientation === 'horizontal'
        ? createHorizontalWrapper()
        : createVerticalWrapper();

    data.forEach(row => {
        const percent = maxVal > 0 ? (row.value / maxVal) * 100 : 0;
        const el = orientation === 'horizontal'
            ? buildHorizontalBar(row, percent, widget.color, columnType)
            : buildVerticalBar(row, percent, widget.color, columnType);
        applyDrillDown(el, widget.table, groupCol, row.label);
        wrapper.appendChild(el);
    });

    return wrapper;
}

function createHorizontalWrapper() {
    const div = document.createElement('div');
    div.className = 'bar-chart';
    return div;
}

function createVerticalWrapper() {
    const div = document.createElement('div');
    div.className = 'dash-vbar-chart';
    return div;
}

function buildHorizontalBar(row, percent, color, columnType) {
    const rowEl = document.createElement('div');
    rowEl.className = 'bar-row';

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

    const val = document.createElement('div');
    val.className = 'bar-value';
    val.textContent = row.value;

    track.appendChild(bar);
    rowEl.append(label, track, val);
    return rowEl;
}

function buildVerticalBar(row, percent, color, columnType) {
    const colEl = document.createElement('div');
    colEl.className = 'dash-vbar-col';

    const val = document.createElement('div');
    val.className = 'dash-vbar-value';
    val.textContent = row.value;

    const track = document.createElement('div');
    track.className = 'dash-vbar-track';

    const bar = document.createElement('div');
    bar.className = 'dash-vbar-fill';
    bar.style.backgroundColor = color || '#3b82f6';
    setTimeout(() => { bar.style.height = `${percent}%`; }, 50);

    const label = document.createElement('div');
    label.className = 'dash-vbar-label';
    const displayLabel = formatCellValue(row.label || 'None', columnType);
    label.textContent = displayLabel;

    track.appendChild(bar);
    colEl.append(val, track, label);
    return colEl;
}
