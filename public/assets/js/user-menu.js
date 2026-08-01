// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.
//
// user-menu.js — Header user menu: avatar colour picker (1..24) and change-password, saved via api.php (update_avatar / change_password). CSRF via apiFetch().

import { showToast } from './toast.js';
import { I18n } from './i18n.js';
import { BulkPanel } from './bulk_panel.js';
import { openNotesPanel } from './notes-panel.js';
import { formatDateTime } from './util/format-value.js';
import { AVATAR_COLORS, renderAvatar } from './avatar.js';

const AVATAR_COUNT = AVATAR_COLORS.length;

// The header button is the single source of truth for the current user's name and
// colour: the tooltip carries the username, data-avatar-id the palette index.
function headerUsername() {
    const tooltip = document.querySelector('#userAvatarBtn .user-avatar-tooltip');
    return tooltip?.textContent?.trim() || '?';
}

import { apiFetch as sharedApiFetch } from './util/api.js';

function apiFetch(action, body) {
    return sharedApiFetch(`api.php?action=${action}`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body,
    });
}

// ── Avatar picker modal ────────────────────────────────────────────────────

function buildAvatarModal(currentId, username) {
    const overlay = document.createElement('div');
    overlay.className = 'um-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', I18n.t('header.change_avatar'));

    const box = document.createElement('div');
    box.className = 'um-box';
    box.innerHTML = `
        <button class="um-close" aria-label="${I18n.t('header.close')}">&times;</button>
        <h3>${I18n.t('header.choose_avatar')}</h3>
        <div class="um-picker" role="group" aria-label="${I18n.t('header.avatar_options')}"></div>
        <div class="um-actions">
            <button class="um-btn um-btn-secondary" id="umAvatarClear">${I18n.t('header.default_color')}</button>
            <button class="um-btn um-btn-primary" id="umAvatarSave" disabled>${I18n.t('common.save')}</button>
        </div>`;

    const picker = box.querySelector('.um-picker');
    let selected = currentId ?? null;

    for (let i = 1; i <= AVATAR_COUNT; i++) {
        const btn = document.createElement('button');
        btn.className = 'um-picker-btn' + (i === selected ? ' selected' : '');
        btn.setAttribute('aria-label', I18n.t('header.avatar_option', { n: i }));
        btn.setAttribute('aria-pressed', String(i === selected));
        btn.dataset.id = String(i);
        btn.appendChild(renderAvatar(i, username));
        btn.addEventListener('click', () => {
            picker.querySelectorAll('.um-picker-btn').forEach(b => {
                b.classList.remove('selected');
                b.setAttribute('aria-pressed', 'false');
            });
            btn.classList.add('selected');
            btn.setAttribute('aria-pressed', 'true');
            selected = i;
            box.querySelector('#umAvatarSave').disabled = false;
        });
        picker.appendChild(btn);
    }

    box.querySelector('.um-close').addEventListener('click', () => closeModal(overlay));
    overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(overlay); });

    // "Use initial" clears the avatar
    box.querySelector('#umAvatarClear').addEventListener('click', async () => {
        await saveAvatar(overlay, null);
    });

    box.querySelector('#umAvatarSave').addEventListener('click', async () => {
        await saveAvatar(overlay, selected);
    });

    // Trap focus inside modal
    overlay.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeModal(overlay);
    });

    overlay.appendChild(box);
    return overlay;
}

async function saveAvatar(overlay, avatarId) {
    const saveBtn = overlay.querySelector('#umAvatarSave');
    if (saveBtn) saveBtn.disabled = true;

    try {
        const res = await apiFetch('update_avatar', { avatar_id: avatarId });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.error ?? I18n.t('header.error_saving_avatar'));
        showToast(I18n.t('header.avatar_updated'), 'success');
        closeModal(overlay);
        updateHeaderAvatar(avatarId);
    } catch (err) {
        showToast(err.message, 'error');
        if (saveBtn) saveBtn.disabled = false;
    }
}

function updateHeaderAvatar(avatarId) {
    const btn = document.getElementById('userAvatarBtn');
    if (!btn) return;

    const tooltip = btn.querySelector('.user-avatar-tooltip');
    const existing = btn.querySelector('.avatar');
    if (!existing) return;

    existing.replaceWith(renderAvatar(avatarId, tooltip?.textContent?.trim() ?? '?'));
    // Keep the button in sync so reopening the picker preselects the saved colour.
    if (avatarId) {
        btn.dataset.avatarId = String(avatarId);
    } else {
        delete btn.dataset.avatarId;
    }
}

// ── Password change modal ─────────────────────────────────────────────────

