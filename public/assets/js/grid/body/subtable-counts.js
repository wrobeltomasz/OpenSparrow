// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

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

            const button = td.querySelector('button');
            if (!button) continue;

            button.classList.add('has-records');
            button.title = I18n.t('grid.drilldown_count', { count: cnt });
        }
    } catch (error) {
        debugLog('subtable counts failed', error);
    }
}
