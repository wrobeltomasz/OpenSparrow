// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { renderAvatar } from '../avatar.js';
import { I18n } from '../i18n.js';

const MIN_QUERY_LENGTH = 2;
const DEBOUNCE_MS = 250;

export function enableMentions(textarea) {
    const overlay = document.createElement('div');
    overlay.className = 'c-mention-list';
    overlay.setAttribute('role', 'listbox');
    overlay.style.display = 'none';
    document.body.appendChild(overlay);

    let suggestions = [];
    let activeIndex = -1;
    let debounceTimer = null;
    let requestId = 0;

    function hide() {
        overlay.style.display = 'none';
        overlay.textContent = '';
        suggestions = [];
        activeIndex = -1;
        if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
        requestId++;
    }

    function tokenAtCaret() {
        const value = textarea.value;
        const caret = textarea.selectionStart ?? value.length;
        const head = value.slice(0, caret);
        const match = /@([a-zA-Z0-9_.-]*)$/.exec(head);
        if (!match) return null;
        const atIndex = head.length - match[0].length;
        const before = atIndex === 0 ? '' : head[atIndex - 1];
        if (before !== '' && !/[\s([\]]/.test(before)) return null;
        return { start: atIndex, end: caret, text: match[1] };
    }

    function position() {
        const rect = textarea.getBoundingClientRect();
        overlay.style.top = `${rect.bottom + window.scrollY}px`;
        overlay.style.left = `${rect.left + window.scrollX}px`;
        overlay.style.minWidth = `${rect.width}px`;
    }

    function render() {
        overlay.textContent = '';
        if (suggestions.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'c-mention-empty';
            empty.textContent = I18n.t('comments.mention_no_results');
            overlay.appendChild(empty);
            activeIndex = -1;
            return;
        }
        suggestions.forEach((user, index) => {
            const item = document.createElement('div');
            item.className = 'c-mention-item' + (index === activeIndex ? ' active' : '');
            item.setAttribute('role', 'option');
            item.dataset.index = String(index);
            item.appendChild(renderAvatar(user.avatar_id, user.username, 22));
            const name = document.createElement('span');
            name.className = 'c-mention-name';
            name.textContent = user.username;
            item.appendChild(name);
            item.addEventListener('mousedown', event => {
                event.preventDefault();
                accept(user);
            });
            overlay.appendChild(item);
        });
    }

    function accept(user) {
        const token = tokenAtCaret();
        if (!token) return;
        const value = textarea.value;
        textarea.value = value.slice(0, token.start) + '@' + user.username + ' ' + value.slice(token.end);
        const caret = token.start + user.username.length + 2;
        textarea.selectionStart = caret;
        textarea.selectionEnd = caret;
        textarea.focus();
        hide();
    }

    function showIfPossible() {
        const token = tokenAtCaret();
        if (!token || token.text.length < MIN_QUERY_LENGTH) {
            hide();
            return;
        }
        position();
        overlay.style.display = 'block';
        scheduleFetch(token.text);
    }

    function scheduleFetch(fragment) {
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => fetchUsers(fragment), DEBOUNCE_MS);
    }

    async function fetchUsers(fragment) {
        const current = ++requestId;
        let users = [];
        try {
            const result = await fetch(
                'api/comments.php?action=mention_users&q=' + encodeURIComponent(fragment),
                { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
            );
            const data = await result.json();
            if (data.success) users = data.users ?? [];
        } catch (_) {
            users = [];
        }
        if (current !== requestId) return;
        suggestions = users;
        activeIndex = users.length > 0 ? 0 : -1;
        render();
    }

    function move(delta) {
        if (suggestions.length === 0) return;
        activeIndex = (activeIndex + delta + suggestions.length) % suggestions.length;
        render();
    }

    textarea.addEventListener('input', showIfPossible);
    textarea.addEventListener('click', showIfPossible);
    textarea.addEventListener('blur', () => {
        setTimeout(hide, 150);
    });
    textarea.addEventListener('keydown', event => {
        if (overlay.style.display !== 'block') return;
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            move(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            move(-1);
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            if (suggestions[activeIndex]) {
                event.preventDefault();
                accept(suggestions[activeIndex]);
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            hide();
        }
    });

    document.addEventListener('mousedown', event => {
        if (overlay.style.display === 'block'
            && !overlay.contains(event.target)
            && event.target !== textarea) {
            hide();
        }
    });
}
