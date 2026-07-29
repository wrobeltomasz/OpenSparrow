// assets/js/edit/subtable-tooltip.js — record tooltip for edit.php generic
// subtables, reusing the same shared tooltip as the grid (util/record-tooltip.js).
// Row data + column metadata are embedded server-side as data-row/data-columns
// JSON attributes (see public/edit.php), so no extra fetch is needed on hover.

import { showRecordTooltip, hideRecordTooltip, rowsFromRecord } from '../util/record-tooltip.js';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.edit-subtable-wrapper table[data-columns]').forEach(table => {
        let columns = {};
        try {
            columns = JSON.parse(table.dataset.columns || '{}');
        } catch (e) {
            return;
        }

        table.querySelectorAll('tbody tr[data-row]').forEach(tr => {
            let row = null;
            try {
                row = JSON.parse(tr.dataset.row);
            } catch (e) {
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
