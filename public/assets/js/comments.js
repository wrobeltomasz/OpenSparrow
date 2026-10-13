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

function formatBody(raw, links) {
    const inline = [];
    let s = String(raw ?? '');

    s = s.replace(/\[Record:\s*([a-zA-Z0-9_]+):(\d+)\]/g, (match, table, id) => {
        const link = (links || {})[table + ':' + id];
        if (!link) return match;
        inline.push('<a href="' + escHtml(link.url) + '" target="_blank" rel="noopener noreferrer" class="c-record-link">' + escHtml(link.label) + '</a>');
        return '\x00I' + (inline.length - 1) + '\x00';
    });

    s = escHtml(s);

    s = s
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g,     '<em>$1</em>')
        .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');

    return s.replace(/\x00I(\d+)\x00/g, (_, index) => inline[+index]);
}

function buildMessage(c, links) {
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
        body.innerHTML = formatBody(c.body, links);
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
    return { comments: data.comments ?? [], links: data.links ?? {} };
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
    return { comment: data.comment, links: data.links ?? {} };
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

let tableOptions = null;

async function loadTableOptions() {
    if (tableOptions) return tableOptions;
    try {
        const result = await fetch('api/schema.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await result.json();
        tableOptions = data.tables ?? {};
    } catch (_) {
        tableOptions = {};
    }
    return tableOptions;
}

async function fetchRecordOptions(table) {
    const result = await fetch(
        'api/notes.php?action=list_records&table=' + encodeURIComponent(table),
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('comments.error_load'));
    return data.records ?? [];
}

function buildLinkPicker(onInsert) {
    const overlay = document.createElement('div');
    overlay.className = 'c-link-overlay';

    const box = document.createElement('div');
    box.className = 'c-link-box';

    const closeButton = document.createElement('button');
    closeButton.className = 'c-link-close';
    closeButton.type = 'button';
    closeButton.textContent = '×';
    closeButton.setAttribute('aria-label', I18n.t('header.close'));
    closeButton.addEventListener('click', () => overlay.remove());

    const title = document.createElement('h3');
    title.textContent = I18n.t('comments.insert_link');

    const tableSelect = document.createElement('select');
    tableSelect.className = 'c-link-select';

    const recordSelect = document.createElement('select');
    recordSelect.className = 'c-link-select';
    recordSelect.disabled = true;

    function setRecordPlaceholder(text) {
        recordSelect.textContent = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = text;
        recordSelect.appendChild(option);
    }
    setRecordPlaceholder(I18n.t('comments.select_table'));

    async function populateTables() {
        const tables = await loadTableOptions();
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = I18n.t('comments.select_table');
        tableSelect.appendChild(placeholder);
        for (const [name, config] of Object.entries(tables)) {
            const option = document.createElement('option');
            option.value = name;
            option.textContent = config.display_name ?? name;
            tableSelect.appendChild(option);
        }
    }
    populateTables();

    async function loadRecords() {
        const selectedTable = tableSelect.value;
        if (!selectedTable) {
            setRecordPlaceholder(I18n.t('comments.select_table'));
            recordSelect.disabled = true;
            return;
        }
        recordSelect.disabled = true;
        setRecordPlaceholder(I18n.t('common.loading'));
        try {
            const records = await fetchRecordOptions(selectedTable);
            setRecordPlaceholder(I18n.t('comments.select_record'));
            let hasRecords = false;
            for (const record of records) {
                const option = document.createElement('option');
                option.value = record.id;
                option.textContent = record.label;
                recordSelect.appendChild(option);
                hasRecords = true;
            }
            if (!hasRecords) {
                setRecordPlaceholder(I18n.t('form.no_records'));
                recordSelect.disabled = true;
                return;
            }
            recordSelect.disabled = false;
        } catch (_) {
            setRecordPlaceholder(I18n.t('comments.error_load'));
        }
    }

    tableSelect.addEventListener('change', loadRecords);

    const actions = document.createElement('div');
    actions.className = 'c-link-actions';

    const cancelButton = document.createElement('button');
    cancelButton.className = 'btn btn-secondary';
    cancelButton.type = 'button';
    cancelButton.textContent = I18n.t('common.cancel');
    cancelButton.addEventListener('click', () => overlay.remove());

    const insertButton = document.createElement('button');
    insertButton.className = 'btn btn-primary';
    insertButton.type = 'button';
    insertButton.textContent = I18n.t('comments.insert');
    insertButton.disabled = true;
    insertButton.addEventListener('click', () => {
        const selectedTable = tableSelect.value;
        const selectedId = recordSelect.value;
        if (!selectedTable || !selectedId) return;
        onInsert(`[Record: ${selectedTable}:${selectedId}]`);
        overlay.remove();
    });

    recordSelect.addEventListener('change', () => {
        insertButton.disabled = !recordSelect.value;
    });

    actions.appendChild(cancelButton);
    actions.appendChild(insertButton);

    box.appendChild(closeButton);
    box.appendChild(title);
    box.appendChild(tableSelect);
    box.appendChild(recordSelect);
    box.appendChild(actions);
    overlay.appendChild(box);
    return overlay;
}

let knownIds = new Set();

function renderComments(thread, comments, links) {
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
            thread.appendChild(buildMessage(c, links));
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

        const linkButton = document.createElement('button');
        linkButton.className = 'c-toolbar-btn';
        linkButton.type = 'button';
        linkButton.title = I18n.t('comments.insert_link');
        const linkIcon = document.createElement('img');
        linkIcon.src = 'assets/icons/material/add_link.svg';
        linkIcon.alt = '';
        linkButton.appendChild(linkIcon);

        toolbarWrap.appendChild(boldButton);
        toolbarWrap.appendChild(italicButton);
        toolbarWrap.appendChild(linkButton);
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

        linkButton.addEventListener('click', () => {
            const overlay = buildLinkPicker(marker => wrapSelection(marker, ''));
            document.body.appendChild(overlay);
            overlay.classList.add('open');
        });

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
                const { comment, links } = await postComment(body);
                textarea.value = '';

                const empty = thread.querySelector('.c-empty');
                if (empty) empty.remove();
                knownIds.add(String(comment.id));
                thread.appendChild(buildMessage(comment, links));
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
        .then(({ comments, links }) => renderComments(thread, comments, links))
        .catch(error => console.error('Comments load failed:', error));

    let pollTimer = null;

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(() => {
            fetchComments()
                .then(({ comments, links }) => renderComments(thread, comments, links))
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
