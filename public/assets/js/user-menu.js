// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// user-menu.js — Header user menu: avatar picker (1..24) and change-password, saved via api.php (update_avatar / change_password). CSRF via apiFetch().

import { showToast } from './toast.js';
import { I18n } from './i18n.js';
import { BulkPanel } from './bulk_panel.js';

const AVATAR_COUNT = 24;

import { apiFetch as sharedApiFetch } from './util/api.js';

function apiFetch(action, body) {
    return sharedApiFetch(`api.php?action=${action}`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body,
    });
}

// ── Avatar picker modal ────────────────────────────────────────────────────

function buildAvatarModal(currentId) {
    const overlay = document.createElement('div');
    overlay.className = 'um-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Change avatar');

    const box = document.createElement('div');
    box.className = 'um-box';
    box.innerHTML = `
        <button class="um-close" aria-label="Close">&times;</button>
        <h3>${I18n.t('header.choose_avatar')}</h3>
        <div class="um-picker" role="group" aria-label="Avatar options"></div>
        <div class="um-actions">
            <button class="um-btn um-btn-secondary" id="umAvatarClear">${I18n.t('header.use_initial')}</button>
            <button class="um-btn um-btn-primary" id="umAvatarSave" disabled>${I18n.t('common.save')}</button>
        </div>`;

    const picker = box.querySelector('.um-picker');
    let selected = currentId ?? null;

    for (let i = 1; i <= AVATAR_COUNT; i++) {
        const btn = document.createElement('button');
        btn.className = 'um-picker-btn' + (i === selected ? ' selected' : '');
        btn.setAttribute('aria-label', `Avatar ${i}`);
        btn.setAttribute('aria-pressed', String(i === selected));
        btn.dataset.id = String(i);
        btn.innerHTML = `<img class="avatar" src="assets/img/avatar-${i}.png" alt="Avatar ${i}" />`;
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
        if (!res.ok || !data.ok) throw new Error(data.error ?? 'Error saving avatar.');
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

    if (avatarId) {
        const img = document.createElement('img');
        img.className = 'avatar avatar-border';
        img.src = `assets/img/avatar-${avatarId}.png`;
        img.alt = `Avatar ${avatarId}`;
        existing.replaceWith(img);
    } else {
        // Fallback to initial circle SVG — built via DOM API to avoid innerHTML XSS (CodeQL js/xss-through-dom)
        const initial = (tooltip?.textContent?.trim()?.[0] ?? '?').toUpperCase();
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'avatar avatar-border avatar-initial');
        svg.setAttribute('viewBox', '0 0 32 32');
        svg.setAttribute('aria-hidden', 'true');

        const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        circle.setAttribute('cx', '16');
        circle.setAttribute('cy', '16');
        circle.setAttribute('r', '16');
        circle.setAttribute('fill', '#364B60');

        const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('x', '16');
        text.setAttribute('y', '21');
        text.setAttribute('text-anchor', 'middle');
        text.setAttribute('fill', '#fff');
        text.setAttribute('font-size', '14');
        text.setAttribute('font-family', 'Inter,sans-serif');
        text.setAttribute('font-weight', '600');
        text.textContent = initial;

        svg.appendChild(circle);
        svg.appendChild(text);
        existing.replaceWith(svg);
    }
}

// ── Password change modal ─────────────────────────────────────────────────

function buildPasswordModal() {
    const overlay = document.createElement('div');
    overlay.className = 'um-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Change password');

    const box = document.createElement('div');
    box.className = 'um-box';
    box.innerHTML = `
        <button class="um-close" aria-label="Close">&times;</button>
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
            if (!res.ok || !data.ok) throw new Error(data.error ?? 'Error changing password.');
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

function renderMyRecords(bodyEl, tables) {
    bodyEl.textContent = '';

    if (!tables.length) {
        const empty = document.createElement('p');
        empty.className = 'dc-empty';
        empty.textContent = I18n.t('header.my_records_empty');
        bodyEl.appendChild(empty);
        return;
    }

    for (const group of tables) {
        const heading = document.createElement('h4');
        heading.textContent = `${group.display_name} (${group.records.length})`;
        bodyEl.appendChild(heading);

        const table = document.createElement('table');
        table.className = 'bp-preview-table';
        const tbody = document.createElement('tbody');

        for (const record of group.records) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            const link = document.createElement('a');
            link.href = 'edit.php?table=' + encodeURIComponent(group.table) + '&id=' + encodeURIComponent(record.id);
            link.textContent = record.label;
            td.appendChild(link);
            tr.appendChild(td);
            tbody.appendChild(tr);
        }

        table.appendChild(tbody);
        bodyEl.appendChild(table);
    }
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
        renderMyRecords(myRecordsPanel.bodyEl, data.tables ?? []);
    } catch (err) {
        myRecordsPanel.setStatus(err.message, true);
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
        const currentId = (() => {
            const img = btn.querySelector('img.avatar');
            if (!img) return null;
            const m = img.src?.match(/avatar-(\d+)\.png/);
            return m ? parseInt(m[1], 10) : null;
        })();
        openModal(buildAvatarModal(currentId));
    });

    document.getElementById('changePasswordBtn')?.addEventListener('click', () => {
        toggle(false);
        openModal(buildPasswordModal());
    });

    document.getElementById('myRecordsBtn')?.addEventListener('click', () => {
        toggle(false);
        openMyRecordsPanel();
    });

    document.getElementById('logoutBtn')?.addEventListener('click', () => {
        window.location.href = 'logout.php';
    });
}

document.addEventListener('DOMContentLoaded', async () => {
    await I18n.load();
    initUserMenu();
});
