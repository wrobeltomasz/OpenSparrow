// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { applyDrillDown } from '../drill-down.js';
import { WidgetRegistry } from '../registry.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

const VB_W = 600;
const VB_H = 220;
const PAD_L = 8;
const PAD_R = 8;
const PAD_T = 12;
const PAD_B = 30;

const DEFAULT_COLOR = '#003366';
const MAX_X_LABELS = 8;

function svgEl(tag, attrs) {
    const el = document.createElementNS(SVG_NS, tag);
    for (const [k, v] of Object.entries(attrs)) {
        el.setAttribute(k, String(v));
    }
    return el;
}

function bucketRange(raw, granularity) {
    const m = String(raw ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return null;
    const start = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    const end = new Date(start);
    switch (granularity) {
        case 'year':  end.setUTCFullYear(end.getUTCFullYear() + 1); break;
        case 'month': end.setUTCMonth(end.getUTCMonth() + 1); break;
        case 'week':  end.setUTCDate(end.getUTCDate() + 7); break;
        case 'day':
        default:      end.setUTCDate(end.getUTCDate() + 1); break;
    }
    return { from: start.toISOString().slice(0, 10), to: end.toISOString().slice(0, 10) };
}

function formatBucketLabel(raw, granularity) {
    const s = String(raw ?? '');
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return s;
    const [, y, mo, d] = m;
    switch (granularity) {
        case 'year':  return y;
        case 'month': return `${y}-${mo}`;
        case 'week':
        case 'day':
        default:      return `${y}-${mo}-${d}`;
    }
}

function renderLineChart(widget) {
    const wrapper = document.createElement('div');
    wrapper.className = 'dash-line-wrapper';

    const data = (widget.data || []).map(r => ({
        label: r.label,
        value: parseFloat(r.value) || 0,
    }));

    if (data.length === 0) {
        wrapper.classList.add('dash-line-empty');
        wrapper.textContent = window.I18n.t('dashboard.no_data');
        return wrapper;
    }

    const granularity = widget.query?.granularity;
    const xCol = widget.query?.x_column;
    const color = widget.color || DEFAULT_COLOR;
    const area = widget.query?.area === true || widget.query?.area === 'true';

    const maxVal = Math.max(...data.map(d => d.value), 0);
    const innerW = VB_W - PAD_L - PAD_R;
    const innerH = VB_H - PAD_T - PAD_B;
    const baseY = PAD_T + innerH;

    const pts = data.map((d, i) => {
        const x = data.length === 1
            ? PAD_L + innerW / 2
            : PAD_L + (i / (data.length - 1)) * innerW;
        const y = maxVal > 0 ? baseY - (d.value / maxVal) * innerH : baseY;
        return { x, y, ...d };
    });

    const svg = svgEl('svg', {
        class: 'dash-line-svg',
        viewBox: `0 0 ${VB_W} ${VB_H}`,
        preserveAspectRatio: 'none',
        role: 'img',
    });

    svg.appendChild(svgEl('line', {
        class: 'dash-line-baseline',
        x1: PAD_L, y1: baseY, x2: PAD_L + innerW, y2: baseY,
        'vector-effect': 'non-scaling-stroke',
    }));

    if (area && pts.length > 1) {
        const dPath = `M ${pts[0].x} ${baseY} `
            + pts.map(p => `L ${p.x} ${p.y}`).join(' ')
            + ` L ${pts[pts.length - 1].x} ${baseY} Z`;
        const areaEl = svgEl('path', { class: 'dash-line-area', d: dPath });
        areaEl.style.fill = color;
        svg.appendChild(areaEl);
    }

    if (pts.length > 1) {
        const line = svgEl('polyline', {
            class: 'dash-line-path',
            points: pts.map(p => `${p.x},${p.y}`).join(' '),
            'vector-effect': 'non-scaling-stroke',
        });
        line.style.stroke = color;
        svg.appendChild(line);
    }

    pts.forEach((p) => {
        const dot = svgEl('circle', {
            class: 'dash-line-point',
            cx: p.x, cy: p.y, r: 4,
        });
        dot.style.fill = color;
        const title = svgEl('title', {});
        title.textContent = `${formatBucketLabel(p.label, granularity)}: ${p.value}`;
        dot.appendChild(title);
        if (xCol) applyDrillDown(dot, widget.table, xCol, p.label, bucketRange(p.label, granularity));
        svg.appendChild(dot);
    });

    wrapper.appendChild(svg);

    const axis = document.createElement('div');
    axis.className = 'dash-line-axis';
    const step = Math.max(1, Math.ceil(data.length / MAX_X_LABELS));
    data.forEach((d, i) => {
        const span = document.createElement('span');
        span.className = 'dash-line-tick';

        const show = i === 0 || i === data.length - 1 || i % step === 0;
        span.textContent = show ? formatBucketLabel(d.label, granularity) : '';
        axis.appendChild(span);
    });
    wrapper.appendChild(axis);

    return wrapper;
}

WidgetRegistry.register('line_chart', renderLineChart);
export { renderLineChart };
