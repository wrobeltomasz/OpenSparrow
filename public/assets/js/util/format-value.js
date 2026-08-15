// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

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

export function formatDateTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
}
