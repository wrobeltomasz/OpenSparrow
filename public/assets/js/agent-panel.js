// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from './i18n.js';
import { renderAnswer } from './rag-render.js';
import { apiFetch } from './util/api.js';

const API  = 'api/rag.php';
const t    = (k, v) => I18n.t(k, v);

const MAX_CONTEXT_ROWS = 50;
const MAX_CONTEXT_COLS = 12;

function formatTime() {
    return new Date().toTimeString().slice(0, 8);
}

let panelElement, overlayElement, tagsElement, convElement, queryElement, sendButton, stopButton, clearButton;
let contextBarElement, gridOptionElement, fabElement;
let tagsLoaded = false;
let currentAbortController = null;
let abortedByUser = false;

let lastTurn = null;

function buildPanel() {
    overlayElement           = document.createElement('div');
    overlayElement.className = 'ag-overlay';
    overlayElement.id        = 'agOverlay';
    document.body.appendChild(overlayElement);

    panelElement = document.createElement('div');
    panelElement.className = 'ag-panel';
    panelElement.id        = 'agPanel';
    panelElement.setAttribute('role', 'dialog');
    panelElement.setAttribute('aria-label', t('agent.title'));
    panelElement.setAttribute('aria-modal', 'true');

    const header  = document.createElement('div');
    header.className = 'ag-header';
    const titleElement = document.createElement('span');
    titleElement.className   = 'ag-title';
    titleElement.textContent = t('agent.title');
    const closeButton  = document.createElement('button');
    closeButton.className  = 'ag-close';
    closeButton.setAttribute('aria-label', t('agent.close'));
    closeButton.textContent = '×';
    header.appendChild(titleElement);
    header.appendChild(closeButton);

    contextBarElement           = document.createElement('div');
    contextBarElement.className = 'ag-context-bar';
    contextBarElement.id        = 'agContextBar';
    contextBarElement.hidden    = true;

    gridOptionElement           = document.createElement('div');
    gridOptionElement.className = 'ag-grid-opt';
    gridOptionElement.id        = 'agGridOpt';
    gridOptionElement.hidden    = true;

    tagsElement           = document.createElement('div');
    tagsElement.className = 'ag-tags';
    tagsElement.id        = 'agTags';

    convElement = document.createElement('div');
    convElement.className = 'ag-conversation';
    convElement.id        = 'agConv';
    convElement.setAttribute('role', 'log');
    convElement.setAttribute('aria-live', 'polite');

    const inputArea      = document.createElement('div');
    inputArea.className  = 'ag-input-area';
    queryElement              = document.createElement('textarea');
    queryElement.className    = 'ag-textarea';
    queryElement.id           = 'agQuery';
    queryElement.rows         = 2;
    queryElement.maxLength    = 2000;
    queryElement.placeholder  = t('agent.placeholder');
    queryElement.setAttribute('aria-label', t('agent.title'));
    const actions       = document.createElement('div');
    actions.className   = 'ag-actions';
    clearButton            = document.createElement('button');
    clearButton.className  = 'btn btn-secondary ag-clear-btn';
    clearButton.type       = 'button';
    clearButton.textContent = t('agent.clear');
    sendButton             = document.createElement('button');
    sendButton.className   = 'btn btn-primary ag-send-btn';
    sendButton.type        = 'button';
    sendButton.textContent = t('agent.send');
    stopButton             = document.createElement('button');
    stopButton.className   = 'btn btn-danger';
    stopButton.type        = 'button';
    stopButton.disabled    = true;
    stopButton.textContent = t('agent.stop');
    actions.appendChild(clearButton);
    actions.appendChild(stopButton);
    actions.appendChild(sendButton);
    inputArea.appendChild(queryElement);
    inputArea.appendChild(actions);

    panelElement.appendChild(header);
    panelElement.appendChild(contextBarElement);
    panelElement.appendChild(gridOptionElement);
    panelElement.appendChild(tagsElement);
    panelElement.appendChild(convElement);
    panelElement.appendChild(inputArea);
    document.body.appendChild(panelElement);

    closeButton.addEventListener('click', closePanel);
    overlayElement.addEventListener('click', closePanel);
    sendButton.addEventListener('click', sendQuery);
    stopButton.addEventListener('click', () => { abortedByUser = true; currentAbortController?.abort(); });
    clearButton.addEventListener('click', () => { convElement.innerHTML = ''; lastTurn = null; });
    queryElement.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendQuery();
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && panelElement.classList.contains('active')) closePanel();
    });
}

