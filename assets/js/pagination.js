// pagination.js
import { renderGrid, getState } from './grid.js';
import { debugLog } from './debug.js';

const PAGE_SIZE_OPTIONS = [10, 25, 50, 100];
const LS_KEY = 'sparrow_page_size';

let pageSize = 25;
let currentPage = 1;

// Called once after schema loads. Priority: localStorage > schema default > 25
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

// --- Setup pagination container and render controls ---
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

// --- Render pagination controls ---
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

    // Rows-per-page selector — leftmost, standard best practice position
    const sizeLabel = document.createElement('label');
    sizeLabel.style.cssText = 'display:flex; align-items:center; gap:6px; font-size:13px; color:var(--muted); margin-right:8px;';
    sizeLabel.textContent = 'Rows per page:';

    const sizeSelect = document.createElement('select');
    sizeSelect.style.cssText = 'padding:3px 6px; border:1px solid var(--border); border-radius:4px; font-size:13px; background:var(--panel);';
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

    // Spacer
    const spacer = document.createElement('span');
    spacer.style.flex = '1';
    paginationEl.appendChild(spacer);

    // Showing info
    renderPaginationInfo(filteredData);

    // Prev button
    const prevBtn = document.createElement('button');
    prevBtn.textContent = 'Prev';
    prevBtn.disabled = currentPage <= 1;
    prevBtn.addEventListener('click', async () => {
        if (currentPage > 1) {
            currentPage--;
            await renderGrid(schema);
        }
    });
    paginationEl.appendChild(prevBtn);

    // Page info
    const info = document.createElement('span');
    info.style.cssText = 'font-size:13px; white-space:nowrap;';
    info.textContent = `Page ${currentPage} of ${totalPages}`;
    paginationEl.appendChild(info);

    // Next button
    const nextBtn = document.createElement('button');
    nextBtn.textContent = 'Next';
    nextBtn.disabled = currentPage >= totalPages;
    nextBtn.addEventListener('click', async () => {
        if (currentPage < totalPages) {
            currentPage++;
            await renderGrid(schema);
        }
    });
    paginationEl.appendChild(nextBtn);

    debugLog("Pagination rendered", { currentPage, totalPages, pageSize });
}

// --- Helpers ---
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

// --- Get paginated rows ---
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

// --- Pagination info renderer ---
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

    infoEl.textContent = `Showing ${start}–${end} of ${totalRecords} records`;
}
