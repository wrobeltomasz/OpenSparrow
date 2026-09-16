// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { escHtml } from '../../assets/js/util/esc.js';
import { buildInnerTabs, buildModal, buildSectionCard, createPageHeader, el, mkTable, mkThead, td, tdEl } from './ui.js';
import { showStatusPill } from './app.js';

export async function renderUsersEditor(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    wrap.appendChild(createPageHeader(
        'Users',
        'Manage accounts, roles and per-user frontend access. Roles: Admin (admin panel only), Editor (full frontend CRUD), Viewer (read-only frontend).'
    ));

    const [managePanel, accessPanel, statisticsPanel, settingsPanel] = buildInnerTabs(wrap, [
        { label: 'Manage Users', icon: 'material/user_attributes.svg' },
        { label: 'Access', icon: 'material/table_chart_view.svg' },
        { label: 'Statistics', icon: 'material/bar_chart.svg' },
        { label: 'Global Settings', icon: 'material/settings.svg' },
    ]);

    workspaceElement.appendChild(wrap);

    renderManageUsers(managePanel, context);
    renderUserAccess(accessPanel);
    renderUserStatistics(statisticsPanel);
    renderUserSettings(settingsPanel, context);
}

async function renderUserAccess(panel) {
    const content = el('div');
    panel.appendChild(content);
    content.innerHTML = '<p class="help-text">Loading users…</p>';

    let users;
    try {
        const response  = await apiFetch('api.php?action=users_list');
        const data = await response.json();
        if (data.status !== 'success') {
            content.innerHTML = `<p class="help-text">${escHtml(data.error || 'Failed to load users.')}</p>`;
            return;
        }
        users = data.users.filter(user => user.role !== 'admin');
    } catch (error) {
        content.innerHTML = '<p class="help-text">Network error while loading users.</p>';
        return;
    }

    content.innerHTML = '';

    const { card: accessCard, body: accessBody } = buildSectionCard(
        'Frontend Access',
        'Restrict a user to a subset of the frontend tables, views and printouts. Each group is independent, '
        + 'and ticking nothing in a group leaves that group unrestricted — which is not the same as revoking access. '
        + 'To cut someone off entirely, deactivate the account in Manage Users. Admin accounts are not listed: '
        + 'they work in this panel and always see everything.'
    );
    content.appendChild(accessCard);

    if (users.length === 0) {
        accessBody.innerHTML = '<p class="help-text">No non-admin users yet. Create one in Manage Users first.</p>';
        return;
    }

    const userLabel = el('label', 'adm-field-label', 'User');
    userLabel.htmlFor = 'taUser';

    const selectElement = el('select', 'adm-input w-260');
    selectElement.id = 'taUser';
    users.forEach(user => {
        const option = el('option', '', user.username + (user.is_active ? '' : ' (inactive)'));
        option.value = user.id;
        selectElement.appendChild(option);
    });

    accessBody.append(userLabel, selectElement);

    selectElement.addEventListener('change', () => loadUserAccess(content, accessCard, selectElement));
    loadUserAccess(content, accessCard, selectElement);
}

function renderScopeSection(panel, scope, allItems, selected, hiddenChildren = {}) {
    const names = Object.keys(allItems)
        .sort((left, right) => (allItems[left] || left).localeCompare(allItems[right] || right));

    const { card, body } = buildSectionCard(
        scope.title,
        names.length === 0 ? scope.empty : 'Tick items to restrict the user to them. Leave everything unticked for unrestricted access.'
    );
    panel.appendChild(card);

    if (names.length === 0) {
        return () => [];
    }

    const badge = el('div', 'ta-badge');
    badge.style.marginBottom = '10px';
    body.appendChild(badge);

    const tableWrap = el('div');
    tableWrap.style.cssText = 'overflow-x:auto;';
    body.appendChild(tableWrap);

    const tableElement = mkTable();
    mkThead(tableElement, ['Access', 'Display Name', 'Name']);
    const tbody = tableElement.createTBody();
    names.forEach(name => {
        const row = tbody.insertRow();
        const accessCell = el('td', 'adm-td');
        const box = el('input', 'adm-check ta-item');
        box.type = 'checkbox';
        box.value = name;
        box.checked = selected.has(name);
        accessCell.appendChild(box);
        const displayCell = el('td', 'adm-td');
        const strong = el('strong', '', allItems[name] || name);
        displayCell.appendChild(strong);
        const nameCell = el('td', 'adm-td');
        nameCell.appendChild(el('code', '', name));
        row.append(accessCell, displayCell, nameCell);
    });
    tableWrap.appendChild(tableElement);

    const buttonRow = el('div');
    buttonRow.style.cssText = 'display:flex; gap:8px; margin-top:10px; flex-wrap:wrap;';
    const allButton = el('button', 'btn btn-secondary btn-sm ta-all', 'Select All');
    const noneButton = el('button', 'btn btn-secondary btn-sm ta-none', 'Select None');
    buttonRow.append(allButton, noneButton);
    body.appendChild(buttonRow);

    const note = el('p', 'help-text ta-note');
    note.style.marginTop = '10px';
    body.appendChild(note);

    const boxes = Array.from(body.querySelectorAll('.ta-item'));

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

    allButton.addEventListener('click', () => {
        boxes.forEach(checkbox => { checkbox.checked = true; });
        refresh();
    });
    noneButton.addEventListener('click', () => {
        boxes.forEach(checkbox => { checkbox.checked = false; });
        refresh();
    });

    return () => boxes.filter(checkbox => checkbox.checked).map(checkbox => checkbox.value);
}

