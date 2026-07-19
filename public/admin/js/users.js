// admin/js/users.js — User management (renderUsersEditor): list/add/toggle/change-role/change-password via api.php (users_* actions). CSRF via apiFetch(); HTML-escapes output.

import { apiFetch } from '../../assets/js/util/api.js';
import { escHtml } from '../../assets/js/util/esc.js';


export async function renderUsersEditor(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = `<h3>System Users</h3><p>Loading users...</p>`;
    
    try {
        const res = await apiFetch('api.php?action=users_list');
        const data = await res.json();
        
        if (data.status !== 'success') {
            workspaceEl.innerHTML = `<h3 style="color:var(--danger);">Error</h3><p>${escHtml(data.error)}</p>`;
            return;
        }
        
        let html = `
            <h2 class="admin-page-title">System Users Management</h2>
            <p class="admin-page-desc">
                Manage user accounts and roles. Roles: <strong>Admin</strong> – admin panel only; <strong>Editor</strong> – full frontend CRUD; <strong>Viewer</strong> – read-only frontend.
            </p>
            <table class="adm-tbl" style="margin-bottom: 30px;">
                <thead>
                    <tr>
                        <th class="adm-th">ID</th>
                        <th class="adm-th">Username</th>
                        <th class="adm-th">Status</th>
                        <th class="adm-th">Role</th>
                        <th class="adm-th">Actions</th>
                    </tr>
                </thead>
                <tbody>
        `;

        data.users.forEach(u => {
            html += `
                <tr>
                    <td class="adm-td">${escHtml(u.id)}</td>
                    <td class="adm-td"><strong>${escHtml(u.username)}</strong></td>
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
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Username</label>
                    <input type="text" id="newUsername" placeholder="e.g. john_doe" class="adm-input" style="width:100%;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Password</label>
                    <input type="password" id="newPassword" placeholder="Minimum 6 characters" class="adm-input" style="width:100%;">
                    <div id="passwordStrengthBar" style="height: 6px; background: var(--accent-mid); border-radius: 3px; margin-top: 8px; overflow: hidden; max-width: 200px;">
                        <div id="passwordStrengthFill" style="height: 100%; width: 0%; transition: width 0.3s, background 0.3s;"></div>
                    </div>
                    <small id="passwordStrengthLabel" style=" display: block; margin-top: 4px;"></small>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Role</label>
                    <select id="newRole" class="adm-input" style="width:100%;">
                        <option value="editor" selected>Editor</option>
                        <option value="viewer">Viewer</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <button id="btnAddUser" class="btn btn-success">Create User</button>
            </div>
        `;
        
        workspaceEl.innerHTML = html;
        
        // Setup toggle active status events
        workspaceEl.querySelectorAll('.btn-toggle-user').forEach(btn => {
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
                        renderUsersEditor(ctx);
                    } else {
                        alert('Error: ' + resData.error);
                    }
                } catch (err) {
                    alert('Network error occurred.');
                }
            });
        });

        // Setup role change events
        workspaceEl.querySelectorAll('.select-user-role').forEach(select => {
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
                        alert('Error: ' + resData.error);
                        renderUsersEditor(ctx); 
                    }
                } catch (err) {
                    alert('Network error occurred.');
                    renderUsersEditor(ctx); 
                }
            });
        });

        // Change password for existing user
        const currentUserId = parseInt(document.querySelector('meta[name="current-user-id"]')?.content ?? '0', 10);

        workspaceEl.querySelectorAll('.btn-change-pwd').forEach(btn => {
            btn.addEventListener('click', () => {
                const id       = parseInt(btn.getAttribute('data-id'), 10);
                const username = btn.getAttribute('data-username');
                const isSelf   = id === currentUserId;

                const overlay = document.createElement('div');
                overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;';

                const box = document.createElement('div');
                box.style.cssText = 'background:#fff;border-radius:10px;padding:28px 24px;width:340px;box-shadow:0 8px 24px rgba(0,0,0,.2);';

                const h3 = document.createElement('h3');
                h3.style.cssText = 'margin:0 0 4px;';
                h3.textContent = 'Change password';
                box.appendChild(h3);

                const userP = document.createElement('p');
                userP.style.cssText = 'margin:0 0 16px;';
                userP.textContent = 'User: ';
                const userStrong = document.createElement('strong');
                userStrong.textContent = username;
                userP.appendChild(userStrong);
                box.appendChild(userP);

                if (isSelf) {
                    const currentInput = document.createElement('input');
                    currentInput.type = 'password';
                    currentInput.id = 'cpw-current';
                    currentInput.placeholder = 'Current password';
                    currentInput.className = 'adm-input w-full';
                    currentInput.style.marginBottom = '8px';
                    box.appendChild(currentInput);
                }

                const newInput = document.createElement('input');
                newInput.type = 'password';
                newInput.id = 'cpw-new';
                newInput.placeholder = 'New password (min 8 chars)';
                newInput.className = 'adm-input w-full';
                newInput.style.marginBottom = '8px';
                box.appendChild(newInput);

                const confirmInput = document.createElement('input');
                confirmInput.type = 'password';
                confirmInput.id = 'cpw-confirm';
                confirmInput.placeholder = 'Confirm new password';
                confirmInput.className = 'adm-input w-full';
                confirmInput.style.marginBottom = '12px';
                box.appendChild(confirmInput);

                const msgEl = document.createElement('p');
                msgEl.id = 'cpw-msg';
                msgEl.style.cssText = 'min-height:18px;margin:0 0 12px;';
                box.appendChild(msgEl);

                const buttonDiv = document.createElement('div');
                buttonDiv.style.cssText = 'display:flex;gap:8px;justify-content:flex-end;';

                const cancelBtn = document.createElement('button');
                cancelBtn.id = 'cpw-cancel';
                cancelBtn.textContent = 'Cancel';
                cancelBtn.className = 'btn btn-secondary';
                buttonDiv.appendChild(cancelBtn);

                const saveBtn = document.createElement('button');
                saveBtn.id = 'cpw-save';
                saveBtn.textContent = 'Save';
                saveBtn.className = 'btn btn-primary btn-sm';
                buttonDiv.appendChild(saveBtn);

                box.appendChild(buttonDiv);
                overlay.appendChild(box);
                document.body.appendChild(overlay);

                (box.querySelector('#cpw-current') ?? newInput).focus();

                box.querySelector('#cpw-cancel').addEventListener('click', () => overlay.remove());
                overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

                box.querySelector('#cpw-save').addEventListener('click', async () => {
                    const pwd     = newInput.value;
                    const confirm = box.querySelector('#cpw-confirm').value;
                    if (isSelf && !box.querySelector('#cpw-current').value) {
                        msgEl.style.color = 'var(--danger)';
                        msgEl.textContent = 'Current password is required.';
                        return;
                    }
                    if (pwd.length < 8) {
                        msgEl.style.color = 'var(--danger)';
                        msgEl.textContent = 'Password must be at least 8 characters.';
                        return;
                    }
                    if (pwd !== confirm) {
                        msgEl.style.color = 'var(--danger)';
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
                            if (data.ok) { overlay.remove(); return; }
                        } else {
                            // Other user — admin override, no current password check
                            res  = await apiFetch('api.php?action=users_change_password', {
                                method: 'POST',
                                body: JSON.stringify({ id, password: pwd }),
                            });
                            data = await res.json();
                            if (data.status === 'success') { overlay.remove(); return; }
                        }
                        msgEl.style.color = 'var(--danger)';
                        msgEl.textContent = data.error || 'Error saving password.';
                    } catch {
                        msgEl.style.color = 'var(--danger)';
                        msgEl.textContent = 'Network error.';
                    }
                });
            });
        });

        // Password strength indicator
        const passwordInput = document.getElementById('newPassword');
        const strengthFill = document.getElementById('passwordStrengthFill');
        const strengthLabel = document.getElementById('passwordStrengthLabel');
        
        function evaluatePassword(pwd) {
            let score = 0;
            if (pwd.length >= 6) score++;
            if (pwd.length >= 8) score++;
            if (pwd.length >= 10) score++;
            if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) score++;
            if (/\d/.test(pwd)) score++;
            if (/[^a-zA-Z0-9]/.test(pwd)) score++;
            
            if (pwd.length < 6) return { level: 'weak', percent: 25, label: 'Too short', color: 'var(--danger)' };
            if (score <= 2) return { level: 'weak', percent: 25, label: 'Weak', color: 'var(--danger)' };
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
        document.getElementById('btnAddUser').addEventListener('click', async () => {
            const username = document.getElementById('newUsername').value;
            const password = document.getElementById('newPassword').value;
            const role = document.getElementById('newRole').value;

            if (!username || !password) {
                alert('Username and password are required!');
                return;
            }

            try {
                const req = await apiFetch('api.php?action=users_add', {
                    method: 'POST',
                    body: JSON.stringify({ username, password, role })
                });
                const resData = await req.json();

                if (resData.status === 'success') {
                    alert('User created successfully!');
                    renderUsersEditor(ctx);
                } else {
                    alert('Error: ' + resData.error);
                }
            } catch (err) {
                alert('Network error occurred.');
            }
        });

    } catch (e) {
        workspaceEl.innerHTML = `<h3 style="color:var(--danger);">Network Error</h3><p>${e.message}</p>`;
    }
}