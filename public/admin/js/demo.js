// admin/js/demo.js — Demo sample-apps catalog + install UI
// DEMOS metadata (CRM/WMS/Tasks: labels, schemas, tables, feature lists); installs/uninstalls the demo apps via api.php (demo_install / demo_uninstall / demo_status).

const DEMOS = {
    crm: {
        label:       'CRM',
        description: 'Full-featured Customer Relationship Management — companies, contacts, deals, leads, products, quotes, invoices, and assets. 75 companies, 60 leads, and 28 deals with full history seeded.',
        schema:      'spw_crm',
        tables:      ['companies', 'contacts', 'deals', 'activities', 'leads', 'products', 'quotes', 'invoices', 'assets'],
        color:       '#64748B',
        icon:        'assets/icons/account_box.png',
        recommended: true,
        features:    ['10 dashboard widgets', '5 calendar sources + reminders', 'Kanban board: Deals by Stage', '2 workflows', '5 read-only views', '3 automations', 'M2M products ↔ contacts', 'file attachments'],
    },
    wms: {
        label:       'WMS',
        description: 'Warehouse Management System — warehouses, products, stock levels, and movements.',
        schema:      'spw_wms',
        tables:      ['warehouses', 'products', 'stock', 'movements'],
        color:       '#ffc300',
        icon:        'assets/icons/box.png',
    },
    tasks: {
        label:       'Task Management',
        description: 'Project & task tracking — projects, tasks, and time logs.',
        schema:      'spw_tasks',
        tables:      ['projects', 'tasks', 'time_logs'],
        color:       '#2b9348',
        icon:        'assets/icons/fact_check.png',
    },
};

function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function apiPost(action, body) {
    return fetch(`api.php?action=${action}`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
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
    workspaceEl.innerHTML = '<p style="color:var(--muted);margin-top:0">Loading…</p>';
    (async () => {
        try {
            const res = await fetch('api.php?action=demo_status');
            const d   = await res.json();
            if (d.installed) {
                renderInstalled(workspaceEl, d.meta);
            } else {
                renderInstallForm(workspaceEl);
            }
        } catch (e) {
            workspaceEl.innerHTML = `<p class="admin-error">Error: ${e.message}</p>`;
        }
    })();
}

function renderInstallForm(workspaceEl) {
    workspaceEl.innerHTML = '';

    const h2 = document.createElement('h2');
    h2.style.marginTop = '0';
    h2.textContent = 'Install Demo System';
    workspaceEl.appendChild(h2);

    const intro = document.createElement('p');
    intro.style.color = 'var(--muted)';
    intro.textContent = 'Choose a demo system. Installs a dedicated PostgreSQL schema, tables, and merges sample config into schema.json, dashboard.json, calendar.json, board.json, workflows.json, and views.json.';
    workspaceEl.appendChild(intro);

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

    const installBtn = document.createElement('button');
    installBtn.textContent = 'Install Demo';
    installBtn.className   = 'btn-primary';
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
            const d = await apiPost('demo_install', { type: selectedType, confirm: 'CONFIRM' });
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
            warningBox.textContent = `"${def.label}" will create schema ${def.schema} and merge demo entries into schema.json, dashboard.json, calendar.json, board.json, workflows.json, views.json, and automations.json. Existing entries with the same keys/IDs will be overwritten.`;
        });

        grid.appendChild(card);
    });
}

function renderInstalled(workspaceEl, meta) {
    workspaceEl.innerHTML = '';
    const def = DEMOS[meta.type] ?? { label: meta.type, color: '#64748B', icon: 'assets/icons/box.png' };

    const h2 = document.createElement('h2');
    h2.style.marginTop = '0';
    h2.textContent = 'Demo Installed';
    workspaceEl.appendChild(h2);

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
    uninstallBtn.className   = 'btn-danger';
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
