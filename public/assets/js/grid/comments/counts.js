// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { debugLog } from '../../debug.js';
import { fetchCommentCounts } from '../api.js';
import { state } from '../state.js';
import { I18n } from '../../i18n.js';
import { makeIconButton } from '../dom.js';

export async function loadCommentCounts(pageRows) {
    if (!state.currentTable || pageRows.length === 0) return;
    const ids = pageRows.map(r => r['id']).filter(Boolean).join(',');
    if (!ids) return;

    try {
        const counts = await fetchCommentCounts(state.currentTable, ids);
        for (const row of pageRows) {
            const rowId = String(row['id']);
            const td = document.querySelector(`[data-actions-row-id="${CSS.escape(rowId)}"]`);
            if (!td) continue;

            const count = counts[rowId] ?? 0;
            const panel = td.querySelector('.td-actions-panel');
            if (!panel) continue;

            if (count > 0) {
                const badge = document.createElement('span');
                badge.className = 'c-count-badge';
                badge.textContent = String(count);
                badge.dataset.rowId = rowId;
                badge.title = I18n.t('grid.go_to_comments');
                badge.addEventListener('click', e => {
                    e.stopPropagation();
                    window.location.href = `edit.php?table=${encodeURIComponent(state.currentTable)}&id=${encodeURIComponent(rowId)}#tab-comments`;
                });
                panel.appendChild(badge);
            } else {
                const addButton = makeIconButton({
                    cy: 'row-comment-add',
                    title: I18n.t('grid.add_comment'),
                    icon: 'assets/icons/add_comment.png',
                    className: 'btn-icon-comment-add',
                    onClick: e => {
                        e.stopPropagation();
                        window.location.href = `edit.php?table=${encodeURIComponent(state.currentTable)}&id=${encodeURIComponent(rowId)}#tab-comments`;
                    },
                });
                panel.appendChild(addButton);
            }
        }
    } catch (error) {
        debugLog('comment counts failed', error);
    }
}
