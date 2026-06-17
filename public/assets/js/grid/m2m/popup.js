// assets/js/grid/m2m/popup.js — Hover popup listing a row's many-to-many items (read from the m2m loader store).

import { getM2mItems } from './loader.js';

let popup   = null;
let hideTimer = null;

export function initM2mPopup() {
    popup = document.createElement('div');
    popup.className = 'm2m-popup';
    popup.hidden = true;
    document.body.appendChild(popup);

    popup.addEventListener('mouseenter', () => clearTimeout(hideTimer));
    popup.addEventListener('mouseleave', () => { popup.hidden = true; });

    document.addEventListener('mouseover', e => {
        const td = e.target.closest('[data-m2m-row-id]');
        if (!td) return;

        const rowId = td.dataset.m2mRowId;
        const mi    = parseInt(td.dataset.m2mIndex, 10);
        const items = getM2mItems(rowId, mi);
        if (!items.length) return;

        clearTimeout(hideTimer);
        positionPopup(td);
        renderPopup(items, td.dataset.m2mLabel || 'Related');
        popup.hidden = false;
    });

    document.addEventListener('mouseout', e => {
        if (!e.target.closest('[data-m2m-row-id]')) return;
        hideTimer = setTimeout(() => { popup.hidden = true; }, 150);
    });
}

function positionPopup(anchor) {
    const rect = anchor.getBoundingClientRect();
    const left = Math.min(Math.max(8, rect.left), window.innerWidth - 260);
    popup.style.left = `${left}px`;
    if (window.innerHeight - rect.bottom >= 160 || rect.top < 160) {
        popup.style.top    = `${rect.bottom + 6}px`;
        popup.style.bottom = '';
    } else {
        popup.style.top    = '';
        popup.style.bottom = `${window.innerHeight - rect.top + 6}px`;
    }
}

function renderPopup(items, label) {
    popup.replaceChildren();

    const title = document.createElement('div');
    title.className = 'm2m-popup-title';
    title.textContent = label;
    popup.appendChild(title);

    for (const text of items) {
        const item = document.createElement('div');
        item.className = 'm2m-popup-item';
        item.textContent = text;
        popup.appendChild(item);
    }
}
