// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { el, mkTable, mkThead, td, tdEl, buildSectionCard, buildModal } from './ui.js';

let shareConfig = null;
let shareVersion = 0;
let schemaTables = [];

const generatedUrls = new Map();

async function loadSchemaTables() {
    try {
        const response = await apiFetch('api.php?action=get&file=schema');
        const schemaData = await response.json();
        const schema = schemaData.config ?? schemaData;
        schemaTables = Object.entries(schema?.tables ?? {})
            .filter(([, tableConfig]) =>
                !tableConfig?.hidden && !tableConfig?.owner_restricted)
            .map(([name, tableConfig]) => ({
                name,
                label: tableConfig.display_name || name,
            }))
            .sort((left, right) => left.label.localeCompare(right.label));
    } catch (_) {
        schemaTables = [];
    }
}

async function loadSharingConfig() {
    const response = await apiFetch('api.php?action=sharing_load');
    const data = await response.json();
    if (data.status === 'success') {
        shareConfig = data.tables || [];
        shareVersion = data.version ?? 0;
        return true;
    }
    return false;
}

async function saveSharingConfig(payloadTables, statusElement) {
    try {
        const response = await apiFetch('api.php?action=sharing_save', {
            method: 'POST',
            body: JSON.stringify({ tables: payloadTables, version: shareVersion }),
        });
        const data = await response.json();
        if (data.status === 'success') {
            shareVersion = data.version ?? shareVersion;
            const tokens = data.generated_tokens || {};
            for (const [tableName, token] of Object.entries(tokens)) {
                generatedUrls.set(tableName, buildShareUrl(token));
            }
            if (statusElement) statusElement.textContent = 'Saved.';
            return data;
        }
        if (statusElement) statusElement.textContent = data.error || 'Save failed.';
        return null;
    } catch (_) {
        if (statusElement) statusElement.textContent = 'Network error while saving.';
        return null;
    }
}

function buildShareUrl(token) {
    const base = window.location.origin + window.location.pathname.replace(/\/admin\/.*$/, '/');
    return `${base}share.php?t=${token}`;
}

function showGeneratedModal(tableName) {
    const url = generatedUrls.get(tableName);
    if (!url) return;

    const { body, saveBtn, close } = buildModal({
        title: `Public link for "${tableName}"`,
        saveLabel: 'Done',
    });

    const urlRow = el('div', 'adm-field-grid');
    urlRow.style.cssText = 'display:flex; gap:8px; align-items:center; margin-bottom:10px;';
    const urlInput = document.createElement('input');
    urlInput.type = 'text';
    urlInput.readOnly = true;
    urlInput.value = url;
    urlInput.style.cssText = 'flex:1; font-family:ui-monospace,Consolas,monospace; font-size:12.5px;';
    const copyButton = el('button', 'btn btn-sm', 'Copy');
    copyButton.addEventListener('click', () => {
        urlInput.select();
        navigator.clipboard.writeText(url).then(() => {
            copyButton.textContent = 'Copied';
        }).catch(() => {
            document.execCommand('copy');
            copyButton.textContent = 'Copied';
        });
    });
    urlRow.append(urlInput, copyButton);

    const onceNote = el('p', 'adm-help');
    onceNote.style.cssText = 'color: var(--muted); font-size: 12.5px; margin: 0 0 10px;';
    onceNote.textContent = 'Shown only once — the token is stored as a hash and cannot be displayed again. '
        + 'Copy it now; if you lose it, regenerate the link (the old one stops working immediately).';

    const scopeNote = el('p', 'adm-help');
    scopeNote.style.cssText = 'color: var(--muted); font-size: 12.5px; margin: 0;';
    scopeNote.textContent = 'Anyone with this link can read the table without logging in. '
        + 'Sorting, filtering, search and CSV export of the loaded rows are available; editing, '
        + 'comments, files and related tables are not.';

    body.append(urlRow, onceNote, scopeNote);

    saveBtn.addEventListener('click', close, { once: true });
}

function buildTableCard(redraw) {
    const { card, body } = buildSectionCard(
        'Shared Tables',
        'Tables exposed through a public read-only link. A link never grants access to other '
        + 'tables, comments, files or attachments — only the grid of the table itself.'
    );

    const warnBox = el('div', '');
    warnBox.style.cssText = 'background: var(--warn-light, #FBF6E7); border: 1px solid var(--warn, #8A6D1F); '
        + 'color: var(--warn, #8A6D1F); padding: 10px 14px; border-radius: 4px; margin-bottom: 14px; line-height: 1.5;';
    warnBox.innerHTML = '<strong>Security notes:</strong> hidden tables, owner-restricted tables and '
        + 'system tables cannot be shared. The token is stored only as an HMAC hash — it is shown '
        + '<strong>once</strong> on generation and cannot be retrieved later. Regenerating a link '
        + 'immediately invalidates the previous one.';
    body.appendChild(warnBox);

    const table = mkTable();
    mkThead(table, ['Shared', 'Table', 'Display name', 'Link status', 'Actions']);
    const tbody = table.createTBody();

    shareConfig.forEach(tableEntry => {
        const tr = tbody.insertRow();

        const toggleCell = tdEl(buildToggle(tableEntry, redraw));
        tr.appendChild(toggleCell);

        tr.appendChild(td(tableEntry.table));
        tr.appendChild(td(tableEntry.display_name || tableEntry.table));
        tr.appendChild(td(buildStatusBadge(tableEntry)));

        const actionsCell = tdEl(buildActionButtons(tableEntry, redraw));
        actionsCell.style.whiteSpace = 'nowrap';
        tr.appendChild(actionsCell);
    });

    const scrollWrapper = el('div', '');
    scrollWrapper.style.cssText = 'overflow-x: auto;';
    scrollWrapper.appendChild(table);
    body.appendChild(scrollWrapper);

    return card;
}

