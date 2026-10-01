// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { debugLog } from '../../debug.js';
import { state } from '../state.js';
import { pageSignature, isPageLoaded, markPageLoaded } from '../side-fetch-cache.js';

const store = new Map();

export function getM2mItems(rowId, m2mIndex) {
    return store.get(`${state.currentTable}:${rowId}:${m2mIndex}`) ?? [];
}

export function clearM2mStore() {
    store.clear();
}

export function setM2mItems(rowId, m2mIndex, labels) {
    store.set(`${state.currentTable}:${rowId}:${m2mIndex}`, labels);
}

export function renderM2mChips(td, items) {
    renderChips(td, items);
}

export async function loadM2mColumns(pageRows, schema) {
    const m2mList = schema.tables[state.currentTable]?.many_to_many;
    if (!m2mList?.length || !pageRows.length) return;

    const ids = pageRows.map(pageRow => pageRow['id']).filter(Boolean).join(',');
    if (!ids) return;

    const signature = pageSignature('m2m', state.currentTable, pageRows);
    const shouldFetch = !isPageLoaded(signature);
    if (shouldFetch) markPageLoaded(signature);

    const relationIndexes = m2mList.map((relation, relationIndex) => relationIndex);
    const fetchPromises = shouldFetch
        ? relationIndexes.map(relationIndex =>
            fetch(`api.php?api=m2m_rows&table=${encodeURIComponent(state.currentTable)}&m2m_index=${relationIndex}&ids=${ids}`)
                .then(result => result.json())
                .then(json => ({ relationIndex, data: json.data || {} }))
                .catch(error => {
                    debugLog('m2m load failed', error);
                    return { relationIndex, data: {} };
                })
        )
        : [];

    const fetchResults = await Promise.all(fetchPromises);

    for (const { relationIndex, data } of fetchResults) {
        for (const [rowId, labels] of Object.entries(data)) {
            store.set(`${state.currentTable}:${rowId}:${relationIndex}`, labels);
        }
    }

    for (const relationIndex of relationIndexes) {
        for (const row of pageRows) {
            const rowKey = String(row['id']);
            const td  = document.querySelector(`[data-m2m-row-id="${CSS.escape(rowKey)}"][data-m2m-index="${relationIndex}"]`);
            if (!td) continue;
            renderChips(td, store.get(`${state.currentTable}:${rowKey}:${relationIndex}`) ?? []);
        }
    }
}

function renderChips(td, items) {
    td.replaceChildren();
    if (!items.length) return;

    const wrap = document.createElement('div');
    wrap.className = 'm2m-chips';

    const visible  = items.slice(0, 3);
    const overflow = items.length - 3;

    for (const label of visible) {
        const chip = document.createElement('span');
        chip.className = 'm2m-chip';
        chip.textContent = label;
        wrap.appendChild(chip);
    }

    if (overflow > 0) {
        const more = document.createElement('span');
        more.className = 'm2m-chip m2m-chip-more';
        more.textContent = `+${overflow}`;
        wrap.appendChild(more);
    }

    td.appendChild(wrap);
}
