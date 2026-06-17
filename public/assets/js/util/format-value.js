// assets/js/util/format-value.js — Shared display formatting: formatBoolean (→ localized Yes/No via I18n) and formatCellValue(value, columnType). Used by dashboard widgets and grid cells.

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
