import { debugLog } from '../debug.js';
import { state } from './state.js';

export async function fetchTableData(table, urlParams, { offset = 0, search = '' } = {}) {
    let url = `api.php?api=list&table=${encodeURIComponent(table)}`;
    const filterCol = urlParams.get('filter_col');
    const filterVal = urlParams.get('filter_val');
    if (urlParams.get('table') === table && filterCol && filterVal !== null) {
        url += `&filter_col=${encodeURIComponent(filterCol)}&filter_val=${encodeURIComponent(filterVal)}`;
    }
    if (offset > 0) url += `&offset=${offset}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
}

export async function preloadForeignKeys(schema) {
    const fks = schema.tables[state.currentTable]?.foreign_keys;
    if (!fks) return;

    const fetches = [];
    for (const col of state.displayedColumns) {
        if (!fks[col]) continue;
        const key = `${state.currentTable}_${col}`;
        if (!state.fkCache.has(key)) {
            state.fkCache.set(key,
                fetch(`api_fk.php?table=${encodeURIComponent(state.currentTable)}&col=${encodeURIComponent(col)}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                .then(r => r.json())
                .then(d => d.rows || [])
                .catch(err => {
                    debugLog('FK fetch failed', { col, err });
                    return [];
                })
            );
        }
        fetches.push(state.fkCache.get(key));
    }
    await Promise.all(fetches);
}

export async function fetchCommentCounts(table, ids) {
    const res = await fetch(
        `api_comments.php?action=counts&related_table=${encodeURIComponent(table)}&related_ids=${encodeURIComponent(ids)}`,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (!data.success) throw new Error('counts API returned success=false');
    return data.counts ?? {};
}

export async function fetchSubtableCounts(table, ids) {
    const res = await fetch(
        `api.php?api=subtable_counts&table=${encodeURIComponent(table)}&ids=${encodeURIComponent(ids)}`,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (!data.success) throw new Error('subtable_counts API returned success=false');
    return data.counts ?? {};
}

export async function fetchCommentPreview(table, rowId) {
    const res = await fetch(
        `api_comments.php?action=list&related_table=${encodeURIComponent(table)}&related_id=${encodeURIComponent(rowId)}&limit=3`,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (!data.success) throw new Error('preview API returned success=false');
    return data.comments ?? [];
}
