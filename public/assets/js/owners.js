// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from './i18n.js';
import { apiFetch } from './util/api.js';

const table    = window.EDIT_TABLE;
const recordId = window.EDIT_ID;
const userRole = window.USER_ROLE;
const csrf     = window.CSRF_TOKEN;

const panel     = document.getElementById('ow-panel');
const current   = document.getElementById('ow-current');
const changeElement  = document.getElementById('ow-change');
const select    = document.getElementById('ow-select');
const saveButton   = document.getElementById('ow-save');
const status    = document.getElementById('ow-status');
const historyElement = document.getElementById('ow-history-body');

let hasOwner = false;

async function loadOwner() {
    try {
        const result  = await fetch(`api/owners.php?action=get&table=${encodeURIComponent(table)}&id=${encodeURIComponent(recordId)}`);
        const data = await result.json();
        if (!data.success) { current.textContent = I18n.t('owners.error_load'); return; }

        if (!data.owner || data.owner.id === null) {
            current.textContent = I18n.t('owners.no_owner');
            hasOwner = false;
        } else {
            hasOwner = true;
            let label = data.owner.username;
            if (data.owner.changed_at) {
                const d = new Date(data.owner.changed_at);
                label += ' ' + I18n.t('owners.last_changed', { date: d.toLocaleDateString() });
            }
            current.textContent = label;
        }

        if (saveButton) {
            saveButton.textContent = hasOwner ? I18n.t('owners.change_owner') : I18n.t('owners.assign_owner');
        }
    } catch {
        current.textContent = I18n.t('owners.error_load');
    }
}

async function loadHistory() {
    if (!historyElement) return;
    try {
        const result  = await fetch(`api/owners.php?action=history&table=${encodeURIComponent(table)}&id=${encodeURIComponent(recordId)}`);
        const data = await result.json();
        if (!data.success) { historyElement.textContent = I18n.t('owners.error_history'); return; }

        if (!data.history.length) {
            historyElement.textContent = I18n.t('owners.no_history');
            return;
        }

        const table_ = document.createElement('table');
        table_.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;';

        const thead = table_.createTHead();
        const hrow  = thead.insertRow();
        [I18n.t('owners.col_owner'), I18n.t('owners.col_changed_by'), I18n.t('owners.col_date')].forEach(h => {
            const th = document.createElement('th');
            th.textContent = h;
            th.style.cssText = 'text-align:left;padding:8px 10px;border-bottom:2px solid var(--border-light);color:var(--muted);font-weight:600;';
            hrow.appendChild(th);
        });

        const tbody = table_.createTBody();
        data.history.forEach(row => {
            const tr = tbody.insertRow();
            tr.style.borderBottom = '1px solid var(--border-light)';
            [
                row.username       || '—',
                row.changed_by_name || '—',
                row.changed_at ? new Date(row.changed_at).toLocaleString() : '—',
            ].forEach(val => {
                const td = tr.insertCell();
                td.textContent = val;
                td.style.padding = '8px 10px';
            });
        });

        historyElement.textContent = '';
        historyElement.appendChild(table_);
    } catch {
        historyElement.textContent = I18n.t('owners.error_history');
    }
}

async function loadEditors() {
    const result  = await fetch('api/owners.php?action=editors');
    const data = await result.json();
    if (!data.success) return;

    select.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = I18n.t('owners.select_user');
    placeholder.disabled = true;
    placeholder.selected = true;
    select.appendChild(placeholder);

    data.users.forEach(u => {
        const option       = document.createElement('option');
        option.value       = u.id;
        option.textContent = u.username;
        select.appendChild(option);
    });
}

async function saveOwner() {
    const ownerId = parseInt(select.value, 10);
    if (!ownerId) {
        status.textContent = I18n.t('owners.select_first');
        status.style.color = 'var(--error)';
        return;
    }

    saveButton.disabled   = true;
    status.textContent = '';

    try {
        const result  = await apiFetch('api/owners.php', {
            method: 'POST',
            body: { action: 'set', table, record_id: recordId, owner_id: ownerId, csrf_token: csrf },
        });
        const data = await result.json();
        if (data.success) {
            status.textContent = I18n.t('owners.saved');
            status.style.color = 'var(--ok)';
            await loadOwner();
            await loadHistory();
        } else {
            status.textContent = data.error || I18n.t('owners.error_save');
            status.style.color = 'var(--error)';
        }
    } catch {
        status.textContent = I18n.t('owners.network_error');
        status.style.color = 'var(--error)';
    } finally {
        saveButton.disabled = false;
    }
}

async function init() {
    if (!panel) return;

    await I18n.load();
    await loadOwner();
    await loadHistory();

    if (userRole === 'editor' || userRole === 'admin') {
        await loadEditors();
        changeElement.hidden = false;
        changeElement.style.display = 'flex';
        saveButton.addEventListener('click', saveOwner);
    }
}

document.addEventListener('DOMContentLoaded', init);
