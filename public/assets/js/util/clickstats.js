// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { getCsrfToken } from './csrf.js';

const ENDPOINT = 'api/clickstats.php';

const MAX_LABEL = 120;

function pageName() {
    const parts = window.location.pathname.split('/');
    return (parts[parts.length - 1] || 'index.php').slice(0, MAX_LABEL);
}

function recordContext() {
    if (window.EDIT_TABLE) {
        return { table: String(window.EDIT_TABLE), id: Number(window.EDIT_ID) || null };
    }
    if (typeof window.CURRENT_GRID_TABLE === 'function') {
        const table = window.CURRENT_GRID_TABLE();
        if (table) return { table: String(table), id: null };
    }
    const urlTable = new URLSearchParams(window.location.search).get('table');
    if (urlTable) return { table: String(urlTable), id: null };
    return { table: null, id: null };
}

const page = pageName();
const context = recordContext();

const payload = JSON.stringify({
    csrf_token: getCsrfToken(),
    events: [{ element: page, page, table: context.table, record_id: context.id }],
});

function send() {
    if (navigator.sendBeacon) {
        const blob = new Blob([payload], { type: 'application/json' });
        if (navigator.sendBeacon(ENDPOINT, blob)) return;
    }
    fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: payload,
        keepalive: true,
    }).catch(() => { });
}

send();
