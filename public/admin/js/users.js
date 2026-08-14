// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// admin/js/users.js — User management (renderUsersEditor): Manage Users / Table Access /
// Statistics / Global Settings inner tabs (list/add/toggle/change-role/change-password,
// user_stats, user_policy_*, user_tables_* via api.php users_* actions).
// CSRF via apiFetch(); HTML-escapes output.

import { apiFetch } from '../../assets/js/util/api.js';
import { escHtml } from '../../assets/js/util/esc.js';
import { buildInnerTabs, buildModal, createPageHeader } from './ui.js';
import { showStatusPill } from './app.js';

export async function renderUsersEditor(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    wrap.appendChild(createPageHeader('Users'));

    const [managePanel, accessPanel, statsPanel, settingsPanel] = buildInnerTabs(wrap, [
        { label: 'Manage Users', icon: 'user_attributes.png' },
        { label: 'Access', icon: 'table_chart_view.png' },
        { label: 'Statistics', icon: 'bar_chart.png' },
        { label: 'Global Settings', icon: 'manage_history.png' },
    ]);

    workspaceEl.appendChild(wrap);

    renderManageUsers(managePanel, ctx);
    renderUserAccess(accessPanel);
    renderUserStats(statsPanel);
    renderUserSettings(settingsPanel, ctx);
}

// Access tab: pick a user, tick what they may reach on the frontend. Empty
// selection = full access (no restriction) — same contract as user_allowed_items()
// in includes/api_helpers.php. The scopes are independent: restricting tables leaves
// views, printouts, boards and workflows untouched. Admins are excluded — they work
// in this panel and must keep seeing everything.
//
// The sections come from the server (user_tables_get -> scopes), which reads them from
// USER_ACCESS_SCOPES. This file deliberately keeps no list of its own: a scope added to
// that registry appears here with no JS change, and a copy kept here is exactly how the
// picker and the gates would drift apart.
async function renderUserAccess(panel) {
    panel.innerHTML = '<p class="help-text">Loading users…</p>';

    let users;
    try {
        const res  = await apiFetch('api.php?action=users_list');
        const data = await res.json();
        if (data.status !== 'success') {
            panel.innerHTML = `<p class="help-text">${escHtml(data.error || 'Failed to load users.')}</p>`;
            return;
        }
        users = data.users.filter(u => u.role !== 'admin');
    } catch (err) {
        panel.innerHTML = '<p class="help-text">Network error while loading users.</p>';
        return;
    }

    panel.innerHTML = `
        <h2 class="admin-page-title">Frontend Access</h2>
        <p class="admin-page-desc">
            Restrict a user to a subset of the frontend tables, views and printouts. Each
            group is independent, and ticking nothing in a group leaves that group
            unrestricted — which is not the same as revoking access. To cut someone off
            entirely, deactivate the account in Manage Users. Admin accounts are not
            listed: they work in this panel and always see everything.
        </p>
        <div class="adm-sec-card">
            <label class="adm-field-label" for="taUser">User</label>
            <select id="taUser" class="adm-input w-260">
                ${users.map(u => `<option value="${u.id}">${escHtml(u.username)}${u.is_active ? '' : ' (inactive)'}</option>`).join('')}
            </select>
            <div id="taScopes" style="margin-top:16px;"></div>
        </div>
    `;

    if (users.length === 0) {
        panel.querySelector('.adm-sec-card').innerHTML =
            '<p class="help-text">No non-admin users yet. Create one in Manage Users first.</p>';
        return;
    }

    const selectEl = panel.querySelector('#taUser');
    const listEl   = panel.querySelector('#taScopes');

    selectEl.addEventListener('change', () => loadUserAccess(listEl, selectEl.value));
    loadUserAccess(listEl, selectEl.value);
}

