/* assets/js/print.js — Frontend print-templates renderer (print.php page)
   Fetches templates from api/print.php and renders block-based printable reports
   (header / text / table). All dynamic values are inserted via textContent /
   programmatic DOM building — never innerHTML — so view data cannot inject markup.
   The Print button calls window.print(); print.css provides the @media print layout. */

import { I18n } from './i18n.js';

const containerEl = document.getElementById('printContainer');

/* ── fetch wrapper ── */
async function apiFetch(url) {
    const res  = await fetch(url);
    const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error ?? `HTTP ${res.status}`);
    return data;
}

/* ── {column} placeholder substitution (values come from the first data row) ── */
function substitute(text, row) {
    return String(text ?? '').replace(/\{([a-zA-Z_][a-zA-Z0-9_ ]*)\}/g, (match, key) =>
        row && Object.prototype.hasOwnProperty.call(row, key) ? String(row[key] ?? '') : match);
}

function showError(message) {
    containerEl.replaceChildren();
    const err = document.createElement('div');
    err.className = 'pr-error';
    err.textContent = I18n.t('print.error', { message });
    containerEl.appendChild(err);
}

/* ── render one template block into the printable sheet ── */
function renderBlock(block, rows, columns) {
    const firstRow = rows[0] ?? null;

    if (block.type === 'header') {
        const level = Math.min(3, Math.max(1, parseInt(block.level, 10) || 1));
        const h = document.createElement(`h${level}`);
        h.className = 'pr-block-header';
        h.textContent = substitute(block.text, firstRow);
        return h;
    }

    if (block.type === 'text') {
        const p = document.createElement('p');
        p.className = 'pr-block-text';
        p.textContent = substitute(block.text, firstRow);
        return p;
    }

    if (block.type === 'table') {
        // Column entries are {name, width?, align?}; older templates may still store bare name strings.
        const cols = (Array.isArray(block.columns) && block.columns.length > 0
            ? block.columns
            : Object.keys(rows[0] ?? {})
        ).map(c => (typeof c === 'string' ? { name: c } : c));

        if (cols.length === 0 || rows.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'pr-block-empty';
            empty.textContent = I18n.t('print.no_data');
            return empty;
        }

        const table = document.createElement('table');
        table.className = 'pr-block-table';

        const thead = document.createElement('thead');
        const headTr = document.createElement('tr');
        cols.forEach(col => {
            const th = document.createElement('th');
            th.textContent = columns[col.name]?.display_name ?? col.name;
            if (col.width) th.style.width = `${col.width}%`;
            // Header alignment is fixed (always centered, see .pr-block-table th in print.css);
            // per-column align only applies to body cells.
            headTr.appendChild(th);
        });
        thead.appendChild(headTr);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        rows.forEach(row => {
            const tr = document.createElement('tr');
            cols.forEach(col => {
                const td = document.createElement('td');
                td.textContent = row[col.name] ?? '';
                if (col.align && col.align !== 'left') td.style.textAlign = col.align;
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        return table;
    }

    return null;
}

/* ── pagination: split the rendered sheet into A4-sized .pr-page chunks with a
   "current / total" footer on each. Browsers don't expose page count/breaks to
   script or CSS (no @page margin-box counter support in Chromium/Firefox print),
   so page boundaries are estimated here from measured element heights against
   the @page geometry declared in print.css (size: A4; margin: 15mm). Table rows
   are split across pages (repeating the header); other blocks are kept whole. ── */
const MM_TO_PX = 96 / 25.4;
const PAGE_CONTENT_HEIGHT_PX = 257 * MM_TO_PX; // 297mm A4 − 15mm×2 @page margin − ~10mm footer reserve

function paginateSheet(sheet) {
    const blocks = Array.from(sheet.children);
    if (blocks.length === 0) return;

    const pages = [[]];
    let heightUsed = 0;
    const newPage = () => { pages.push([]); heightUsed = 0; };

    blocks.forEach(block => {
        if (block.tagName === 'TABLE') {
            const thead       = block.querySelector('thead');
            const theadHeight = thead ? thead.getBoundingClientRect().height : 0;
            const rowsEls     = Array.from(block.querySelectorAll('tbody > tr'));
            let curTbody = null;

            const startChunk = () => {
                const chunk = block.cloneNode(false);
                if (thead) chunk.appendChild(thead.cloneNode(true));
                curTbody = document.createElement('tbody');
                chunk.appendChild(curTbody);
                pages[pages.length - 1].push(chunk);
                heightUsed += theadHeight;
            };

            startChunk();
            rowsEls.forEach(tr => {
                const rowHeight = tr.getBoundingClientRect().height;
                if (heightUsed + rowHeight > PAGE_CONTENT_HEIGHT_PX && curTbody.children.length > 0) {
                    newPage();
                    startChunk();
                }
                curTbody.appendChild(tr.cloneNode(true));
                heightUsed += rowHeight;
            });
        } else {
            const blockHeight = block.getBoundingClientRect().height;
            if (heightUsed + blockHeight > PAGE_CONTENT_HEIGHT_PX && pages[pages.length - 1].length > 0) {
                newPage();
            }
            pages[pages.length - 1].push(block.cloneNode(true));
            heightUsed += blockHeight;
        }
    });

    sheet.replaceChildren();
    pages.forEach((nodes, i) => {
        const pageEl = document.createElement('div');
        pageEl.className = 'pr-page';
        nodes.forEach(n => pageEl.appendChild(n));

        const footer = document.createElement('div');
        footer.className = 'pr-page-footer';
        footer.textContent = I18n.t('print.page_of', { current: i + 1, total: pages.length });
        pageEl.appendChild(footer);

        sheet.appendChild(pageEl);
    });
}

/* ── load and render one print template ── */
async function loadPrint(printName) {
    const loadEl = document.createElement('div');
    loadEl.className = 'pr-loading';
    loadEl.textContent = I18n.t('common.loading');
    containerEl.replaceChildren(loadEl);

    let data;
    try {
        data = await apiFetch(`api/print.php?action=data&print=${encodeURIComponent(printName)}`);
    } catch (err) {
        showError(err.message);
        return;
    }

    containerEl.replaceChildren();

    /* toolbar (hidden by @media print) */
    const toolbar = document.createElement('div');
    toolbar.className = 'pr-toolbar';

    const title = document.createElement('span');
    title.className = 'pr-toolbar-title';
    title.textContent = data.display_name ?? printName;
    toolbar.appendChild(title);

    const printBtn = document.createElement('button');
    printBtn.id = 'printPage';
    printBtn.className = 'pr-print-btn';
    printBtn.textContent = I18n.t('print.print_button');
    printBtn.addEventListener('click', () => window.print());
    toolbar.appendChild(printBtn);

    containerEl.appendChild(toolbar);

    /* printable sheet */
    const sheet = document.createElement('div');
    sheet.id = 'printSheet';
    sheet.className = 'pr-sheet';

    const rows    = Array.isArray(data.rows) ? data.rows : [];
    const columns = data.columns ?? {};
    (data.blocks ?? []).forEach(block => {
        const el = renderBlock(block, rows, columns);
        if (el) sheet.appendChild(el);
    });

    if (sheet.childNodes.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'pr-block-empty';
        empty.textContent = I18n.t('print.no_data');
        sheet.appendChild(empty);
    }

    containerEl.appendChild(sheet);
    // Requires layout of the just-appended nodes, so pagination runs after the sheet is in the DOM.
    paginateSheet(sheet);
}

/* ── selector cards (same pattern as the Views selector) ── */
async function loadSelector() {
    const loadEl = document.createElement('div');
    loadEl.className = 'pr-loading';
    loadEl.textContent = I18n.t('print.loading');
    containerEl.replaceChildren(loadEl);

    let data;
    try {
        data = await apiFetch('api/print.php?action=list');
    } catch (err) {
        showError(err.message);
        return;
    }

    containerEl.replaceChildren();

    if (!data.prints || data.prints.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'pr-empty';
        empty.textContent = I18n.t('print.empty');
        containerEl.appendChild(empty);
        return;
    }

    const grid = document.createElement('div');
    grid.className = 'pr-selector';

    data.prints.forEach(p => {
        const card = document.createElement('div');
        card.className = 'pr-selector-card';

        const header = document.createElement('div');
        header.className = 'pr-card-header';

        const iconWrap = document.createElement('div');
        iconWrap.className = 'pr-card-icon';
        if (p.icon) {
            const img = document.createElement('img');
            img.src = p.icon;
            img.alt = '';
            iconWrap.appendChild(img);
        } else {
            const dot = document.createElement('div');
            dot.className = 'pr-card-icon-dot';
            iconWrap.appendChild(dot);
        }
        header.appendChild(iconWrap);

        const cardTitle = document.createElement('h3');
        cardTitle.className = 'pr-card-title';
        cardTitle.textContent = p.display_name ?? p.name;
        header.appendChild(cardTitle);

        const desc = document.createElement('p');
        desc.className = 'pr-card-desc';
        desc.textContent = p.description || '';

        const footer = document.createElement('div');
        footer.className = 'pr-card-footer';
        const openLink = document.createElement('span');
        openLink.className = 'pr-card-open';
        openLink.textContent = I18n.t('print.open');
        footer.appendChild(openLink);

        card.appendChild(header);
        card.appendChild(desc);
        card.appendChild(footer);
        card.addEventListener('click', () => {
            window.location.href = `print.php?print=${encodeURIComponent(p.name)}`;
        });
        grid.appendChild(card);
    });

    containerEl.appendChild(grid);
}

/* ── entry point ── */
document.addEventListener('DOMContentLoaded', async () => {
    await I18n.load();
    const initial = window.PRINT_INITIAL;
    if (initial) {
        loadPrint(initial);
    } else {
        loadSelector();
    }
});
