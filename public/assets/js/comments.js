// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// comments.js — Record comment thread on edit.php (window.EDIT_TABLE/EDIT_ID)
// Lists/adds comments via api/comments.php, polls every 15s; read-only for non-editors. Includes an XSS-safe mini markdown formatter (escapes HTML, incl. quotes, before auto-linking URLs).

import { renderAvatar } from './avatar.js';
import { I18n } from './i18n.js';

const POLL_INTERVAL_MS = 15000;

const table   = window.EDIT_TABLE      ?? '';
const recordId = window.EDIT_ID        ?? 0;
const myId    = window.CURRENT_USER_ID ?? 0;
const myRole  = window.USER_ROLE       ?? 'viewer';
const isReadOnly = myRole !== 'editor';

import { getCsrfToken as csrfToken } from './util/csrf.js';

// ── Tiny markdown-like formatter (no external libs) ────────────────────────
function formatBody(raw) {
    // Escape HTML first — including quotes, since the auto-linked URL below is
    // placed inside an href="" attribute. Without escaping " an attacker could
    // close the attribute and inject an event handler (stored XSS).
    const esc = raw
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    return esc
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g,     '<em>$1</em>')
        .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
}

function formatTime(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
}

// ── DOM builders ───────────────────────────────────────────────────────────

function buildMsg(c) {
    const isMine   = parseInt(c.user_id, 10) === myId;
    const isAdmin  = myRole === 'editor';
    const deleted  = !!c.deleted_at;

    const wrap = document.createElement('div');
    wrap.className = 'c-msg' + (isMine ? ' c-msg-mine' : '') + (deleted ? ' c-msg-deleted' : '');
    wrap.dataset.id = c.id;

    const avatar = renderAvatar(
        c.avatar_id ? parseInt(c.avatar_id, 10) : null,
        c.username ?? '?',
        32
    );

    const bubble = document.createElement('div');
    bubble.className = 'c-msg-bubble';

    const meta = document.createElement('div');
    meta.className = 'c-msg-meta';

    const author = document.createElement('span');
    author.className = 'c-msg-author';
    author.textContent = c.username ?? 'Unknown';

    const time = document.createElement('span');
    time.textContent = formatTime(c.created_at);

    meta.appendChild(author);
    meta.appendChild(time);

    const body = document.createElement('div');
    body.className = 'c-msg-body';

    if (deleted) {
        body.textContent = '';
        const delEm = document.createElement('em');
        delEm.textContent = I18n.t('comments.deleted_text');
        body.appendChild(delEm);
    } else {
        body.innerHTML = formatBody(c.body);
    }

    bubble.appendChild(meta);
    bubble.appendChild(body);

    // Delete button — visible to author or admin, only if not already deleted
    if (!deleted && (isMine || isAdmin)) {
        const delBtn = document.createElement('button');
        delBtn.className = 'c-msg-del-btn';
        delBtn.textContent = I18n.t('comments.delete');
        delBtn.addEventListener('click', () => deleteComment(parseInt(c.id, 10), wrap));
        bubble.appendChild(delBtn);
    }

    wrap.appendChild(avatar);
    wrap.appendChild(bubble);
    return wrap;
}

function buildEmptyState() {
    const p = document.createElement('p');
    p.className = 'c-empty';
    p.textContent = I18n.t('comments.none');
    return p;
}

// ── API calls ──────────────────────────────────────────────────────────────

async function fetchComments() {
    const res = await fetch(
        `api/comments.php?action=list&related_table=${encodeURIComponent(table)}&related_id=${encodeURIComponent(recordId)}`,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    const data = await res.json();
    if (!data.success) throw new Error(data.error ?? 'Failed to load comments.');
    return data.comments ?? [];
}

async function postComment(body) {
    const res = await fetch('api/comments.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            action: 'add',
            related_table: table,
            related_id: recordId,
            body,
            csrf_token: csrfToken(),
        }),
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error ?? 'Failed to post comment.');
    return data.comment;
}

async function deleteComment(id, msgEl) {
    if (!confirm(I18n.t('comments.delete_confirm'))) return;
    const res = await fetch('api/comments.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            action: 'delete',
            id,
            csrf_token: csrfToken(),
        }),
    });
    const data = await res.json();
    if (!data.success) {
        alert(data.error ?? 'Failed to delete comment.');
        return;
    }
    // Mark as deleted in DOM without full reload
    msgEl.classList.add('c-msg-deleted');
    const bodyEl = msgEl.querySelector('.c-msg-body');
    if (bodyEl) {
        bodyEl.textContent = '';
        const delEm = document.createElement('em');
        delEm.textContent = I18n.t('comments.deleted_text');
        bodyEl.appendChild(delEm);
    }
    const delBtn = msgEl.querySelector('.c-msg-del-btn');
    if (delBtn) delBtn.remove();
}

