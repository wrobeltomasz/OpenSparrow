// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { renderAvatar } from './avatar.js';
import { I18n } from './i18n.js';

const POLL_INTERVAL_MS = 15000;

const table   = window.EDIT_TABLE      ?? '';
const recordId = window.EDIT_ID        ?? 0;
const myId    = window.CURRENT_USER_ID ?? 0;
const myRole  = window.USER_ROLE       ?? 'viewer';
const isReadOnly = myRole !== 'editor';

import { getCsrfToken as csrfToken } from './util/csrf.js';
import { escHtml } from './util/esc.js';
import { apiFetch } from './util/api.js';
import { formatDateTime as formatTime } from './util/format-value.js';

function formatBody(raw) {
    const esc = escHtml(raw);

    return esc
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g,     '<em>$1</em>')
        .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
}

function buildMessage(c) {
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
    author.textContent = c.username ?? I18n.t('comments.unknown_author');

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

    if (!deleted && (isMine || isAdmin)) {
        const delButton = document.createElement('button');
        delButton.className = 'c-msg-del-btn';
        delButton.textContent = I18n.t('comments.delete');
        delButton.addEventListener('click', () => deleteComment(parseInt(c.id, 10), wrap));
        bubble.appendChild(delButton);
    }

    wrap.appendChild(avatar);
    wrap.appendChild(bubble);
    return wrap;
}

function buildEmptyState() {
    const paragraph = document.createElement('p');
    paragraph.className = 'c-empty';
    paragraph.textContent = I18n.t('comments.none');
    return paragraph;
}

async function fetchComments() {
    const result = await fetch(
        `api/comments.php?action=list&related_table=${encodeURIComponent(table)}&related_id=${encodeURIComponent(recordId)}`,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? 'Failed to load comments.');
    return data.comments ?? [];
}

async function postComment(body) {
    const result = await apiFetch('api/comments.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: {
            action: 'add',
            related_table: table,
            related_id: recordId,
            body,
            csrf_token: csrfToken(),
        },
    });
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? 'Failed to post comment.');
    return data.comment;
}

async function deleteComment(id, messageElement) {
    if (!confirm(I18n.t('comments.delete_confirm'))) return;
    const result = await apiFetch('api/comments.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: {
            action: 'delete',
            id,
            csrf_token: csrfToken(),
        },
    });
    const data = await result.json();
    if (!data.success) {
        alert(data.error ?? 'Failed to delete comment.');
        return;
    }

    messageElement.classList.add('c-msg-deleted');
    const bodyElement = messageElement.querySelector('.c-msg-body');
    if (bodyElement) {
        bodyElement.textContent = '';
        const delEm = document.createElement('em');
        delEm.textContent = I18n.t('comments.deleted_text');
        bodyElement.appendChild(delEm);
    }
    const delButton = messageElement.querySelector('.c-msg-del-btn');
    if (delButton) delButton.remove();
}

let knownIds = new Set();

function renderComments(thread, comments) {
    if (comments.length === 0 && thread.children.length === 0) {
        thread.appendChild(buildEmptyState());
        return;
    }

    const empty = thread.querySelector('.c-empty');
    if (empty && comments.length > 0) empty.remove();

    let appended = false;
    for (const c of comments) {
        const commentId = String(c.id);
        if (!knownIds.has(commentId)) {
            knownIds.add(commentId);
            thread.appendChild(buildMessage(c));
            appended = true;
        }
    }

    if (appended) {
        thread.scrollTop = thread.scrollHeight;
    }
}

async function init() {
    await I18n.load();
    const panel = document.getElementById('c-panel');
    if (!panel || !table || !recordId) return;

    const thread = document.createElement('div');
    thread.className = 'c-thread';
    thread.setAttribute('aria-live', 'polite');
    panel.appendChild(thread);

    if (!isReadOnly) {
        const toolbarWrap = document.createElement('div');
        toolbarWrap.className = 'c-toolbar';

        const boldButton = document.createElement('button');
        boldButton.className = 'c-toolbar-btn';
        boldButton.type = 'button';
        boldButton.textContent = 'B';
        boldButton.title = I18n.t('comments.bold_title');

        const italicButton = document.createElement('button');
        italicButton.className = 'c-toolbar-btn';
        italicButton.type = 'button';
        italicButton.style.fontStyle = 'italic';
        italicButton.textContent = 'I';
        italicButton.title = I18n.t('comments.italic_title');

        toolbarWrap.appendChild(boldButton);
        toolbarWrap.appendChild(italicButton);
        panel.appendChild(toolbarWrap);

        const inputArea = document.createElement('div');
        inputArea.className = 'c-input-area';

        const textarea = document.createElement('textarea');
        textarea.className = 'c-input';
        textarea.placeholder = I18n.t('comments.placeholder');
        textarea.rows = 2;
        textarea.maxLength = 4000;

        const sendButton = document.createElement('button');
        sendButton.className = 'btn btn-primary c-send-btn';
        sendButton.type = 'button';
        sendButton.textContent = I18n.t('comments.send');

        inputArea.appendChild(textarea);
        inputArea.appendChild(sendButton);
        panel.appendChild(inputArea);

        function wrapSelection(before, after) {
            const start = textarea.selectionStart;
            const end   = textarea.selectionEnd;
            const selectElement   = textarea.value.slice(start, end);
            textarea.value =
                textarea.value.slice(0, start) + before + selectElement + after + textarea.value.slice(end);
            textarea.selectionStart = start + before.length;
            textarea.selectionEnd   = end + before.length;
            textarea.focus();
        }

        boldButton.addEventListener('click', () => wrapSelection('**', '**'));
        italicButton.addEventListener('click', () => wrapSelection('*', '*'));

        textarea.addEventListener('keydown', event => {
            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                sendButton.click();
            }
        });

        sendButton.addEventListener('click', async () => {
            const body = textarea.value.trim();
            if (!body) return;

            sendButton.disabled = true;
            try {
                const comment = await postComment(body);
                textarea.value = '';

                const empty = thread.querySelector('.c-empty');
                if (empty) empty.remove();
                knownIds.add(String(comment.id));
                thread.appendChild(buildMessage(comment));
                thread.scrollTop = thread.scrollHeight;
            } catch (error) {
                alert(error.message);
            } finally {
                sendButton.disabled = false;
                textarea.focus();
            }
        });
    }

    fetchComments()
        .then(comments => renderComments(thread, comments))
        .catch(error => console.error('Comments load failed:', error));

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

    const commentsTabButton = document.querySelector('[data-tab="tab-comments"]');
    const commentsPanel  = document.getElementById('tab-comments');

    if (commentsTabButton) {
        commentsTabButton.addEventListener('click', () => startPolling());
    }

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
