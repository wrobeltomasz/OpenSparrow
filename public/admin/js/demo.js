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
            + 'workflows and GDPR anonymization rules. A full tour of the platform: a dashboard of stat, bar, pie '
            + 'and line widgets, a calendar with reminders, a Kanban board of the sales pipeline, step-by-step '
            + 'workflows (one validated by a PostgreSQL procedure), aggregate views with subtotals and drill-down, '
            + 'parameterised printouts built on those views, event-driven automations that notify, update and send '
            + 'email, and a RAG knowledge base so the Ask AI panel can answer questions about the data. Collaboration '
            + 'is seeded too — three demo users with comments, personal notes, record ownership, notifications, '
            + 'file and image attachments.',
        schema:      'spw_crm',
        tables:      ['companies', 'contacts', 'deals', 'activities', 'leads'],
        color:       'var(--muted)',
        icon:        'assets/icons/account_box.png',
        recommended: true,
        // Keep in sync with public/admin/demo/crm.php — these counts are what the
        // definition actually installs, not what an older build shipped.
        features:    [
            '7 dashboard widgets',
            'Stat, bar, pie and line charts',
            '2 calendar sources + reminders',
            'Kanban board: Deals by Stage',
            '3 workflows',
            'PostgreSQL procedure validation',
            '4 read-only views',
            'Subtotals + drill-down',
            '2 printouts with parameters',
            '3 automations: notify, update, email',
            'RAG knowledge base: 9 documents',
            'RAG aggregate view on deals',
            'GDPR anonymization: 4 rules',
            'M2M stakeholders on deals',
            'File and image attachments',
            '3 demo users',
            'Comments, notes, notifications',
            'Record ownership',
            'Audit history + record snapshots',
            'Highlight rules + colored enums',
        ],
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
                renderInstallForm(workspaceEl, { snapshotsLockedByEnv: !!d.snapshots_locked_by_env });
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

// One install option: checkbox, clickable label and the help text explaining what it
// installs and why you might want it off. Returns the row so callers can reach the
// input; keeps the three options below from repeating the same eight nodes.
function buildInstallOption({ id, label, help, checked = true }) {
    const wrap = document.createElement('div');
    wrap.className = 'demo-install-option';

    const row = document.createElement('div');
    row.style.cssText = 'display:flex;align-items:center;gap:8px;';

    const chk = document.createElement('input');
    chk.type      = 'checkbox';
    chk.id        = id;
    chk.className = 'adm-check';
    chk.checked   = checked;

    const lbl = document.createElement('label');
    lbl.htmlFor     = id;
    lbl.textContent = label;
    lbl.className   = 'adm-field-label';
    lbl.style.cursor = 'pointer';

    row.append(chk, lbl);

    const helpEl = document.createElement('div');
    helpEl.className   = 'help-text';
    helpEl.textContent = help;

    wrap.append(row, helpEl);
    return { wrap, chk, help: helpEl };
}

function renderInstallForm(workspaceEl, { snapshotsLockedByEnv = false } = {}) {
    workspaceEl.innerHTML = '';

    workspaceEl.appendChild(createPageHeader('Install Demo System',
        'Installs a dedicated PostgreSQL schema with tables, views, procedures and sample data, and merges the demo '
        + 'schema, menu, dashboard, calendar, board, workflows, views, printouts, automations, anonymization rules, '
        + 'file relations and RAG knowledge base into the app configuration.'));

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

    // Optional parts of the install. All three default to on — the demo is meant to
    // show the whole platform — but each is separable, and the help text says what you
    // lose by turning it off so the choice is informed rather than a guess.
    const options = document.createElement('div');
    options.className = 'demo-install-options';

    const optTitle = document.createElement('strong');
    optTitle.className = 'demo-install-options-title';
    optTitle.textContent = 'What to install';
    options.appendChild(optTitle);

    // Knowledge base. The documents are inert without Ollama (they just sit in
    // spw_rag_files), but without them the Ask AI panel has nothing to retrieve and
    // looks broken on a fresh demo.
    const rag = buildInstallOption({
        id:    'demo-rag-docs-chk',
        label: 'RAG knowledge base',
        help:  'Loads nine sample documents describing this demo into RAG Documents, so the Ask AI '
            + 'panel can answer questions about the CRM data. Installing them is pure SQL and needs '
            + 'no network; only answering a question later requires Ollama. Leave it off if you do '
            + 'not plan to use Ask AI — the Ask AI panel will then return nothing.',
    });

    // Demo accounts. A genuine opt-out rather than a convenience one: the accounts
    // share one fixed password documented in the repository, so an installation
    // reachable from a network may legitimately not want them.
    const users = buildInstallOption({
        id:    'demo-users-chk',
        label: 'Demo users and collaboration data',
        help:  'Creates three demo accounts (demo.anna, demo.marek, demo.julia) and the data keyed '
            + 'to them: comment threads, personal notes, record ownership and notifications. All '
            + 'three share the fixed password "test", so leave this off on any installation reachable '
            + 'from a network. Without it the CRM data, dashboard, board, workflows, views, printouts '
            + 'and automations are unaffected; file attachments are installed under your own account.',
    });

    // Audit history. A bare toggle would leave both the Audit module and the per-record
    // history empty until the first manual edit, so this seeds backdated entries too.
    const audit = buildInstallOption({
        id:    'demo-audit-chk',
        label: 'Audit history and record snapshots',
        help:  'Backfills roughly twenty dated edits on the demo deals, contacts and companies — who '
            + 'changed what and when, with a full JSONB snapshot of the record at each point — and '
            + 'turns Record Snapshots on so the history keeps growing. Without it the Audit module '
            + 'and per-record history stay empty until you make the first edit yourself.',
    });

    // Audit entries are attributed to the demo accounts, so the option cannot outlive
    // them; the server enforces the same dependency in demo_install.
    const syncAuditAvailability = () => {
        const envLocked = snapshotsLockedByEnv;
        const blocked   = envLocked || !users.chk.checked;
        audit.chk.disabled = blocked;
        if (blocked) audit.chk.checked = false;
        if (envLocked) {
            audit.help.textContent = 'Unavailable: the RECORD_SNAPSHOTS_ENABLED environment variable '
                + 'controls the record snapshot setting, so the demo cannot change it.';
        } else if (!users.chk.checked) {
            audit.help.textContent = 'Requires the demo users above — every history entry records which '
                + 'demo account made the change.';
        } else {
            audit.help.textContent = audit.helpDefault;
        }
    };
    audit.helpDefault = audit.help.textContent;
    users.chk.addEventListener('change', syncAuditAvailability);

    options.append(rag.wrap, users.wrap, audit.wrap);
    syncAuditAvailability();

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
                type:          selectedType,
                confirm:       'CONFIRM',
                rag_docs:      rag.chk.checked,
                demo_users:    users.chk.checked,
                audit_history: audit.chk.checked,
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
    confirmSection.appendChild(options);
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
            warningBox.textContent = `"${def.label}" will create schema ${def.schema} and merge demo entries into the schema, menu, dashboard, calendar, board, workflows, views, printouts, automations, anonymization, files and RAG configuration. Existing entries with the same keys/IDs will be overwritten.`;
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
    warn.textContent = `Uninstalling will DROP SCHEMA ${meta.schema} CASCADE (all data lost) and remove demo entries from the app configuration — menu, dashboard, calendar, board, workflows, views, printouts, automations, anonymization, files and RAG documents — along with the demo users and their comments, notes, notifications and seeded audit history. Audit entries and record snapshots you created yourself are kept. This cannot be undone.`;

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
