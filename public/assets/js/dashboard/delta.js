// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from '../i18n.js';

function buildDelta(widget) {
    const previous = widget.prev_data;
    const current = widget.data;
    if (typeof previous !== 'number' || typeof current !== 'number') return null;

    const difference = current - previous;
    const element = document.createElement('div');
    element.className = 'dash-delta ' + (difference > 0 ? 'up' : difference < 0 ? 'down' : 'flat');

    if (previous === 0) {
        element.textContent = difference === 0 ? '0%' : (difference > 0 ? '+' : '') + String(difference);
    } else {
        const percent = (difference / previous) * 100;
        const rounded = Math.abs(percent) >= 10 ? Math.round(percent) : Math.round(percent * 10) / 10;
        element.textContent = (difference > 0 ? '+' : '') + rounded + '%';
    }
    element.title = I18n.t('dashboard.vs_prev', { prev: previous });
    return element;
}

export { buildDelta };
