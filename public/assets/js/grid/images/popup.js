// assets/js/grid/images/popup.js — Hover popup showing a row's gallery images larger
// (thumbnails read from the image loader store).

import { getImageEntry, thumbUrl, fullUrl } from './loader.js';
import { createHoverPopup } from '../hover-popup.js';

let popup = null;

export function initImagePopup() {
    popup = createHoverPopup({ className: 'img-popup', width: 280, verticalThreshold: 220 });

    document.addEventListener('mouseover', e => {
        const td = e.target.closest('[data-img-row-id]');
        if (!td) return;

        const entry = getImageEntry(td.dataset.imgRowId);
        if (!entry?.items?.length) return;

        renderPopup(entry, td.dataset.imgLabel || '');
        popup.show(td);
    });

    document.addEventListener('mouseout', e => {
        if (!e.target.closest('[data-img-row-id]')) return;
        popup.scheduleHide();
    });
}

function renderPopup(entry, label) {
    popup.el.replaceChildren();

    if (label) {
        const title = document.createElement('div');
        title.className = 'img-popup-title';
        title.textContent = label;
        popup.el.appendChild(title);
    }

    const grid = document.createElement('div');
    grid.className = 'img-popup-grid';

    for (const item of entry.items) {
        const link = document.createElement('a');
        link.href = fullUrl(item.uuid);
        link.target = '_blank';
        link.rel = 'noopener';
        link.title = item.name || '';

        const img = document.createElement('img');
        img.loading = 'lazy';
        img.src = thumbUrl(item.uuid);
        img.alt = item.name || '';
        link.appendChild(img);
        grid.appendChild(link);
    }

    popup.el.appendChild(grid);
}
