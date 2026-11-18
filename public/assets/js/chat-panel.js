// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from './i18n.js';
import { BulkPanel } from './bulk_panel.js';
import { apiFetch } from './util/api.js';
import { escHtml } from './util/esc.js';
import { formatDateTime } from './util/format-value.js';
import { renderAvatar } from './avatar.js';
import { enableUserTooltips } from './util/user-tooltip.js';
import { buildRecordLinkPicker } from './util/record-link-picker.js';
import { buildIconPicker } from './util/icon-picker.js';

let panel = null;
let pickerOverlay = null;
let pickerKind = null;

function openPicker(kind, overlay) {
    const stillOpen = pickerOverlay && pickerOverlay.isConnected;
    if (stillOpen && pickerKind === kind) {
        pickerOverlay.remove();
        pickerOverlay = null;
        pickerKind = null;
        return;
    }
    if (pickerOverlay && pickerOverlay.isConnected) {
        pickerOverlay.remove();
    }
    pickerOverlay = overlay;
    pickerKind = kind;
    document.body.appendChild(overlay);
    overlay.classList.add('open');
}

function currentUserId() {
    return parseInt(window.CURRENT_USER_ID ?? '0', 10);
}

function renderChatBody(raw, links) {
    const inline = [];
    let s = String(raw ?? '');

    s = s.replace(/\[Icon:\s*([a-z0-9_]+)\]/g, (match, name) => {
        inline.push('<img src="assets/icons/material/' + escHtml(name) + '.svg" class="chat-icon" alt="' + escHtml(name) + '" />');
        return '\x00I' + (inline.length - 1) + '\x00';
    });

    s = s.replace(/\[Record:\s*([a-zA-Z0-9_]+):(\d+)\]/g, (match, table, id) => {
        const link = (links || {})[table + ':' + id];
        if (!link) return match;
        inline.push('<a href="' + escHtml(link.url) + '" target="_blank" rel="noopener noreferrer" class="c-record-link">' + escHtml(link.label) + '</a>');
        return '\x00I' + (inline.length - 1) + '\x00';
    });

    s = escHtml(s);

    s = s
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>');

    return s.replace(/\x00I(\d+)\x00/g, (_, index) => inline[+index]);
}

async function fetchConversations() {
    const result = await fetch('api/chat.php?action=list', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('chat.error_load'));
    return data.conversations ?? [];
}

async function fetchThread(peerId) {
    const result = await fetch('api/chat.php?action=thread&peer_id=' + encodeURIComponent(peerId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('chat.error_load'));
    return { messages: data.messages ?? [], links: data.links ?? {} };
}

async function fetchUsers(query) {
    const result = await fetch('api/chat.php?action=users&q=' + encodeURIComponent(query), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('chat.error_load'));
    return data.users ?? [];
}

async function sendMessage(recipientId, body) {
    const result = await apiFetch('api/chat.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: { action: 'send', recipient_id: recipientId, body },
    });
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('chat.error_send'));
    return { message: data.message, links: data.links ?? {} };
}

async function hideMessage(id) {
    const result = await apiFetch('api/chat.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: { action: 'hide', id },
    });
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('chat.error_send'));
}

function buildSearchBox(onStartChat) {
    const wrap = document.createElement('div');
    wrap.className = 'chat-search';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'chat-search-input';
    input.placeholder = I18n.t('chat.search');
    input.autocomplete = 'off';
    wrap.appendChild(input);

    const results = document.createElement('ul');
    results.className = 'chat-search-results';
    wrap.appendChild(results);

    let timer = null;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const query = input.value.trim();
        if (query.length < 2) {
            results.textContent = '';
            return;
        }
        timer = setTimeout(async () => {
            try {
                const users = await fetchUsers(query);
                results.textContent = '';
                if (!users.length) {
                    const empty = document.createElement('li');
                    empty.className = 'chat-search-empty';
                    empty.textContent = I18n.t('chat.no_users');
                    results.appendChild(empty);
                    return;
                }
                for (const user of users) {
                    const item = document.createElement('li');
                    item.className = 'chat-search-item';
                    const avatar = renderAvatar(user.avatar_id, user.username);
                    avatar.classList.add('chat-search-avatar');
                    item.appendChild(avatar);
                    const name = document.createElement('span');
                    name.textContent = user.username;
                    name.setAttribute('data-username', user.username);
                    item.appendChild(name);
                    item.addEventListener('click', () => {
                        results.textContent = '';
                        input.value = '';
                        onStartChat(user);
                    });
                    results.appendChild(item);
                }
            } catch (_) {
                results.textContent = '';
            }
        }, 200);
    });

    return wrap;
}

