// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { renderGrid, getState } from './grid.js';
import { debugLog } from './debug.js';
import { I18n } from './i18n.js';

const PAGE_SIZE_OPTIONS = [10, 25, 50, 100];
const LS_KEY = 'sparrow_page_size';

let pageSize = 25;
let currentPage = 1;

export function initPageSize(schema) {
    const saved = Number(localStorage.getItem(LS_KEY));
    if (PAGE_SIZE_OPTIONS.includes(saved)) {
        pageSize = saved;
        return;
    }
    const fromSchema = Number(schema?.default_page_size);
    if (PAGE_SIZE_OPTIONS.includes(fromSchema)) {
        pageSize = fromSchema;
    }
}

export function setupPagination(schema) {
    let paginationElement = document.getElementById('pagination');

    if (!paginationElement) {
        paginationEl: paginationElement = document.createElement('div');
        paginationElement.id = 'pagination';
        paginationElement.className = 'pagination';

        const gridSection = document.getElementById('gridSection');
        if (gridSection) {
            gridSection.appendChild(paginationElement);
        } else {
            document.body.appendChild(paginationElement);
        }
    }

    renderPagination(schema);
}

export function renderPagination(schema) {
    const { filteredData } = getState();

    const totalPages = Math.max(1, Math.ceil(filteredData.length / pageSize));

    if (currentPage > totalPages) {
        currentPage = totalPages;
    }

    const paginationElement = document.getElementById('pagination');
    if (!paginationElement) return;

    paginationElement.innerHTML = '';
    paginationElement.style.cssText = 'display:flex; align-items:center; gap:8px; flex-wrap:wrap;';

    const sizeLabel = document.createElement('label');
    sizeLabel.className = 'pag-size';
    sizeLabel.textContent = I18n.t('grid.rows_per_page') + ':';

    const sizeSelect = document.createElement('select');
    PAGE_SIZE_OPTIONS.forEach(n => {
        const option = document.createElement('option');
        option.value = n;
        option.textContent = n;
        if (n === pageSize) option.selected = true;
        sizeSelect.appendChild(option);
    });
    sizeSelect.addEventListener('change', async () => {
        pageSize = Number(sizeSelect.value);
        currentPage = 1;
        localStorage.setItem(LS_KEY, pageSize);
        await renderGrid(schema);
    });
    sizeLabel.appendChild(sizeSelect);
    paginationElement.appendChild(sizeLabel);

    const spacer = document.createElement('span');
    spacer.style.flex = '1';
    paginationElement.appendChild(spacer);

    renderPaginationInformation(filteredData);

    const previousButton = document.createElement('button');
    previousButton.textContent = I18n.t('pagination.prev');
    previousButton.disabled = currentPage <= 1;
    previousButton.addEventListener('click', async () => {
        if (currentPage > 1) {
            currentPage--;
            await renderGrid(schema);
        }
    });
    paginationElement.appendChild(previousButton);

    const information = document.createElement('span');
    information.style.cssText = 'font-size:13px; white-space:nowrap;';
    information.textContent = I18n.t('pagination.page_of', { page: currentPage, total: totalPages });
    paginationElement.appendChild(information);

    const nextButton = document.createElement('button');
    nextButton.textContent = I18n.t('pagination.next');
    nextButton.disabled = currentPage >= totalPages;
    nextButton.addEventListener('click', async () => {
        if (currentPage < totalPages) {
            currentPage++;
            await renderGrid(schema);
        }
    });
    paginationElement.appendChild(nextButton);

    const { wasTruncated, loadedOffset, totalRows } = getState();
    if (wasTruncated) {
        const remaining = totalRows > loadedOffset ? totalRows - loadedOffset : 0;
        const loadMoreButton = document.createElement('button');
        loadMoreButton.textContent = remaining > 0
            ? `${I18n.t('grid.load_more')} (${remaining.toLocaleString()})`
            : I18n.t('grid.load_more');
        loadMoreButton.style.cssText = 'margin-left:12px;';
        loadMoreButton.addEventListener('click', () => {
            document.dispatchEvent(new CustomEvent('grid:loadMore'));
        });
        paginationElement.appendChild(loadMoreButton);
    }

    debugLog("Pagination rendered", { currentPage, totalPages, pageSize });
}

export function getPageState() {
    return { currentPage, pageSize };
}

export function setPageSize(size) {
    pageSize = size;
    currentPage = 1;
}

export function resetPagination() {
    currentPage = 1;
}

export function getPageRows() {
    const { filteredData } = getState();

    const totalPages = Math.max(1, Math.ceil(filteredData.length / pageSize));
    if (currentPage > totalPages) {
        currentPage = totalPages;
    }

    const start = (currentPage - 1) * pageSize;
    const end = start + pageSize;

    return filteredData.slice(start, end);
}

function renderPaginationInformation(filteredData) {
    const totalRecords = filteredData.length;

    const start = totalRecords === 0 ? 0 : (currentPage - 1) * pageSize + 1;
    const end = Math.min(currentPage * pageSize, totalRecords);

    let informationElement = document.getElementById('pagination-info');

    if (!informationElement) {
        infoEl: informationElement = document.createElement('span');
        informationElement.id = 'pagination-info';
        informationElement.style.cssText = 'font-size:13px; color:var(--muted); white-space:nowrap; margin-right:8px;';
        const paginationElement = document.getElementById('pagination');
        if (paginationElement) paginationElement.appendChild(informationElement);
    }

    const { wasTruncated, totalRows, loadedOffset } = getState();
    if (wasTruncated && totalRows > loadedOffset) {
        const dbTotal = totalRows.toLocaleString();
        informationElement.textContent = I18n.t('grid.showing', { from: start, to: end, total: totalRecords })
            + ` / ${dbTotal} ${I18n.t('grid.total_in_db')}`;
    } else {
        informationElement.textContent = I18n.t('grid.showing', { from: start, to: end, total: totalRecords });
    }
}
