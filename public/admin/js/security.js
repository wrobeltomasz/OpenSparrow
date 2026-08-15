// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export function renderSecurityEditor(key, itemData, isArray, ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = `
        <h3>Security Settings</h3>
        <p style="color:var(--muted);  max-width:480px;">
            To change your password or another user's password, go to
            <strong>System &rarr; Users</strong> and click <strong>Change pwd</strong>
            next to the relevant account.
        </p>`;
}