function messageBubble(message, onHide, links) {
    const mine = message.sender_id === currentUserId();
    const row = document.createElement('div');
    row.className = 'chat-msg' + (mine ? ' chat-msg--mine' : '');

    const author = document.createElement('div');
    author.className = 'chat-msg-author';
    author.textContent = mine ? I18n.t('chat.you') : (message.sender_username ?? I18n.t('chat.you'));
    if (!mine && message.sender_username) {
        author.setAttribute('data-username', message.sender_username);
    }
    row.appendChild(author);

    const bubble = document.createElement('div');
    bubble.className = 'chat-msg-bubble';
    bubble.innerHTML = renderChatBody(message.body, links);
    row.appendChild(bubble);

    const meta = document.createElement('div');
    meta.className = 'chat-msg-meta';
    meta.textContent = formatDateTime(message.created_at);
    row.appendChild(meta);

    const actions = document.createElement('div');
    actions.className = 'chat-msg-actions';
    const hideButton = document.createElement('button');
    hideButton.className = 'btn btn-secondary chat-hide-btn';
    hideButton.type = 'button';
    hideButton.textContent = I18n.t('chat.hide');
    hideButton.addEventListener('click', async () => {
        if (!confirm(I18n.t('chat.hide_confirm'))) return;
        try {
            await onHide(message.id);
            row.remove();
        } catch (error) {
            alert(error.message);
        }
    });
    actions.appendChild(hideButton);
    row.appendChild(actions);

    return row;
}

function buildThread(peer, onBack) {
    const thread = document.createElement('div');
    thread.className = 'chat-thread';

    const header = document.createElement('div');
    header.className = 'chat-thread-header';
    const backButton = document.createElement('button');
    backButton.className = 'btn btn-secondary';
    backButton.type = 'button';
    backButton.textContent = I18n.t('chat.back');
    backButton.addEventListener('click', onBack);
    header.appendChild(backButton);

    const title = document.createElement('span');
    title.className = 'chat-thread-title';
    title.textContent = peer.username;
    title.setAttribute('data-username', peer.username);
    header.appendChild(title);
    thread.appendChild(header);

    const messages = document.createElement('div');
    messages.className = 'chat-messages';
    thread.appendChild(messages);

    const composer = document.createElement('div');
    composer.className = 'chat-composer';
    const input = document.createElement('textarea');
    input.className = 'chat-input';
    input.rows = 2;
    input.maxLength = 4000;
    input.placeholder = I18n.t('chat.placeholder');
    composer.appendChild(input);

    const boldButton = document.createElement('button');
    boldButton.className = 'btn btn-secondary chat-toolbar-btn';
    boldButton.type = 'button';
    boldButton.textContent = 'B';
    boldButton.title = I18n.t('comments.bold_title');
    boldButton.addEventListener('click', () => wrapSelection('**', '**'));
    composer.appendChild(boldButton);

    const italicButton = document.createElement('button');
    italicButton.className = 'btn btn-secondary chat-toolbar-btn';
    italicButton.type = 'button';
    italicButton.style.fontStyle = 'italic';
    italicButton.textContent = 'I';
    italicButton.title = I18n.t('comments.italic_title');
    italicButton.addEventListener('click', () => wrapSelection('*', '*'));
    composer.appendChild(italicButton);

    const linkButton = document.createElement('button');
    linkButton.className = 'btn btn-secondary chat-link-btn';
    linkButton.type = 'button';
    linkButton.title = I18n.t('comments.insert_link');
    const linkIcon = document.createElement('img');
    linkIcon.src = 'assets/icons/material/add_link.svg';
    linkIcon.alt = '';
    linkButton.appendChild(linkIcon);
    linkButton.addEventListener('click', () => {
        openPicker('link', buildRecordLinkPicker(insertMarker));
    });
    composer.appendChild(linkButton);

    const iconButton = document.createElement('button');
    iconButton.className = 'btn btn-secondary chat-link-btn';
    iconButton.type = 'button';
    iconButton.title = I18n.t('chat.insert_icon');
    const iconButtonIcon = document.createElement('img');
    iconButtonIcon.src = 'assets/icons/material/add_reaction.svg';
    iconButtonIcon.alt = '';
    iconButton.appendChild(iconButtonIcon);
    iconButton.addEventListener('click', () => {
        openPicker('icon', buildIconPicker(insertMarker));
    });
    composer.appendChild(iconButton);

    const sendButton = document.createElement('button');
    sendButton.className = 'btn btn-primary';
    sendButton.type = 'button';
    sendButton.textContent = I18n.t('chat.send');
    composer.appendChild(sendButton);
    thread.appendChild(composer);

    function insertMarker(marker) {
        const start = input.selectionStart;
        const end = input.selectionEnd;
        input.value = input.value.slice(0, start) + marker + input.value.slice(end);
        const caret = start + marker.length;
        input.selectionStart = caret;
        input.selectionEnd = caret;
        input.focus();
    }

    function wrapSelection(before, after) {
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const selected = input.value.slice(start, end);
        input.value = input.value.slice(0, start) + before + selected + after + input.value.slice(end);
        input.selectionStart = start + before.length;
        input.selectionEnd = end + before.length;
        input.focus();
    }

    async function reload() {
        messages.textContent = '';
        const { messages: items, links } = await fetchThread(peer.id);
        for (const message of items) {
            messages.appendChild(messageBubble(message, hideMessage, links));
        }
        messages.scrollTop = messages.scrollHeight;
    }

    async function send() {
        const body = input.value.trim();
        if (!body) return;
        sendButton.disabled = true;
        try {
            const { message, links } = await sendMessage(peer.id, body);
            input.value = '';
            messages.appendChild(messageBubble(message, hideMessage, links));
            messages.scrollTop = messages.scrollHeight;
        } catch (error) {
            alert(error.message);
        } finally {
            sendButton.disabled = false;
        }
    }

    sendButton.addEventListener('click', send);
    input.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            send();
        }
    });

    reload().catch(error => panel.setStatus(error.message, true));
    return thread;
}

