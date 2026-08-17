// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from '../i18n.js';

function csvCell(value) {
    const stringValue = value === null || value === undefined ? '' : String(value);
    return '"' + stringValue.replace(/"/g, '""') + '"';
}

function widgetRows(widget) {
    const data = widget.data;
    if (typeof data === 'number') {
        const header = ['metric', 'value'];
        const row = [widget.title, data];
        if (typeof widget.prev_data === 'number') {
            header.push('previous_period');
            row.push(widget.prev_data);
        }
        return [header, row];
    }
    if (Array.isArray(data) && data.length > 0) {
        const columns = Object.keys(data[0]);
        return [columns, ...data.map(dataRow => columns.map(columnName => dataRow[columnName]))];
    }
    return null;
}

function downloadCSV(rows, title) {
    const csv = rows.map(dataRow => dataRow.map(csvCell).join(',')).join('\r\n');

    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const downloadLink = document.createElement('a');
    const slug = String(title || 'widget').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'widget';
    downloadLink.href = url;
    downloadLink.download = slug + '_' + new Date().toISOString().slice(0, 10) + '.csv';
    downloadLink.click();
    URL.revokeObjectURL(url);
}

function buildExportButton(widget) {
    if (!window.USER_CAPS?.canExport) return null;
    if (widgetRows(widget) === null) return null;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'dash-export-btn';
    button.textContent = 'CSV';
    button.title = I18n.t('grid.export_csv');
    button.addEventListener('click', (event) => {
        event.stopPropagation();
        const rows = widgetRows(widget);
        if (rows) downloadCSV(rows, widget.title);
    });
    return button;
}

export { buildExportButton };