function buildToggle(tableEntry, redraw) {
    const label = document.createElement('label');
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.checked = !!tableEntry.enabled;
    checkbox.addEventListener('change', async () => {
        const statusSpan = el('span', 'adm-help');
        statusSpan.textContent = '';
        const payload = { [tableEntry.table]: { enabled: checkbox.checked } };
        const result = await saveSharingConfig(payload, statusSpan);
        if (result !== null) {
            tableEntry.enabled = checkbox.checked;
            redraw();
        } else {
            checkbox.checked = tableEntry.enabled;
        }
    });
    label.appendChild(checkbox);
    return label;
}

function buildStatusBadge(tableEntry) {
    const badge = el('span', 'adm-badge');
    if (tableEntry.enabled) {
        badge.textContent = 'Active';
        if (tableEntry.generated_at) badge.textContent += ` — generated ${tableEntry.generated_at}`;
        badge.style.cssText = 'background: var(--ok-light, #EAF5F0); border-color: var(--ok, #0E7E4F); '
            + 'color: var(--ok, #0E7E4F);';
    } else {
        badge.textContent = tableEntry.generated_at ? 'Disabled — link kept' : 'Not shared';
    }
    return badge;
}

function buildActionButtons(tableEntry, redraw) {
    const wrapper = el('span', '');

    if (tableEntry.enabled && generatedUrls.has(tableEntry.table)) {
        const copyButton = el('button', 'btn btn-xs', 'Copy link');
        copyButton.style.marginRight = '6px';
        copyButton.addEventListener('click', () => showGeneratedModal(tableEntry.table));
        wrapper.appendChild(copyButton);
    }

    const generateLabel = tableEntry.enabled ? 'Regenerate' : 'Generate link';
    const generateButton = el('button', 'btn btn-xs btn-primary', generateLabel);
    generateButton.style.marginRight = '6px';
    generateButton.addEventListener('click', async () => {
        generateButton.disabled = true;
        const payload = { [tableEntry.table]: { enabled: true, regenerate: true } };
        const result = await saveSharingConfig(payload, null);
        generateButton.disabled = false;
        if (result !== null) {
            tableEntry.enabled = true;
            showGeneratedModal(tableEntry.table);
            redraw();
        }
    });
    wrapper.appendChild(generateButton);

    if (tableEntry.enabled) {
        const revokeButton = el('button', 'btn btn-xs btn-danger', 'Revoke');
        revokeButton.addEventListener('click', async () => {
            if (!confirm(`Disable the public link for "${tableEntry.table}"? `
                + 'Anyone with the old link will lose access.')) {
                return;
            }
            const payload = { [tableEntry.table]: { enabled: false, regenerate: true } };
            const result = await saveSharingConfig(payload, null);
            if (result !== null) {
                generatedUrls.delete(tableEntry.table);
                tableEntry.enabled = false;
                redraw();
            }
        });
        wrapper.appendChild(revokeButton);
    }

    return wrapper;
}

function buildReferenceCard() {
    const { card, body } = buildSectionCard(
        'How a public link works',
        'Short reference for the administrator.'
    );

    const firstParagraph = el('p', '');
    firstParagraph.style.marginTop = '0';
    firstParagraph.innerHTML = 'A shared link looks like <code>share.php?t=&lt;64-char-token&gt;</code>. '
        + 'Opening it shows the table grid read-only: sorting, filtering, search and CSV export of the '
        + 'loaded rows are available; adding, editing, deleting, comments and record details are not.';

    const secondParagraph = el('p', '');
    secondParagraph.style.marginBottom = '0';
    secondParagraph.innerHTML = 'The name of the table never appears in the URL — the token alone '
        + 'decides which table is served. Treat a link like a password: anyone who obtains it can read '
        + 'the table until it is revoked or regenerated.';

    body.append(firstParagraph, secondParagraph);
    return card;
}

export async function renderSharingPage(context) {
    const { workspaceEl: workspaceElement } = context;

    workspaceElement.innerHTML = '';

    const loaded = await Promise.all([loadSchemaTables(), loadSharingConfig()]);
    if (!loaded[1]) {
        const pageWrapper = el('div', 'admin-page');
        pageWrapper.appendChild(el('p', '', 'Could not load the sharing configuration.'));
        workspaceElement.appendChild(pageWrapper);
        return;
    }

    const redraw = () => {
        workspaceElement.innerHTML = '';
        const fragment = document.createDocumentFragment();
        const pageWrapper = el('div', 'admin-page');
        pageWrapper.appendChild(buildTableCard(redraw));
        pageWrapper.appendChild(buildReferenceCard());
        fragment.appendChild(pageWrapper);
        workspaceElement.appendChild(fragment);
    };

    redraw();
}