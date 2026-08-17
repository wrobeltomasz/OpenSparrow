// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { showRecordTooltip, hideRecordTooltip, rowsFromRecord } from '../util/record-tooltip.js';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.edit-subtable-wrapper table[data-columns]').forEach(table => {
        let columns = {};
        try {
            columns = JSON.parse(table.dataset.columns || '{}');
        } catch (error) {
            return;
        }

        table.querySelectorAll('tbody tr[data-row]').forEach(tr => {
            let row = null;
            try {
                row = JSON.parse(tr.dataset.row);
            } catch (error) {
                return;
            }

            tr.addEventListener('mouseenter', () => {
                showRecordTooltip(tr, { title: tr.dataset.title || '', rows: rowsFromRecord(row, columns) });
            });
            tr.addEventListener('mouseleave', hideRecordTooltip);
            tr.addEventListener('focusin', hideRecordTooltip);
        });
    });
});
