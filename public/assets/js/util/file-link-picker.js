// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from '../i18n.js';

const FILE_PAGE_SIZE = 50;

const typeIcons = {
    image: 'assets/icons/material/image.svg',
    pdf: 'assets/icons/picture_as_pdf.png',
    doc: 'assets/icons/material/docs.svg',
    spreadsheet: 'assets/icons/grid_on.png',
    archive: 'assets/icons/folder_zip.png',
    other: 'assets/icons/material/file_present.svg'
};

async function fetchFiles(query) {
    const parameters = new URLSearchParams({
        action: 'list',
        page: 1,
        limit: FILE_PAGE_SIZE,
        type: 'all',
        search: query,
        sort: 'created_at',
        dir: 'desc'
    });
    const result = await fetch('api/files.php?' + parameters.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('chat.error_load'));
    return data.files ?? [];
}

export function buildFileLinkPicker(onInsert) {
    const overlay = document.createElement('div');
    overlay.className = 'file-picker-overlay';

    const box = document.createElement('div');
    box.className = 'file-picker-box';

    const closeButton = document.createElement('button');
    closeButton.className = 'c-link-close';
    closeButton.type = 'button';
    closeButton.textContent = '×';
    closeButton.setAttribute('aria-label', I18n.t('header.close'));
    closeButton.addEventListener('click', () => overlay.remove());

    const title = document.createElement('h3');
    title.textContent = I18n.t('files.select_file');

    const searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.className = 'file-picker-search';
    searchInput.placeholder = I18n.t('files.search_placeholder');

    const scrollArea = document.createElement('div');
    scrollArea.className = 'file-picker-scroll';

    box.appendChild(closeButton);
    box.appendChild(title);
    box.appendChild(searchInput);
    box.appendChild(scrollArea);
    overlay.appendChild(box);

    function buildItem(file) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'file-picker-item';

        const icon = document.createElement('img');
        icon.src = typeIcons[file.type] || typeIcons.other;
        icon.alt = '';
        item.appendChild(icon);

        const name = document.createElement('span');
        name.className = 'file-picker-name';
        name.textContent = file.display_name || file.name;
        item.appendChild(name);

        item.addEventListener('click', () => {
            onInsert('[File: ' + file.uuid + ']');
            overlay.remove();
        });
        return item;
    }

    function render(items) {
        scrollArea.textContent = '';
        if (!items.length) {
            const empty = document.createElement('p');
            empty.className = 'file-picker-empty';
            empty.textContent = I18n.t('files.no_files_match');
            scrollArea.appendChild(empty);
            return;
        }
        const list = document.createElement('div');
        list.className = 'file-picker-list';
        for (const file of items) {
            list.appendChild(buildItem(file));
        }
        scrollArea.appendChild(list);
    }

    function load(query) {
        scrollArea.textContent = '';
        const loading = document.createElement('p');
        loading.className = 'file-picker-empty';
        loading.textContent = I18n.t('common.loading');
        scrollArea.appendChild(loading);
        fetchFiles(query).then(render).catch(() => {
            scrollArea.textContent = '';
            const error = document.createElement('p');
            error.className = 'file-picker-empty';
            error.textContent = I18n.t('chat.error_load');
            scrollArea.appendChild(error);
        });
    }

    let searchTimer = 0;
    searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => load(searchInput.value.trim()), 250);
    });

    load('');

    return overlay;
}
