// assets/js/grid/body/subtable-counts.js — loadSubtableCounts(): fetches child-record counts (api.js) for the visible rows and highlights the expand buttons that have records via color + tooltip.

import { debugLog } from '../../debug.js';
import { fetchSubtableCounts } from '../api.js';
import { state } from '../state.js';
import { I18n } from '../../i18n.js';

export async function loadSubtableCounts(pageRows, schema) {
    const subtables = schema.tables[state.currentTable]?.subtables || [];
    if (!state.currentTable || pageRows.length === 0 || subtables.length === 0) return;

    const ids = pageRows.map(r => r['id']).filter(Boolean).join(',');
    if (!ids) return;

    try {
        const counts = await fetchSubtableCounts(state.currentTable, ids);
        for (const row of pageRows) {
            const rowId = String(row['id']);
            const cnt = counts[rowId] ?? 0;
            if (cnt === 0) continue;

            const td = document.querySelector(`[data-expand-row-id="${CSS.escape(rowId)}"]`);
            if (!td) continue;

            const btn = td.querySelector('button');
            if (!btn) continue;

            btn.classList.add('has-records');
            btn.title = I18n.t('grid.drilldown_count', { count: cnt });
        }
    } catch (err) {
        debugLog('subtable counts failed', err);
    }
}
