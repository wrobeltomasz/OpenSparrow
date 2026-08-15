// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from '../i18n.js';

function buildDelta(widget) {
    const prev = widget.prev_data;
    const cur = widget.data;
    if (typeof prev !== 'number' || typeof cur !== 'number') return null;

    const diff = cur - prev;
    const el = document.createElement('div');
    el.className = 'dash-delta ' + (diff > 0 ? 'up' : diff < 0 ? 'down' : 'flat');

    if (prev === 0) {
        el.textContent = diff === 0 ? '0%' : (diff > 0 ? '+' : '') + String(diff);
    } else {
        const pct = (diff / prev) * 100;
        const rounded = Math.abs(pct) >= 10 ? Math.round(pct) : Math.round(pct * 10) / 10;
        el.textContent = (diff > 0 ? '+' : '') + rounded + '%';
    }
    el.title = I18n.t('dashboard.vs_prev', { prev });
    return el;
}

export { buildDelta };
