// assets/js/agent-panel.js — Sliding AI agent panel
// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// Slide-in AI assistant over the grid: sends the visible rows (max 50x12) as page context + the question to api/rag.php, renders answers via rag-render.js. Tag filter, conversation history, abort/stop, clear. CSRF from meta tag.

import { I18n } from './i18n.js';
import { renderAnswer } from './rag-render.js';

const API  = 'api/rag.php';
const CSRF = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const t    = (k, v) => I18n.t(k, v);

// Maximum grid rows and columns included in the page context sent to the model.
const MAX_CONTEXT_ROWS = 50;
const MAX_CONTEXT_COLS = 12;

function fmtTime() {
    return new Date().toTimeString().slice(0, 8);
}

let panelEl, overlayEl, tagsEl, convEl, queryEl, sendBtn, stopBtn, clearBtn;
let contextBarEl, gridOptEl, fabEl;
let tagsLoaded = false;
let currentAbortController = null;
let abortedByUser = false;

// ── Build DOM ─────────────────────────────────────────────────────────────────

function buildPanel() {
    overlayEl           = document.createElement('div');
    overlayEl.className = 'ag-overlay';
    overlayEl.id        = 'agOverlay';
    document.body.appendChild(overlayEl);

    panelEl = document.createElement('div');
    panelEl.className = 'ag-panel';
    panelEl.id        = 'agPanel';
    panelEl.setAttribute('role', 'dialog');
    panelEl.setAttribute('aria-label', t('agent.title'));
    panelEl.setAttribute('aria-modal', 'true');

    // Header
    const header  = document.createElement('div');
    header.className = 'ag-header';
    const titleEl = document.createElement('span');
    titleEl.className   = 'ag-title';
    titleEl.textContent = t('agent.title');
    const closeBtn  = document.createElement('button');
    closeBtn.className  = 'ag-close';
    closeBtn.setAttribute('aria-label', t('agent.close'));
    closeBtn.textContent = '×';
    header.appendChild(titleEl);
    header.appendChild(closeBtn);

    // Context bar (table indicator)
    contextBarEl           = document.createElement('div');
    contextBarEl.className = 'ag-context-bar';
    contextBarEl.id        = 'agContextBar';
    contextBarEl.hidden    = true;

    // Current-table-data option (grid context — opt-in via its own checkbox)
    gridOptEl           = document.createElement('div');
    gridOptEl.className = 'ag-grid-opt';
    gridOptEl.id        = 'agGridOpt';
    gridOptEl.hidden    = true;

    // Tags strip
    tagsEl           = document.createElement('div');
    tagsEl.className = 'ag-tags';
    tagsEl.id        = 'agTags';

    // Conversation area
    convEl = document.createElement('div');
    convEl.className = 'ag-conversation';
    convEl.id        = 'agConv';
    convEl.setAttribute('role', 'log');
    convEl.setAttribute('aria-live', 'polite');

    // Input area
    const inputArea      = document.createElement('div');
    inputArea.className  = 'ag-input-area';
    queryEl              = document.createElement('textarea');
    queryEl.className    = 'ag-textarea';
    queryEl.id           = 'agQuery';
    queryEl.rows         = 2;
    queryEl.maxLength    = 2000;
    queryEl.placeholder  = t('agent.placeholder');
    queryEl.setAttribute('aria-label', t('agent.title'));
    const actions       = document.createElement('div');
    actions.className   = 'ag-actions';
    clearBtn            = document.createElement('button');
    clearBtn.className  = 'btn btn-secondary';
    clearBtn.type       = 'button';
    clearBtn.textContent = t('agent.clear');
    sendBtn             = document.createElement('button');
    sendBtn.className   = 'btn btn-primary';
    sendBtn.type        = 'button';
    sendBtn.textContent = t('agent.send');
    stopBtn             = document.createElement('button');
    stopBtn.className   = 'btn btn-danger';
    stopBtn.type        = 'button';
    stopBtn.disabled    = true;
    stopBtn.textContent = t('agent.stop');
    actions.appendChild(clearBtn);
    actions.appendChild(stopBtn);
    actions.appendChild(sendBtn);
    inputArea.appendChild(queryEl);
    inputArea.appendChild(actions);

    panelEl.appendChild(header);
    panelEl.appendChild(contextBarEl);
    panelEl.appendChild(gridOptEl);
    panelEl.appendChild(tagsEl);
    panelEl.appendChild(convEl);
    panelEl.appendChild(inputArea);
    document.body.appendChild(panelEl);

    // Events
    closeBtn.addEventListener('click', closePanel);
    overlayEl.addEventListener('click', closePanel);
    sendBtn.addEventListener('click', sendQuery);
    stopBtn.addEventListener('click', () => { abortedByUser = true; currentAbortController?.abort(); });
    clearBtn.addEventListener('click', () => { convEl.innerHTML = ''; });
    queryEl.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendQuery();
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && panelEl.classList.contains('active')) closePanel();
    });
}

