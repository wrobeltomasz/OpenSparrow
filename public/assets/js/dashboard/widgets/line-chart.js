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

function svgElement(tag, attrs) {
    const element = document.createElementNS(SVG_NS, tag);
    for (const [k, v] of Object.entries(attrs)) {
        element.setAttribute(k, String(v));
    }
    return element;
}

function bucketRange(raw, granularity) {
    const dateMatch = String(raw ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!dateMatch) return null;
    const start = new Date(Date.UTC(+dateMatch[1], +dateMatch[2] - 1, +dateMatch[3]));
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
    const dateString = String(raw ?? '');
    const dateMatch = dateString.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!dateMatch) return dateString;
    const [, pointY, mo, point] = dateMatch;
    switch (granularity) {
        case 'year':  return pointY;
        case 'month': return `${pointY}-${mo}`;
        case 'week':
        case 'day':
        default:      return `${pointY}-${mo}-${point}`;
    }
}

function renderLineChart(widget) {
    const wrapper = document.createElement('div');
    wrapper.className = 'dash-line-wrapper';

    const data = (widget.data || []).map(dataRow => ({
        label: dataRow.label,
        value: parseFloat(dataRow.value) || 0,
    }));

    if (data.length === 0) {
        wrapper.classList.add('dash-line-empty');
        wrapper.textContent = window.I18n.t('dashboard.no_data');
        return wrapper;
    }

    const granularity = widget.query?.granularity;
    const xColumn = widget.query?.x_column;
    const color = widget.color || DEFAULT_COLOR;
    const area = widget.query?.area === true || widget.query?.area === 'true';

    const maxValue = Math.max(...data.map(point => point.value), 0);
    const innerW = VB_W - PAD_L - PAD_R;
    const innerH = VB_H - PAD_T - PAD_B;
    const baseY = PAD_T + innerH;

    const points = data.map((point, pointIndex) => {
        const pointX = data.length === 1
            ? PAD_L + innerW / 2
            : PAD_L + (pointIndex / (data.length - 1)) * innerW;
        const pointY = maxValue > 0 ? baseY - (point.value / maxValue) * innerH : baseY;
        return { x: pointX, y: pointY, ...point };
    });

    const svg = svgElement('svg', {
        class: 'dash-line-svg',
        viewBox: `0 0 ${VB_W} ${VB_H}`,
        preserveAspectRatio: 'none',
        role: 'img',
    });

    svg.appendChild(svgElement('line', {
        class: 'dash-line-baseline',
        x1: PAD_L, y1: baseY, x2: PAD_L + innerW, y2: baseY,
        'vector-effect': 'non-scaling-stroke',
    }));

    if (area && points.length > 1) {
        const dPath = `M ${points[0].x} ${baseY} `
            + points.map(point => `L ${point.x} ${point.y}`).join(' ')
            + ` L ${points[points.length - 1].x} ${baseY} Z`;
        const areaElement = svgElement('path', { class: 'dash-line-area', d: dPath });
        areaElement.style.fill = color;
        svg.appendChild(areaElement);
    }

    if (points.length > 1) {
        const line = svgElement('polyline', {
            class: 'dash-line-path',
            points: points.map(point => `${point.x},${point.y}`).join(' '),
            'vector-effect': 'non-scaling-stroke',
        });
        line.style.stroke = color;
        svg.appendChild(line);
    }

    points.forEach((point) => {
        const dot = svgElement('circle', {
            class: 'dash-line-point',
            cx: point.x, cy: point.y, r: 4,
        });
        dot.style.fill = color;
        const title = svgElement('title', {});
        title.textContent = `${formatBucketLabel(point.label, granularity)}: ${point.value}`;
        dot.appendChild(title);
        if (xColumn) applyDrillDown(dot, widget.table, xColumn, point.label, bucketRange(point.label, granularity));
        svg.appendChild(dot);
    });

    wrapper.appendChild(svg);

    const axis = document.createElement('div');
    axis.className = 'dash-line-axis';
    const step = Math.max(1, Math.ceil(data.length / MAX_X_LABELS));
    data.forEach((point, pointIndex) => {
        const span = document.createElement('span');
        span.className = 'dash-line-tick';

        const show = pointIndex === 0 || pointIndex === data.length - 1 || pointIndex % step === 0;
        span.textContent = show ? formatBucketLabel(point.label, granularity) : '';
        axis.appendChild(span);
    });
    wrapper.appendChild(axis);

    return wrapper;
}

WidgetRegistry.register('line_chart', renderLineChart);
export { renderLineChart };
