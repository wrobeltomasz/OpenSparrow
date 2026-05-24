// assets/js/rag.js — Knowledge base chat interface

const API = 'api_rag.php';
const CSRF = () => window.CSRF_TOKEN ?? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const tagListEl    = document.getElementById('ragTagList');
const convEl       = document.getElementById('ragConversation');
const queryEl      = document.getElementById('ragQuery');
const sendBtn      = document.getElementById('ragSendBtn');
const clearBtn     = document.getElementById('ragClearBtn');

function escHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
}

// ── Tag sidebar ──────────────────────────────────────────────────────────────

async function loadTags() {
    try {
        const res  = await fetch(API + '?action=tags');
        const data = await res.json();
        renderTags(data.tags ?? []);
    } catch {
        tagListEl.innerHTML = '';
        const msg = document.createElement('span');
        msg.className   = 'rag-tag-empty';
        msg.textContent = 'Could not load tags.';
        tagListEl.appendChild(msg);
    }
}

function renderTags(tags) {
    tagListEl.innerHTML = '';
    if (tags.length === 0) {
        const msg = document.createElement('span');
        msg.className   = 'rag-tag-empty';
        msg.textContent = 'No tags yet.';
        tagListEl.appendChild(msg);
        return;
    }
    tags.forEach(tag => {
        const label = document.createElement('label');
        label.className = 'rag-tag-item';

        const cb = document.createElement('input');
        cb.type  = 'checkbox';
        cb.value = tag;

        const txt = document.createTextNode(tag);
        label.appendChild(cb);
        label.appendChild(txt);
        tagListEl.appendChild(label);
    });
}

function selectedTags() {
    return Array.from(tagListEl.querySelectorAll('input[type=checkbox]:checked'))
        .map(cb => cb.value);
}

// ── Conversation rendering ───────────────────────────────────────────────────

function appendUserMsg(text) {
    const wrap   = document.createElement('div');
    wrap.className = 'rag-msg rag-msg-user';

    const bubble = document.createElement('div');
    bubble.className   = 'rag-msg-bubble';
    bubble.textContent = text;

    wrap.appendChild(bubble);
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

function replaceWithAnswer(thinkingWrap, answer, sources) {
    thinkingWrap.innerHTML = '';

    const bubble = document.createElement('div');
    bubble.className   = 'rag-msg-bubble';
    bubble.textContent = answer;
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
    const query = queryEl.value.trim();
    if (!query) return;

    const tags = selectedTags();

    sendBtn.disabled   = true;
    queryEl.disabled   = true;

    appendUserMsg(query);
    queryEl.value = '';

    const thinkWrap = appendThinking();

    try {
        const res  = await fetch(API + '?action=query', {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'X-CSRF-Token':  CSRF(),
            },
            body: JSON.stringify({ query, tags }),
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

// ── Event listeners ──────────────────────────────────────────────────────────

sendBtn.addEventListener('click', sendQuery);

queryEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendQuery();
    }
});

clearBtn.addEventListener('click', () => {
    convEl.innerHTML = '';
});

// ── Init ─────────────────────────────────────────────────────────────────────

loadTags();
