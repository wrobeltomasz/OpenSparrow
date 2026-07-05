// assets/js/dashboard/delta.js — buildDelta(widget): previous-period trend badge for count/sum cards.
// Compares widget.data with widget.prev_data (sent by api.php only when a global date filter is active);
// returns a styled element or null when there is nothing to compare.

import { I18n } from '../i18n.js';

function buildDelta(widget) {
    const prev = widget.prev_data;
    const cur = widget.data;
    if (typeof prev !== 'number' || typeof cur !== 'number') return null;

    const diff = cur - prev;
    const el = document.createElement('div');
    el.className = 'dash-delta ' + (diff > 0 ? 'up' : diff < 0 ? 'down' : 'flat');

    if (prev === 0) {
        // No baseline — a percentage would be meaningless, show the absolute change
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
