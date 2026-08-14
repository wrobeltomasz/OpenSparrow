// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.
//
// util/clickstats.js — click statistics collector (Admin → System → Click Statistics).
//
// This module is only ever loaded when the module is enabled: templates/layout.php
// emits the <script> tag conditionally, so with the feature off the browser never
// fetches this file, no listener exists and no request is made. Keep it that way —
// do not import it from another module, or it becomes unconditional.
//
// One delegated listener buffers clicks in memory and flushes them in batches via
// navigator.sendBeacon, so a session of clicking costs a handful of requests rather
// than one per click.

import { getCsrfToken } from './csrf.js';

// Delivery endpoint, relative to the page (every app page lives in public/).
const ENDPOINT = 'api/clickstats.php';

// Flush cadence. Long enough that a burst of clicks travels in one request.
const FLUSH_MS = 5000;

// Hard cap on the buffer. A runaway page (or a user hammering a button) must not
// grow the array without bound; extra events past this are dropped, not queued.
// Must not exceed the per-request limit the endpoint enforces.
const MAX_BUFFER = 50;

// Label length must match the element column width (varchar(120)); the server
// truncates too, this only avoids sending bytes that will be cut.
const MAX_LABEL = 120;

const buffer = [];
let timer = null;

// The clickable ancestor of the click target, if any. [data-stat] comes first so
// an explicitly tagged wrapper wins over a plain <button> inside it.
function findTarget(node) {
    if (!(node instanceof Element)) return null;
    return node.closest('[data-stat], button, a, .btn');
}

// Element label, in order of preference: the explicit data-stat annotation, the
// element id, then a derived class+text label. Never reads input values, and the
// page name is stored without its query string, so nothing the user typed is
// recorded.
//
// The last branch is the one to keep in mind: it stores the element's own visible
// text, and on a list of records that text IS record content — a file name on
// files.php, a record title in a row link. That is unavoidable for a label meant
// to be readable, and it does not depend on the "Record Table And Record" switch,
// which only governs the separate table/record_id columns. Annotate an element
// with data-stat to pin exactly what gets stored for it.
function labelFor(el) {
    const explicit = el.getAttribute('data-stat');
    if (explicit) return explicit.trim().slice(0, MAX_LABEL);

    if (el.id) return ('#' + el.id).slice(0, MAX_LABEL);

    const cls = (el.classList[0] || el.tagName).toLowerCase();
    const text = (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40);
    return (text ? `${cls}: ${text}` : cls).slice(0, MAX_LABEL);
}

// Record in context, read from the globals the pages already publish:
// edit.php sets EDIT_TABLE/EDIT_ID, the grid exposes CURRENT_GRID_TABLE().
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

// Page identity: the script name only, so the log never stores query strings
// (which can carry search terms and record ids the user typed).
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

    // sendBeacon survives the page being unloaded, which a normal fetch does not.
    // It sends no headers, so the CSRF token travels in the body and the endpoint
    // validates it from there.
    if (navigator.sendBeacon) {
        const blob = new Blob([payload], { type: 'application/json' });
        if (navigator.sendBeacon(ENDPOINT, blob)) return;
    }
    // Fallback for browsers without sendBeacon (and for a beacon the browser
    // refused to queue). keepalive lets it outlive the page too.
    fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: payload,
        keepalive: true,
    }).catch(() => { /* statistics must never surface an error to the user */ });
}

function schedule() {
    if (timer !== null) return;
    timer = setTimeout(flush, FLUSH_MS);
}

document.addEventListener('click', (e) => {
    const el = findTarget(e.target);
    if (!el) return;
    if (buffer.length >= MAX_BUFFER) return;

    const ctx = recordContext();
    buffer.push({
        element: labelFor(el),
        page: pageName(),
        table: ctx.table,
        record_id: ctx.id,
    });
    schedule();
}, { passive: true, capture: true });

// Deliver what is buffered before the page goes away. pagehide covers navigation
// and the back/forward cache; visibilitychange covers tab switches on mobile,
// where pagehide is not guaranteed to fire.
window.addEventListener('pagehide', flush);
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush();
});
