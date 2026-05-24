// assets/js/agent-panel.js — Sliding AI agent panel
// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

import { I18n } from './i18n.js';

const API  = 'api_rag.php';
const CSRF = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const t    = (k, v) => I18n.t(k, v);

let panelEl, overlayEl, tagsEl, convEl, queryEl, sendBtn, clearBtn;
let tagsLoaded = false;

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
    clearBtn.className  = 'ag-clear-btn';
    clearBtn.type       = 'button';
    clearBtn.textContent = t('agent.clear');
    sendBtn             = document.createElement('button');
    sendBtn.className   = 'ag-send-btn';
    sendBtn.type        = 'button';
    sendBtn.textContent = t('agent.send');
    actions.appendChild(clearBtn);
    actions.appendChild(sendBtn);
    inputArea.appendChild(queryEl);
    inputArea.appendChild(actions);

    panelEl.appendChild(header);
    panelEl.appendChild(tagsEl);
    panelEl.appendChild(convEl);
    panelEl.appendChild(inputArea);
    document.body.appendChild(panelEl);

    // Events
    closeBtn.addEventListener('click', closePanel);
    overlayEl.addEventListener('click', closePanel);
    sendBtn.addEventListener('click', sendQuery);
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
    if (!tagsLoaded) loadTags();
    queryEl.focus();
}

function closePanel() {
    panelEl.classList.remove('active');
    overlayEl.classList.remove('active');
}

// ── Tags ──────────────────────────────────────────────────────────────────────

function pageTableName() {
    return new URLSearchParams(window.location.search).get('table') ?? '';
}

function readGridContext() {
    const table = document.querySelector('#grid table');
    if (!table) return '';
    const tableName = pageTableName();

    // headers: only th[data-col] elements, preserving their index among all ths
    const allThs    = Array.from(table.querySelectorAll('thead th'));
    const headerEls = allThs.filter(th => th.dataset.col);
    if (headerEls.length === 0) return '';

    const headers    = headerEls.map(th => th.dataset.col);
    const colIndexes = headerEls.map(th => allThs.indexOf(th));

    const rows = [];
    table.querySelectorAll('tbody tr').forEach(tr => {
        const allTds  = Array.from(tr.querySelectorAll('td'));
        const cells   = colIndexes.map(i => (allTds[i]?.textContent.trim() ?? '').replace(/\s+/g, ' '));
        if (cells.some(c => c !== '')) rows.push(cells);
    });

    if (rows.length === 0) return '';

    let text = `table: ${tableName}, ${rows.length} row(s) visible\n`;
    text += headers.join(' | ') + '\n';
    rows.forEach(r => { text += r.join(' | ') + '\n'; });
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
    const currentTable = pageTableName().toLowerCase();
    tags.forEach(tag => {
        const label     = document.createElement('label');
        label.className = 'ag-tag-item';
        const cb        = document.createElement('input');
        cb.type         = 'checkbox';
        cb.value        = tag;
        if (currentTable && tag.toLowerCase() === currentTable) cb.checked = true;
        label.appendChild(cb);
        label.appendChild(document.createTextNode(' ' + tag));
        tagsEl.appendChild(label);
    });
}

function selectedTags() {
    return Array.from(tagsEl.querySelectorAll('input[type=checkbox]:checked')).map(cb => cb.value);
}

// ── Conversation ──────────────────────────────────────────────────────────────

function appendUserMsg(text) {
    const wrap      = document.createElement('div');
    wrap.className  = 'ag-msg ag-msg-user';
    const bubble    = document.createElement('div');
    bubble.className   = 'ag-msg-bubble';
    bubble.textContent = text;
    wrap.appendChild(bubble);
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

function replaceWithAnswer(wrap, answer, sources) {
    wrap.innerHTML = '';
    const bubble       = document.createElement('div');
    bubble.className   = 'ag-msg-bubble';
    bubble.textContent = answer;
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

    sendBtn.disabled = true;
    queryEl.disabled = true;
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
            body: JSON.stringify({ query, tags: selectedTags(), page_context: readGridContext(), language: document.documentElement.lang || '' }),
        });
        const data = await res.json();
        if (!res.ok || data.error) {
            replaceWithError(thinkWrap, data.error ?? 'Request failed.');
        } else {
            replaceWithAnswer(thinkWrap, data.answer, data.sources ?? []);
        }
    } catch (err) {
        replaceWithError(thinkWrap, err.message || 'Network error.');
    } finally {
        sendBtn.disabled = false;
        queryEl.disabled = false;
        queryEl.focus();
    }
}

// ── Init ──────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', async () => {
    await I18n.load();
    buildPanel();
    document.getElementById('openAgentBtn')?.addEventListener('click', () => {
        document.getElementById('userAvatarMenu')?.classList.remove('open');
        document.getElementById('userAvatarBtn')?.setAttribute('aria-expanded', 'false');
        openPanel();
    });
});