// ── Open / Close ──────────────────────────────────────────────────────────────

function openPanel() {
    panelEl.classList.add('active');
    overlayEl.classList.add('active');
    if (fabEl) fabEl.hidden = true;
    updateContextBar();
    renderGridDataOption();
    if (!tagsLoaded) loadTags();
    queryEl.focus();
}

function closePanel() {
    panelEl.classList.remove('active');
    overlayEl.classList.remove('active');
    if (fabEl) fabEl.hidden = false;
}

// ── Context bar ───────────────────────────────────────────────────────────────

function pageTableName() {
    return new URLSearchParams(window.location.search).get('table') ?? '';
}

function pageTableDisplayName() {
    const raw = pageTableName();
    if (!raw) return '';
    const activeLink = document.querySelector('.custom-nav-link.active[data-table]');
    return activeLink?.querySelector('.menu-text')?.textContent.trim() || raw;
}

function updateContextBar() {
    const displayName = pageTableDisplayName();
    if (!displayName) {
        contextBarEl.hidden = true;
        return;
    }
    contextBarEl.hidden = false;
    contextBarEl.innerHTML = '';
    const icon = document.createElement('img');
    icon.src    = 'assets/icons/grid_on.png';
    icon.alt    = '';
    icon.width  = 14;
    icon.height = 14;
    icon.style.cssText = 'vertical-align:middle; opacity:0.7; flex-shrink:0;';
    const label = document.createElement('span');
    label.textContent = t('agent.context_table', { table: displayName });
    contextBarEl.appendChild(icon);
    contextBarEl.appendChild(label);
}

// ── FAB ───────────────────────────────────────────────────────────────────────

function buildFab() {
    if (!window.CHAT_BUBBLE_ENABLED) return;
    fabEl           = document.createElement('button');
    fabEl.id        = 'agFab';
    fabEl.className = 'ag-fab';
    fabEl.type      = 'button';
    fabEl.setAttribute('aria-label', t('agent.title'));
    const img   = document.createElement('img');
    img.src     = 'assets/icons/comment.png';
    img.alt     = '';
    img.width   = 24;
    img.height  = 24;
    fabEl.appendChild(img);
    fabEl.addEventListener('click', openPanel);
    document.body.appendChild(fabEl);
}

// ── Tags ──────────────────────────────────────────────────────────────────────

