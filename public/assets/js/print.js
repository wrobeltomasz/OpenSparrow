// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from './i18n.js';
import { apiJson as apiFetch } from './util/api.js';

const containerElement = document.getElementById('printContainer');

function substitute(text, row) {
    return String(text ?? '').replace(/\{([a-zA-Z_][a-zA-Z0-9_ ]*)\}/g, (match, key) =>
        row && Object.prototype.hasOwnProperty.call(row, key) ? String(row[key] ?? '') : match);
}

function readParametersFromLocation() {
    const values = {};
    new URLSearchParameters(window.location.search).forEach((value, key) => {
        if (key.startsWith('p_') && value !== '') values[key.slice(2)] = value;
    });
    return values;
}

function buildParametersQuery(values) {
    return Object.entries(values)
        .filter(([, v]) => v !== '' && v != null)
        .map(([k, v]) => `p_${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
        .join('&');
}

function updateUrl(printName, values) {
    const qs  = buildParametersQuery(values);
    const url = `print.php?print=${encodeURIComponent(printName)}${qs ? `&${qs}` : ''}`;
    window.history.replaceState(null, '', url);
}

async function fetchParameterOptions(printName, key) {
    try {
        const data = await apiFetch(
            `api/print.php?action=param_options&print=${encodeURIComponent(printName)}&key=${encodeURIComponent(key)}`
        );
        return data.options ?? [];
    } catch {
        return [];
    }
}

let currentPrintName = null;

function initClearFilters() {
    const button = document.getElementById('clearFilters');
    if (!button) return;
    button.addEventListener('click', () => {
        if (currentPrintName) loadPrint(currentPrintName, {});
    });
}

async function renderParametersBar(printName, parameterDefs, currentValues) {
    const bar = document.getElementById('printFilters');
    const clearButton = document.getElementById('clearFilters');
    if (!bar) return;
    bar.replaceChildren();

    if (!Array.isArray(parameterDefs) || parameterDefs.length === 0) {
        if (clearButton) clearButton.hidden = true;
        return;
    }

    for (const def of parameterDefs) {
        const select = document.createElement('select');
        select.id = `printParam_${def.key}`;

        const label = document.createElement('label');
        label.className = 'print-filter-label';
        label.htmlFor = select.id;
        label.textContent = def.label || def.key;

        if (!def.required) {
            const optionAll = document.createElement('option');
            optionAll.value = '';
            optionAll.textContent = I18n.t('print.params_all');
            select.appendChild(optionAll);
        }

        const options = await fetchParameterOptions(printName, def.key);
        options.forEach(option => {
            const o = document.createElement('option');
            o.value = option.value ?? '';
            o.textContent = option.label ?? option.value ?? '';
            if (String(currentValues[def.key] ?? '') === String(option.value ?? '')) o.selected = true;
            select.appendChild(o);
        });

        select.addEventListener('change', () => {
            loadPrint(printName, { ...currentValues, [def.key]: select.value });
        });

        bar.appendChild(label);
        bar.appendChild(select);
    }

    if (clearButton) clearButton.hidden = Object.values(currentValues).every(v => !v);
}

function showError(message) {
    containerElement.replaceChildren();
    const error = document.createElement('div');
    error.className = 'pr-error';
    error.textContent = I18n.t('print.error', { message });
    containerElement.appendChild(error);
}

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
        const columns = (Array.isArray(block.columns) && block.columns.length > 0
            ? block.columns
            : Object.keys(rows[0] ?? {})
        ).map(c => (typeof c === 'string' ? { name: c } : c));

        if (columns.length === 0 || rows.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'pr-block-empty';
            empty.textContent = I18n.t('print.no_data');
            return empty;
        }

        const table = document.createElement('table');
        table.className = 'pr-block-table';

        const thead = document.createElement('thead');
        const headTr = document.createElement('tr');
        columns.forEach(column => {
            const th = document.createElement('th');
            th.textContent = columns[column.name]?.display_name ?? column.name;
            if (column.width) th.style.width = `${column.width}%`;

            headTr.appendChild(th);
        });
        thead.appendChild(headTr);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        rows.forEach(row => {
            const tr = document.createElement('tr');
            columns.forEach(column => {
                const td = document.createElement('td');
                td.textContent = row[column.name] ?? '';
                if (column.align && column.align !== 'left') td.style.textAlign = column.align;
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        return table;
    }

    return null;
}

const MM_TO_PX = 96 / 25.4;
const PAGE_CONTENT_HEIGHT_PX = 257 * MM_TO_PX;

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
            const rowsElements     = Array.from(block.querySelectorAll('tbody > tr'));
            let currentTbody = null;

            const startChunk = () => {
                const chunk = block.cloneNode(false);
                if (thead) chunk.appendChild(thead.cloneNode(true));
                currentTbody = document.createElement('tbody');
                chunk.appendChild(currentTbody);
                pages[pages.length - 1].push(chunk);
                heightUsed += theadHeight;
            };

            startChunk();
            rowsElements.forEach(tr => {
                const rowHeight = tr.getBoundingClientRect().height;
                if (heightUsed + rowHeight > PAGE_CONTENT_HEIGHT_PX && currentTbody.children.length > 0) {
                    newPage();
                    startChunk();
                }
                currentTbody.appendChild(tr.cloneNode(true));
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
        const pageElement = document.createElement('div');
        pageElement.className = 'pr-page';
        nodes.forEach(n => pageElement.appendChild(n));

        const footer = document.createElement('div');
        footer.className = 'pr-page-footer';
        footer.textContent = I18n.t('print.page_of', { current: i + 1, total: pages.length });
        pageElement.appendChild(footer);

        sheet.appendChild(pageElement);
    });
}

async function loadPrint(printName, parameterValues = {}) {
    currentPrintName = printName;
    const loadElement = document.createElement('div');
    loadElement.className = 'pr-loading';
    loadElement.textContent = I18n.t('common.loading');
    containerElement.replaceChildren(loadElement);

    let data;
    try {
        const qs = buildParametersQuery(parameterValues);
        data = await apiFetch(`api/print.php?action=data&print=${encodeURIComponent(printName)}${qs ? `&${qs}` : ''}`);
    } catch (error) {
        showError(error.message);
        return;
    }

    containerElement.replaceChildren();
    updateUrl(printName, data.applied_params ?? {});
    await renderParametersBar(printName, data.params ?? [], data.applied_params ?? {});

    const toolbar = document.createElement('div');
    toolbar.className = 'pr-toolbar';

    const title = document.createElement('span');
    title.className = 'pr-toolbar-title';
    title.textContent = data.display_name ?? printName;
    toolbar.appendChild(title);

    const printButton = document.createElement('button');
    printButton.id = 'printPage';
    printButton.className = 'pr-print-btn';
    printButton.textContent = I18n.t('print.print_button');
    printButton.addEventListener('click', () => window.print());
    toolbar.appendChild(printButton);

    containerElement.appendChild(toolbar);

    const sheet = document.createElement('div');
    sheet.id = 'printSheet';
    sheet.className = 'pr-sheet';

    const rows    = Array.isArray(data.rows) ? data.rows : [];
    const columns = data.columns ?? {};
    (data.blocks ?? []).forEach(block => {
        const element = renderBlock(block, rows, columns);
        if (element) sheet.appendChild(element);
    });

    if (sheet.childNodes.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'pr-block-empty';
        empty.textContent = I18n.t('print.no_data');
        sheet.appendChild(empty);
    }

    containerElement.appendChild(sheet);

    paginateSheet(sheet);
}

async function loadSelector() {
    const loadElement = document.createElement('div');
    loadElement.className = 'pr-loading';
    loadElement.textContent = I18n.t('print.loading');
    containerElement.replaceChildren(loadElement);

    let data;
    try {
        data = await apiFetch('api/print.php?action=list');
    } catch (error) {
        showError(error.message);
        return;
    }

    containerElement.replaceChildren();

    if (!data.prints || data.prints.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'pr-empty';
        empty.textContent = I18n.t('print.empty');
        containerElement.appendChild(empty);
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
            const image = document.createElement('img');
            image.src = p.icon;
            image.alt = '';
            iconWrap.appendChild(image);
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

        const description = document.createElement('p');
        description.className = 'pr-card-desc';
        description.textContent = p.description || '';

        const footer = document.createElement('div');
        footer.className = 'pr-card-footer';
        const openLink = document.createElement('span');
        openLink.className = 'pr-card-open';
        openLink.textContent = I18n.t('print.open');
        footer.appendChild(openLink);

        card.appendChild(header);
        card.appendChild(description);
        card.appendChild(footer);
        card.addEventListener('click', () => {
            window.location.href = `print.php?print=${encodeURIComponent(p.name)}`;
        });
        grid.appendChild(card);
    });

    containerElement.appendChild(grid);
}

document.addEventListener('DOMContentLoaded', async () => {
    await I18n.load();
    initClearFilters();
    const initial = window.PRINT_INITIAL;
    if (initial) {
        loadPrint(initial, readParametersFromLocation());
    } else {
        loadSelector();
    }
});