function buildPasswordModal() {
    const overlay = document.createElement('div');
    overlay.className = 'um-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', I18n.t('auth.change_password'));

    const box = document.createElement('div');
    box.className = 'um-box';
    box.innerHTML = `
        <button class="um-close" aria-label="${I18n.t('header.close')}">&times;</button>
        <h3>${I18n.t('auth.change_password')}</h3>
        <p class="um-error" id="umPwdError"></p>
        <form class="um-form" id="umPwdForm" autocomplete="off">
            <label for="umPwdCurrent">${I18n.t('auth.current_password')}</label>
            <input type="password" id="umPwdCurrent" autocomplete="current-password" required />
            <label for="umPwdNew">${I18n.t('auth.new_password')}</label>
            <input type="password" id="umPwdNew" autocomplete="new-password" required minlength="8" />
            <label for="umPwdConfirm">${I18n.t('auth.confirm_password')}</label>
            <input type="password" id="umPwdConfirm" autocomplete="new-password" required minlength="8" />
            <div class="um-actions">
                <button type="button" class="um-btn um-btn-secondary" id="umPwdCancel">${I18n.t('common.cancel')}</button>
                <button type="submit" class="um-btn um-btn-primary">${I18n.t('common.save')}</button>
            </div>
        </form>`;

    const errEl  = box.querySelector('#umPwdError');
    const form   = box.querySelector('#umPwdForm');
    const submit = form.querySelector('[type="submit"]');

    box.querySelector('.um-close').addEventListener('click', () => closeModal(overlay));
    box.querySelector('#umPwdCancel').addEventListener('click', () => closeModal(overlay));
    overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(overlay); });
    overlay.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(overlay); });

    form.addEventListener('submit', async e => {
        e.preventDefault();
        errEl.classList.remove('visible');

        const current = form.querySelector('#umPwdCurrent').value;
        const newPwd  = form.querySelector('#umPwdNew').value;
        const confirm = form.querySelector('#umPwdConfirm').value;

        if (newPwd !== confirm) {
            errEl.textContent = I18n.t('auth.passwords_no_match');
            errEl.classList.add('visible');
            return;
        }
        if (newPwd.length < 8) {
            errEl.textContent = I18n.t('auth.password_too_short');
            errEl.classList.add('visible');
            return;
        }

        submit.disabled = true;
        try {
            const res  = await apiFetch('change_password', { current_password: current, new_password: newPwd });
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error ?? I18n.t('auth.error_changing_password'));
            showToast(I18n.t('auth.password_changed'), 'success');
            closeModal(overlay);
        } catch (err) {
            errEl.textContent = err.message;
            errEl.classList.add('visible');
            submit.disabled = false;
        }
    });

    overlay.appendChild(box);
    return overlay;
}

// ── Modal helpers ─────────────────────────────────────────────────────────

function openModal(overlay) {
    document.body.appendChild(overlay);
    // Trigger CSS transition on next frame
    requestAnimationFrame(() => overlay.classList.add('open'));
    overlay.querySelector('button')?.focus();
}

function closeModal(overlay) {
    overlay.classList.remove('open');
    overlay.addEventListener('transitionend', () => overlay.remove(), { once: true });
    // Fallback if no transition fires
    setTimeout(() => { if (overlay.parentNode) overlay.remove(); }, 300);
    document.getElementById('userAvatarBtn')?.focus();
}

// ── My records panel ────────────────────────────────────────────────────────

let myRecordsPanel = null;

function renderMyRecords(bodyEl, records) {
    bodyEl.textContent = '';

    if (!records.length) {
        const empty = document.createElement('p');
        empty.className = 'dc-empty';
        empty.textContent = I18n.t('header.my_records_empty');
        bodyEl.appendChild(empty);
        return;
    }

    const list = document.createElement('ul');
    list.className = 'um-list';

    for (const record of records) {
        const item = document.createElement('li');
        item.className = 'um-item';

        const link = document.createElement('a');
        link.className = 'um-item-link';
        link.href = 'edit.php?table=' + encodeURIComponent(record.table)
            + '&id=' + encodeURIComponent(record.id);
        link.textContent = `${record.table_display} → ${record.label}`;
        item.appendChild(link);

        const meta = document.createElement('div');
        meta.className = 'um-item-meta';
        meta.textContent = I18n.t('header.my_records_assigned', {
            date: formatDateTime(record.assigned_at),
        });
        item.appendChild(meta);

        list.appendChild(item);
    }

    bodyEl.appendChild(list);
}

