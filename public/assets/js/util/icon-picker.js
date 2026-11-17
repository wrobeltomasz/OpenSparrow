// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from '../i18n.js';

const ICON_INDEX_URL = 'assets/icons/material/index.json';
const ICON_TAGS_URL = 'assets/icons/material/tags.json';
const ICON_PAGE_SIZE = 120;

let iconCache = null;

function normalizeIconQuery(value) {
    return String(value).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
}

async function loadIcons() {
    if (iconCache) return iconCache;

    let icons = [];
    try {
        const indexResponse = await fetch(ICON_INDEX_URL);
        const indexData = indexResponse.ok ? await indexResponse.json() : { icons: [] };
        icons = (Array.isArray(indexData.icons) ? indexData.icons : [])
            .map(entry => ({ name: String(entry.name), popularity: Number(entry.popularity) || 0, tags: [] }))
            .sort((first, second) => second.popularity - first.popularity || first.name.localeCompare(second.name));
    } catch (_) {
        icons = [];
    }

    try {
        const tagsResponse = await fetch(ICON_TAGS_URL);
        const tagsData = tagsResponse.ok ? await tagsResponse.json() : { icons: [] };
        const byName = new Map();
        (Array.isArray(tagsData.icons) ? tagsData.icons : []).forEach(entry => {
            byName.set(String(entry.name), Array.isArray(entry.tags) ? entry.tags : []);
        });
        icons.forEach(entry => {
            entry.tags = byName.get(entry.name) || [];
        });
    } catch (_) {
    }

    iconCache = icons;
    return icons;
}

export function buildIconPicker(onInsert) {
    const overlay = document.createElement('div');
    overlay.className = 'icon-picker-overlay';

    const box = document.createElement('div');
    box.className = 'icon-picker-box';

    const closeButton = document.createElement('button');
    closeButton.className = 'c-link-close';
    closeButton.type = 'button';
    closeButton.textContent = '×';
    closeButton.setAttribute('aria-label', I18n.t('header.close'));
    closeButton.addEventListener('click', () => overlay.remove());

    const title = document.createElement('h3');
    title.textContent = I18n.t('chat.icon_picker_title');

    const searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.className = 'icon-picker-search';
    searchInput.placeholder = I18n.t('chat.icon_search');

    const scrollArea = document.createElement('div');
    scrollArea.className = 'icon-picker-scroll';

    box.appendChild(closeButton);
    box.appendChild(title);
    box.appendChild(searchInput);
    box.appendChild(scrollArea);
    overlay.appendChild(box);

    let icons = [];
    let visibleCount = ICON_PAGE_SIZE;

    function buildCell(icon) {
        const cell = document.createElement('button');
        cell.type = 'button';
        cell.className = 'icon-picker-cell';
        cell.title = icon.name;

        const image = document.createElement('img');
        image.src = 'assets/icons/material/' + icon.name + '.svg';
        image.alt = '';
        image.loading = 'lazy';
        cell.appendChild(image);

        const name = document.createElement('span');
        name.className = 'icon-picker-name';
        name.textContent = icon.name;
        cell.appendChild(name);

        cell.addEventListener('click', () => {
            onInsert('[Icon: ' + icon.name + ']');
            overlay.remove();
        });
        return cell;
    }

    function matches(icon, terms) {
        if (terms.length === 0) return true;
        const haystack = normalizeIconQuery(icon.name + ' ' + icon.tags.join(' '));
        return terms.every(term => haystack.includes(term));
    }

    function render() {
        scrollArea.textContent = '';
        const terms = normalizeIconQuery(searchInput.value).split(' ').filter(Boolean);
        const filtered = icons.filter(icon => matches(icon, terms));
        const visible = filtered.slice(0, visibleCount);

        const grid = document.createElement('div');
        grid.className = 'icon-picker-grid';
        visible.forEach(icon => grid.appendChild(buildCell(icon)));
        scrollArea.appendChild(grid);

        if (filtered.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'icon-picker-empty';
            empty.textContent = I18n.t('chat.icon_no_results');
            scrollArea.appendChild(empty);
            return;
        }

        if (filtered.length > visibleCount) {
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'btn btn-secondary btn-sm icon-picker-more';
            more.textContent = I18n.t('chat.icon_show_more');
            more.addEventListener('click', () => {
                visibleCount += ICON_PAGE_SIZE;
                render();
            });
            scrollArea.appendChild(more);
        }
    }

    let searchTimer = 0;
    searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            visibleCount = ICON_PAGE_SIZE;
            render();
        }, 150);
    });

    loadIcons().then(loaded => {
        icons = loaded;
        render();
    });

    return overlay;
}
