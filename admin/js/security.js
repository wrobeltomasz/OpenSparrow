// admin/security.js

export function renderSecurityEditor(key, itemData, isArray, ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = `
        <h3>Security Settings</h3>
        <p style="color:#64748B; font-size:14px; max-width:480px;">
            To change your password or another user's password, go to
            <strong>System &rarr; Users</strong> and click <strong>Change pwd</strong>
            next to the relevant account.
        </p>`;
}