async function loadUserAccess(panel, anchorCard, selectElement) {
    const userId = selectElement.value;

    panel.querySelectorAll('.adm-sec-card').forEach(card => { if (card !== anchorCard) card.remove(); });
    panel.querySelectorAll('.users-access-save-row').forEach(row => row.remove());

    const statusLine = el('p', 'help-text', 'Loading…');
    anchorCard.after(statusLine);

    let data;
    try {
        const response = await apiFetch(`api.php?action=user_tables_get&user_id=${encodeURIComponent(userId)}`);
        data = await response.json();
    } catch (error) {
        statusLine.textContent = 'Network error while loading access.';
        return;
    }
    if (data.status !== 'success') {
        statusLine.textContent = data.error || 'Failed to load access.';
        return;
    }

    statusLine.remove();
    const scopes  = Array.isArray(data.scopes) ? data.scopes : [];
    const readers = {};
    scopes.forEach(scope => {
        readers[scope.key] = renderScopeSection(
            panel,
            scope,
            (data.items || {})[scope.key] || {},
            new Set((data.selected || {})[scope.key] || []),

            scope.key === 'tables' ? (data.hidden_children || {}) : {}
        );
    });

    const saveRow = el('div', 'users-access-save-row');
    saveRow.style.cssText = 'display:flex; align-items:center; gap:10px; margin-top:4px;';
    const saveElement = el('button', 'btn btn-success', 'Save Access');
    saveRow.appendChild(saveElement);
    panel.appendChild(saveRow);

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
    const content = el('div');
    panel.appendChild(content);
    content.innerHTML = '<p class="c-muted" style="padding:16px;">Loading users…</p>';

    let data;
    let policy;
    try {
        const [usersResult, policyResult] = await Promise.all([
            apiFetch('api.php?action=users_list'),
            apiFetch('api.php?action=user_policy_get'),
        ]);
        data = await usersResult.json();
        policy = await policyResult.json();
    } catch (_) {
        content.innerHTML = '';
        content.appendChild(el('p', '', 'Network error while loading users.')).style.color = 'var(--error)';
        return;
    }

    if (data.status !== 'success') {
        content.innerHTML = '';
        content.appendChild(el('p', '', data.error || 'Could not load users.')).style.color = 'var(--error)';
        return;
    }

    {

        const minPasswordLength = policy.status === 'success' ? policy.min_password_length : 12;
        const defaultRole = policy.status === 'success' ? policy.default_role : 'editor';

        const hasContact = data.contact_columns !== false;

        const listDescription = 'Manage user accounts and roles. Roles: Admin — admin panel only; Editor — full frontend CRUD; Viewer — read-only frontend.';
        const { card: listCard, body: listBody } = buildSectionCard('System Users', listDescription);
        content.appendChild(listCard);

        if (!hasContact) {
            const contactWarning = el('p', 'admin-page-desc');
            contactWarning.style.color = 'var(--error)';
            contactWarning.innerHTML = 'Contact details (name, email, phone) are unavailable: run '
                + 'Migrations &rarr; Initialize System Tables to apply the '
                + '<code>3.3_user_contact</code> migration.';
            listBody.appendChild(contactWarning);
        }

        const tableWrap = el('div');
        tableWrap.style.cssText = 'overflow-x:auto;';
        listBody.appendChild(tableWrap);

        const tableElement = mkTable();
        const headerColumns = ['ID', 'Username'];
        if (hasContact) headerColumns.push('Name', 'Email', 'Phone');
        headerColumns.push('Status', 'Role', 'Actions');
        mkThead(tableElement, headerColumns);
        const tbody = tableElement.createTBody();

        const cell = (cellValue) => (cellValue ?? '').trim()
            ? el('span', '', cellValue)
            : el('span', 'adm-td-empty');
        const fullName = (user) => [user.first_name ?? '', user.last_name ?? ''].join(' ').trim();

        data.users.forEach(user => {
            const row = tbody.insertRow();

            row.appendChild(td(user.id));
            const usernameCell = el('td', 'adm-td');
            usernameCell.appendChild(el('strong', '', user.username));
            row.appendChild(usernameCell);
            if (hasContact) {
                row.appendChild(tdEl(cell(fullName(user))));
                row.appendChild(tdEl(cell(user.email)));
                row.appendChild(tdEl(cell(user.phone)));
            }
            const statusCell = el('td', 'adm-td');
            statusCell.appendChild(el(
                'span',
                'adm-badge ' + (user.is_active ? 'adm-badge-ok' : 'adm-badge-danger'),
                user.is_active ? 'Active' : 'Inactive'
            ));
            row.appendChild(statusCell);

            const roleCell = el('td', 'adm-td');
            const roleSelect = el('select', 'select-user-role adm-input');
            roleSelect.dataset.id = user.id;
            [['admin', 'Admin'], ['editor', 'Editor'], ['viewer', 'Viewer']].forEach(([roleValue, roleLabel]) => {
                const option = el('option', '', roleLabel);
                option.value = roleValue;
                if ((user.role || 'editor') === roleValue) option.selected = true;
                roleSelect.appendChild(option);
            });
            roleCell.appendChild(roleSelect);
            row.appendChild(roleCell);

            const actionsCell = el('td', 'adm-td');
            actionsCell.style.cssText = 'display:flex; gap:6px; flex-wrap:wrap;';

            const toggleButton = el(
                'button',
                'btn btn-xs btn-toggle-user ' + (user.is_active ? 'btn-warning' : 'btn-secondary'),
                user.is_active ? 'Deactivate' : 'Activate'
            );
            toggleButton.dataset.id = user.id;
            toggleButton.dataset.active = String(user.is_active);
            actionsCell.appendChild(toggleButton);

            const passwordButton = el('button', 'btn btn-xs btn-secondary btn-change-pwd', 'Change pwd');
            passwordButton.dataset.id = user.id;
            passwordButton.dataset.username = user.username;
            actionsCell.appendChild(passwordButton);

            if (hasContact) {
                const contactButton = el('button', 'btn btn-xs btn-secondary btn-edit-contact', 'Edit Details');
                contactButton.dataset.id = user.id;
                contactButton.dataset.username = user.username;
                contactButton.dataset.firstName = user.first_name ?? '';
                contactButton.dataset.lastName = user.last_name ?? '';
                contactButton.dataset.email = user.email ?? '';
                contactButton.dataset.phone = user.phone ?? '';
                actionsCell.appendChild(contactButton);
            }

            row.appendChild(actionsCell);
        });

        tableWrap.appendChild(tableElement);

        const { card: addCard, body: addBody } = buildSectionCard(
            'Add New User',
            'Create a new account. The password policy below sets the minimum length; the strength meter updates as you type.'
        );
        content.appendChild(addCard);

        const addForm = el('div');
        addForm.style.maxWidth = '520px';
        addBody.appendChild(addForm);

        const addField = (labelText, inputElement) => {
            const group = el('div');
            group.style.marginBottom = '15px';
            group.appendChild(el('label', 'adm-field-label', labelText));
            inputElement.className = 'adm-input w-full';
            group.appendChild(inputElement);
            addForm.appendChild(group);
            return group;
        };

        const newUsername = el('input');
        newUsername.type = 'text';
        newUsername.id = 'newUsername';
        newUsername.placeholder = 'e.g. john_doe';
        addField('Username', newUsername);

        const newPassword = el('input');
        newPassword.type = 'password';
        newPassword.id = 'newPassword';
        newPassword.placeholder = `Minimum ${minPasswordLength} characters`;
        const passwordGroup = addField('Password', newPassword);

        const strengthBar = el('div');
        strengthBar.id = 'passwordStrengthBar';
        strengthBar.style.cssText = 'height: 6px; background: var(--accent-mid); border-radius: 3px; margin-top: 8px; overflow: hidden; max-width: 200px;';
        const strengthFill = el('div');
        strengthFill.id = 'passwordStrengthFill';
        strengthFill.style.cssText = 'height: 100%; width: 0%; transition: width 0.3s, background 0.3s;';
        strengthBar.appendChild(strengthFill);
        passwordGroup.appendChild(strengthBar);

        const strengthLabel = el('small');
        strengthLabel.id = 'passwordStrengthLabel';
        strengthLabel.style.cssText = 'display: block; margin-top: 4px;';
        passwordGroup.appendChild(strengthLabel);

        const newRole = el('select');
        newRole.id = 'newRole';
        [['editor', 'Editor'], ['viewer', 'Viewer'], ['admin', 'Admin']].forEach(([roleValue, roleLabel]) => {
            const option = el('option', '', roleLabel);
            option.value = roleValue;
            if (defaultRole === roleValue) option.selected = true;
            newRole.appendChild(option);
        });
        addField('Role', newRole);

        if (hasContact) {
            const newFirstName = el('input');
            newFirstName.type = 'text';
            newFirstName.id = 'newFirstName';
            newFirstName.maxLength = 100;
            addField('First Name (Optional)', newFirstName);

            const newLastName = el('input');
            newLastName.type = 'text';
            newLastName.id = 'newLastName';
            newLastName.maxLength = 100;
            addField('Last Name (Optional)', newLastName);

            const newEmail = el('input');
            newEmail.type = 'email';
            newEmail.id = 'newEmail';
            newEmail.maxLength = 255;
            addField('Email (Optional)', newEmail);

            const newPhone = el('input');
            newPhone.type = 'text';
            newPhone.id = 'newPhone';
            newPhone.maxLength = 32;
            addField('Phone (Optional)', newPhone);
        }

        const addButton = el('button', 'btn btn-success', 'Create User');
        addButton.id = 'btnAddUser';
        addForm.appendChild(addButton);

        content.querySelectorAll('.btn-toggle-user').forEach(button => {
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

        content.querySelectorAll('.select-user-role').forEach(select => {
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

        content.querySelectorAll('.btn-change-pwd').forEach(button => {
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

        content.querySelectorAll('.btn-edit-contact').forEach(button => {
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

        const passwordInput = content.querySelector('#newPassword');

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

        content.querySelector('#btnAddUser').addEventListener('click', async (event) => {
            const addButton   = event.currentTarget;
            const username = content.querySelector('#newUsername').value;
            const password = content.querySelector('#newPassword').value;
            const role = content.querySelector('#newRole').value;

            const contactValue = (id) => content.querySelector(id)?.value ?? '';
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
    }
}

async function renderUserStatistics(panel) {
    const content = el('div');
    panel.appendChild(content);
    content.innerHTML = '<p class="c-muted" style="padding:16px;">Loading statistics…</p>';

    let data;
    try {
        const response = await apiFetch('api.php?action=users_stats');
        data = await response.json();
    } catch (_) {
        content.innerHTML = '';
        content.appendChild(el('p', '', 'Network error while loading statistics.')).style.color = 'var(--error)';
        return;
    }
    if (data.status !== 'success') {
        content.innerHTML = '';
        content.appendChild(el('p', '', data.error || 'Could not load statistics.')).style.color = 'var(--error)';
        return;
    }

    content.innerHTML = '';

    const { card: summaryCard, body: summaryBody } = buildSectionCard(
        'User Statistics',
        'Aggregated account metrics across the whole system.'
    );
    content.appendChild(summaryCard);

    const cardsGrid = el('div');
    cardsGrid.style.cssText = 'display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:4px;';
    summaryBody.appendChild(cardsGrid);

    [
        ['Total Users', String(data.total ?? 0)],
        ['Active', String(data.active ?? 0)],
        ['Inactive', String(data.inactive ?? 0)],
    ].forEach(([label, value]) => {
        const box = el('div');
        box.style.cssText = 'text-align:center;padding:16px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg);';
        const valueEntry = el('div', '', value);
        valueEntry.style.cssText = 'font-weight:var(--font-weight-bold);margin-bottom:4px;';
        const labelDiv = el('div', '', label);
        labelDiv.style.cssText = 'font-weight:var(--font-weight-bold);';
        box.append(valueEntry, labelDiv);
        cardsGrid.appendChild(box);
    });

    const { card: roleCard, body: roleBody } = buildSectionCard(
        'Users By Role',
        'How the accounts are distributed across the three roles.'
    );
    content.appendChild(roleCard);

    const roleWrap = el('div');
    roleWrap.style.cssText = 'overflow-x:auto;';
    roleBody.appendChild(roleWrap);

    const roleTable = mkTable();
    mkThead(roleTable, ['Role', 'Count']);
    const roleTbody = roleTable.createTBody();
    const byRole = data.by_role ?? {};
    [['admin', 'Admin'], ['editor', 'Editor'], ['viewer', 'Viewer']].forEach(([roleKey, roleLabel]) => {
        const row = roleTbody.insertRow();
        row.appendChild(td(roleLabel));
        row.appendChild(td(byRole[roleKey] ?? 0));
    });
    roleWrap.appendChild(roleTable);

    const { card: recentCard, body: recentBody } = buildSectionCard(
        'Recent User Activity',
        'Latest account changes, newest first.'
    );
    content.appendChild(recentCard);

    const recentWrap = el('div');
    recentWrap.style.cssText = 'overflow-x:auto;';
    recentBody.appendChild(recentWrap);

    const recentTable = mkTable();
    mkThead(recentTable, ['Action', 'By', 'When']);
    const recentTbody = recentTable.createTBody();
    const recentRows = data.recent ?? [];
    if (recentRows.length === 0) {
        const row = recentTbody.insertRow();
        const emptyCell = row.insertCell();
        emptyCell.colSpan = 3;
        emptyCell.textContent = 'No recent activity.';
        emptyCell.style.cssText = 'padding:16px;text-align:center;font-style:italic;';
    } else {
        recentRows.forEach(recentEntry => {
            const row = recentTbody.insertRow();
            row.appendChild(td(recentEntry.action));
            row.appendChild(td(recentEntry.username || '—'));
            row.appendChild(td(recentEntry.created_at));
        });
    }
    recentWrap.appendChild(recentTable);
}

async function renderUserSettings(panel, context) {
    const content = el('div');
    panel.appendChild(content);
    content.innerHTML = '<p class="c-muted" style="padding:16px;">Loading settings…</p>';

    let data;
    try {
        const response = await apiFetch('api.php?action=user_policy_get');
        data = await response.json();
    } catch (_) {
        content.innerHTML = '';
        content.appendChild(el('p', '', 'Network error while loading settings.')).style.color = 'var(--error)';
        return;
    }
    if (data.status !== 'success') {
        content.innerHTML = '';
        content.appendChild(el('p', '', data.error || 'Could not load settings.')).style.color = 'var(--error)';
        return;
    }

    content.innerHTML = '';

    const { card: policyCard, body: policyBody } = buildSectionCard(
        'Global User Settings',
        'Policy applied to new users and password changes across the whole system.'
    );
    content.appendChild(policyCard);

    const policyForm = el('div');
    policyForm.style.maxWidth = '400px';
    policyBody.appendChild(policyForm);

    const minLengthLabel = el('label', 'adm-field-label', 'Minimum password length');
    minLengthLabel.htmlFor = 'policyMinPasswordLength';
    policyForm.appendChild(minLengthLabel);

    const minLengthInput = el('input', 'adm-input');
    minLengthInput.type = 'number';
    minLengthInput.id = 'policyMinPasswordLength';
    minLengthInput.style.width = '100%';
    minLengthInput.min = data.password_min_length ?? 0;
    minLengthInput.step = '1';
    minLengthInput.value = data.min_password_length ?? 12;
    minLengthInput.style.marginBottom = '15px';
    policyForm.appendChild(minLengthInput);

    const defaultRoleLabel = el('label', 'adm-field-label', 'Default role for new users');
    defaultRoleLabel.htmlFor = 'policyDefaultRole';
    policyForm.appendChild(defaultRoleLabel);

    const defaultRoleSelect = el('select', 'adm-input');
    defaultRoleSelect.id = 'policyDefaultRole';
    defaultRoleSelect.style.width = '100%';
    [['editor', 'Editor'], ['viewer', 'Viewer'], ['admin', 'Admin']].forEach(([roleValue, roleLabel]) => {
        const option = el('option', '', roleLabel);
        option.value = roleValue;
        if ((data.default_role ?? 'editor') === roleValue) option.selected = true;
        defaultRoleSelect.appendChild(option);
    });
    defaultRoleSelect.style.marginBottom = '15px';
    policyForm.appendChild(defaultRoleSelect);

    const saveButton = el('button', 'btn btn-save', 'Save');
    saveButton.id = 'btnSaveUserPolicy';
    policyForm.appendChild(saveButton);

    saveButton.addEventListener('click', async () => {
        const min_password_length = parseInt(minLengthInput.value, 10);
        const default_role = defaultRoleSelect.value;

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
}
