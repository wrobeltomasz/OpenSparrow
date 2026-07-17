// admin/js/fdw.js — MySQL Gateway (FDW) configuration panel
// Connect/test the external MySQL, pick gateway tables + per-table metadata/icons, and sync columns via admin/api_fdw.php (mysql_* actions).
import { apiFetch } from '../../assets/js/util/api.js';
import { showStatusPill } from './app.js';

const ICONS = [
    'account_balance', 'account_box', 'account_tree', 'add_comment', 'agriculture',
    'airport_shuttle', 'apartment', 'arrow_split', 'automation', 'autorenew',
    'ballot', 'bar_chart', 'barcode', 'book_3', 'book_3s', 'box', 'bucket_check',
    'build', 'calculate', 'calendar', 'calendar_check', 'call', 'car_gear',
    'checklist_rtl', 'comment', 'computer', 'dashboard', 'data_table',
    'data_thresholding', 'database', 'delivery_truck_speed', 'docs', 'download',
    'download_2', 'fact_check', 'factory', 'file_present', 'files', 'folder_open',
    'folder_zip', 'folder_zip_blue', 'food_bank', 'forklift', 'garage', 'grid_on',
    'grid_on_blue', 'health_and_safety', 'health_cross', 'id_card', 'image',
    'inventory', 'inventory_2', 'light_group', 'local_convenience_store',
    'local_gas_station', 'local_shipping', 'location_away', 'location_city', 'mail',
    'manage_history', 'menu_book', 'motorcycle', 'order_approve', 'orders',
    'package_2', 'payments', 'person', 'person_text', 'photo_library',
    'picture_as_pdf', 'picture_as_pdf_blue', 'playground', 'point_of_sale', 'search',
    'shopping_cart', 'table_chart_view', 'table_edit', 'today', 'tram', 'trolley',
    'upload', 'user_attributes', 'warehouse', 'watch_screentime',
];

async function fdwApi(action, { method = 'GET', body = null } = {}) {
    const res = await apiFetch('api_fdw.php?action=' + action, {
        method,
        body: body ?? undefined,
    });
    return res.json();
}

function el(tag, css, text) {
    const node = document.createElement(tag);
    if (css) node.style.cssText = css;
    if (text !== undefined) node.textContent = text;
    return node;
}

// ── Tab bar ──────────────────────────────────────────────────────────────────

function buildTabs(wrap, tabs) {
    const bar = document.createElement('div');
    bar.className = 'item-panel-items';

    const panels = {};
    const btns   = {};

    tabs.forEach(({ id, label }) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'item-btn';
        btn.textContent = label;
        bar.appendChild(btn);
        btns[id] = btn;

        const panel = document.createElement('div');
        panel.style.display = 'none';
        wrap.appendChild(panel);
        panels[id] = panel;
    });

    wrap.insertBefore(bar, wrap.firstChild);

    function activate(id) {
        Object.entries(btns).forEach(([k, b]) => {
            b.classList.toggle('active', k === id);
        });
        Object.entries(panels).forEach(([k, p]) => {
            p.style.display = k === id ? '' : 'none';
        });
    }

    tabs.forEach(({ id }) => btns[id].addEventListener('click', () => activate(id)));
    activate(tabs[0].id);
    return { panels, activate };
}

// ── Entry point ──────────────────────────────────────────────────────────────

export async function renderFdwPage(/* ctx */) {
    const workspace = document.getElementById('editorForm');
    workspace.innerHTML = '';
    workspace.appendChild(el('p', 'color:#64748B;font-size:13px;', 'Loading...'));

    let status;
    try {
        status = await fdwApi('mysql_status');
    } catch (e) {
        workspace.innerHTML = '';
        workspace.appendChild(el('p', 'color:#a80000;', 'Failed to load External Databases status.'));
        return;
    }

    workspace.innerHTML = '';
    workspace.appendChild(el('h2', 'margin:0 0 20px;font-size:18px;font-weight:700;', 'External Databases — MySQL Gateway'));

    const { panels } = buildTabs(workspace, [
        { id: 'connection', label: 'Connection' },
        { id: 'tables',     label: 'Tables' },
    ]);

    renderConnectionTab(panels.connection, status);
    renderTablesTab(panels.tables, status);
}

// ── Connection tab ───────────────────────────────────────────────────────────

