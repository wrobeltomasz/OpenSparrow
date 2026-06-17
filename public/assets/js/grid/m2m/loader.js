// assets/js/grid/m2m/loader.js — Many-to-many cell data store: loads + caches related items per (table:rowId:m2mIdx); getM2mItems/clearM2mStore.

import { debugLog } from '../../debug.js';
import { state } from '../state.js';

// Keyed: `${table}:${rowId}:${m2mIdx}` → string[]
const store = new Map();

export function getM2mItems(rowId, m2mIdx) {
    return store.get(`${state.currentTable}:${rowId}:${m2mIdx}`) ?? [];
}

export function clearM2mStore() {
    store.clear();
}

export async function loadM2mColumns(pageRows, schema) {
    const m2mList = schema.tables[state.currentTable]?.many_to_many;
    if (!m2mList?.length || !pageRows.length) return;

    const ids = pageRows.map(r => r['id']).filter(Boolean).join(',');
    if (!ids) return;

    for (let mi = 0; mi < m2mList.length; mi++) {
        try {
            const res  = await fetch(`api.php?api=m2m_rows&table=${encodeURIComponent(state.currentTable)}&m2m_index=${mi}&ids=${ids}`);
            const json = await res.json();
            const data = json.data || {};

            for (const [rowId, labels] of Object.entries(data)) {
                store.set(`${state.currentTable}:${rowId}:${mi}`, labels);
            }

            for (const row of pageRows) {
                const rid = String(row['id']);
                const td  = document.querySelector(`[data-m2m-row-id="${CSS.escape(rid)}"][data-m2m-index="${mi}"]`);
                if (!td) continue;
                renderChips(td, store.get(`${state.currentTable}:${rid}:${mi}`) ?? []);
            }
        } catch (err) {
            debugLog('m2m load failed', err);
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
