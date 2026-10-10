// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { getState } from './grid/state.js';
import { debugLog } from './debug.js';

const crcTable = (() => {
    const table = new Uint32Array(256);
    for (let index = 0; index < 256; index++) {
        let value = index;
        for (let bit = 0; bit < 8; bit++) {
            value = value & 1 ? 0xEDB88320 ^ (value >>> 1) : value >>> 1;
        }
        table[index] = value >>> 0;
    }
    return table;
})();

function crc32(bytes) {
    let crc = 0xFFFFFFFF;
    for (let index = 0; index < bytes.length; index++) {
        crc = crcTable[(crc ^ bytes[index]) & 0xFF] ^ (crc >>> 8);
    }
    return (crc ^ 0xFFFFFFFF) >>> 0;
}

function zipStore(entries) {
    const encoder = new TextEncoder();
    const localParts = [];
    const centralParts = [];
    let offset = 0;
    for (const entry of entries) {
        const nameBytes = encoder.encode(entry.name);
        const dataCrc = crc32(entry.data);
        const size = entry.data.length;
        const local = new Uint8Array(30 + nameBytes.length);
        const localView = new DataView(local.buffer);
        localView.setUint32(0, 0x04034b50, true);
        localView.setUint16(4, 20, true);
        localView.setUint16(6, 0x0800, true);
        localView.setUint16(8, 0, true);
        localView.setUint16(10, 0, true);
        localView.setUint16(12, 0x21, true);
        localView.setUint32(14, dataCrc, true);
        localView.setUint32(18, size, true);
        localView.setUint32(22, size, true);
        localView.setUint16(26, nameBytes.length, true);
        localView.setUint16(28, 0, true);
        local.set(nameBytes, 30);
        localParts.push(local, entry.data);
        const central = new Uint8Array(46 + nameBytes.length);
        const centralView = new DataView(central.buffer);
        centralView.setUint32(0, 0x02014b50, true);
        centralView.setUint16(4, 20, true);
        centralView.setUint16(6, 20, true);
        centralView.setUint16(8, 0x0800, true);
        centralView.setUint16(10, 0, true);
        centralView.setUint16(12, 0, true);
        centralView.setUint16(14, 0x21, true);
        centralView.setUint32(16, dataCrc, true);
        centralView.setUint32(20, size, true);
        centralView.setUint32(24, size, true);
        centralView.setUint16(28, nameBytes.length, true);
        centralView.setUint16(30, 0, true);
        centralView.setUint16(32, 0, true);
        centralView.setUint16(34, 0, true);
        centralView.setUint16(36, 0, true);
        centralView.setUint32(38, 0, true);
        centralView.setUint32(42, offset, true);
        central.set(nameBytes, 46);
        centralParts.push(central);
        offset += local.length + size;
    }
    const centralSize = centralParts.reduce((sum, part) => sum + part.length, 0);
    const end = new Uint8Array(22);
    const endView = new DataView(end.buffer);
    endView.setUint32(0, 0x06054b50, true);
    endView.setUint16(4, 0, true);
    endView.setUint16(6, 0, true);
    endView.setUint16(8, entries.length, true);
    endView.setUint16(10, entries.length, true);
    endView.setUint32(12, centralSize, true);
    endView.setUint32(16, offset, true);
    endView.setUint16(20, 0, true);
    return new Blob([...localParts, ...centralParts, end], {
        type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });
}

function escapeXmlText(text) {
    return text
        .replace(/[\x00-\x08\x0B\x0C\x0E-\x1F]/g, '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&apos;');
}

function columnLetter(index) {
    let label = '';
    let current = index;
    while (current >= 0) {
        label = String.fromCharCode(65 + (current % 26)) + label;
        current = Math.floor(current / 26) - 1;
    }
    return label;
}

function buildCellXml(reference, value) {
    if (value === null || value === undefined || value === '') return `<c r="${reference}"/>`;
    if (typeof value === 'boolean') return `<c r="${reference}" t="b"><v>${value ? 1 : 0}</v></c>`;
    if (typeof value === 'number' && Number.isFinite(value)) return `<c r="${reference}"><v>${value}</v></c>`;
    const text = String(value);
    const escaped = escapeXmlText(text);
    const preserve = /^\s|\s$/.test(text) ? ' xml:space="preserve"' : '';
    return `<c r="${reference}" t="inlineStr"><is><t${preserve}>${escaped}</t></is></c>`;
}

function sanitizeSheetName(name) {
    return String(name ?? '')
        .replace(/[[\]:*?/\\]/g, '')
        .replace(/[\x00-\x1F]/g, '')
        .trim()
        .slice(0, 31)
        .replace(/^'|'$/g, '') || 'Sheet1';
}

export function buildXlsxBlob({ headers, rows, sheetName }) {
    const sheetRows = [headers, ...rows];
    let sheetData = '';
    sheetRows.forEach((values, rowIndex) => {
        let cells = '';
        values.forEach((value, columnIndex) => {
            cells += buildCellXml(columnLetter(columnIndex) + (rowIndex + 1), value);
        });
        sheetData += `<row r="${rowIndex + 1}">${cells}</row>`;
    });
    const safeName = escapeXmlText(sanitizeSheetName(sheetName));
    const parts = [
        {
            name: '[Content_Types].xml',
            text: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                + '<Default Extension="xml" ContentType="application/xml"/>'
                + '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                + '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                + '</Types>',
        },
        {
            name: '_rels/.rels',
            text: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                + '</Relationships>',
        },
        {
            name: 'xl/workbook.xml',
            text: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                + '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                + 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                + `<sheets><sheet name="${safeName}" sheetId="1" r:id="rId1"/></sheets></workbook>`,
        },
        {
            name: 'xl/_rels/workbook.xml.rels',
            text: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                + '</Relationships>',
        },
        {
            name: 'xl/worksheets/sheet1.xml',
            text: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                + '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                + `<sheetData>${sheetData}</sheetData></worksheet>`,
        },
    ];
    const encoder = new TextEncoder();
    return zipStore(parts.map(part => ({ name: part.name, data: encoder.encode(part.text) })));
}

export function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}

export function exportXlsx() {
    const { displayedColumns, filteredData, currentTable } = getState();
    debugLog('Exporting XLSX', { rows: filteredData.length });
    const rows = filteredData.map(dataRow =>
        displayedColumns.map(columnName => dataRow[columnName] ?? null)
    );
    const blob = buildXlsxBlob({
        headers: [...displayedColumns],
        rows,
        sheetName: currentTable,
    });
    downloadBlob(blob, 'export.xlsx');
}