function connectionBadge(connected, configured) {
    let bg, fg, border, label;
    if (connected) {
        bg = 'rgba(43,147,72,0.12)'; fg = '#2b9348'; border = '#2b9348'; label = 'Connected';
    } else if (configured) {
        bg = 'rgba(208,0,0,0.08)'; fg = '#a80000'; border = '#d00000'; label = 'Not connected';
    } else {
        bg = '#F1F5F9'; fg = '#64748B'; border = '#CBD5E1'; label = 'Not configured';
    }
    const b = el('span', `display:inline-block;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:600;background:${bg};color:${fg};border:1px solid ${border};`, label);
    b.dataset.badge = 'conn';
    return b;
}

function updateBadge(badge, connected, configured) {
    let bg, fg, border, label;
    if (connected) {
        bg = 'rgba(43,147,72,0.12)'; fg = '#2b9348'; border = '#2b9348'; label = 'Connected';
    } else if (configured) {
        bg = 'rgba(208,0,0,0.08)'; fg = '#a80000'; border = '#d00000'; label = 'Not connected';
    } else {
        bg = '#F1F5F9'; fg = '#64748B'; border = '#CBD5E1'; label = 'Not configured';
    }
    badge.style.background = bg;
    badge.style.color = fg;
    badge.style.borderColor = border;
    badge.textContent = label;
}

