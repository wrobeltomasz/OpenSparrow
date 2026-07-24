// assets/js/util/format-value.js — Shared display formatting: formatBoolean (→ localized Yes/No via I18n), formatCellValue(value, columnType) and formatDateTime(iso). Used by dashboard widgets, grid cells and the comment panels.

import { I18n } from '../i18n.js';

export function formatBoolean(value) {
    const boolVal = value === true || value === 't' || value === 'true';
    return boolVal
        ? I18n.t('common.boolean.true', {}, null) || 'Yes'
        : I18n.t('common.boolean.false', {}, null) || 'No';
}

export function formatCellValue(value, columnType) {
    if (columnType === 'boolean') {
        return formatBoolean(value);
    }
    return String(value);
}

// Timestamp as short local date + time, following the browser locale.
// Shared by the comment thread (comments.js) and the "My comments" panel.
export function formatDateTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
}
