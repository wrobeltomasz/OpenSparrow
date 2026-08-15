// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { mkTable, mkThead, td, tdStatus, tdError } from './ui.js';

export function mkStatus() {
    const el = document.createElement('p');
    el.style.cssText = 'margin-top:10px; display:none;';
    return el;
}

export function showStatus(el, msg, ok) {
    el.textContent = msg;
    el.style.color = ok ? 'var(--ok)' : 'var(--error)';
    el.style.display = '';
}

export function fg(label, node) {
    const g = document.createElement('div');
    g.className = 'form-group';
    const l = document.createElement('label');
    l.textContent = label;
    g.append(l, node);
    return g;
}

export function input(value, type = 'text') {
    const i = document.createElement('input');
    i.type = type;
    i.className = 'adm-input';
    i.value = value ?? '';
    return i;
}

export function checkbox(labelText, checked, onChange) {
    const box = input('', 'checkbox');
    box.className = 'adm-check';
    box.checked = checked;
    box.onchange = () => onChange(box.checked);
    const lbl = document.createElement('label');
    lbl.style.cssText = 'display:flex; align-items:center; gap:8px;';
    lbl.append(box, document.createTextNode(labelText));
    return { input: box, label: lbl };
}

export function buildCollapsibleCard({ titleText, placeholder = '(unnamed)', onDelete, confirmMsg }) {
    const card = document.createElement('div');
    card.className = 'column-block collapsed';

    const hdr = document.createElement('div');
    hdr.className = 'block-header';
    const chevron = document.createElement('span');
    chevron.className = 'block-chevron';
    chevron.textContent = '▶';
    const title = document.createElement('strong');
    title.className = 'block-title';
    title.textContent = titleText || placeholder;

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'icon-btn icon-btn-danger';
    del.title = 'Delete';
    del.textContent = '✕';
    del.onclick = (e) => {
        e.stopPropagation();
        if (confirmMsg && !confirm(confirmMsg)) return;
        onDelete();
    };
    hdr.append(chevron, title, del);
    hdr.onclick = (e) => { if (!e.target.closest('button')) card.classList.toggle('collapsed'); };

    const body = document.createElement('div');
    body.className = 'block-body';
    card.append(hdr, body);
    return { card, body, title };
}

export function buildHistoryTable(headers, rows, rowFn) {
    const tbl = mkTable();
    mkThead(tbl, headers);

    const statusCell = tdStatus;
    const errorCell  = tdError;

    const tbody = tbl.createTBody();
    rows.forEach(r => {
        const tr = tbody.insertRow();
        rowFn(r, { td, statusCell, errorCell }).forEach(cell => tr.appendChild(cell));
    });

    const wrap = document.createElement('div');
    wrap.style.overflowX = 'auto';
    wrap.appendChild(tbl);
    return wrap;
}

export async function persistConfig(action, payload) {
    try {
        const res  = await apiFetch('api.php?action=' + action, {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.status === 'success') {
            return { ok: true, version: data.version };
        }
        return { ok: false, error: data.error || 'Save failed.' };
    } catch (_) {
        return { ok: false, error: 'Network error while saving.' };
    }
}

export async function runCronAction(action, body, out) {
    out.style.display = '';
    out.textContent = 'Running…';
    try {
        const res  = await apiFetch('api.php?action=' + action, {
            method: 'POST',
            body: JSON.stringify(body),
        });
        const data = await res.json();
        out.textContent = data.output || data.error || 'No output.';
    } catch (_) {
        out.textContent = 'Network error.';
    }
}
