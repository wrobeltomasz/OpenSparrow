// assets/js/rag.js — Knowledge base chat interface (rag.php page)
// Document picker + chat sent to api/rag.php, answers rendered via rag-render.js; token-count warning (>8000), conversation memory, stop/clear. CSRF from window.CSRF_TOKEN/meta.

import { renderAnswer } from './rag-render.js';

const API  = 'api/rag.php';
const CSRF = () => window.CSRF_TOKEN ?? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
const TOKEN_WARN_LIMIT = 8000;

const fileListEl  = document.getElementById('ragFileList');
const tokenWarnEl = document.getElementById('ragTokenWarn');
const convEl      = document.getElementById('ragConversation');
const queryEl     = document.getElementById('ragQuery');
const sendBtn     = document.getElementById('ragSendBtn');
const stopBtn     = document.getElementById('ragStopBtn');
const clearBtn    = document.getElementById('ragClearBtn');
const memPillEl   = document.getElementById('ragMemoryPill');

let conversationHistory = [];
let maxTurns = 0;
let currentAbortController = null;
let abortedByUser = false;

function fmtTime() {
    return new Date().toTimeString().slice(0, 8);
}

// Rough token estimate: 1 token ~ 4 characters
function estimateTokens(charCount) {
    return Math.ceil(charCount / 4);
}

// ── Memory pill ───────────────────────────────────────────────────────────────

function updateMemoryPill() {
    if (!memPillEl || maxTurns <= 0) {
        if (memPillEl) memPillEl.hidden = true;
        return;
    }
    const current = Math.floor(conversationHistory.length / 2);
    memPillEl.textContent = 'Memory: ' + current + ' / ' + maxTurns + ' turn' + (maxTurns !== 1 ? 's' : '');
    memPillEl.hidden = false;
}

// ── File sidebar ─────────────────────────────────────────────────────────────

async function loadFiles() {
    try {
        const res  = await fetch(API + '?action=files');
        const data = await res.json();
        maxTurns = (data.conversation_turns ?? 0);
        updateMemoryPill();
        const files = (data.files ?? []).map(f => ({
            id:       f.id,
            filename: f.filename,
            tokens:   estimateTokens(f.char_count ?? 0),
        }));
        renderFiles(files);
    } catch {
        fileListEl.innerHTML = '';
        const msg = document.createElement('span');
        msg.className   = 'rag-tag-empty';
        msg.textContent = 'Could not load documents.';
        fileListEl.appendChild(msg);
    }
}

function renderFiles(files) {
    fileListEl.innerHTML = '';
    if (files.length === 0) {
        const msg = document.createElement('span');
        msg.className   = 'rag-tag-empty';
        msg.textContent = 'No documents yet.';
        fileListEl.appendChild(msg);
        return;
    }
    files.forEach(file => {
        const label = document.createElement('label');
        label.className = 'rag-tag-item';

        const cb = document.createElement('input');
        cb.type           = 'checkbox';
        cb.value          = String(file.id);
        cb.dataset.tokens = String(file.tokens);
        cb.addEventListener('change', updateTokenWarning);

        const nameSpan = document.createElement('span');
        nameSpan.className   = 'rag-file-name';
        nameSpan.textContent = file.filename;
        nameSpan.title       = file.filename;

        const tokSpan = document.createElement('span');
        tokSpan.className   = 'rag-file-tokens';
        tokSpan.textContent = '~' + file.tokens.toLocaleString() + 't';

        label.appendChild(cb);
        label.appendChild(nameSpan);
        label.appendChild(tokSpan);
        fileListEl.appendChild(label);
    });
    updateTokenWarning();
}

function selectedFileIds() {
    return Array.from(fileListEl.querySelectorAll('input[type=checkbox]:checked'))
        .map(cb => parseInt(cb.value, 10));
}

function updateTokenWarning() {
    const checked = Array.from(fileListEl.querySelectorAll('input[type=checkbox]:checked'));
    const total   = checked.reduce((sum, cb) => sum + parseInt(cb.dataset.tokens ?? '0', 10), 0);
    if (total > TOKEN_WARN_LIMIT) {
        tokenWarnEl.textContent = 'Warning: ~' + total.toLocaleString() + ' tokens selected. Deselect documents to reduce below 8,000.';
        tokenWarnEl.hidden = false;
    } else {
        tokenWarnEl.hidden = true;
    }
}

// ── Conversation rendering ───────────────────────────────────────────────────