// ── Render ─────────────────────────────────────────────────────────────────

let knownIds = new Set();

function renderComments(thread, comments) {
    if (comments.length === 0 && thread.children.length === 0) {
        thread.appendChild(buildEmptyState());
        return;
    }

    // Remove empty state if present
    const empty = thread.querySelector('.c-empty');
    if (empty && comments.length > 0) empty.remove();

    // Append only new comments (poll-safe)
    let appended = false;
    for (const c of comments) {
        const cid = String(c.id);
        if (!knownIds.has(cid)) {
            knownIds.add(cid);
            thread.appendChild(buildMsg(c));
            appended = true;
        }
    }

    if (appended) {
        thread.scrollTop = thread.scrollHeight;
    }
}

// ── Init ───────────────────────────────────────────────────────────────────

async function init() {
    await I18n.load();
    const panel = document.getElementById('c-panel');
    if (!panel || !table || !recordId) return;

    // Build thread + input area
    const thread = document.createElement('div');
    thread.className = 'c-thread';
    thread.setAttribute('aria-live', 'polite');
    panel.appendChild(thread);

    if (!isReadOnly) {
        const toolbarWrap = document.createElement('div');
        toolbarWrap.className = 'c-toolbar';

        const boldBtn = document.createElement('button');
        boldBtn.className = 'c-toolbar-btn';
        boldBtn.type = 'button';
        boldBtn.textContent = 'B';
        boldBtn.title = 'Bold (**text**)';

        const italicBtn = document.createElement('button');
        italicBtn.className = 'c-toolbar-btn';
        italicBtn.type = 'button';
        italicBtn.style.fontStyle = 'italic';
        italicBtn.textContent = 'I';
        italicBtn.title = 'Italic (*text*)';

        toolbarWrap.appendChild(boldBtn);
        toolbarWrap.appendChild(italicBtn);
        panel.appendChild(toolbarWrap);

        const inputArea = document.createElement('div');
        inputArea.className = 'c-input-area';

        const textarea = document.createElement('textarea');
        textarea.className = 'c-input';
        textarea.placeholder = I18n.t('comments.placeholder');
        textarea.rows = 2;
        textarea.maxLength = 4000;

        const sendBtn = document.createElement('button');
        sendBtn.className = 'btn btn-primary';
        sendBtn.type = 'button';
        sendBtn.textContent = I18n.t('comments.send');

        inputArea.appendChild(textarea);
        inputArea.appendChild(sendBtn);
        panel.appendChild(inputArea);

        // Toolbar actions — wrap selection
        function wrapSelection(before, after) {
            const start = textarea.selectionStart;
            const end   = textarea.selectionEnd;
            const sel   = textarea.value.slice(start, end);
            textarea.value =
                textarea.value.slice(0, start) + before + sel + after + textarea.value.slice(end);
            textarea.selectionStart = start + before.length;
            textarea.selectionEnd   = end + before.length;
            textarea.focus();
        }

        boldBtn.addEventListener('click', () => wrapSelection('**', '**'));
        italicBtn.addEventListener('click', () => wrapSelection('*', '*'));

        // Ctrl+Enter submits
        textarea.addEventListener('keydown', e => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                sendBtn.click();
            }
        });

        sendBtn.addEventListener('click', async () => {
            const body = textarea.value.trim();
            if (!body) return;

            sendBtn.disabled = true;
            try {
                const comment = await postComment(body);
                textarea.value = '';
                // Remove empty state if present
                const empty = thread.querySelector('.c-empty');
                if (empty) empty.remove();
                knownIds.add(String(comment.id));
                thread.appendChild(buildMsg(comment));
                thread.scrollTop = thread.scrollHeight;
            } catch (err) {
                alert(err.message);
            } finally {
                sendBtn.disabled = false;
                textarea.focus();
            }
        });
    }

    // Initial load
    fetchComments()
        .then(comments => renderComments(thread, comments))
        .catch(err => console.error('Comments load failed:', err));

    // Poll only when the Comments tab is visible
    let pollTimer = null;

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(() => {
            fetchComments()
                .then(comments => renderComments(thread, comments))
                .catch(() => {});
        }, POLL_INTERVAL_MS);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    const commentsTabBtn = document.querySelector('[data-tab="tab-comments"]');
    const commentsPanel  = document.getElementById('tab-comments');

    if (commentsTabBtn) {
        commentsTabBtn.addEventListener('click', () => startPolling());
    }

    // Watch for tab-panel visibility changes
    if (typeof IntersectionObserver !== 'undefined' && commentsPanel) {
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    startPolling();
                } else {
                    stopPolling();
                }
            });
        }, { threshold: 0.1 });
        observer.observe(commentsPanel);
    }
}

document.addEventListener('DOMContentLoaded', init);
