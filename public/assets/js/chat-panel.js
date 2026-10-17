// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from './i18n.js';
import { BulkPanel } from './bulk_panel.js';
import { apiFetch } from './util/api.js';
import { formatDateTime } from './util/format-value.js';
import { renderAvatar } from './avatar.js';
import { enableUserTooltips } from './util/user-tooltip.js';

let panel = null;

function currentUserId() {
    return parseInt(window.CURRENT_USER_ID ?? '0', 10);
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
    return data.messages ?? [];
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
    return data.message;
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

function messageBubble(message, onHide) {
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
    bubble.textContent = message.body;
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

    const sendButton = document.createElement('button');
    sendButton.className = 'btn btn-primary';
    sendButton.type = 'button';
    sendButton.textContent = I18n.t('chat.send');
    composer.appendChild(sendButton);
    thread.appendChild(composer);

    async function reload() {
        messages.textContent = '';
        const items = await fetchThread(peer.id);
        for (const message of items) {
            messages.appendChild(messageBubble(message, hideMessage));
        }
        messages.scrollTop = messages.scrollHeight;
    }

    async function send() {
        const body = input.value.trim();
        if (!body) return;
        sendButton.disabled = true;
        try {
            const message = await sendMessage(peer.id, body);
            input.value = '';
            messages.appendChild(messageBubble(message, hideMessage));
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

export async function openChatPanel() {
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

    showConversations();
}