function readGridContext() {
    const table = document.querySelector('#grid table');
    if (!table) return '';
    const tableName = pageTableName();

    // headers: only th[data-col] elements, preserving their index among all ths
    const allThs       = Array.from(table.querySelectorAll('thead th'));
    let   headerEls    = allThs.filter(th => th.dataset.col);
    if (headerEls.length === 0) return '';

    // Limit to essential columns: always keep an "id" column, then fill up to the cap.
    const totalCols = headerEls.length;
    if (headerEls.length > MAX_CONTEXT_COLS) {
        const idEls   = headerEls.filter(th => th.dataset.col.toLowerCase() === 'id');
        const restEls = headerEls.filter(th => th.dataset.col.toLowerCase() !== 'id');
        headerEls     = idEls.concat(restEls).slice(0, MAX_CONTEXT_COLS);
        // Restore original left-to-right order for readability.
        headerEls.sort((a, b) => allThs.indexOf(a) - allThs.indexOf(b));
    }

    const headers    = headerEls.map(th => th.dataset.col);
    const colIndexes = headerEls.map(th => allThs.indexOf(th));

    const allRows = [];
    table.querySelectorAll('tbody tr').forEach(tr => {
        const allTds  = Array.from(tr.querySelectorAll('td'));
        const cells   = colIndexes.map(i => (allTds[i]?.textContent.trim() ?? '').replace(/\s+/g, ' '));
        if (cells.some(c => c !== '')) allRows.push(cells);
    });

    if (allRows.length === 0) return '';

    // Truncate by whole rows rather than mid-string to keep every row well-formed.
    const rows        = allRows.slice(0, MAX_CONTEXT_ROWS);
    const hiddenRows  = allRows.length - rows.length;
    const hiddenCols  = totalCols - headers.length;

    let text = `table: ${tableName}, ${rows.length} of ${allRows.length} row(s) shown\n`;
    text += headers.join(' | ') + '\n';
    rows.forEach(r => { text += r.join(' | ') + '\n'; });
    if (hiddenRows > 0) text += `...(${hiddenRows} more rows not shown)\n`;
    if (hiddenCols > 0) text += `...(${hiddenCols} more columns not shown)\n`;
    return text;
}

async function loadTags() {
    try {
        const res  = await fetch(API + '?action=tags');
        const data = await res.json();
        renderTags(data.tags ?? []);
        tagsLoaded = true;
    } catch {
        const msg        = document.createElement('span');
        msg.className    = 'ag-tag-empty';
        msg.textContent  = t('agent.tags_error');
        tagsEl.innerHTML = '';
        tagsEl.appendChild(msg);
    }
}

function renderTags(tags) {
    tagsEl.innerHTML = '';
    if (tags.length === 0) {
        const msg       = document.createElement('span');
        msg.className   = 'ag-tag-empty';
        msg.textContent = t('agent.no_tags');
        tagsEl.appendChild(msg);
        return;
    }
    // Tags start unchecked: documents are attached only when the user picks them.
    tags.forEach(tag => {
        const label     = document.createElement('label');
        label.className = 'ag-tag-item';
        const cb        = document.createElement('input');
        cb.type         = 'checkbox';
        cb.value        = tag;
        label.appendChild(cb);
        label.appendChild(document.createTextNode(' ' + tag));
        tagsEl.appendChild(label);
    });
}

function selectedTags() {
    return Array.from(tagsEl.querySelectorAll('input[type=checkbox]:checked')).map(cb => cb.value);
}

// Opt-in "current table data" checkbox. The grid context is sent to the model
// only when this box is checked — never attached automatically.
function renderGridDataOption() {
    if (readGridContext() === '') {
        gridOptEl.hidden    = true;
        gridOptEl.innerHTML = '';
        return;
    }
    const prevChecked = gridOptEl.querySelector('#agGridDataCb')?.checked ?? false;
    gridOptEl.innerHTML = '';
    gridOptEl.hidden    = false;

    const label     = document.createElement('label');
    label.className = 'ag-tag-item';
    const cb        = document.createElement('input');
    cb.type         = 'checkbox';
    cb.id           = 'agGridDataCb';
    cb.checked      = prevChecked;
    label.appendChild(cb);
    label.appendChild(document.createTextNode(' ' + t('agent.use_grid_data')));
    gridOptEl.appendChild(label);
}

