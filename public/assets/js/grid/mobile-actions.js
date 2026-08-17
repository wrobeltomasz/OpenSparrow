// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

document.addEventListener("DOMContentLoaded", () => {
    const mobileActions = document.getElementById("mobileActions");
    const clickById = id => { const targetButton = document.getElementById(id); if (targetButton) targetButton.click(); };
    if (mobileActions) {
        mobileActions.addEventListener("change", event => {
            const action = event.target.value;
            if (action === "add") clickById("addRow");
            if (action === "export") clickById("exportCsv");
            if (action === "data-cleanup") clickById("dataCleanupBtn");
            if (action === "keyboard-help") clickById("kgHelpBtn");
            if (action === "refresh") location.reload();
            mobileActions.value = "";
        });
    }
});
