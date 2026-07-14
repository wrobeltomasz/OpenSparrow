// assets/js/grid/m2m/popup.js — Hover popup listing a row's many-to-many items (read from the m2m loader store).

import { getM2mItems } from './loader.js';
import { createHoverPopup } from '../hover-popup.js';

let popup = null;

export function initM2mPopup() {
    popup = createHoverPopup({ className: 'm2m-popup', width: 260, verticalThreshold: 160 });

    document.addEventListener('mouseover', e => {
        const td = e.target.closest('[data-m2m-row-id]');
        if (!td) return;

        const rowId = td.dataset.m2mRowId;
        const mi    = parseInt(td.dataset.m2mIndex, 10);
        const items = getM2mItems(rowId, mi);
        if (!items.length) return;

        renderPopup(items, td.dataset.m2mLabel || 'Related');
        popup.show(td);
    });

    document.addEventListener('mouseout', e => {
        if (!e.target.closest('[data-m2m-row-id]')) return;
        popup.scheduleHide();
    });
}

function renderPopup(items, label) {
    popup.el.replaceChildren();

    const title = document.createElement('div');
    title.className = 'm2m-popup-title';
    title.textContent = label;
    popup.el.appendChild(title);

    for (const text of items) {
        const item = document.createElement('div');
        item.className = 'm2m-popup-item';
        item.textContent = text;
        popup.el.appendChild(item);
    }
}