function appendUserMsg(text) {
    const wrap   = document.createElement('div');
    wrap.className = 'rag-msg rag-msg-user';

    const bubble = document.createElement('div');
    bubble.className   = 'rag-msg-bubble';
    bubble.textContent = text;

    const ts = document.createElement('div');
    ts.className   = 'rag-msg-time';
    ts.textContent = fmtTime();

    wrap.appendChild(bubble);
    wrap.appendChild(ts);
    convEl.appendChild(wrap);
    scrollDown();
    return wrap;
}

function appendThinking() {
    const wrap   = document.createElement('div');
    wrap.className = 'rag-msg rag-msg-assistant';

    const bubble = document.createElement('div');
    bubble.className   = 'rag-msg-thinking';
    bubble.textContent = 'Thinking…';

    wrap.appendChild(bubble);
    convEl.appendChild(wrap);
    scrollDown();
    return wrap;
}

function replaceWithAnswer(thinkingWrap, answer, sources, suggestions) {
    thinkingWrap.innerHTML = '';

    const bubble = document.createElement('div');
    bubble.className = 'rag-msg-bubble';
    bubble.innerHTML = renderAnswer(answer, {
        allowedTables: window.SCHEMA_TABLES,
        linkClass:     'rag-record-link',
        markdown:      true,
    });
    thinkingWrap.appendChild(bubble);

    if (sources && sources.length > 0) {
        const srcRow = document.createElement('div');
        srcRow.className = 'rag-msg-sources';
        sources.forEach(src => {
            const chip = document.createElement('span');
            chip.className   = 'rag-source-chip';
            chip.textContent = src.filename;
            srcRow.appendChild(chip);
        });
        thinkingWrap.appendChild(srcRow);
    }

    if (suggestions && suggestions.length > 0) {
        const suggRow = document.createElement('div');
        suggRow.className = 'rag-msg-suggestions';
        suggestions.forEach(q => {
            const chip = document.createElement('button');
            chip.type      = 'button';
            chip.className = 'rag-suggestion-chip';
            chip.textContent = q;
            chip.addEventListener('click', () => {
                queryEl.value = q;
                sendQuery();
            });
            suggRow.appendChild(chip);
        });
        thinkingWrap.appendChild(suggRow);
    }

    const ts = document.createElement('div');
    ts.className   = 'rag-msg-time';
    ts.textContent = fmtTime();
    thinkingWrap.appendChild(ts);

    scrollDown();
}

function replaceWithError(thinkingWrap, msg) {
    thinkingWrap.innerHTML = '';
    const el = document.createElement('div');
    el.className   = 'rag-msg-error';
    el.textContent = 'Error: ' + msg;
    thinkingWrap.appendChild(el);
    scrollDown();
}

function scrollDown() {
    convEl.scrollIntoView({ behavior: 'smooth', block: 'end' });
}

// ── Send ─────────────────────────────────────────────────────────────────────

async function sendQuery() {
    const query   = queryEl.value.trim();
    if (!query) return;

    const fileIds = selectedFileIds();
    if (fileIds.length === 0) {
        appendUserMsg(query);
        queryEl.value = '';
        const thinkWrap = appendThinking();
        replaceWithError(thinkWrap, 'Please select at least one document from the list on the left.');
        return;
    }

    sendBtn.disabled  = true;
    queryEl.disabled  = true;
    stopBtn.disabled  = false;
    abortedByUser     = false;
    currentAbortController = new AbortController();

    appendUserMsg(query);
    queryEl.value = '';

    const thinkWrap = appendThinking();

    try {
        const res = await fetch(API + '?action=query', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF(),
            },
            body: JSON.stringify({ query, file_ids: fileIds, history: conversationHistory }),
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
            replaceWithAnswer(thinkWrap, data.answer, data.sources ?? [], data.suggestions ?? []);
            conversationHistory.push({ role: 'user', content: query });
            conversationHistory.push({ role: 'assistant', content: data.answer });
            updateMemoryPill();
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
        sendBtn.disabled  = false;
        queryEl.disabled  = false;
        stopBtn.disabled  = true;
        queryEl.focus();
    }
}

// ── Event listeners ──────────────────────────────────────────────────────────

sendBtn.addEventListener('click', sendQuery);

stopBtn.addEventListener('click', () => {
    abortedByUser = true;
    currentAbortController?.abort();
});

queryEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendQuery();
    }
});

clearBtn.addEventListener('click', () => {
    convEl.innerHTML = '';
    conversationHistory = [];
    updateMemoryPill();
});

// ── Init ─────────────────────────────────────────────────────────────────────

loadFiles();