function openPanel() {
    panelElement.classList.add('active');
    overlayElement.classList.add('active');
    if (fabElement) fabElement.hidden = true;
    updateContextBar();
    renderGridDataOption();
    if (!tagsLoaded) loadTags();
    queryElement.focus();
}

function closePanel() {
    panelElement.classList.remove('active');
    overlayElement.classList.remove('active');
    if (fabElement) fabElement.hidden = false;
}

function pageTableName() {
    if (typeof window.CURRENT_GRID_TABLE === 'function') {
        const live = window.CURRENT_GRID_TABLE();
        if (live) return live;
    }
    const fromUrl = new URLSearchParameters(window.location.search).get('table');
    if (fromUrl) return fromUrl;

    return window.CURRENT_VIEW?.name ?? '';
}

function pageTableDisplayName() {
    const currentTable = pageTableName();
    if (currentTable) {
        const activeLink = document.querySelector('.custom-nav-link.active[data-table]');
        return activeLink?.querySelector('.menu-text')?.textContent.trim() || currentTable;
    }
    return window.CURRENT_VIEW?.display ?? '';
}

function updateContextBar() {
    const displayName = pageTableDisplayName();
    if (!displayName) {
        contextBarElement.hidden = true;
        return;
    }
    contextBarElement.hidden = false;
    contextBarElement.innerHTML = '';
    const icon = document.createElement('img');
    icon.src    = 'assets/icons/grid_on.png';
    icon.alt    = '';
    icon.width  = 14;
    icon.height = 14;
    icon.style.cssText = 'vertical-align:middle; opacity:0.7; flex-shrink:0;';
    const label = document.createElement('span');
    label.textContent = t('agent.context_table', { table: displayName });
    contextBarElement.appendChild(icon);
    contextBarElement.appendChild(label);
}

function buildFab() {
    if (!window.CHAT_BUBBLE_ENABLED) return;
    fabElement           = document.createElement('button');
    fabElement.id        = 'agFab';
    fabElement.className = 'ag-fab';
    fabElement.type      = 'button';
    fabElement.setAttribute('aria-label', t('agent.title'));
    const image   = document.createElement('img');
    image.src     = 'assets/icons/comment.png';
    image.alt     = '';
    image.width   = 24;
    image.height  = 24;
    fabElement.appendChild(image);
    fabElement.addEventListener('click', openPanel);
    document.body.appendChild(fabElement);
}

function readGridContext() {
    if (typeof window.CURRENT_GRID_CONTEXT === 'function') {
        try {
            const text = window.CURRENT_GRID_CONTEXT();
            if (text) return text;
        } catch (err) {
            console.error('Grid context provider failed, falling back to DOM:', err);
        }
    }
    return readGridContextFromDom();
}

function readGridContextFromDom() {
    const table = document.querySelector('#grid table, #viewContainer table');
    if (!table) return '';
    const tableName = pageTableName();

    const allThs       = Array.from(table.querySelectorAll('thead th'));
    let   headerElements    = allThs.filter(th => th.dataset.col);
    if (headerElements.length === 0) return '';

    const totalColumns = headerElements.length;
    if (headerElements.length > MAX_CONTEXT_COLS) {
        const idElements   = headerElements.filter(th => th.dataset.col.toLowerCase() === 'id');
        const restElements = headerElements.filter(th => th.dataset.col.toLowerCase() !== 'id');
        headerElements     = idElements.concat(restElements).slice(0, MAX_CONTEXT_COLS);

        headerElements.sort((a, b) => allThs.indexOf(a) - allThs.indexOf(b));
    }

    const headers    = headerElements.map(th => th.dataset.col);
    const columnIndexes = headerElements.map(th => allThs.indexOf(th));

    const allRows = [];

    table.querySelectorAll('tbody tr:not(.vw-group-header):not(.vw-group-subtotal)').forEach(tr => {
        const allTds  = Array.from(tr.querySelectorAll('td'));
        const cells   = columnIndexes.map(i => (allTds[i]?.textContent.trim() ?? '').replace(/\s+/g, ' '));
        if (cells.some(c => c !== '')) allRows.push(cells);
    });

    if (allRows.length === 0) return '';

    const rows        = allRows.slice(0, MAX_CONTEXT_ROWS);
    const hiddenRows  = allRows.length - rows.length;
    const hiddenColumns  = totalColumns - headers.length;

    let text = hiddenRows === 0
        ? `table: ${tableName} — COMPLETE SET: all ${allRows.length} row(s) of this report are`
          + ' included below. No rows are missing, so you MAY count, sum and average over these rows.\n'
        : `table: ${tableName} — CURRENT PAGE ONLY: ${rows.length} of ${allRows.length} row(s) shown.\n`;
    text += headers.join(' | ') + '\n';
    rows.forEach(r => { text += r.join(' | ') + '\n'; });
    if (hiddenRows > 0) {
        text += `...(${hiddenRows} more rows not shown — do not compute totals or counts over the whole set)\n`;
    }
    if (hiddenColumns > 0) text += `...(${hiddenColumns} more columns not shown)\n`;
    return text;
}

