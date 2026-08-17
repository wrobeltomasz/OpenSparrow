// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

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

        const image = document.createElement('img');
        image.loading = 'lazy';
        image.src = thumbUrl(item.uuid);
        image.alt = item.name || '';
        link.appendChild(image);
        grid.appendChild(link);
    }

    popup.el.appendChild(grid);
}
