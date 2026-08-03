// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// admin/js/demo.js — Demo sample-app catalog + install UI
// DEMOS metadata (CRM: label, schema, tables, feature list); installs/uninstalls the demo app via api.php (demo_install / demo_uninstall / demo_status).

import { apiFetch } from '../../assets/js/util/api.js';
import { createPageHeader } from './ui.js';

const DEMOS = {
    crm: {
        label:       'CRM',
        description: 'Customer Relationship Management — companies, contacts, deals and activities in the menu, '
            + 'with leads seeded behind them to drive the automations, '
            + 'workflows and GDPR anonymization rules.',
        schema:      'spw_crm',
        tables:      ['companies', 'contacts', 'deals', 'activities', 'leads'],
        color:       'var(--muted)',
        icon:        'assets/icons/account_box.png',
        recommended: true,
        // Keep in sync with public/admin/demo/crm.php — these counts are what the
        // definition actually installs, not what an older build shipped.
        features:    ['7 dashboard widgets', '2 calendar sources + reminders', 'Kanban board: Deals by Stage', '3 workflows', '4 read-only views', '3 automations', '2 printouts', 'M2M stakeholders on deals', 'file attachments', 'RAG knowledge base'],
    },
};


function apiPost(action, body) {
    return apiFetch(`api.php?action=${action}`, {
        method:  'POST',
        body:    JSON.stringify(body),
    }).then(r => r.json());
}

function statusMsg(container, type, text) {
    let el = container.querySelector('.demo-status-msg');
    if (!el) {
        el = document.createElement('p');
        el.className = 'demo-status-msg';
        container.appendChild(el);
    }
    el.className = `demo-status-msg admin-${type === 'error' ? 'error' : 'notice'}`;
    el.textContent = text;
}

export function renderDemoPage({ workspaceEl }) {
    workspaceEl.innerHTML = '<p style="margin-top:0">Loading…</p>';
    (async () => {
        try {
            const res = await apiFetch('api.php?action=demo_status');
            const d   = await res.json();
            if (d.installed) {
                renderInstalled(workspaceEl, d.meta);
            } else {
                renderInstallForm(workspaceEl);
            }
        } catch (e) {
            // Exception text often originates from the API — build the node
            // instead of interpolating it into HTML.
            workspaceEl.innerHTML = '';
            const err = document.createElement('p');
            err.className = 'admin-error';
            err.textContent = 'Error: ' + e.message;
            workspaceEl.appendChild(err);
        }
    })();
}

