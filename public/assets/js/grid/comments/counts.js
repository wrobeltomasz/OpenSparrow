// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { debugLog } from '../../debug.js';
import { fetchCommentCounts } from '../api.js';
import { state } from '../state.js';
import { I18n } from '../../i18n.js';
import { makeIconButton } from '../dom.js';
import { pageSignature, isPageLoaded, markPageLoaded } from '../side-fetch-cache.js';

const countsStore = new Map();

export function clearCommentCountsStore() {
    countsStore.clear();
}

export async function loadCommentCounts(pageRows) {
    if (!state.currentTable || pageRows.length === 0) return;
    const ids = pageRows.map(pageRow => pageRow['id']).filter(Boolean).join(',');
    if (!ids) return;

    const signature = pageSignature('comments', state.currentTable, pageRows);
    if (!isPageLoaded(signature)) {
        try {
            const counts = await fetchCommentCounts(state.currentTable, ids);
            for (const [rowId, count] of Object.entries(counts)) {
                countsStore.set(`${state.currentTable}:${rowId}`, count);
            }
            markPageLoaded(signature);
        } catch (error) {
            debugLog('comment counts failed', error);
            return;
        }
    }

    for (const row of pageRows) {
        const rowId = String(row['id']);
        const td = document.querySelector(`[data-actions-row-id="${CSS.escape(rowId)}"]`);
        if (!td) continue;

        const panel = td.querySelector('.td-actions-panel');
        if (!panel) continue;

        const count = countsStore.get(`${state.currentTable}:${rowId}`) ?? 0;

        if (count > 0) {
            const badge = document.createElement('span');
            badge.className = 'c-count-badge';
            badge.textContent = String(count);
            badge.dataset.rowId = rowId;
            badge.title = I18n.t('grid.go_to_comments');
            badge.addEventListener('click', event => {
                event.stopPropagation();
                window.location.href = `edit.php?table=${encodeURIComponent(state.currentTable)}&id=${encodeURIComponent(rowId)}#tab-comments`;
            });
            panel.appendChild(badge);
        } else {
            const addButton = makeIconButton({
                cy: 'row-comment-add',
                title: I18n.t('grid.add_comment'),
                icon: 'assets/icons/material/add_comment.svg',
                className: 'btn-icon-comment-add',
                onClick: event => {
                    event.stopPropagation();
                    window.location.href = `edit.php?table=${encodeURIComponent(state.currentTable)}&id=${encodeURIComponent(rowId)}#tab-comments`;
                },
            });
            panel.appendChild(addButton);
        }
    }
}