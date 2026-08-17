// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from '../i18n.js';

export function firstEqCondition(conditions) {
    if (!Array.isArray(conditions)) return null;
    return conditions.find(condition => condition.op === '=' && condition.col && condition.val !== undefined && condition.val !== null) ?? null;
}

export function applyDrillDown(element, table, filterColumn = null, filterValue = null, range = null) {
    element.style.cursor = 'pointer';
    element.title = I18n.t('dashboard.click_details');
    element.addEventListener('click', () => {
        let url = `index.php?table=${encodeURIComponent(table)}`;
        if (filterColumn && range && (range.from || range.to)) {
            url += `&filter_col=${encodeURIComponent(filterColumn)}`;
            if (range.from) url += `&filter_from=${encodeURIComponent(range.from)}`;
            if (range.to) url += `&filter_to=${encodeURIComponent(range.to)}`;
        } else if (filterColumn) {
            const value = filterValue !== null && filterValue !== undefined ? filterValue : '';
            url += `&filter_col=${encodeURIComponent(filterColumn)}&filter_val=${encodeURIComponent(value)}`;
        }
        window.location.href = url;
    });
    element.addEventListener('mouseenter', () => { element.style.opacity = '0.8'; });
    element.addEventListener('mouseleave', () => { element.style.opacity = '1'; });
}
