// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { debugLog } from '../debug.js';
import { state } from './state.js';

export async function fetchTableData(table, urlParameters, { offset = 0, search = '', includeTotal = true, order = null, columnFilters = null } = {}) {
    let url = `api.php?api=list&table=${encodeURIComponent(table)}`;
    const filterColumn = urlParameters.get('filter_col');
    const filterValue = urlParameters.get('filter_val');
    const filterFrom = urlParameters.get('filter_from');
    const filterTo = urlParameters.get('filter_to');
    if (urlParameters.get('table') === table && filterColumn && (filterValue !== null || filterFrom !== null || filterTo !== null)) {
        url += `&filter_col=${encodeURIComponent(filterColumn)}`;
        if (filterValue !== null) url += `&filter_val=${encodeURIComponent(filterValue)}`;
        if (filterFrom !== null) url += `&filter_from=${encodeURIComponent(filterFrom)}`;
        if (filterTo !== null) url += `&filter_to=${encodeURIComponent(filterTo)}`;
    }
    if (offset > 0) url += `&offset=${offset}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    if (!includeTotal) url += '&include_total=0';
    if (order && order.length > 0) {
        const orderValue = order.map(rule => `${rule.column}:${rule.asc ? 'asc' : 'desc'}`).join(',');
        url += `&order=${encodeURIComponent(orderValue)}`;
    }
    if (columnFilters && Object.keys(columnFilters).length > 0) {
        url += `&column_filters=${encodeURIComponent(JSON.stringify(columnFilters))}`;
    }
    const result = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!result.ok) throw new Error(`HTTP ${result.status}`);
    return result.json();
}

export async function preloadForeignKeys(schema) {
    const foreignKeys = schema.tables[state.currentTable]?.foreign_keys;
    if (!foreignKeys) return;

    const fetches = [];
    for (const column of state.displayedColumns) {
        if (!foreignKeys[column]) continue;
        const key = `${state.currentTable}_${column}`;
        if (!state.fkCache.has(key)) {
            state.fkCache.set(key,
                fetch(`api/fk.php?table=${encodeURIComponent(state.currentTable)}&col=${encodeURIComponent(column)}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                .then(response => response.json())
                .then(payload => payload.rows || [])
                .catch(error => {
                    debugLog('FK fetch failed', { col: column, err: error });
                    return [];
                })
            );
        }
        fetches.push(state.fkCache.get(key));
    }
    await Promise.all(fetches);
    buildFkData(schema, foreignKeys);
}

export async function buildFkData(schema, foreignKeys) {
    for (const column of state.displayedColumns) {
        const fkConfig = foreignKeys[column];
        if (!fkConfig) continue;
        const key = `${state.currentTable}_${column}`;
        if (state.fkData.has(key) || !state.fkCache.has(key)) continue;

        const dispColumns = Array.isArray(fkConfig.display_column)
            ? fkConfig.display_column
            : [fkConfig.display_column || 'id'];

        const cachedRows = (await state.fkCache.get(key)) || [];

        const options = [];
        const labels = new Set();
        const labelToId = new Map();
        cachedRows.forEach(referenceRow => {
            const displayValue = dispColumns.map(displayColumn => referenceRow[displayColumn + '__display'] ?? referenceRow[displayColumn] ?? '').join(' - ') || String(referenceRow['id']);
            const realId = String(referenceRow['id']);
            options.push({ value: displayValue, realId });
            labels.add(displayValue);
            labelToId.set(displayValue, realId);
        });
        state.fkData.set(key, { options, labels, labelToId });
    }
}

export async function fetchCommentCounts(table, ids) {
    const result = await fetch(
        `api/comments.php?action=counts&related_table=${encodeURIComponent(table)}&related_ids=${encodeURIComponent(ids)}`,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    if (!result.ok) throw new Error(`HTTP ${result.status}`);
    const data = await result.json();
    if (!data.success) throw new Error('counts API returned success=false');
    return data.counts ?? {};
}

export async function fetchSubtableCounts(table, ids) {
    const result = await fetch(
        `api.php?api=subtable_counts&table=${encodeURIComponent(table)}&ids=${encodeURIComponent(ids)}`,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    if (!result.ok) throw new Error(`HTTP ${result.status}`);
    const data = await result.json();
    if (!data.success) throw new Error('subtable_counts API returned success=false');
    return data.counts ?? {};
}

export async function fetchCommentPreview(table, rowId) {
    const result = await fetch(
        `api/comments.php?action=list&related_table=${encodeURIComponent(table)}&related_id=${encodeURIComponent(rowId)}&limit=3`,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    if (!result.ok) throw new Error(`HTTP ${result.status}`);
    const data = await result.json();
    if (!data.success) throw new Error('preview API returned success=false');
    return data.comments ?? [];
}
