import { applyDrillDown } from '../drill-down.js';

export function renderBars(widget, orientation) {
    const data = widget.data || [];
    if (data.length === 0) {
        const p = document.createElement('p');
        p.textContent = 'No data';
        return p;
    }

    const maxVal = Math.max(...data.map(d => parseFloat(d.value)));
    const groupCol = widget.query?.group_column;
    const wrapper = orientation === 'horizontal'
        ? createHorizontalWrapper()
        : createVerticalWrapper();

    data.forEach(row => {
        const percent = maxVal > 0 ? (row.value / maxVal) * 100 : 0;
        const el = orientation === 'horizontal'
            ? buildHorizontalBar(row, percent, widget.color)
            : buildVerticalBar(row, percent, widget.color);
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

function buildHorizontalBar(row, percent, color) {
    const rowEl = document.createElement('div');
    rowEl.className = 'bar-row';

    const label = document.createElement('div');
    label.className = 'bar-label';
    label.textContent = row.label || 'None';

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

function buildVerticalBar(row, percent, color) {
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
    label.textContent = row.label || 'None';

    track.appendChild(bar);
    colEl.append(val, track, label);
    return colEl;
}