function renderConnectionTab(panel, status) {
    const isEnv = status.source === 'env';

    const card = el('div', 'background:#F4F7F9;border:1px solid #CBD5E1;border-radius:8px;padding:20px;');

    const hdr = el('div', 'display:flex;align-items:center;gap:10px;margin-bottom:14px;');
    hdr.appendChild(el('h3', 'margin:0;font-size:15px;font-weight:600;', 'MySQL Connection'));
    const srcStyles = {
        env:  ['from env vars', '#DDEAF4', '#1E293B'],
        file: ['from config file', '#F0FDF4', '#166534'],
        none: ['not configured', '#FEF9C3', '#854D0E'],
    };
    const [srcLabel, srcBg, srcFg] = srcStyles[status.source] || ['unknown', '#F1F5F9', '#64748B'];
    hdr.appendChild(el('span', `padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:${srcBg};color:${srcFg};`, srcLabel));
    card.appendChild(hdr);

    if (isEnv) {
        const note = el('div', 'margin-bottom:14px;padding:8px 12px;background:#DDEAF4;border-left:3px solid #3B82F6;border-radius:4px;font-size:12px;color:#1E293B;');
        note.textContent = 'Credentials are set via MYSQL_* environment variables and take precedence over this form. Remove those env vars to use file-based credentials.';
        card.appendChild(note);
    }

    const grid = el('div', 'display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;');
    function inputGroup(labelText, fieldName, value, placeholder, type = 'text') {
        const g = document.createElement('div');
        const lbl = el('label');
        lbl.className = 'adm-field-label';
        lbl.textContent = labelText;
        const inp = el('input');
        inp.className = 'adm-input w-full mono';
        inp.type = type;
        inp.value = value;
        inp.placeholder = placeholder;
        inp.disabled = isEnv;
        inp.dataset.field = fieldName;
        g.append(lbl, inp);
        return g;
    }

    grid.appendChild(inputGroup('Host',     'host',     status.host     || '',       'localhost or host.docker.internal'));
    grid.appendChild(inputGroup('Port',     'port',     String(status.port || 3306), '3306'));
    grid.appendChild(inputGroup('Database', 'database', status.database || '',       'mydb'));
    grid.appendChild(inputGroup('User',     'user',     status.user     || '',       'root'));

    const pwGroup = document.createElement('div');
    pwGroup.style.gridColumn = '1 / -1';
    const pwLbl = el('label');
    pwLbl.className = 'adm-field-label';
    pwLbl.textContent = 'Password';
    const pwInp = el('input');
    pwInp.className = 'adm-input w-full mono';
    pwInp.type = 'password';
    pwInp.placeholder = status.has_password ? '(leave blank to keep current password)' : 'enter password';
    pwInp.disabled = isEnv;
    pwInp.dataset.field = 'password';
    pwGroup.append(pwLbl, pwInp);
    grid.appendChild(pwGroup);

    card.appendChild(grid);

    const actionRow = el('div', 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;');
    const badge = connectionBadge(status.connected, status.configured);

    if (!isEnv) {
        const saveBtn = el('button', '', 'Save & Test');
        saveBtn.className = 'btn btn-primary';
        saveBtn.addEventListener('click', async () => {
            const val = (name) => { const i = grid.querySelector(`[data-field="${name}"]`); return i ? i.value : ''; };
            const payload = {
                host:     val('host').trim(),
                port:     parseInt(val('port'), 10) || 3306,
                database: val('database').trim(),
                user:     val('user').trim(),
                password: val('password'),
            };
            if (!payload.host || !payload.database || !payload.user) {
                showStatusPill(saveBtn, 'Host, database and user are required', 'error');
                return;
            }
            try {
                const r = await fdwApi('mysql_credentials_save', { method: 'POST', body: payload });
                const ok = r.status === 'success' && r.connected;
                showStatusPill(saveBtn, r.message || (r.error || 'Error'), ok ? 'success' : 'error');
                updateBadge(badge, r.connected, true);
                if (ok) pwInp.value = '';
            } catch (e) {
                showStatusPill(saveBtn, 'Request failed', 'error');
            }
        });
        actionRow.appendChild(saveBtn);
    } else {
        const testBtn = el('button', '', 'Test connection');
        testBtn.className = 'btn btn-primary';
        testBtn.addEventListener('click', async () => {
            try {
                const r = await fdwApi('mysql_test', { method: 'POST', body: {} });
                showStatusPill(testBtn, r.status === 'success' ? 'Connected' : (r.error || 'Failed'), r.status === 'success' ? 'success' : 'error');
            } catch (e) {
                showStatusPill(testBtn, 'Request failed', 'error');
            }
        });
        actionRow.appendChild(testBtn);
    }

    actionRow.appendChild(badge);
    card.appendChild(actionRow);
    panel.appendChild(card);
}

// ── Tables tab ───────────────────────────────────────────────────────────────

function makeIconSelect(currentIcon) {
    const wrap = el('div', 'display:flex;align-items:center;gap:8px;');

    const sel = document.createElement('select');
    sel.className = 'adm-input flex-1';

    const noneOpt = document.createElement('option');
    noneOpt.value = '';
    noneOpt.textContent = '— no icon —';
    sel.appendChild(noneOpt);

    ICONS.forEach(name => {
        const opt = document.createElement('option');
        opt.value = 'assets/icons/' + name + '.png';
        opt.textContent = name.replace(/_/g, ' ');
        if (opt.value === currentIcon) opt.selected = true;
        sel.appendChild(opt);
    });

    const preview = document.createElement('img');
    preview.style.cssText = 'width:22px;height:22px;object-fit:contain;opacity:.7;flex-shrink:0;';
    preview.alt = '';
    if (currentIcon) {
        preview.src = '../' + currentIcon;
    } else {
        preview.style.display = 'none';
    }

    sel.addEventListener('change', () => {
        if (sel.value) {
            preview.src = '../' + sel.value;
            preview.style.display = '';
        } else {
            preview.style.display = 'none';
        }
    });

    wrap.append(sel, preview);
    return { wrap, sel };
}

function renderTableCard(container, tableName, meta, onRemove) {
    const card = el('div', 'background:#fff;border:1px solid #CBD5E1;border-radius:8px;margin-bottom:12px;overflow:hidden;');

    // Header
    const hdr = el('div', 'display:flex;align-items:center;gap:10px;padding:12px 16px;background:#F4F7F9;border-bottom:1px solid #CBD5E1;');
    const nameCode = el('code', 'flex:1;font-size:13px;font-weight:600;color:#1E293B;');
    nameCode.textContent = tableName;
    hdr.appendChild(nameCode);

    const rmBtn = el('button', '', 'Remove');
    rmBtn.className = 'btn btn-secondary btn-sm';
    rmBtn.addEventListener('click', () => onRemove(rmBtn));
    hdr.appendChild(rmBtn);
    card.appendChild(hdr);

    // Body — metadata form
    const body = el('div', 'padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px;');

    // Display Name
    const nameGroup = document.createElement('div');
    const nameLbl = el('label', '', 'Display Name');
    nameLbl.className = 'adm-field-label';
    const nameInp = el('input');
    nameInp.className = 'adm-input w-full';
    nameInp.type = 'text';
    nameInp.value = meta.display_name || '';
    nameInp.placeholder = tableName;
    nameGroup.append(nameLbl, nameInp);
    body.appendChild(nameGroup);

    // Icon
    const iconGroup = document.createElement('div');
    const iconLbl = el('label', '', 'Menu Icon');
    iconLbl.className = 'adm-field-label';
    iconGroup.appendChild(iconLbl);
    const { wrap: iconWrap, sel: iconSel } = makeIconSelect(meta.icon || '');
    iconGroup.appendChild(iconWrap);
    body.appendChild(iconGroup);

    // Hidden — full width
    const hiddenGroup = el('div', 'grid-column:1/-1;display:flex;align-items:center;gap:8px;');
    const hiddenId = 'fdw-hidden-' + tableName;
    const hiddenCb = document.createElement('input');
    hiddenCb.type = 'checkbox';
    hiddenCb.id = hiddenId;
    hiddenCb.checked = !!meta.hidden;
    hiddenCb.className = 'adm-check';
    const hiddenLbl = document.createElement('label');
    hiddenLbl.htmlFor = hiddenId;
    hiddenLbl.style.cursor = 'pointer';
    hiddenLbl.textContent = 'Hide from menu';
    hiddenGroup.append(hiddenCb, hiddenLbl);
    body.appendChild(hiddenGroup);

    // Save row
    const saveRow = el('div', 'grid-column:1/-1;display:flex;justify-content:flex-end;padding-top:4px;');
    const saveBtn = el('button', '', 'Save');
    saveBtn.className = 'btn btn-success';
    saveBtn.addEventListener('click', async () => {
        try {
            const r = await fdwApi('mysql_meta_save', {
                method: 'POST',
                body: {
                    table:        tableName,
                    display_name: nameInp.value.trim(),
                    icon:         iconSel.value,
                    hidden:       hiddenCb.checked,
                },
            });
            showStatusPill(saveBtn, r.status === 'success' ? 'Saved' : (r.error || 'Failed'), r.status === 'success' ? 'success' : 'error');
        } catch (e) {
            showStatusPill(saveBtn, 'Request failed', 'error');
        }
    });
    saveRow.appendChild(saveBtn);
    body.appendChild(saveRow);

    card.appendChild(body);
    container.appendChild(card);
}

function renderTablesTab(panel, status) {
    let currentTables = [...(status.mysql_tables || [])];
    const tableMeta   = status.table_meta || {};

    panel.appendChild(el('h3', 'margin:0 0 4px;font-size:15px;font-weight:600;', 'MySQL Table Routing'));
    panel.appendChild(el('p', 'margin:0 0 16px;font-size:13px;color:#64748B;',
        'Tables listed here are served from MySQL by the Gateway. Configure each table\'s display name, icon, and menu visibility below.'));

    // Add row — placed above the card list so new tables are easy to add
    const addRow = el('div', 'display:flex;gap:8px;margin-bottom:16px;');
    const inp = el('input', '', '');
    inp.className = 'adm-input flex-1 mono';
    inp.type = 'text';
    inp.placeholder = 'table_or_view_name';
    const addBtn = el('button', '', 'Add');
    addBtn.className = 'btn btn-primary';
    addRow.append(inp, addBtn);
    panel.appendChild(addRow);

    // Cards container
    const cardsEl = document.createElement('div');
    panel.appendChild(cardsEl);

    async function saveList(anchor) {
        try {
            const r = await fdwApi('mysql_tables_save', { method: 'POST', body: { mysql_tables: currentTables } });
            if (r.status !== 'success') showStatusPill(anchor, r.error || 'Failed to save', 'error');
        } catch (e) {
            showStatusPill(anchor, 'Request failed', 'error');
        }
    }

    function refreshCards() {
        cardsEl.innerHTML = '';
        if (currentTables.length === 0) {
            cardsEl.appendChild(el('p', 'color:#94A3B8;font-size:13px;margin:0;', 'No MySQL tables configured.'));
            return;
        }
        for (const t of currentTables) {
            renderTableCard(cardsEl, t, tableMeta[t] || {}, async (rmBtn) => {
                currentTables = currentTables.filter(x => x !== t);
                await saveList(rmBtn);
                refreshCards();
            });
        }
    }

    addBtn.addEventListener('click', async () => {
        const v = inp.value.trim();
        if (!v || !/^[A-Za-z_][A-Za-z0-9_]*$/.test(v)) { showStatusPill(addBtn, 'Invalid name', 'error'); return; }
        if (currentTables.includes(v)) { showStatusPill(addBtn, 'Already in list', 'info'); return; }
        currentTables.push(v);
        inp.value = '';
        await saveList(addBtn);
        refreshCards();
    });
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') addBtn.click(); });

    refreshCards();
}
