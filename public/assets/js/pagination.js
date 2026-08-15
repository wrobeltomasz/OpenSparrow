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
    let paginationEl = document.getElementById('pagination');

    if (!paginationEl) {
        paginationEl = document.createElement('div');
        paginationEl.id = 'pagination';
        paginationEl.className = 'pagination';

        const gridSection = document.getElementById('gridSection');
        if (gridSection) {
            gridSection.appendChild(paginationEl);
        } else {
            document.body.appendChild(paginationEl);
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

    const paginationEl = document.getElementById('pagination');
    if (!paginationEl) return;

    paginationEl.innerHTML = '';
    paginationEl.style.cssText = 'display:flex; align-items:center; gap:8px; flex-wrap:wrap;';

    const sizeLabel = document.createElement('label');
    sizeLabel.className = 'pag-size';
    sizeLabel.textContent = I18n.t('grid.rows_per_page') + ':';

    const sizeSelect = document.createElement('select');
    PAGE_SIZE_OPTIONS.forEach(n => {
        const opt = document.createElement('option');
        opt.value = n;
        opt.textContent = n;
        if (n === pageSize) opt.selected = true;
        sizeSelect.appendChild(opt);
    });
    sizeSelect.addEventListener('change', async () => {
        pageSize = Number(sizeSelect.value);
        currentPage = 1;
        localStorage.setItem(LS_KEY, pageSize);
        await renderGrid(schema);
    });
    sizeLabel.appendChild(sizeSelect);
    paginationEl.appendChild(sizeLabel);

    const spacer = document.createElement('span');
    spacer.style.flex = '1';
    paginationEl.appendChild(spacer);

    renderPaginationInfo(filteredData);

    const prevBtn = document.createElement('button');
    prevBtn.textContent = I18n.t('pagination.prev');
    prevBtn.disabled = currentPage <= 1;
    prevBtn.addEventListener('click', async () => {
        if (currentPage > 1) {
            currentPage--;
            await renderGrid(schema);
        }
    });
    paginationEl.appendChild(prevBtn);

    const info = document.createElement('span');
    info.style.cssText = 'font-size:13px; white-space:nowrap;';
    info.textContent = I18n.t('pagination.page_of', { page: currentPage, total: totalPages });
    paginationEl.appendChild(info);

    const nextBtn = document.createElement('button');
    nextBtn.textContent = I18n.t('pagination.next');
    nextBtn.disabled = currentPage >= totalPages;
    nextBtn.addEventListener('click', async () => {
        if (currentPage < totalPages) {
            currentPage++;
            await renderGrid(schema);
        }
    });
    paginationEl.appendChild(nextBtn);

    const { wasTruncated, loadedOffset, totalRows } = getState();
    if (wasTruncated) {
        const remaining = totalRows > loadedOffset ? totalRows - loadedOffset : 0;
        const loadMoreBtn = document.createElement('button');
        loadMoreBtn.textContent = remaining > 0
            ? `${I18n.t('grid.load_more')} (${remaining.toLocaleString()})`
            : I18n.t('grid.load_more');
        loadMoreBtn.style.cssText = 'margin-left:12px;';
        loadMoreBtn.addEventListener('click', () => {
            document.dispatchEvent(new CustomEvent('grid:loadMore'));
        });
        paginationEl.appendChild(loadMoreBtn);
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

function renderPaginationInfo(filteredData) {
    const totalRecords = filteredData.length;

    const start = totalRecords === 0 ? 0 : (currentPage - 1) * pageSize + 1;
    const end = Math.min(currentPage * pageSize, totalRecords);

    let infoEl = document.getElementById('pagination-info');

    if (!infoEl) {
        infoEl = document.createElement('span');
        infoEl.id = 'pagination-info';
        infoEl.style.cssText = 'font-size:13px; color:var(--muted); white-space:nowrap; margin-right:8px;';
        const paginationEl = document.getElementById('pagination');
        if (paginationEl) paginationEl.appendChild(infoEl);
    }

    const { wasTruncated, totalRows, loadedOffset } = getState();
    if (wasTruncated && totalRows > loadedOffset) {
        const dbTotal = totalRows.toLocaleString();
        infoEl.textContent = I18n.t('grid.showing', { from: start, to: end, total: totalRecords })
            + ` / ${dbTotal} ${I18n.t('grid.total_in_db')}`;
    } else {
        infoEl.textContent = I18n.t('grid.showing', { from: start, to: end, total: totalRecords });
    }
}
