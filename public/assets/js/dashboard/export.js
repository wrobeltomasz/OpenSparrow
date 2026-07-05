// assets/js/dashboard/export.js — buildExportButton(widget): per-widget CSV download built
// client-side from widget.data (no server call). Returns null when the user lacks the
// canExport capability or the widget has no exportable data.

import { I18n } from '../i18n.js';

function csvCell(value) {
    const s = value === null || value === undefined ? '' : String(value);
    return '"' + s.replace(/"/g, '""') + '"';
}

function widgetRows(widget) {
    const data = widget.data;
    if (typeof data === 'number') {
        // stat_card / kpi_card (count, sum) — single metric, optional previous period
        const header = ['metric', 'value'];
        const row = [widget.title, data];
        if (typeof widget.prev_data === 'number') {
            header.push('previous_period');
            row.push(widget.prev_data);
        }
        return [header, row];
    }
    if (Array.isArray(data) && data.length > 0) {
        // group_by ({label, value}) and list widgets — columns from the first row
        const cols = Object.keys(data[0]);
        return [cols, ...data.map(r => cols.map(c => r[c]))];
    }
    return null;
}

function downloadCSV(rows, title) {
    const csv = rows.map(r => r.map(csvCell).join(',')).join('\r\n');
    // BOM so Excel opens UTF-8 labels correctly
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const slug = String(title || 'widget').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'widget';
    a.href = url;
    a.download = slug + '_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
    URL.revokeObjectURL(url);
}

function buildExportButton(widget) {
    if (!window.USER_CAPS?.canExport) return null;
    if (widgetRows(widget) === null) return null;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'dash-export-btn';
    btn.textContent = 'CSV';
    btn.title = I18n.t('grid.export_csv');
    btn.addEventListener('click', (e) => {
        // Cards are drill-down clickable — keep the export click local
        e.stopPropagation();
        const rows = widgetRows(widget);
        if (rows) downloadCSV(rows, widget.title);
    });
    return btn;
}

export { buildExportButton };
