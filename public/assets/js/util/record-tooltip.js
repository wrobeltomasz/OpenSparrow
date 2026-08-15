// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const TOOLTIP_ID = 'record-tooltip';
const TOOLTIP_STYLE = 'position:absolute;display:none;background:#fff;border:1px solid var(--border);'
    + 'padding:12px;border-radius:6px;box-shadow:0 5px 15px rgba(0,0,0,0.2);font-size:13px;'
    + 'z-index:10000;pointer-events:none;min-width:220px;max-width:340px;max-height:400px;'
    + 'overflow-y:auto;color:var(--text);';

export function getRecordTooltip() {
    let el = document.getElementById(TOOLTIP_ID);
    if (!el) {
        el = document.createElement('div');
        el.id = TOOLTIP_ID;
        el.style.cssText = TOOLTIP_STYLE;
        document.body.appendChild(el);
    }
    return el;
}

export function rowsFromRecord(rowData = {}, columns = {}) {
    const rows = [];

    const seen = new Set();
    const keys = [];
    for (const k of Object.keys(columns)) { keys.push(k); seen.add(k); }
    for (const k of Object.keys(rowData)) {
        if (k.endsWith('__display')) continue;
        if (!seen.has(k)) keys.push(k);
    }
    for (const key of keys) {
        if (key === 'id') continue;
        if (key.endsWith('__display')) continue;
        const val = rowData[key + '__display'] ?? rowData[key];
        if (val === null || val === undefined || val === '') continue;
        const colCfg = columns[key] || {};
        const label = colCfg.display_name || key;
        const color = (colCfg.type || '').toLowerCase() === 'enum'
            ? (colCfg.enum_colors?.[String(val)] ?? null)
            : null;
        rows.push({ label, value: String(val), color });
    }
    return rows;
}

let showTimer = null;

function renderTooltipNow(anchor, { title, rows } = {}) {
    const el = getRecordTooltip();
    el.innerHTML = '';

    if (title !== undefined && title !== null && title !== '') {
        const header = document.createElement('div');
        header.style.cssText = 'font-weight:bold;font-size:14px;margin-bottom:8px;'
            + 'border-bottom:1px solid var(--border-light);padding-bottom:5px;';
        header.textContent = String(title);
        el.appendChild(header);
    }

    (rows || []).forEach(row => {
        const rowDiv = document.createElement('div');
        rowDiv.style.marginBottom = '4px';

        const strong = document.createElement('strong');
        strong.style.color = 'var(--muted)';
        strong.textContent = row.label + ': ';
        rowDiv.appendChild(strong);

        if (row.color) {
            const swatch = document.createElement('span');
            swatch.style.cssText = 'display:inline-block;width:10px;height:10px;border-radius:2px;'
                + `background:${row.color};margin-right:4px;vertical-align:middle;`;
            rowDiv.appendChild(swatch);
        }

        const spanVal = document.createElement('span');
        spanVal.style.color = 'var(--text)';
        spanVal.textContent = String(row.value);
        rowDiv.appendChild(spanVal);

        el.appendChild(rowDiv);
    });

    el.style.display = 'block';

    const rect = anchor.getBoundingClientRect();
    let topPos = rect.bottom + window.scrollY + 5;
    if (topPos + el.offsetHeight > window.innerHeight + window.scrollY) {
        topPos = rect.top + window.scrollY - el.offsetHeight - 5;
    }
    el.style.left = (rect.left + window.scrollX) + 'px';
    el.style.top = topPos + 'px';
}

export function showRecordTooltip(anchor, model = {}, delay = 1000) {
    clearTimeout(showTimer);
    showTimer = setTimeout(() => renderTooltipNow(anchor, model), delay);
}

export function hideRecordTooltip() {
    clearTimeout(showTimer);
    showTimer = null;
    const el = document.getElementById(TOOLTIP_ID);
    if (el) el.style.display = 'none';
}
