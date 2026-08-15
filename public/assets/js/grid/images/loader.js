// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { debugLog } from '../../debug.js';
import { state } from '../state.js';

const store = new Map();

export function getImageEntry(rowId) {
    return store.get(`${state.currentTable}:${rowId}`) ?? null;
}

export function clearImageStore() {
    store.clear();
}

export function thumbUrl(uuid) {
    return `file_download.php?uuid=${encodeURIComponent(uuid)}&thumb=1`;
}

export function fullUrl(uuid) {
    return `file_download.php?uuid=${encodeURIComponent(uuid)}`;
}

export async function loadImageColumn(pageRows, schema) {
    const cfg = schema.tables[state.currentTable]?.images;
    if (!cfg?.enabled || !cfg.show_in_grid || !pageRows.length) return;

    const ids = pageRows.map(r => r['id']).filter(Boolean).join(',');
    if (!ids) return;

    try {
        const res  = await fetch(`api.php?api=image_rows&table=${encodeURIComponent(state.currentTable)}&ids=${ids}`);
        const json = await res.json();
        const data = json.data || {};

        for (const [rowId, entry] of Object.entries(data)) {
            store.set(`${state.currentTable}:${rowId}`, entry);
        }

        for (const row of pageRows) {
            const rid = String(row['id']);
            const td  = document.querySelector(`[data-img-row-id="${CSS.escape(rid)}"]`);
            if (!td) continue;
            renderThumb(td, store.get(`${state.currentTable}:${rid}`));
        }
    } catch (err) {
        debugLog('image column load failed', err);
    }
}

function renderThumb(td, entry) {
    td.replaceChildren();
    if (!entry || !entry.items?.length) return;

    const wrap = document.createElement('div');
    wrap.className = 'img-cell';

    const first = entry.items[0];
    const img = document.createElement('img');
    img.className = 'img-thumb';
    img.loading = 'lazy';
    img.src = thumbUrl(first.uuid);
    img.alt = first.name || '';
    wrap.appendChild(img);

    const extra = (entry.total || entry.items.length) - 1;
    if (extra > 0) {
        const more = document.createElement('span');
        more.className = 'img-more';
        more.textContent = `+${extra}`;
        wrap.appendChild(more);
    }

    td.appendChild(wrap);
}
