// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from '../i18n.js';
import { showRecordTooltip, hideRecordTooltip } from './record-tooltip.js';

const cache = new Map();
const pending = new Map();

function fetchUserInfo(username, endpoint) {
    if (cache.has(username)) return Promise.resolve(cache.get(username));
    if (pending.has(username)) return pending.get(username);

    const promise = fetch(endpoint + encodeURIComponent(username), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(response => response.json())
        .then(data => {
            const info = data.user ?? null;
            cache.set(username, info);
            pending.delete(username);
            return info;
        })
        .catch(() => {
            pending.delete(username);
            return null;
        });

    pending.set(username, promise);
    return promise;
}

function roleLabel(role) {
    const key = { admin: 'roles.admin', editor: 'roles.editor', viewer: 'roles.viewer' }[role];
    return key ? I18n.t(key) : role;
}

function buildUserInfoModel(username, info, titlePrefix) {
    const rows = [];
    let chat = null;
    if (info) {
        const fullName = [info.first_name, info.last_name].filter(Boolean).join(' ');
        if (fullName) rows.push({ label: I18n.t('comments.user_info_name'), value: fullName });
        if (info.role) rows.push({ label: I18n.t('comments.user_info_role'), value: roleLabel(info.role) });
        if (info.email) rows.push({ label: I18n.t('comments.user_info_email'), value: info.email });
        if (info.phone) rows.push({ label: I18n.t('comments.user_info_phone'), value: info.phone });

        if (info.id && String(info.id) !== String(window.CURRENT_USER_ID ?? '')) {
            const peer = {
                id: info.id,
                username: info.username ?? username,
                avatar_id: info.avatar_id ?? null,
            };
            chat = {
                id: peer.id,
                username: peer.username,
                avatar_id: peer.avatar_id,
                label: I18n.t('chat.message_user'),
                onClick: () => import('../chat-panel.js').then(module => module.openChatPanel(peer)),
            };
        }
    }
    return { title: titlePrefix + username, rows, chat };
}

export function enableUserTooltips(container, { endpoint, titlePrefix = '' } = {}) {
    let hoverToken = 0;

    container.addEventListener('mouseover', event => {
        const anchor = event.target.closest('[data-username]');
        if (!anchor) return;
        const username = anchor.getAttribute('data-username');
        if (!username) return;
        anchor.classList.add('os-tip');

        const token = ++hoverToken;
        const pendingInfo = fetchUserInfo(username, endpoint);
        const prefix = anchor.dataset.tooltipPrefix ?? titlePrefix;

        setTimeout(() => {
            if (token !== hoverToken) return;
            pendingInfo.then(info => {
                if (token !== hoverToken) return;
                showRecordTooltip(anchor, buildUserInfoModel(username, info, prefix), 0);
            });
        }, 1000);
    });

    container.addEventListener('mouseout', event => {
        if (!event.target.closest('[data-username]')) return;
        hoverToken++;
        hideRecordTooltip();
    });
}
