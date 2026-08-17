// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { getCsrfToken } from './csrf.js';

const ENDPOINT = 'api/clickstats.php';

const FLUSH_MS = 5000;

const MAX_BUFFER = 50;

const MAX_LABEL = 120;

const buffer = [];
let timer = null;

function findTarget(node) {
    if (!(node instanceof Element)) return null;
    return node.closest('[data-stat], button, a, .btn');
}

function labelFor(element) {
    const explicit = element.getAttribute('data-stat');
    if (explicit) return explicit.trim().slice(0, MAX_LABEL);

    if (element.id) return ('#' + element.id).slice(0, MAX_LABEL);

    const classOrTagName = (element.classList[0] || element.tagName).toLowerCase();
    const text = (element.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40);
    return (text ? `${classOrTagName}: ${text}` : classOrTagName).slice(0, MAX_LABEL);
}

function recordContext() {
    if (window.EDIT_TABLE) {
        return { table: String(window.EDIT_TABLE), id: Number(window.EDIT_ID) || null };
    }
    if (typeof window.CURRENT_GRID_TABLE === 'function') {
        const table = window.CURRENT_GRID_TABLE();
        if (table) return { table: String(table), id: null };
    }
    return { table: null, id: null };
}

function pageName() {
    const parts = window.location.pathname.split('/');
    return (parts[parts.length - 1] || 'index.php').slice(0, 120);
}

function flush() {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
    if (buffer.length === 0) return;

    const payload = JSON.stringify({
        csrf_token: getCsrfToken(),
        events: buffer.splice(0, buffer.length),
    });

    if (navigator.sendBeacon) {
        const blob = new Blob([payload], { type: 'application/json' });
        if (navigator.sendBeacon(ENDPOINT, blob)) return;
    }

    fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: payload,
        keepalive: true,
    }).catch(() => {  });
}

function schedule() {
    if (timer !== null) return;
    timer = setTimeout(flush, FLUSH_MS);
}

document.addEventListener('click', (event) => {
    const element = findTarget(event.target);
    if (!element) return;
    if (buffer.length >= MAX_BUFFER) return;

    const context = recordContext();
    buffer.push({
        element: labelFor(element),
        page: pageName(),
        table: context.table,
        record_id: context.id,
    });
    schedule();
}, { passive: true, capture: true });

window.addEventListener('pagehide', flush);
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush();
});