function buildConversationList(bodyElement, onOpenConversation, onStartChat) {
    const list = document.createElement('ul');
    list.className = 'chat-conversations';

    async function reload() {
        list.textContent = '';
        try {
            const conversations = await fetchConversations();
            if (!conversations.length) {
                const empty = document.createElement('li');
                empty.className = 'chat-empty';
                empty.textContent = I18n.t('chat.empty');
                list.appendChild(empty);
                return;
            }
            for (const conversation of conversations) {
                const item = document.createElement('li');
                item.className = 'chat-conversation';

                const avatar = renderAvatar(conversation.peer_avatar_id, conversation.peer_username);
                avatar.classList.add('chat-conversation-avatar');
                item.appendChild(avatar);

                const info = document.createElement('div');
                info.className = 'chat-conversation-info';
                const name = document.createElement('div');
                name.className = 'chat-conversation-name';
                name.textContent = conversation.peer_username;
                name.setAttribute('data-username', conversation.peer_username);
                info.appendChild(name);

                const preview = document.createElement('div');
                preview.className = 'chat-conversation-preview';
                preview.textContent = conversation.body ?? '';
                info.appendChild(preview);

                const time = document.createElement('div');
                time.className = 'chat-conversation-time';
                time.textContent = formatDateTime(conversation.created_at);
                info.appendChild(time);

                item.appendChild(info);
                item.addEventListener('click', () => onOpenConversation({
                    id: conversation.peer_id,
                    username: conversation.peer_username,
                    avatar_id: conversation.peer_avatar_id,
                }));
                list.appendChild(item);
            }
        } catch (error) {
            panel.setStatus(error.message, true);
        }
    }

    return { element: list, reload };
}

export async function openChatPanel(peer = null) {
    if (!panel) {
        panel = new BulkPanel({ id: 'chatPanel', title: I18n.t('chat.title'), showApply: false });
        enableUserTooltips(panel.bodyElement, {
            endpoint: 'api/chat.php?action=user_info&username=',
            titlePrefix: '@',
        });
    }

    panel.open();
    panel.clearStatus();
    panel.bodyElement.textContent = '';

    const content = document.createElement('div');
    content.className = 'chat-layout';
    panel.bodyElement.appendChild(content);

    function showConversations() {
        content.textContent = '';
        const search = buildSearchBox(user => showThread(user));
        content.appendChild(search);
        const { element, reload } = buildConversationList(content, showThread, showThread);
        content.appendChild(element);
        reload();
    }

    function showThread(peer) {
        content.textContent = '';
        content.appendChild(buildThread(peer, showConversations));
    }

    if (peer) {
        showThread({ id: peer.id, username: peer.username, avatar_id: peer.avatar_id });
    } else {
        showConversations();
    }
}