function renderInstallForm(workspaceEl) {
    workspaceEl.innerHTML = '';

    workspaceEl.appendChild(createPageHeader('Install Demo System',
        'Installs a dedicated PostgreSQL schema with tables and sample data, and merges the demo schema, dashboard, calendar, board, workflows, and views configuration into the app configuration.'));

    const grid = document.createElement('div');
    grid.className = 'demo-cards';
    workspaceEl.appendChild(grid);

    let selectedType = null;

    const confirmSection = document.createElement('div');
    confirmSection.className = 'demo-confirm-section';
    confirmSection.style.display = 'none';

    const warningBox = document.createElement('div');
    warningBox.className = 'demo-warning';

    const confirmLabel = document.createElement('label');
    confirmLabel.textContent = 'Type CONFIRM to proceed:';
    confirmLabel.style.cssText = 'display:block;font-weight:600;margin-top:16px;';

    const confirmInput = document.createElement('input');
    confirmInput.type        = 'text';
    confirmInput.placeholder = 'CONFIRM';
    confirmInput.className   = 'demo-confirm-input';

    // Knowledge-base opt-out. Checked by default: the documents are inert without
    // Ollama (they just sit in spw_rag_files), but without them the Ask AI panel has
    // nothing to retrieve and looks broken on a fresh demo.
    const ragRow = document.createElement('div');
    ragRow.style.cssText = 'display:flex;align-items:center;gap:8px;margin-top:16px;';

    const ragChk = document.createElement('input');
    ragChk.type      = 'checkbox';
    ragChk.id        = 'demo-rag-docs-chk';
    ragChk.className = 'adm-check';
    ragChk.checked   = true;

    const ragLabel = document.createElement('label');
    ragLabel.htmlFor     = 'demo-rag-docs-chk';
    ragLabel.textContent = 'Install RAG knowledge base for this demo';
    ragLabel.className   = 'adm-field-label';
    ragLabel.style.cursor = 'pointer';

    ragRow.append(ragChk, ragLabel);

    const ragHelp = document.createElement('div');
    ragHelp.className   = 'help-text';
    ragHelp.textContent = 'Loads the sample documents describing this demo into RAG Documents, '
        + 'so the Ask AI panel can answer questions about it. Answering needs Ollama; '
        + 'installing the documents does not.';

    const installBtn = document.createElement('button');
    installBtn.textContent = 'Install Demo';
    installBtn.className   = 'btn btn-primary';
    installBtn.style.marginTop = '12px';
    installBtn.disabled = true;

    confirmInput.addEventListener('input', () => {
        installBtn.disabled = confirmInput.value !== 'CONFIRM';
    });

    installBtn.addEventListener('click', async () => {
        if (!selectedType || confirmInput.value !== 'CONFIRM') return;
        installBtn.disabled  = true;
        installBtn.textContent = 'Installing…';
        try {
            const d = await apiPost('demo_install', {
                type:     selectedType,
                confirm:  'CONFIRM',
                rag_docs: ragChk.checked,
            });
            if (d.status === 'success') {
                renderDemoPage({ workspaceEl });
            } else {
                statusMsg(confirmSection, 'error', d.error ?? 'Installation failed.');
                installBtn.disabled  = false;
                installBtn.textContent = 'Install Demo';
            }
        } catch (e) {
            statusMsg(confirmSection, 'error', e.message);
            installBtn.disabled  = false;
            installBtn.textContent = 'Install Demo';
        }
    });

    confirmSection.appendChild(warningBox);
    confirmSection.appendChild(ragRow);
    confirmSection.appendChild(ragHelp);
    confirmSection.appendChild(confirmLabel);
    confirmSection.appendChild(confirmInput);
    confirmSection.appendChild(installBtn);
    workspaceEl.appendChild(confirmSection);

    Object.entries(DEMOS).forEach(([key, def]) => {
        const card = document.createElement('div');
        card.className   = 'demo-card';
        card.dataset.type = key;
        if (def.recommended) card.classList.add('recommended');
        const featureTags = (def.features ?? []).map(f => `<span class="demo-feature-tag">${f}</span>`).join('');
        card.innerHTML   = `
            ${def.recommended ? '<span class="demo-recommended-badge">Recommended</span>' : ''}
            <img class="demo-card-icon" src="../${def.icon}" alt="">
            <div class="demo-card-title">${def.label}</div>
            <div class="demo-card-desc">${def.description}</div>
            ${featureTags ? `<div class="demo-card-features">${featureTags}</div>` : ''}
            <div class="demo-card-meta">
                <code class="demo-schema-badge">${def.schema}</code>
                <span class="demo-card-tables">${def.tables.join(' · ')}</span>
            </div>
        `;
        card.style.setProperty('--demo-color', def.color);

        card.addEventListener('click', () => {
            document.querySelectorAll('.demo-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            selectedType = key;
            confirmInput.value   = '';
            installBtn.disabled  = true;
            confirmSection.style.display = '';
            warningBox.textContent = `"${def.label}" will create schema ${def.schema} and merge demo entries into the schema, dashboard, calendar, board, workflows, views, and automations configuration. Existing entries with the same keys/IDs will be overwritten.`;
        });

        grid.appendChild(card);
    });
}

function renderInstalled(workspaceEl, meta) {
    workspaceEl.innerHTML = '';
    const def = DEMOS[meta.type] ?? { label: meta.type, color: 'var(--muted)', icon: 'assets/icons/box.png' };

    workspaceEl.appendChild(createPageHeader('Demo Installed'));

    const badge = document.createElement('div');
    badge.className = 'demo-installed-badge';
    badge.style.borderColor = def.color;
    badge.innerHTML = `
        <img class="demo-installed-icon" src="../${def.icon}" alt="">
        <div>
            <strong>${def.label}</strong>
            <div class="demo-installed-meta">
                Schema: <code>${meta.schema}</code> &nbsp;·&nbsp;
                Installed: ${meta.installed_at ?? '—'}
            </div>
            <div class="demo-installed-tables">Tables: ${(meta.tables ?? []).join(', ')}</div>
        </div>
    `;
    workspaceEl.appendChild(badge);

    const sep = document.createElement('hr');
    sep.style.margin = '24px 0';
    workspaceEl.appendChild(sep);

    const uninstallWrap = document.createElement('div');
    uninstallWrap.className = 'demo-confirm-section';

    const warn = document.createElement('div');
    warn.className   = 'demo-warning demo-warning-danger';
    warn.textContent = `Uninstalling will DROP SCHEMA ${meta.schema} CASCADE (all data lost) and remove demo entries from all JSON config files. This cannot be undone.`;

    const lbl = document.createElement('label');
    lbl.textContent = 'Type CONFIRM to uninstall:';
    lbl.style.cssText = 'display:block;font-weight:600;margin-top:16px;';

    const confirmInput = document.createElement('input');
    confirmInput.type        = 'text';
    confirmInput.placeholder = 'CONFIRM';
    confirmInput.className   = 'demo-confirm-input';

    const uninstallBtn = document.createElement('button');
    uninstallBtn.textContent = 'Uninstall Demo';
    uninstallBtn.className   = 'btn btn-danger';
    uninstallBtn.style.marginTop = '12px';
    uninstallBtn.disabled = true;

    confirmInput.addEventListener('input', () => {
        uninstallBtn.disabled = confirmInput.value !== 'CONFIRM';
    });

    uninstallBtn.addEventListener('click', async () => {
        if (confirmInput.value !== 'CONFIRM') return;
        uninstallBtn.disabled   = true;
        uninstallBtn.textContent = 'Uninstalling…';
        try {
            const d = await apiPost('demo_uninstall', { confirm: 'CONFIRM' });
            if (d.status === 'success') {
                renderDemoPage({ workspaceEl });
            } else {
                statusMsg(uninstallWrap, 'error', d.error ?? 'Uninstall failed.');
                uninstallBtn.disabled   = false;
                uninstallBtn.textContent = 'Uninstall Demo';
            }
        } catch (e) {
            statusMsg(uninstallWrap, 'error', e.message);
            uninstallBtn.disabled   = false;
            uninstallBtn.textContent = 'Uninstall Demo';
        }
    });

    uninstallWrap.appendChild(warn);
    uninstallWrap.appendChild(lbl);
    uninstallWrap.appendChild(confirmInput);
    uninstallWrap.appendChild(uninstallBtn);
    workspaceEl.appendChild(uninstallWrap);
}