function gridDataSelected() {
    return gridOptEl.querySelector('#agGridDataCb')?.checked ?? false;
}

// ── Conversation ──────────────────────────────────────────────────────────────

function appendUserMsg(text) {
    const wrap      = document.createElement('div');
    wrap.className  = 'ag-msg ag-msg-user';
    const bubble    = document.createElement('div');
    bubble.className   = 'ag-msg-bubble';
    bubble.textContent = text;
    const ts           = document.createElement('div');
    ts.className       = 'ag-msg-time';
    ts.textContent     = fmtTime();
    wrap.appendChild(bubble);
    wrap.appendChild(ts);
    convEl.appendChild(wrap);
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
    convEl.appendChild(wrap);
    scrollDown();
    return wrap;
}

// Standalone notice (e.g. "select at least one source"). Does not consume the
// typed query, so the user can tick a checkbox and resend without retyping.
function appendNotice(text) {
    const wrap     = document.createElement('div');
    wrap.className = 'ag-msg ag-msg-assistant';
    const el       = document.createElement('div');
    el.className   = 'ag-msg-warning';
    el.textContent = text;
    wrap.appendChild(el);
    convEl.appendChild(wrap);
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
        markdown:      false,
    });
    wrap.appendChild(bubble);

    if (sources && sources.length > 0) {
        const srcRow     = document.createElement('div');
        srcRow.className = 'ag-msg-sources';
        sources.forEach(src => {
            const chip       = document.createElement('span');
            chip.className   = 'ag-source-chip';
            chip.textContent = src.filename;
            srcRow.appendChild(chip);
        });
        wrap.appendChild(srcRow);
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
                queryEl.value = q;
                sendQuery();
            });
            suggRow.appendChild(chip);
        });
        wrap.appendChild(suggRow);
    }

    const ts       = document.createElement('div');
    ts.className   = 'ag-msg-time';
    ts.textContent = fmtTime();
    wrap.appendChild(ts);

    scrollDown();
}

function replaceWithError(wrap, msg) {
    wrap.innerHTML = '';
    const el       = document.createElement('div');
    el.className   = 'ag-msg-error';
    el.textContent = 'Error: ' + msg;
    wrap.appendChild(el);
    scrollDown();
}

function scrollDown() {
    convEl.scrollTop = convEl.scrollHeight;
}

// ── Send ──────────────────────────────────────────────────────────────────────

async function sendQuery() {
    const query = queryEl.value.trim();
    if (!query) return;

    const tags        = selectedTags();
    const includeGrid = gridDataSelected();
    // Require an explicit source: at least one document tag, or the current table data.
    if (tags.length === 0 && !includeGrid) {
        appendNotice(t('agent.select_one'));
        return;
    }

    currentAbortController = new AbortController();
    abortedByUser          = false;
    sendBtn.disabled       = true;
    stopBtn.disabled       = false;
    queryEl.disabled       = true;
    appendUserMsg(query);
    queryEl.value = '';
    const thinkWrap = appendThinking();

    try {
        const res  = await fetch(API + '?action=query', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF(),
            },
            body: JSON.stringify({ query, tags, page_context: includeGrid ? readGridContext() : '', language: document.documentElement.lang || '' }),
            signal: currentAbortController.signal,
        });

        let data;
        try {
            data = await res.json();
        } catch {
            replaceWithError(thinkWrap, 'The server timed out or returned an unexpected response. Please try again.');
            return;
        }

        if (!res.ok || data.error) {
            replaceWithError(thinkWrap, data.error ?? 'Request failed.');
        } else {
            replaceWithAnswer(thinkWrap, data.answer, data.sources ?? [], data.tag_fallback ?? false, data.suggestions ?? []);
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
        sendBtn.disabled       = false;
        stopBtn.disabled       = true;
        queryEl.disabled       = false;
        queryEl.focus();
    }
}

// ── Init ──────────────────────────────────────────────────────────────────────

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