async function loadTags() {
    try {
        const result  = await fetch(API + '?action=tags');
        const data = await result.json();
        renderTags(data.tags ?? []);
        tagsLoaded = true;
    } catch {
        const msg        = document.createElement('span');
        msg.className    = 'ag-tag-empty';
        msg.textContent  = t('agent.tags_error');
        tagsElement.innerHTML = '';
        tagsElement.appendChild(msg);
    }
}

function renderTags(tags) {
    tagsElement.innerHTML = '';
    if (tags.length === 0) {
        const msg       = document.createElement('span');
        msg.className   = 'ag-tag-empty';
        msg.textContent = t('agent.no_tags');
        tagsElement.appendChild(msg);
        return;
    }

    tags.forEach(tag => {
        const label     = document.createElement('label');
        label.className = 'ag-tag-item';
        const callback        = document.createElement('input');
        callback.type         = 'checkbox';
        callback.value        = tag;
        label.appendChild(callback);
        label.appendChild(document.createTextNode(' ' + tag));
        tagsElement.appendChild(label);
    });
}

function selectedTags() {
    return Array.from(tagsElement.querySelectorAll('input[type=checkbox]:checked')).map(callback => callback.value);
}

function renderGridDataOption() {
    if (readGridContext() === '') {
        gridOptionElement.hidden    = true;
        gridOptionElement.innerHTML = '';
        return;
    }
    const previousChecked = gridOptionElement.querySelector('#agGridDataCb')?.checked ?? false;
    gridOptionElement.innerHTML = '';
    gridOptionElement.hidden    = false;

    const label     = document.createElement('label');
    label.className = 'ag-tag-item';
    const callback        = document.createElement('input');
    callback.type         = 'checkbox';
    callback.id           = 'agGridDataCb';
    callback.checked      = previousChecked;
    label.appendChild(callback);
    label.appendChild(document.createTextNode(' ' + t('agent.use_grid_data')));
    gridOptionElement.appendChild(label);
}

function gridDataSelected() {
    return gridOptionElement.querySelector('#agGridDataCb')?.checked ?? false;
}

function appendUserMessage(text) {
    const wrap      = document.createElement('div');
    wrap.className  = 'ag-msg ag-msg-user';
    const bubble    = document.createElement('div');
    bubble.className   = 'ag-msg-bubble';
    bubble.textContent = text;
    const timestamp           = document.createElement('div');
    timestamp.className       = 'ag-msg-time';
    timestamp.textContent     = formatTime();
    wrap.appendChild(bubble);
    wrap.appendChild(timestamp);
    convElement.appendChild(wrap);
    scrollDown();
    return wrap;
}

function appendThinking() {
    const wrap         = document.createElement('div');
    wrap.className     = 'ag-msg ag-msg-assistant';
    const thinking     = document.createElement('div');
    thinking.className   = 'ag-msg-thinking';
    thinking.textContent = t('agent.thinking');
    wrap.appendChild(thinking);
    convElement.appendChild(wrap);
    scrollDown();
    return wrap;
}

function appendNotice(text) {
    const wrap     = document.createElement('div');
    wrap.className = 'ag-msg ag-msg-assistant';
    const element       = document.createElement('div');
    element.className   = 'ag-msg-warning';
    element.textContent = text;
    wrap.appendChild(element);
    convElement.appendChild(wrap);
    scrollDown();
}

