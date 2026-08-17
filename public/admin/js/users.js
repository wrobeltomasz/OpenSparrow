// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { escHtml } from '../../assets/js/util/esc.js';
import { buildInnerTabs, buildModal, createPageHeader } from './ui.js';
import { showStatusPill } from './app.js';

export async function renderUsersEditor(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    wrap.appendChild(createPageHeader('Users'));

    const [managePanel, accessPanel, statisticsPanel, settingsPanel] = buildInnerTabs(wrap, [
        { label: 'Manage Users', icon: 'user_attributes.png' },
        { label: 'Access', icon: 'table_chart_view.png' },
        { label: 'Statistics', icon: 'bar_chart.png' },
        { label: 'Global Settings', icon: 'manage_history.png' },
    ]);

    workspaceElement.appendChild(wrap);

    renderManageUsers(managePanel, context);
    renderUserAccess(accessPanel);
    renderUserStatistics(statisticsPanel);
    renderUserSettings(settingsPanel, context);
}

async function renderUserAccess(panel) {
    panel.innerHTML = '<p class="help-text">Loading users…</p>';

    let users;
    try {
        const response  = await apiFetch('api.php?action=users_list');
        const data = await response.json();
        if (data.status !== 'success') {
            panel.innerHTML = `<p class="help-text">${escHtml(data.error || 'Failed to load users.')}</p>`;
            return;
        }
        users = data.users.filter(user => user.role !== 'admin');
    } catch (error) {
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
                ${users.map(user => `<option value="${user.id}">${escHtml(user.username)}${user.is_active ? '' : ' (inactive)'}</option>`).join('')}
            </select>
            <div id="taScopes" style="margin-top:16px;"></div>
        </div>
    `;

    if (users.length === 0) {
        panel.querySelector('.adm-sec-card').innerHTML =
            '<p class="help-text">No non-admin users yet. Create one in Manage Users first.</p>';
        return;
    }

    const selectElement = panel.querySelector('#taUser');
    const listElement   = panel.querySelector('#taScopes');

    selectElement.addEventListener('change', () => loadUserAccess(listElement, selectElement.value));
    loadUserAccess(listElement, selectElement.value);
}

function renderScopeSection(container, scope, allItems, selected, hiddenChildren = {}) {
    const names = Object.keys(allItems)
        .sort((a, checkbox) => (allItems[a] || a).localeCompare(allItems[checkbox] || checkbox));

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
                ${names.map(selectedCount => `
                    <tr>
                        <td class="adm-td">
                            <input type="checkbox" class="adm-check ta-item" value="${escHtml(selectedCount)}"
                                   ${selected.has(selectedCount) ? 'checked' : ''}>
                        </td>
                        <td class="adm-td"><strong>${escHtml(allItems[selectedCount] || selectedCount)}</strong></td>
                        <td class="adm-td"><code>${escHtml(selectedCount)}</code></td>
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

    const refreshBadge = () => {
        const selectedCount = boxes.filter(checkbox => checkbox.checked).length;
        badge.innerHTML = selectedCount === 0
            ? '<span class="adm-badge adm-badge-ok">Full access (no restriction)</span>'
            : `<span class="adm-badge">Restricted to ${selectedCount} of ${boxes.length} ${escHtml(scope.noun)}</span>`;
    };

    const refreshNote = () => {
        const extra = [...new Set(
            boxes.filter(checkbox => checkbox.checked)
                .flatMap(checkbox => (Array.isArray(hiddenChildren[checkbox.value]) ? hiddenChildren[checkbox.value] : []))
        )].sort();
        note.textContent = extra.length === 0
            ? ''
            : 'Hidden helper tables granted along with the ticked ones: ' + extra.join(', ');
    };

    const refresh = () => { refreshBadge(); refreshNote(); };
    boxes.forEach(checkbox => checkbox.addEventListener('change', refresh));
    refresh();

    section.querySelector('.ta-all').addEventListener('click', () => {
        boxes.forEach(checkbox => { checkbox.checked = true; });
        refresh();
    });
    section.querySelector('.ta-none').addEventListener('click', () => {
        boxes.forEach(checkbox => { checkbox.checked = false; });
        refresh();
    });

    return () => boxes.filter(checkbox => checkbox.checked).map(checkbox => checkbox.value);
}

async function loadUserAccess(listElement, userId) {
    listElement.innerHTML = '<p class="help-text">Loading…</p>';

    let data;
    try {
        const response = await apiFetch(`api.php?action=user_tables_get&user_id=${encodeURIComponent(userId)}`);
        data = await response.json();
    } catch (error) {
        listElement.innerHTML = '<p class="help-text">Network error while loading access.</p>';
        return;
    }
    if (data.status !== 'success') {
        listElement.innerHTML = `<p class="help-text">${escHtml(data.error || 'Failed to load access.')}</p>`;
        return;
    }

    listElement.innerHTML = '';
    const scopes  = Array.isArray(data.scopes) ? data.scopes : [];
    const readers = {};
    scopes.forEach(scope => {
        readers[scope.key] = renderScopeSection(
            listElement,
            scope,
            (data.items || {})[scope.key] || {},
            new Set((data.selected || {})[scope.key] || []),

            scope.key === 'tables' ? (data.hidden_children || {}) : {}
        );
    });

    const saveElement = document.createElement('button');
    saveElement.className = 'btn btn-success';
    saveElement.textContent = 'Save Access';
    listElement.appendChild(saveElement);

    saveElement.addEventListener('click', async () => {
        const payload = { user_id: parseInt(userId, 10) };
        scopes.forEach(scope => { payload[scope.key] = readers[scope.key](); });
        try {
            const response = await apiFetch('api.php?action=user_tables_save', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const saveResult = await response.json();
            if (saveResult.status === 'success') {
                showStatusPill(saveElement, 'Access saved.', 'success');
            } else {
                showStatusPill(saveElement, saveResult.error || 'Save failed.', 'error');
            }
        } catch (error) {
            showStatusPill(saveElement, 'Network error.', 'error');
        }
    });
}

async function renderManageUsers(panel, context) {
    panel.innerHTML = `<h3>System Users</h3><p>Loading users...</p>`;

    try {
        const [usersResult, policyResult] = await Promise.all([
            apiFetch('api.php?action=users_list'),
            apiFetch('api.php?action=user_policy_get'),
        ]);
        const data = await usersResult.json();
        const policy = await policyResult.json();

        if (data.status !== 'success') {
            panel.innerHTML = `<h3 style="color:var(--error);">Error</h3><p>${escHtml(data.error)}</p>`;
            return;
        }

        const minPasswordLength = policy.status === 'success' ? policy.min_password_length : 8;
        const defaultRole = policy.status === 'success' ? policy.default_role : 'editor';

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

        const cell = (cellValue) => (cellValue ?? '').trim()
            ? escHtml(cellValue)
            : '<span class="adm-td-empty">&mdash;</span>';
        const fullName = (user) => [user.first_name ?? '', user.last_name ?? ''].join(' ').trim();

        data.users.forEach(user => {
            html += `
                <tr>
                    <td class="adm-td">${escHtml(user.id)}</td>
                    <td class="adm-td"><strong>${escHtml(user.username)}</strong></td>
                    ${hasContact ? `<td class="adm-td">${cell(fullName(user))}</td>
                    <td class="adm-td">${cell(user.email)}</td>
                    <td class="adm-td">${cell(user.phone)}</td>` : ''}
                    <td class="adm-td">
                        <span class="adm-badge ${user.is_active ? 'adm-badge-ok' : 'adm-badge-danger'}">
                            ${user.is_active ? 'Active' : 'Inactive'}
                        </span>
                    </td>
                    <td class="adm-td">
                        <select class="select-user-role adm-input" data-id="${user.id}">
                            <option value="admin"  ${user.role === 'admin'  ? 'selected' : ''}>Admin</option>
                            <option value="editor" ${user.role === 'editor' || !user.role ? 'selected' : ''}>Editor</option>
                            <option value="viewer" ${user.role === 'viewer' ? 'selected' : ''}>Viewer</option>
                        </select>
                    </td>
                    <td class="adm-td" style="display:flex; gap:6px; flex-wrap:wrap;">
                        <button class="btn btn-xs btn-toggle-user ${user.is_active ? 'btn-warning' : 'btn-secondary'}" data-id="${user.id}" data-active="${user.is_active}">
                            ${user.is_active ? 'Deactivate' : 'Activate'}
                        </button>
                        <button class="btn btn-xs btn-secondary btn-change-pwd" data-id="${user.id}" data-username="${escHtml(user.username)}">
                            Change pwd
                        </button>
                        ${hasContact ? `<button class="btn btn-xs btn-secondary btn-edit-contact" data-id="${user.id}" data-username="${escHtml(user.username)}"
                            data-first-name="${escHtml(user.first_name ?? '')}" data-last-name="${escHtml(user.last_name ?? '')}"
                            data-email="${escHtml(user.email ?? '')}" data-phone="${escHtml(user.phone ?? '')}">
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

        panel.querySelectorAll('.btn-toggle-user').forEach(button => {
            button.addEventListener('click', async (event) => {
                const id = event.target.getAttribute('data-id');
                const currentlyActive = event.target.getAttribute('data-active') === 'true';
                if (!confirm(`Are you sure you want to ${currentlyActive ? 'deactivate' : 'activate'} this user?`)) return;

                try {
                    const request = await apiFetch('api.php?action=users_toggle', {
                        method: 'POST',
                        body: JSON.stringify({ id, is_active: !currentlyActive })
                    });

                    const resultData = await request.json();
                    if (resultData.status === 'success') {
                        renderManageUsers(panel, context);
                    } else {
                        showStatusPill(event.target, resultData.error || 'Update failed.', 'error');
                    }
                } catch (error) {
                    showStatusPill(event.target, 'Network error.', 'error');
                }
            });
        });

        panel.querySelectorAll('.select-user-role').forEach(select => {
            select.addEventListener('change', async (event) => {
                const id = event.target.getAttribute('data-id');
                const role = event.target.value;

                try {
                    const request = await apiFetch('api.php?action=users_update_role', {
                        method: 'POST',
                        body: JSON.stringify({ id, role })
                    });

                    const resultData = await request.json();
                    if (resultData.status !== 'success') {
                        showStatusPill(event.target, resultData.error || 'Role change failed.', 'error');
                        renderManageUsers(panel, context);
                    }
                } catch (error) {
                    showStatusPill(event.target, 'Network error.', 'error');
                    renderManageUsers(panel, context);
                }
            });
        });

        const currentUserId = parseInt(document.querySelector('meta[name="current-user-id"]')?.content ?? '0', 10);

        panel.querySelectorAll('.btn-change-pwd').forEach(button => {
            button.addEventListener('click', () => {
                const id       = parseInt(button.getAttribute('data-id'), 10);
                const username = button.getAttribute('data-username');
                const isSelf   = id === currentUserId;

                const { box, body, msgEl: messageElement, cancelBtn: cancelButton, saveBtn: saveButton, close } = buildModal({
                    title: 'Change password',
                    subtitleLabel: 'User: ',
                    subtitleValue: username,
                });

                messageElement.id = 'cpw-msg';
                cancelButton.id = 'cpw-cancel';
                saveButton.id = 'cpw-save';

                saveButton.classList.add('btn-sm');

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

                saveButton.addEventListener('click', async () => {
                    const newPassword     = newInput.value;
                    const confirm = box.querySelector('#cpw-confirm').value;
                    if (isSelf && !box.querySelector('#cpw-current').value) {
                        messageElement.style.color = 'var(--error)';
                        messageElement.textContent = 'Current password is required.';
                        return;
                    }
                    if (newPassword.length < minPasswordLength) {
                        messageElement.style.color = 'var(--error)';
                        messageElement.textContent = `Password must be at least ${minPasswordLength} characters.`;
                        return;
                    }
                    if (newPassword !== confirm) {
                        messageElement.style.color = 'var(--error)';
                        messageElement.textContent = 'Passwords do not match.';
                        return;
                    }
                    messageElement.textContent = 'Saving…';
                    try {
                        let response, data;
                        if (isSelf) {
                            response  = await apiFetch('../api.php?action=change_password', {
                                method: 'POST',
                                body: JSON.stringify({ current_password: box.querySelector('#cpw-current').value, new_password: newPassword }),
                            });
                            data = await response.json();
                            if (data.ok) { close(); return; }
                        } else {
                            response  = await apiFetch('api.php?action=users_change_password', {
                                method: 'POST',
                                body: JSON.stringify({ id, password: newPassword }),
                            });
                            data = await response.json();
                            if (data.status === 'success') { close(); return; }
                        }
                        messageElement.style.color = 'var(--error)';
                        messageElement.textContent = data.error || 'Error saving password.';
                    } catch {
                        messageElement.style.color = 'var(--error)';
                        messageElement.textContent = 'Network error.';
                    }
                });
            });
        });

        panel.querySelectorAll('.btn-edit-contact').forEach(button => {
            button.addEventListener('click', () => {
                const id = parseInt(button.getAttribute('data-id'), 10);

                const { body, msgEl: messageElement, saveBtn: saveButton, close } = buildModal({
                    title: 'Edit Details',
                    subtitleLabel: 'User: ',
                    subtitleValue: button.getAttribute('data-username'),
                });

                const fields = [
                    { key: 'first_name', attr: 'data-first-name', label: 'First Name', type: 'text',  max: 100 },
                    { key: 'last_name',  attr: 'data-last-name',  label: 'Last Name',  type: 'text',  max: 100 },
                    { key: 'email',      attr: 'data-email',      label: 'Email',      type: 'email', max: 255 },
                    { key: 'phone',      attr: 'data-phone',      label: 'Phone',      type: 'text',  max: 32  },
                ];
                const inputs = {};
                fields.forEach(fieldElement => {
                    const label = document.createElement('label');
                    label.className = 'adm-field-label';
                    label.textContent = fieldElement.label;
                    body.appendChild(label);

                    const input = document.createElement('input');
                    input.type = fieldElement.type;
                    input.className = 'adm-input w-full';
                    input.maxLength = fieldElement.max;
                    input.value = button.getAttribute(fieldElement.attr) || '';
                    input.style.marginBottom = '8px';
                    body.appendChild(input);
                    inputs[fieldElement.key] = input;
                });

                inputs.first_name.focus();

                saveButton.addEventListener('click', async () => {
                    messageElement.textContent = 'Saving…';
                    const payload = { id };
                    fields.forEach(fieldElement => { payload[fieldElement.key] = inputs[fieldElement.key].value; });
                    try {
                        const response = await apiFetch('api.php?action=users_update_contact', {
                            method: 'POST',
                            body: JSON.stringify(payload),
                        });
                        const resultData = await response.json();
                        if (resultData.status === 'success') {
                            close();
                            renderManageUsers(panel, context);
                            return;
                        }
                        messageElement.style.color = 'var(--error)';
                        messageElement.textContent = resultData.error || 'Error saving details.';
                    } catch {
                        messageElement.style.color = 'var(--error)';
                        messageElement.textContent = 'Network error.';
                    }
                });
            });
        });

        const passwordInput = panel.querySelector('#newPassword');
        const strengthFill = panel.querySelector('#passwordStrengthFill');
        const strengthLabel = panel.querySelector('#passwordStrengthLabel');

        function evaluatePassword(newPassword) {
            let score = 0;
            if (newPassword.length >= 6) score++;
            if (newPassword.length >= 8) score++;
            if (newPassword.length >= 10) score++;
            if (/[a-z]/.test(newPassword) && /[A-Z]/.test(newPassword)) score++;
            if (/\d/.test(newPassword)) score++;
            if (/[^a-zA-Z0-9]/.test(newPassword)) score++;

            if (newPassword.length < minPasswordLength) return { level: 'weak', percent: 25, label: 'Too short', color: 'var(--error)' };
            if (score <= 2) return { level: 'weak', percent: 25, label: 'Weak', color: 'var(--error)' };
            if (score <= 3) return { level: 'fair', percent: 50, label: 'Fair', color: 'var(--warn)' };
            if (score <= 4) return { level: 'good', percent: 75, label: 'Good', color: 'var(--muted)' };
            return { level: 'strong', percent: 100, label: 'Strong', color: 'var(--ok)' };
        }

        passwordInput.addEventListener('input', () => {
            const newPassword = passwordInput.value;
            if (!newPassword) {
                strengthFill.style.width = '0%';
                strengthLabel.textContent = '';
                return;
            }
            const result = evaluatePassword(newPassword);
            strengthFill.style.width = result.percent + '%';
            strengthFill.style.background = result.color;
            strengthLabel.textContent = result.label;
            strengthLabel.style.color = result.color;
        });

        panel.querySelector('#btnAddUser').addEventListener('click', async (event) => {
            const addButton   = event.currentTarget;
            const username = panel.querySelector('#newUsername').value;
            const password = panel.querySelector('#newPassword').value;
            const role = panel.querySelector('#newRole').value;

            const contactValue = (id) => panel.querySelector(id)?.value ?? '';
            const first_name = contactValue('#newFirstName');
            const last_name  = contactValue('#newLastName');
            const email      = contactValue('#newEmail');
            const phone      = contactValue('#newPhone');

            if (!username || !password) {
                showStatusPill(addButton, 'Username and password are required.', 'error');
                return;
            }

            try {
                const request = await apiFetch('api.php?action=users_add', {
                    method: 'POST',
                    body: JSON.stringify({ username, password, role, first_name, last_name, email, phone })
                });
                const resultData = await request.json();

                if (resultData.status === 'success') {
                    showStatusPill(addButton, 'User created.', 'success');
                    renderManageUsers(panel, context);
                } else {
                    showStatusPill(addButton, resultData.error || 'Could not create the user.', 'error');
                }
            } catch (error) {
                showStatusPill(addButton, 'Network error.', 'error');
            }
        });
    } catch (event) {
        panel.innerHTML = `<h3 style="color:var(--error);">Network Error</h3><p>${escHtml(event.message)}</p>`;
    }
}

async function renderUserStatistics(panel) {
    panel.innerHTML = `<p>Loading statistics...</p>`;

    try {
        const response = await apiFetch('api.php?action=users_stats');
        const data = await response.json();

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
            data.recent.forEach(recentEntry => {
                html += `
                    <tr>
                        <td class="adm-td">${escHtml(recentEntry.action)}</td>
                        <td class="adm-td">${escHtml(recentEntry.username || '—')}</td>
                        <td class="adm-td">${escHtml(recentEntry.created_at)}</td>
                    </tr>
                `;
            });
        }

        html += `
                </tbody>
            </table>
        `;

        panel.innerHTML = html;
    } catch (event) {
        panel.innerHTML = `<h3 style="color:var(--error);">Network Error</h3><p>${escHtml(event.message)}</p>`;
    }
}

async function renderUserSettings(panel, context) {
    panel.innerHTML = `<p>Loading settings...</p>`;

    try {
        const response = await apiFetch('api.php?action=user_policy_get');
        const data = await response.json();

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

        panel.querySelector('#btnSaveUserPolicy').addEventListener('click', async (event) => {
            const saveButton = event.currentTarget;
            const min_password_length = parseInt(panel.querySelector('#policyMinPasswordLength').value, 10);
            const default_role = panel.querySelector('#policyDefaultRole').value;

            try {
                const request = await apiFetch('api.php?action=user_policy_save', {
                    method: 'POST',
                    body: JSON.stringify({ min_password_length, default_role })
                });
                const resultData = await request.json();

                if (resultData.status === 'success') {
                    showStatusPill(saveButton, 'Settings saved.', 'success');
                    renderUserSettings(panel, context);
                } else {
                    showStatusPill(saveButton, resultData.error || 'Save failed.', 'error');
                }
            } catch (error) {
                showStatusPill(saveButton, 'Network error.', 'error');
            }
        });
    } catch (event) {
        panel.innerHTML = `<h3 style="color:var(--error);">Network Error</h3><p>${escHtml(event.message)}</p>`;
    }
}
