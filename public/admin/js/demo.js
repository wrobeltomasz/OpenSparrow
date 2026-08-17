// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

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
    }).then(response => response.json());
}

function statusMessage(container, type, text) {
    let element = container.querySelector('.demo-status-msg');
    if (!element) {
        element = document.createElement('p');
        element.className = 'demo-status-msg';
        container.appendChild(element);
    }
    element.className = `demo-status-msg admin-${type === 'error' ? 'error' : 'notice'}`;
    element.textContent = text;
}

export function renderDemoPage({ workspaceEl: workspaceElement }) {
    workspaceElement.innerHTML = '<p style="margin-top:0">Loading…</p>';
    (async () => {
        try {
            const result = await apiFetch('api.php?action=demo_status');
            const payload   = await result.json();
            if (payload.installed) {
                renderInstalled(workspaceElement, payload.meta);
            } else {
                renderInstallForm(workspaceElement, { snapshotsLockedByEnv: !!payload.snapshots_locked_by_env });
            }
        } catch (error) {
            workspaceElement.innerHTML = '';
            const errorParagraph = document.createElement('p');
            errorParagraph.className = 'admin-error';
            errorParagraph.textContent = 'Error: ' + error.message;
            workspaceElement.appendChild(errorParagraph);
        }
    })();
}

function buildInstallOption({ id, label, help, checked = true }) {
    const wrap = document.createElement('div');
    wrap.className = 'demo-install-option';

    const row = document.createElement('div');
    row.style.cssText = 'display:flex;align-items:center;gap:8px;';

    const checkbox = document.createElement('input');
    checkbox.type      = 'checkbox';
    checkbox.id        = id;
    checkbox.className = 'adm-check';
    checkbox.checked   = checked;

    const labelElement = document.createElement('label');
    labelElement.htmlFor     = id;
    labelElement.textContent = label;
    labelElement.className   = 'adm-field-label';
    labelElement.style.cursor = 'pointer';

    row.append(checkbox, labelElement);

    const helpElement = document.createElement('div');
    helpElement.className   = 'help-text';
    helpElement.textContent = help;

    wrap.append(row, helpElement);
    return { wrap, chk: checkbox, help: helpElement };
}