async function openMyRecordsPanel() {
    if (!myRecordsPanel) {
        myRecordsPanel = new BulkPanel({
            id: 'myRecordsPanel',
            title: I18n.t('header.my_records'),
            showApply: false,
        });
    }

    myRecordsPanel.open();
    myRecordsPanel.clearStatus();
    myRecordsPanel.setStatus(I18n.t('common.loading'));
    myRecordsPanel.bodyEl.textContent = '';

    try {
        const res = await fetch('api/owners.php?action=mine', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error ?? I18n.t('common.error_generic'));

        myRecordsPanel.clearStatus();
        renderMyRecords(myRecordsPanel.bodyEl, data.records ?? []);
    } catch (err) {
        myRecordsPanel.setStatus(err.message, true);
    }
}

// ── My comments panel ───────────────────────────────────────────────────────

const MY_COMMENT_SNIPPET_MAX = 140;

let myCommentsPanel = null;

function renderMyComments(bodyEl, comments) {
    bodyEl.textContent = '';

    if (!comments.length) {
        const empty = document.createElement('p');
        empty.className = 'dc-empty';
        empty.textContent = I18n.t('header.my_comments_empty');
        bodyEl.appendChild(empty);
        return;
    }

    const list = document.createElement('ul');
    list.className = 'um-list';

    for (const comment of comments) {
        const item = document.createElement('li');
        item.className = 'um-item';

        const body = document.createElement('div');
        body.className = 'um-item-body';
        const raw = comment.body ?? '';
        body.textContent = raw.length > MY_COMMENT_SNIPPET_MAX
            ? `${raw.slice(0, MY_COMMENT_SNIPPET_MAX)}…`
            : raw;
        item.appendChild(body);

        const link = document.createElement('a');
        link.className = 'um-item-link';
        // #tab-comments opens the record straight on its comment thread — same deep
        // link the grid comment badges use.
        link.href = 'edit.php?table=' + encodeURIComponent(comment.related_table)
            + '&id=' + encodeURIComponent(comment.related_id) + '#tab-comments';
        link.textContent = `${comment.table_display} → ${comment.record_label}`;
        item.appendChild(link);

        const meta = document.createElement('div');
        meta.className = 'um-item-meta';
        meta.textContent = formatDateTime(comment.created_at);
        item.appendChild(meta);

        list.appendChild(item);
    }

    bodyEl.appendChild(list);
}

async function openMyCommentsPanel() {
    if (!myCommentsPanel) {
        myCommentsPanel = new BulkPanel({
            id: 'myCommentsPanel',
            title: I18n.t('header.my_comments'),
            showApply: false,
        });
    }

    myCommentsPanel.open();
    myCommentsPanel.clearStatus();
    myCommentsPanel.setStatus(I18n.t('common.loading'));
    myCommentsPanel.bodyEl.textContent = '';

    try {
        const res = await fetch('api/comments.php?action=mine', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error ?? I18n.t('common.error_generic'));

        myCommentsPanel.clearStatus();
        renderMyComments(myCommentsPanel.bodyEl, data.comments ?? []);
    } catch (err) {
        myCommentsPanel.setStatus(err.message, true);
    }
}

// ── Dropdown ──────────────────────────────────────────────────────────────

function initUserMenu() {
    const btn  = document.getElementById('userAvatarBtn');
    const menu = document.getElementById('userAvatarMenu');
    if (!btn || !menu) return;

    const toggle = open => {
        menu.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', String(open));
        if (!open && menu.contains(document.activeElement)) {
            btn.focus();
        }
    };

    btn.addEventListener('click', e => {
        e.stopPropagation();
        toggle(!menu.classList.contains('open'));
    });

    document.addEventListener('click', e => {
        if (!btn.contains(e.target) && !menu.contains(e.target)) {
            toggle(false);
        }
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && menu.classList.contains('open')) toggle(false);
    });

    document.getElementById('changeAvatarBtn')?.addEventListener('click', () => {
        toggle(false);
        const currentId = parseInt(btn.dataset.avatarId ?? '', 10);
        openModal(buildAvatarModal(Number.isInteger(currentId) ? currentId : null, headerUsername()));
    });

    document.getElementById('changePasswordBtn')?.addEventListener('click', () => {
        toggle(false);
        openModal(buildPasswordModal());
    });

    document.getElementById('myRecordsBtn')?.addEventListener('click', () => {
        toggle(false);
        openMyRecordsPanel();
    });

    document.getElementById('myCommentsBtn')?.addEventListener('click', () => {
        toggle(false);
        openMyCommentsPanel();
    });

    document.getElementById('notesBtn')?.addEventListener('click', () => {
        toggle(false);
        openNotesPanel();
    });

    document.getElementById('logoutBtn')?.addEventListener('click', () => {
        window.location.href = 'logout.php';
    });
}

// Every menu label and modal in this module goes through I18n.t(), so the bundle must be
// in place before any handler can run — otherwise clicking the avatar early renders raw
// keys ("header.choose_avatar").
document.addEventListener('DOMContentLoaded', async () => {
    await I18n.load();
    initUserMenu();
});