// One checkbox section per scope. Returns a live reader for the ticked values so
// the shared Save button does not need to know how many scopes there are.
// hiddenChildren maps a grantable table to the hidden helper tables that ticking it
// drags in (see admin_hidden_children_map in includes/admin/users.php); it is display
// only and never part of the payload.
function renderScopeSection(container, scope, allItems, selected, hiddenChildren = {}) {
    const names = Object.keys(allItems)
        .sort((a, b) => (allItems[a] || a).localeCompare(allItems[b] || b));

    const section = document.createElement('div');
    section.style.marginBottom = '22px';

    if (names.length === 0) {
        section.innerHTML = `<h4>${escHtml(scope.title)}</h4><p class="help-text">${escHtml(scope.empty)}</p>`;
        container.appendChild(section);
        return () => [];
    }

    section.innerHTML = `
        <h4>${escHtml(scope.title)}</h4>
        <div class="ta-badge" style="margin-bottom:10px;"></div>
        <table class="adm-tbl">
            <thead>
                <tr>
                    <th class="adm-th">Access</th>
                    <th class="adm-th">Display Name</th>
                    <th class="adm-th">Name</th>
                </tr>
            </thead>
            <tbody>
                ${names.map(n => `
                    <tr>
                        <td class="adm-td">
                            <input type="checkbox" class="adm-check ta-item" value="${escHtml(n)}"
                                   ${selected.has(n) ? 'checked' : ''}>
                        </td>
                        <td class="adm-td"><strong>${escHtml(allItems[n] || n)}</strong></td>
                        <td class="adm-td"><code>${escHtml(n)}</code></td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
        <div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap;">
            <button class="btn btn-secondary btn-sm ta-all">Select All</button>
            <button class="btn btn-secondary btn-sm ta-none">Select None</button>
        </div>
        <p class="help-text ta-note" style="margin-top:10px;"></p>
    `;
    container.appendChild(section);

    const boxes = Array.from(section.querySelectorAll('.ta-item'));
    const badge = section.querySelector('.ta-badge');
    const note  = section.querySelector('.ta-note');

    // Nothing ticked reads as "no access" to most people, so say the opposite out loud.
    const refreshBadge = () => {
        const n = boxes.filter(b => b.checked).length;
        badge.innerHTML = n === 0
            ? '<span class="adm-badge adm-badge-ok">Full access (no restriction)</span>'
            : `<span class="adm-badge">Restricted to ${n} of ${boxes.length} ${escHtml(scope.noun)}</span>`;
    };

    // Hidden helper tables have no menu entry and no grid of their own, so they are not
    // listed above and access to them follows the parent instead. Name what a tick drags
    // in — a closure nobody can see is a closure nobody can reason about.
    const refreshNote = () => {
        // Array.isArray, not `|| []`: a table named after an Object prototype key would
        // otherwise hand flatMap a function instead of a list.
        const extra = [...new Set(
            boxes.filter(b => b.checked)
                .flatMap(b => (Array.isArray(hiddenChildren[b.value]) ? hiddenChildren[b.value] : []))
        )].sort();
        note.textContent = extra.length === 0
            ? ''
            : 'Hidden helper tables granted along with the ticked ones: ' + extra.join(', ');
    };

    const refresh = () => { refreshBadge(); refreshNote(); };
    boxes.forEach(b => b.addEventListener('change', refresh));
    refresh();

    section.querySelector('.ta-all').addEventListener('click', () => {
        boxes.forEach(b => { b.checked = true; });
        refresh();
    });
    section.querySelector('.ta-none').addEventListener('click', () => {
        boxes.forEach(b => { b.checked = false; });
        refresh();
    });

    return () => boxes.filter(b => b.checked).map(b => b.value);
}

async function loadUserAccess(listEl, userId) {
    listEl.innerHTML = '<p class="help-text">Loading…</p>';

    let data;
    try {
        const res = await apiFetch(`api.php?action=user_tables_get&user_id=${encodeURIComponent(userId)}`);
        data = await res.json();
    } catch (err) {
        listEl.innerHTML = '<p class="help-text">Network error while loading access.</p>';
        return;
    }
    if (data.status !== 'success') {
        listEl.innerHTML = `<p class="help-text">${escHtml(data.error || 'Failed to load access.')}</p>`;
        return;
    }

    listEl.innerHTML = '';
    const scopes  = Array.isArray(data.scopes) ? data.scopes : [];
    const readers = {};
    scopes.forEach(scope => {
        // PHP casts these to objects so string keys survive JSON — an empty one
        // still arrives as {}, never as [].
        readers[scope.key] = renderScopeSection(
            listEl,
            scope,
            (data.items || {})[scope.key] || {},
            new Set((data.selected || {})[scope.key] || []),
            // Only tables have a hidden-subtable closure to explain.
            scope.key === 'tables' ? (data.hidden_children || {}) : {}
        );
    });

    const saveEl = document.createElement('button');
    saveEl.className = 'btn btn-success';
    saveEl.textContent = 'Save Access';
    listEl.appendChild(saveEl);

    saveEl.addEventListener('click', async () => {
        const payload = { user_id: parseInt(userId, 10) };
        scopes.forEach(scope => { payload[scope.key] = readers[scope.key](); });
        try {
            const res = await apiFetch('api.php?action=user_tables_save', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const out = await res.json();
            if (out.status === 'success') {
                showStatusPill(saveEl, 'Access saved.', 'success');
            } else {
                showStatusPill(saveEl, out.error || 'Save failed.', 'error');
            }
        } catch (err) {
            showStatusPill(saveEl, 'Network error.', 'error');
        }
    });
}

async function renderManageUsers(panel, ctx) {
    panel.innerHTML = `<h3>System Users</h3><p>Loading users...</p>`;

    try {
        const [usersRes, policyRes] = await Promise.all([
            apiFetch('api.php?action=users_list'),
            apiFetch('api.php?action=user_policy_get'),
        ]);
        const data = await usersRes.json();
        const policy = await policyRes.json();

        if (data.status !== 'success') {
            panel.innerHTML = `<h3 style="color:var(--error);">Error</h3><p>${escHtml(data.error)}</p>`;
            return;
        }

        const minPasswordLength = policy.status === 'success' ? policy.min_password_length : 8;
        const defaultRole = policy.status === 'success' ? policy.default_role : 'editor';

        // False on a database where 3.3_user_contact has not been run. Everything else
        // on this tab still works, so the contact fields are simply left out rather
        // than rendered as controls whose save could only fail.
        const hasContact = data.contact_columns !== false;

        let html = `
            <h2 class="admin-page-title">System Users Management</h2>
            <p class="admin-page-desc">
                Manage user accounts and roles. Roles: <strong>Admin</strong> – admin panel only; <strong>Editor</strong> – full frontend CRUD; <strong>Viewer</strong> – read-only frontend.
            </p>
            ${hasContact ? '' : `<p class="admin-page-desc" style="color:var(--error);">
                Contact details (name, email, phone) are unavailable: run
                Migrations &rarr; Initialize System Tables to apply the
                <code>3.3_user_contact</code> migration.
            </p>`}
            <table class="adm-tbl" style="margin-bottom: 30px;">
                <thead>
                    <tr>
                        <th class="adm-th">ID</th>
                        <th class="adm-th">Username</th>
                        ${hasContact ? `<th class="adm-th">Name</th>
                        <th class="adm-th">Email</th>
                        <th class="adm-th">Phone</th>` : ''}
                        <th class="adm-th">Status</th>
                        <th class="adm-th">Role</th>
                        <th class="adm-th">Actions</th>
                    </tr>
                </thead>
                <tbody>
        `;

        // Optional, informational contact fields — an unset one renders as an em dash.
        const cell = (v) => (v ?? '').trim()
            ? escHtml(v)
            : '<span class="adm-td-empty">&mdash;</span>';
        const fullName = (u) => [u.first_name ?? '', u.last_name ?? ''].join(' ').trim();

        data.users.forEach(u => {
            html += `
                <tr>
                    <td class="adm-td">${escHtml(u.id)}</td>
                    <td class="adm-td"><strong>${escHtml(u.username)}</strong></td>
                    ${hasContact ? `<td class="adm-td">${cell(fullName(u))}</td>
                    <td class="adm-td">${cell(u.email)}</td>
                    <td class="adm-td">${cell(u.phone)}</td>` : ''}
                    <td class="adm-td">
                        <span class="adm-badge ${u.is_active ? 'adm-badge-ok' : 'adm-badge-danger'}">
                            ${u.is_active ? 'Active' : 'Inactive'}
                        </span>
                    </td>
                    <td class="adm-td">
                        <select class="select-user-role adm-input" data-id="${u.id}">
                            <option value="admin"  ${u.role === 'admin'  ? 'selected' : ''}>Admin</option>
                            <option value="editor" ${u.role === 'editor' || !u.role ? 'selected' : ''}>Editor</option>
                            <option value="viewer" ${u.role === 'viewer' ? 'selected' : ''}>Viewer</option>
                        </select>
                    </td>
                    <td class="adm-td" style="display:flex; gap:6px; flex-wrap:wrap;">
                        <button class="btn btn-xs btn-toggle-user ${u.is_active ? 'btn-warning' : 'btn-secondary'}" data-id="${u.id}" data-active="${u.is_active}">
                            ${u.is_active ? 'Deactivate' : 'Activate'}
                        </button>
                        <button class="btn btn-xs btn-secondary btn-change-pwd" data-id="${u.id}" data-username="${escHtml(u.username)}">
                            Change pwd
                        </button>
                        ${hasContact ? `<button class="btn btn-xs btn-secondary btn-edit-contact" data-id="${u.id}" data-username="${escHtml(u.username)}"
                            data-first-name="${escHtml(u.first_name ?? '')}" data-last-name="${escHtml(u.last_name ?? '')}"
                            data-email="${escHtml(u.email ?? '')}" data-phone="${escHtml(u.phone ?? '')}">
                            Edit Details
                        </button>` : ''}
                    </td>
                </tr>
            `;
        });

        html += `
                </tbody>
            </table>

            <div style="background: var(--accent-mid); padding: 20px; border-radius: 6px; border: 1px solid var(--accent-mid);">
                <h4 style="margin-top: 0; margin-bottom: 15px;">Add New User</h4>
                <div style="margin-bottom: 15px;">
                    <label class="adm-field-label">Username</label>
                    <input type="text" id="newUsername" placeholder="e.g. john_doe" class="adm-input w-full">
                </div>
                <div style="margin-bottom: 15px;">
                    <label class="adm-field-label">Password</label>
                    <input type="password" id="newPassword" placeholder="Minimum ${minPasswordLength} characters" class="adm-input" style="width:100%;">
                    <div id="passwordStrengthBar" style="height: 6px; background: var(--accent-mid); border-radius: 3px; margin-top: 8px; overflow: hidden; max-width: 200px;">
                        <div id="passwordStrengthFill" style="height: 100%; width: 0%; transition: width 0.3s, background 0.3s;"></div>
                    </div>
                    <small id="passwordStrengthLabel" style=" display: block; margin-top: 4px;"></small>
                </div>
                <div style="margin-bottom: 15px;">
                    <label class="adm-field-label">Role</label>
                    <select id="newRole" class="adm-input w-full">
                        <option value="editor" ${defaultRole === 'editor' ? 'selected' : ''}>Editor</option>
                        <option value="viewer" ${defaultRole === 'viewer' ? 'selected' : ''}>Viewer</option>
                        <option value="admin" ${defaultRole === 'admin' ? 'selected' : ''}>Admin</option>
                    </select>
                </div>
                ${hasContact ? `<div style="margin-bottom: 15px;">
                    <label class="adm-field-label">First Name (Optional)</label>
                    <input type="text" id="newFirstName" class="adm-input w-full" maxlength="100">
                </div>
                <div style="margin-bottom: 15px;">
                    <label class="adm-field-label">Last Name (Optional)</label>
                    <input type="text" id="newLastName" class="adm-input w-full" maxlength="100">
                </div>
                <div style="margin-bottom: 15px;">
                    <label class="adm-field-label">Email (Optional)</label>
                    <input type="email" id="newEmail" class="adm-input w-full" maxlength="255">
                </div>
                <div style="margin-bottom: 15px;">
                    <label class="adm-field-label">Phone (Optional)</label>
                    <input type="text" id="newPhone" class="adm-input w-full" maxlength="32">
                </div>` : ''}
                <button id="btnAddUser" class="btn btn-success">Create User</button>
            </div>
        `;

        panel.innerHTML = html;

        // Setup toggle active status events
        panel.querySelectorAll('.btn-toggle-user').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const id = e.target.getAttribute('data-id');
                const currentlyActive = e.target.getAttribute('data-active') === 'true';
                if (!confirm(`Are you sure you want to ${currentlyActive ? 'deactivate' : 'activate'} this user?`)) return;

                try {
                    const req = await apiFetch('api.php?action=users_toggle', {
                        method: 'POST',
                        body: JSON.stringify({ id, is_active: !currentlyActive })
                    });

                    const resData = await req.json();
                    if (resData.status === 'success') {
                        renderManageUsers(panel, ctx);
                    } else {
                        showStatusPill(e.target, resData.error || 'Update failed.', 'error');
                    }
                } catch (err) {
                    showStatusPill(e.target, 'Network error.', 'error');
                }
            });
        });

        // Setup role change events
        panel.querySelectorAll('.select-user-role').forEach(select => {
            select.addEventListener('change', async (e) => {
                const id = e.target.getAttribute('data-id');
                const role = e.target.value;

                try {
                    const req = await apiFetch('api.php?action=users_update_role', {
                        method: 'POST',
                        body: JSON.stringify({ id, role })
                    });

                    const resData = await req.json();
                    if (resData.status !== 'success') {
                        showStatusPill(e.target, resData.error || 'Role change failed.', 'error');
                        renderManageUsers(panel, ctx);
                    }
                } catch (err) {
                    showStatusPill(e.target, 'Network error.', 'error');
                    renderManageUsers(panel, ctx);
                }
            });
        });

        // Change password for existing user
        const currentUserId = parseInt(document.querySelector('meta[name="current-user-id"]')?.content ?? '0', 10);

        panel.querySelectorAll('.btn-change-pwd').forEach(btn => {
            btn.addEventListener('click', () => {
                const id       = parseInt(btn.getAttribute('data-id'), 10);
                const username = btn.getAttribute('data-username');
                const isSelf   = id === currentUserId;

                const { box, body, msgEl, cancelBtn, saveBtn, close } = buildModal({
                    title: 'Change password',
                    subtitleLabel: 'User: ',
                    subtitleValue: username,
                });
                // The cpw-* ids stay on the nodes: they are long-standing selector
                // hooks, and dropping them in a refactor is how E2E specs break.
                msgEl.id = 'cpw-msg';
                cancelBtn.id = 'cpw-cancel';
                saveBtn.id = 'cpw-save';
                // Kept from the hand-rolled dialog this replaced — appended, not
                // swapped, so the button keeps every class it had before.
                saveBtn.classList.add('btn-sm');

                if (isSelf) {
                    const currentInput = document.createElement('input');
                    currentInput.type = 'password';
                    currentInput.id = 'cpw-current';
                    currentInput.placeholder = 'Current password';
                    currentInput.className = 'adm-input w-full';
                    currentInput.style.marginBottom = '8px';
                    body.appendChild(currentInput);
                }

                const newInput = document.createElement('input');
                newInput.type = 'password';
                newInput.id = 'cpw-new';
                newInput.placeholder = `New password (min ${minPasswordLength} chars)`;
                newInput.className = 'adm-input w-full';
                newInput.style.marginBottom = '8px';
                body.appendChild(newInput);

                const confirmInput = document.createElement('input');
                confirmInput.type = 'password';
                confirmInput.id = 'cpw-confirm';
                confirmInput.placeholder = 'Confirm new password';
                confirmInput.className = 'adm-input w-full';
                confirmInput.style.marginBottom = '12px';
                body.appendChild(confirmInput);

                (box.querySelector('#cpw-current') ?? newInput).focus();

                saveBtn.addEventListener('click', async () => {
                    const pwd     = newInput.value;
                    const confirm = box.querySelector('#cpw-confirm').value;
                    if (isSelf && !box.querySelector('#cpw-current').value) {
                        msgEl.style.color = 'var(--error)';
                        msgEl.textContent = 'Current password is required.';
                        return;
                    }
                    if (pwd.length < minPasswordLength) {
                        msgEl.style.color = 'var(--error)';
                        msgEl.textContent = `Password must be at least ${minPasswordLength} characters.`;
                        return;
                    }
                    if (pwd !== confirm) {
                        msgEl.style.color = 'var(--error)';
                        msgEl.textContent = 'Passwords do not match.';
                        return;
                    }
                    msgEl.textContent = 'Saving…';
                    try {
                        let res, data;
                        if (isSelf) {
                            // Own account — verify current password via frontend API
                            res  = await apiFetch('../api.php?action=change_password', {
                                method: 'POST',
                                body: JSON.stringify({ current_password: box.querySelector('#cpw-current').value, new_password: pwd }),
                            });
                            data = await res.json();
                            if (data.ok) { close(); return; }
                        } else {
                            // Other user — admin override, no current password check
                            res  = await apiFetch('api.php?action=users_change_password', {
                                method: 'POST',
                                body: JSON.stringify({ id, password: pwd }),
                            });
                            data = await res.json();
                            if (data.status === 'success') { close(); return; }
                        }
                        msgEl.style.color = 'var(--error)';
                        msgEl.textContent = data.error || 'Error saving password.';
                    } catch {
                        msgEl.style.color = 'var(--error)';
                        msgEl.textContent = 'Network error.';
                    }
                });
            });
        });

        // Edit the optional contact details (same modal shape as the password dialog)
        panel.querySelectorAll('.btn-edit-contact').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.getAttribute('data-id'), 10);

                const { body, msgEl, saveBtn, close } = buildModal({
                    title: 'Edit Details',
                    subtitleLabel: 'User: ',
                    subtitleValue: btn.getAttribute('data-username'),
                });

                const fields = [
                    { key: 'first_name', attr: 'data-first-name', label: 'First Name', type: 'text',  max: 100 },
                    { key: 'last_name',  attr: 'data-last-name',  label: 'Last Name',  type: 'text',  max: 100 },
                    { key: 'email',      attr: 'data-email',      label: 'Email',      type: 'email', max: 255 },
                    { key: 'phone',      attr: 'data-phone',      label: 'Phone',      type: 'text',  max: 32  },
                ];
                const inputs = {};
                fields.forEach(f => {
                    const label = document.createElement('label');
                    label.className = 'adm-field-label';
                    label.textContent = f.label;
                    body.appendChild(label);

                    const input = document.createElement('input');
                    input.type = f.type;
                    input.className = 'adm-input w-full';
                    input.maxLength = f.max;
                    input.value = btn.getAttribute(f.attr) || '';
                    input.style.marginBottom = '8px';
                    body.appendChild(input);
                    inputs[f.key] = input;
                });

                inputs.first_name.focus();

                saveBtn.addEventListener('click', async () => {
                    msgEl.textContent = 'Saving…';
                    const payload = { id };
                    fields.forEach(f => { payload[f.key] = inputs[f.key].value; });
                    try {
                        const res = await apiFetch('api.php?action=users_update_contact', {
                            method: 'POST',
                            body: JSON.stringify(payload),
                        });
                        const resData = await res.json();
                        if (resData.status === 'success') {
                            close();
                            renderManageUsers(panel, ctx);
                            return;
                        }
                        msgEl.style.color = 'var(--error)';
                        msgEl.textContent = resData.error || 'Error saving details.';
                    } catch {
                        msgEl.style.color = 'var(--error)';
                        msgEl.textContent = 'Network error.';
                    }
                });
            });
        });

        // Password strength indicator
        const passwordInput = panel.querySelector('#newPassword');
        const strengthFill = panel.querySelector('#passwordStrengthFill');
        const strengthLabel = panel.querySelector('#passwordStrengthLabel');

        function evaluatePassword(pwd) {
            let score = 0;
            if (pwd.length >= 6) score++;
            if (pwd.length >= 8) score++;
            if (pwd.length >= 10) score++;
            if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) score++;
            if (/\d/.test(pwd)) score++;
            if (/[^a-zA-Z0-9]/.test(pwd)) score++;

            if (pwd.length < minPasswordLength) return { level: 'weak', percent: 25, label: 'Too short', color: 'var(--error)' };
            if (score <= 2) return { level: 'weak', percent: 25, label: 'Weak', color: 'var(--error)' };
            if (score <= 3) return { level: 'fair', percent: 50, label: 'Fair', color: 'var(--warn)' };
            if (score <= 4) return { level: 'good', percent: 75, label: 'Good', color: 'var(--muted)' };
            return { level: 'strong', percent: 100, label: 'Strong', color: 'var(--ok)' };
        }

        passwordInput.addEventListener('input', () => {
            const pwd = passwordInput.value;
            if (!pwd) {
                strengthFill.style.width = '0%';
                strengthLabel.textContent = '';
                return;
            }
            const result = evaluatePassword(pwd);
            strengthFill.style.width = result.percent + '%';
            strengthFill.style.background = result.color;
            strengthLabel.textContent = result.label;
            strengthLabel.style.color = result.color;
        });

        // Setup user creation
        panel.querySelector('#btnAddUser').addEventListener('click', async (e) => {
            const addBtn   = e.currentTarget;
            const username = panel.querySelector('#newUsername').value;
            const password = panel.querySelector('#newPassword').value;
            const role = panel.querySelector('#newRole').value;
            // Contact fields are informational and optional — never blocked on here,
            // and absent entirely when 3.3_user_contact has not been applied.
            const contactValue = (id) => panel.querySelector(id)?.value ?? '';
            const first_name = contactValue('#newFirstName');
            const last_name  = contactValue('#newLastName');
            const email      = contactValue('#newEmail');
            const phone      = contactValue('#newPhone');

            if (!username || !password) {
                showStatusPill(addBtn, 'Username and password are required.', 'error');
                return;
            }

            try {
                const req = await apiFetch('api.php?action=users_add', {
                    method: 'POST',
                    body: JSON.stringify({ username, password, role, first_name, last_name, email, phone })
                });
                const resData = await req.json();

                if (resData.status === 'success') {
                    showStatusPill(addBtn, 'User created.', 'success');
                    renderManageUsers(panel, ctx);
                } else {
                    showStatusPill(addBtn, resData.error || 'Could not create the user.', 'error');
                }
            } catch (err) {
                showStatusPill(addBtn, 'Network error.', 'error');
            }
        });

    } catch (e) {
        panel.innerHTML = `<h3 style="color:var(--error);">Network Error</h3><p>${escHtml(e.message)}</p>`;
    }
}

async function renderUserStats(panel) {
    panel.innerHTML = `<p>Loading statistics...</p>`;

    try {
        const res = await apiFetch('api.php?action=users_stats');
        const data = await res.json();

        if (data.status !== 'success') {
            panel.innerHTML = `<h3 style="color:var(--error);">Error</h3><p>${escHtml(data.error)}</p>`;
            return;
        }

        let html = `
            <h2 class="admin-page-title">User Statistics</h2>
            <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:24px;">
                <div class="adm-sec-card stat-tile" style="min-width:140px;">
                    <div class="stat-tile-label admin-page-desc">Total users</div>
                    <div class="stat-tile-value">${escHtml(data.total)}</div>
                </div>
                <div class="adm-sec-card stat-tile" style="min-width:140px;">
                    <div class="stat-tile-label admin-page-desc">Active</div>
                    <div class="stat-tile-value c-ok">${escHtml(data.active)}</div>
                </div>
                <div class="adm-sec-card stat-tile" style="min-width:140px;">
                    <div class="stat-tile-label admin-page-desc">Inactive</div>
                    <div class="stat-tile-value c-danger">${escHtml(data.inactive)}</div>
                </div>
            </div>

            <h4>By role</h4>
            <table class="adm-tbl" style="margin-bottom:30px; max-width:400px;">
                <thead>
                    <tr><th class="adm-th">Role</th><th class="adm-th">Count</th></tr>
                </thead>
                <tbody>
                    <tr><td class="adm-td">Admin</td><td class="adm-td">${escHtml(data.by_role.admin)}</td></tr>
                    <tr><td class="adm-td">Editor</td><td class="adm-td">${escHtml(data.by_role.editor)}</td></tr>
                    <tr><td class="adm-td">Viewer</td><td class="adm-td">${escHtml(data.by_role.viewer)}</td></tr>
                </tbody>
            </table>

            <h4>Recent user activity</h4>
            <table class="adm-tbl">
                <thead>
                    <tr>
                        <th class="adm-th">Action</th>
                        <th class="adm-th">By</th>
                        <th class="adm-th">When</th>
                    </tr>
                </thead>
                <tbody>
        `;

        if (data.recent.length === 0) {
            html += `<tr><td class="adm-td" colspan="3">No recent activity.</td></tr>`;
        } else {
            data.recent.forEach(r => {
                html += `
                    <tr>
                        <td class="adm-td">${escHtml(r.action)}</td>
                        <td class="adm-td">${escHtml(r.username || '—')}</td>
                        <td class="adm-td">${escHtml(r.created_at)}</td>
                    </tr>
                `;
            });
        }

        html += `
                </tbody>
            </table>
        `;

        panel.innerHTML = html;
    } catch (e) {
        panel.innerHTML = `<h3 style="color:var(--error);">Network Error</h3><p>${escHtml(e.message)}</p>`;
    }
}

async function renderUserSettings(panel, ctx) {
    panel.innerHTML = `<p>Loading settings...</p>`;

    try {
        const res = await apiFetch('api.php?action=user_policy_get');
        const data = await res.json();

        if (data.status !== 'success') {
            panel.innerHTML = `<h3 style="color:var(--error);">Error</h3><p>${escHtml(data.error)}</p>`;
            return;
        }

        panel.innerHTML = `
            <h2 class="admin-page-title">Global User Settings</h2>
            <p class="admin-page-desc">Policy applied to new users and password changes across the whole system.</p>
            <div style="max-width:400px;">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Minimum password length</label>
                    <input type="number" id="policyMinPasswordLength" class="adm-input" style="width:100%;" min="6" step="1" value="${escHtml(data.min_password_length)}">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Default role for new users</label>
                    <select id="policyDefaultRole" class="adm-input" style="width:100%;">
                        <option value="editor" ${data.default_role === 'editor' ? 'selected' : ''}>Editor</option>
                        <option value="viewer" ${data.default_role === 'viewer' ? 'selected' : ''}>Viewer</option>
                        <option value="admin" ${data.default_role === 'admin' ? 'selected' : ''}>Admin</option>
                    </select>
                </div>
                <button id="btnSaveUserPolicy" class="btn btn-save">Save</button>
            </div>
        `;

        panel.querySelector('#btnSaveUserPolicy').addEventListener('click', async (e) => {
            const saveBtn = e.currentTarget;
            const min_password_length = parseInt(panel.querySelector('#policyMinPasswordLength').value, 10);
            const default_role = panel.querySelector('#policyDefaultRole').value;

            try {
                const req = await apiFetch('api.php?action=user_policy_save', {
                    method: 'POST',
                    body: JSON.stringify({ min_password_length, default_role })
                });
                const resData = await req.json();

                if (resData.status === 'success') {
                    showStatusPill(saveBtn, 'Settings saved.', 'success');
                    renderUserSettings(panel, ctx);
                } else {
                    showStatusPill(saveBtn, resData.error || 'Save failed.', 'error');
                }
            } catch (err) {
                showStatusPill(saveBtn, 'Network error.', 'error');
            }
        });
    } catch (e) {
        panel.innerHTML = `<h3 style="color:var(--error);">Network Error</h3><p>${escHtml(e.message)}</p>`;
    }
}