function replaceWithAnswer(wrap, answer, sources, tagFallback, suggestions) {
    wrap.innerHTML = '';

    if (tagFallback) {
        const warn       = document.createElement('div');
        warn.className   = 'ag-msg-warning';
        warn.textContent = t('agent.tag_fallback');
        wrap.appendChild(warn);
    }

    const bubble     = document.createElement('div');
    bubble.className = 'ag-msg-bubble';
    bubble.innerHTML = renderAnswer(answer, {
        allowedTables: window.SCHEMA_TABLES,
        linkClass:     'ag-record-link',
        markdown:      true,
    });
    wrap.appendChild(bubble);

    if (sources && sources.length > 0) {
        const sourceRow     = document.createElement('div');
        sourceRow.className = 'ag-msg-sources';
        sources.forEach(source => {
            const chip       = document.createElement('span');
            chip.className   = 'ag-source-chip';
            chip.textContent = source.filename;
            sourceRow.appendChild(chip);
        });
        wrap.appendChild(sourceRow);
    }

    if (suggestions && suggestions.length > 0) {
        const suggRow     = document.createElement('div');
        suggRow.className = 'ag-msg-suggestions';
        suggestions.forEach(q => {
            const chip       = document.createElement('button');
            chip.type        = 'button';
            chip.className   = 'ag-suggestion-chip';
            chip.textContent = q;
            chip.addEventListener('click', () => {
                queryElement.value = q;
                sendQuery();
            });
            suggRow.appendChild(chip);
        });
        wrap.appendChild(suggRow);
    }

    const timestamp       = document.createElement('div');
    timestamp.className   = 'ag-msg-time';
    timestamp.textContent = formatTime();
    wrap.appendChild(timestamp);

    scrollDown();
}

function replaceWithError(wrap, msg) {
    wrap.innerHTML = '';
    const element       = document.createElement('div');
    element.className   = 'ag-msg-error';
    element.textContent = I18n.t('agent.error_prefix', { msg: message });
    wrap.appendChild(element);
    scrollDown();
}

function scrollDown() {
    convElement.scrollTop = convElement.scrollHeight;
}

async function sendQuery() {
    const query = queryElement.value.trim();
    if (!query) return;

    const tags        = selectedTags();
    const includeGrid = gridDataSelected();

    if (tags.length === 0 && !includeGrid) {
        appendNotice(t('agent.select_one'));
        return;
    }

    currentAbortController = new AbortController();
    abortedByUser          = false;
    sendButton.disabled       = true;
    stopButton.disabled       = false;
    queryElement.disabled       = true;
    appendUserMessage(query);
    queryElement.value = '';
    const thinkWrap = appendThinking();

    try {
        const result  = await apiFetch(API + '?action=query', {
            method:  'POST',
            body: {
                query, tags,
                page_context: includeGrid ? readGridContext() : '',
                table: includeGrid ? pageTableName() : '',
                language: document.documentElement.lang || '',
                history: lastTurn
                    ? [
                        { role: 'user',      content: lastTurn.query },
                        { role: 'assistant', content: lastTurn.answer },
                    ]
                    : [],
            },
            signal: currentAbortController.signal,
        });

        let data;
        try {
            data = await result.json();
        } catch {
            replaceWithError(thinkWrap, 'The server timed out or returned an unexpected response. Please try again.');
            return;
        }

        if (!result.ok || data.error) {
            replaceWithError(thinkWrap, data.error ?? 'Request failed.');
        } else {
            replaceWithAnswer(thinkWrap, data.answer, data.sources ?? [], data.tag_fallback ?? false, data.suggestions ?? []);

            const answer = String(data.answer ?? '').trim();
            lastTurn = (answer === '' || data.no_answer) ? null : { query, answer };
        }
    } catch (err) {
        if (err.name === 'AbortError') {
            if (abortedByUser) {
                replaceWithError(thinkWrap, 'Query cancelled.');
            } else {
                replaceWithError(thinkWrap, 'The request timed out. The AI model may be busy — please try again.');
            }
        } else {
            replaceWithError(thinkWrap, err.message || 'Network error.');
        }
    } finally {
        currentAbortController = null;
        sendButton.disabled       = false;
        stopButton.disabled       = true;
        queryElement.disabled       = false;
        queryElement.focus();
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    await I18n.load();
    buildPanel();
    buildFab();
    document.getElementById('openAgentBtn')?.addEventListener('click', () => {
        document.getElementById('userAvatarMenu')?.classList.remove('open');
        document.getElementById('userAvatarBtn')?.setAttribute('aria-expanded', 'false');
        openPanel();
    });
});