function renderInstallForm(workspaceElement, { snapshotsLockedByEnv: snapshotsLockedByEnvironment = false } = {}) {
    workspaceElement.innerHTML = '';

    workspaceElement.appendChild(createPageHeader('Install Demo System',
        'Installs a dedicated PostgreSQL schema with tables, views, procedures and sample data, and merges the demo '
        + 'schema, menu, dashboard, calendar, board, workflows, views, printouts, automations, anonymization rules, '
        + 'file relations and RAG knowledge base into the app configuration.'));

    const grid = document.createElement('div');
    grid.className = 'demo-cards';
    workspaceElement.appendChild(grid);

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

    const options = document.createElement('div');
    options.className = 'demo-install-options';

    const optionTitle = document.createElement('strong');
    optionTitle.className = 'demo-install-options-title';
    optionTitle.textContent = 'What to install';
    options.appendChild(optionTitle);

    const ragOption = buildInstallOption({
        id:    'demo-rag-docs-chk',
        label: 'RAG knowledge base',
        help:  'Loads nine sample documents describing this demo into RAG Documents, so the Ask AI '
            + 'panel can answer questions about the CRM data. Installing them is pure SQL and needs '
            + 'no network; only answering a question later requires Ollama. Leave it off if you do '
            + 'not plan to use Ask AI — the Ask AI panel will then return nothing.',
    });

    const users = buildInstallOption({
        id:    'demo-users-chk',
        label: 'Demo users and collaboration data',
        help:  'Creates three demo accounts (demo.anna, demo.marek, demo.julia) and the data keyed '
            + 'to them: comment threads, personal notes, record ownership and notifications. All '
            + 'three share the fixed password "test", so leave this off on any installation reachable '
            + 'from a network. Without it the CRM data, dashboard, board, workflows, views, printouts '
            + 'and automations are unaffected; file attachments are installed under your own account.',
    });

    const audit = buildInstallOption({
        id:    'demo-audit-chk',
        label: 'Audit history and record snapshots',
        help:  'Backfills roughly twenty dated edits on the demo deals, contacts and companies — who '
            + 'changed what and when, with a full JSONB snapshot of the record at each point — and '
            + 'turns Record Snapshots on so the history keeps growing. Without it the Audit module '
            + 'and per-record history stay empty until you make the first edit yourself.',
    });

    const syncAuditAvailability = () => {
        const environmentLocked = snapshotsLockedByEnvironment;
        const blocked   = environmentLocked || !users.chk.checked;
        audit.chk.disabled = blocked;
        if (blocked) audit.chk.checked = false;
        if (environmentLocked) {
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

    options.append(ragOption.wrap, users.wrap, audit.wrap);
    syncAuditAvailability();

    const installButton = document.createElement('button');
    installButton.textContent = 'Install Demo';
    installButton.className   = 'btn btn-primary';
    installButton.style.marginTop = '12px';
    installButton.disabled = true;

    confirmInput.addEventListener('input', () => {
        installButton.disabled = confirmInput.value !== 'CONFIRM';
    });

    installButton.addEventListener('click', async () => {
        if (!selectedType || confirmInput.value !== 'CONFIRM') return;
        installButton.disabled  = true;
        installButton.textContent = 'Installing…';
        try {
            const payload = await apiPost('demo_install', {
                type:          selectedType,
                confirm:       'CONFIRM',
                rag_docs:      ragOption.chk.checked,
                demo_users:    users.chk.checked,
                audit_history: audit.chk.checked,
            });
            if (payload.status === 'success') {
                renderDemoPage({ workspaceEl: workspaceElement });
            } else {
                statusMessage(confirmSection, 'error', payload.error ?? 'Installation failed.');
                installButton.disabled  = false;
                installButton.textContent = 'Install Demo';
            }
        } catch (error) {
            statusMessage(confirmSection, 'error', error.message);
            installButton.disabled  = false;
            installButton.textContent = 'Install Demo';
        }
    });

    confirmSection.appendChild(warningBox);
    confirmSection.appendChild(options);
    confirmSection.appendChild(confirmLabel);
    confirmSection.appendChild(confirmInput);
    confirmSection.appendChild(installButton);
    workspaceElement.appendChild(confirmSection);

    Object.entries(DEMOS).forEach(([key, demoDefinition]) => {
        const card = document.createElement('div');
        card.className   = 'demo-card';
        card.dataset.type = key;
        if (demoDefinition.recommended) card.classList.add('recommended');
        const featureTags = (demoDefinition.features ?? []).map(featureName => `<span class="demo-feature-tag">${featureName}</span>`).join('');
        card.innerHTML   = `
            ${demoDefinition.recommended ? '<span class="demo-recommended-badge">Recommended</span>' : ''}
            <img class="demo-card-icon" src="../${demoDefinition.icon}" alt="">
            <div class="demo-card-title">${demoDefinition.label}</div>
            <div class="demo-card-desc">${demoDefinition.description}</div>
            ${featureTags ? `<div class="demo-card-features">${featureTags}</div>` : ''}
            <div class="demo-card-meta">
                <code class="demo-schema-badge">${demoDefinition.schema}</code>
                <span class="demo-card-tables">${demoDefinition.tables.join(' · ')}</span>
            </div>
        `;
        card.style.setProperty('--demo-color', demoDefinition.color);

        card.addEventListener('click', () => {
            document.querySelectorAll('.demo-card').forEach(cardElement => cardElement.classList.remove('selected'));
            card.classList.add('selected');
            selectedType = key;
            confirmInput.value   = '';
            installButton.disabled  = true;
            confirmSection.style.display = '';
            warningBox.textContent = `"${demoDefinition.label}" will create schema ${demoDefinition.schema} and merge demo entries into the schema, menu, dashboard, calendar, board, workflows, views, printouts, automations, anonymization, files and RAG configuration. Existing entries with the same keys/IDs will be overwritten.`;
        });

        grid.appendChild(card);
    });
}

function renderInstalled(workspaceElement, meta) {
    workspaceElement.innerHTML = '';
    const demoDefinition = DEMOS[meta.type] ?? { label: meta.type, color: 'var(--muted)', icon: 'assets/icons/box.png' };

    workspaceElement.appendChild(createPageHeader('Demo Installed'));

    const badge = document.createElement('div');
    badge.className = 'demo-installed-badge';
    badge.style.borderColor = demoDefinition.color;
    badge.innerHTML = `
        <img class="demo-installed-icon" src="../${demoDefinition.icon}" alt="">
        <div>
            <strong>${demoDefinition.label}</strong>
            <div class="demo-installed-meta">
                Schema: <code>${meta.schema}</code> &nbsp;·&nbsp;
                Installed: ${meta.installed_at ?? '—'}
            </div>
            <div class="demo-installed-tables">Tables: ${(meta.tables ?? []).join(', ')}</div>
        </div>
    `;
    workspaceElement.appendChild(badge);

    const sep = document.createElement('hr');
    sep.style.margin = '24px 0';
    workspaceElement.appendChild(sep);

    const uninstallWrap = document.createElement('div');
    uninstallWrap.className = 'demo-confirm-section';

    const warn = document.createElement('div');
    warn.className   = 'demo-warning demo-warning-danger';
    warn.textContent = `Uninstalling will DROP SCHEMA ${meta.schema} CASCADE (all data lost) and remove demo entries from the app configuration — menu, dashboard, calendar, board, workflows, views, printouts, automations, anonymization, files and RAG documents — along with the demo users and their comments, notes, notifications and seeded audit history. Audit entries and record snapshots you created yourself are kept. This cannot be undone.`;

    const labelElement = document.createElement('label');
    labelElement.textContent = 'Type CONFIRM to uninstall:';
    labelElement.style.cssText = 'display:block;font-weight:600;margin-top:16px;';

    const confirmInput = document.createElement('input');
    confirmInput.type        = 'text';
    confirmInput.placeholder = 'CONFIRM';
    confirmInput.className   = 'demo-confirm-input';

    const uninstallButton = document.createElement('button');
    uninstallButton.textContent = 'Uninstall Demo';
    uninstallButton.className   = 'btn btn-danger';
    uninstallButton.style.marginTop = '12px';
    uninstallButton.disabled = true;

    confirmInput.addEventListener('input', () => {
        uninstallButton.disabled = confirmInput.value !== 'CONFIRM';
    });

    uninstallButton.addEventListener('click', async () => {
        if (confirmInput.value !== 'CONFIRM') return;
        uninstallButton.disabled   = true;
        uninstallButton.textContent = 'Uninstalling…';
        try {
            const payload = await apiPost('demo_uninstall', { confirm: 'CONFIRM' });
            if (payload.status === 'success') {
                renderDemoPage({ workspaceEl: workspaceElement });
            } else {
                statusMessage(uninstallWrap, 'error', payload.error ?? 'Uninstall failed.');
                uninstallButton.disabled   = false;
                uninstallButton.textContent = 'Uninstall Demo';
            }
        } catch (error) {
            statusMessage(uninstallWrap, 'error', error.message);
            uninstallButton.disabled   = false;
            uninstallButton.textContent = 'Uninstall Demo';
        }
    });

    uninstallWrap.appendChild(warn);
    uninstallWrap.appendChild(labelElement);
    uninstallWrap.appendChild(confirmInput);
    uninstallWrap.appendChild(uninstallButton);
    workspaceElement.appendChild(uninstallWrap);
}
