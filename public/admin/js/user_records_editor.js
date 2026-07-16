// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// admin/js/user_records_editor.js — User Records module admin editor (renderUserRecordsEditor):
// edits the "user_records" config, which drives the front-end "My records" panel opened from the
// avatar menu (public/assets/js/user-menu.js -> public/api/owners.php action=mine). Two tabs:
// "Column Mapping" (per table, which columns are CONCAT_WS'd into each record's label) and
// "Settings" (how many recently-assigned records are shown per table).
import { markDirty } from './app.js';
import { createPageHeader, buildInnerTabs } from './ui.js';

export function renderUserRecordsEditor(ctx) {
    const { workspaceEl, currentConfig } = ctx;
    workspaceEl.innerHTML = '';

    if (!currentConfig.columns || typeof currentConfig.columns !== 'object' || Array.isArray(currentConfig.columns)) {
        currentConfig.columns = {};
    }
    if (typeof currentConfig.limit !== 'number' || currentConfig.limit < 0) {
        currentConfig.limit = 20;
    }

    const wrap = document.createElement('div');
    wrap.style.cssText = 'padding: 20px 24px; max-width: 900px;';
    wrap.appendChild(createPageHeader(
        'User Records Configuration',
        'Controls the "My records" panel every user opens from the avatar menu: which columns '
        + 'label each assigned record, and how many recently-assigned records are shown per table. '
        + 'Use "Save config" in the top bar to persist.'
    ));

    const tabsContainer = document.createElement('div');
    wrap.appendChild(tabsContainer);
    workspaceEl.appendChild(wrap);

    const [columnsPanel, settingsPanel] = buildInnerTabs(tabsContainer, [
        { label: 'Column Mapping' },
        { label: 'Settings' },
    ]);

    renderColumnsPanel(columnsPanel, currentConfig);
    renderSettingsPanel(settingsPanel, currentConfig);
}

function renderColumnsPanel(panel, currentConfig) {
    panel.innerHTML = '<p class="help-text">Loading tables…</p>';

    fetch('api.php?action=get&file=schema')
        .then((res) => res.json())
        .then((schema) => {
            const tables = (schema && schema.tables) || {};
            const tableNames = Object.keys(tables)
                .filter((t) => !tables[t].hidden)
                .sort((a, b) => (tables[a].display_name || a).localeCompare(tables[b].display_name || b));

            panel.innerHTML = '';

            if (tableNames.length === 0) {
                panel.innerHTML = '<p class="help-text">No tables found.</p>';
                return;
            }

            const intro = document.createElement('p');
            intro.className = 'help-text';
            intro.textContent = 'Pick one or more columns per table. Selected columns are combined '
                + 'into the record label (e.g. "First Name" + "Last Name" → "Jane Doe"). '
                + 'Leave a table unchecked to fall back to its first grid column automatically.';
            panel.appendChild(intro);

            tableNames.forEach((tableName) => {
                panel.appendChild(buildTableBlock(tableName, tables[tableName], currentConfig));
            });
        })
        .catch(() => {
            panel.innerHTML = '<p class="help-text">Failed to load tables.</p>';
        });
}

// Collapsible table card — same .column-block / .block-header / .block-chevron /
// .block-body markup as the Schema tab's per-column editor (admin/style.css), so this
// tab matches the rest of the admin panel instead of introducing a new look.
function buildTableBlock(tableName, tableCfg, currentConfig) {
    const block = document.createElement('div');
    block.className = 'column-block collapsed';

    const headerDiv = document.createElement('div');
    headerDiv.className = 'block-header';
    headerDiv.addEventListener('click', () => block.classList.toggle('collapsed'));

    const chevron = document.createElement('span');
    chevron.className = 'block-chevron';
    chevron.textContent = '▶';

    const h4 = document.createElement('h4');
    h4.textContent = tableCfg.display_name || tableName;
    h4.style.cssText = 'margin:0; border-bottom:none; flex:1;';

    headerDiv.style.cssText = 'display:flex; align-items:center; gap:8px; '
        + 'border-bottom:1px solid #DDEAF4; padding-bottom:5px; margin-bottom:15px;';
    headerDiv.appendChild(chevron);
    headerDiv.appendChild(h4);
    block.appendChild(headerDiv);

    const bodyDiv = document.createElement('div');
    bodyDiv.className = 'block-body';

    const cols = Object.keys(tableCfg.columns || {})
        .filter((c) => (tableCfg.columns[c].type || '') !== 'virtual');

    const grp = document.createElement('div');
    grp.className = 'form-group';
    const label = document.createElement('label');
    label.textContent = 'Label columns';
    grp.appendChild(label);
    grp.appendChild(createColumnMultiSelect(
        cols.map((c) => ({ value: c, label: tableCfg.columns[c].display_name || c })),
        currentConfig.columns[tableName] || [],
        (val) => {
            if (val.length) currentConfig.columns[tableName] = val;
            else delete currentConfig.columns[tableName];
            markDirty();
        }
    ));
    bodyDiv.appendChild(grp);

    block.appendChild(bodyDiv);
    return block;
}

// String-preserving multi-select for a table's own columns. ui.js's createMultiSelect
// coerces option values to numbers (it is built for user-ID lists), which would corrupt
// string column names — same reasoning as board.js's local createColumnMultiSelect.
function createColumnMultiSelect(options, selectedValues, onChange) {
    const container = document.createElement('div');
    container.style.cssText = 'max-height:150px; overflow-y:auto; border:1px solid var(--border); '
        + 'padding:10px; border-radius:4px; background:var(--panel);';

    const selected = Array.isArray(selectedValues) ? [...selectedValues] : [];

    if (options.length === 0) {
        container.innerHTML = '<span style="color:var(--muted); font-size:13px;">No columns available</span>';
        return container;
    }

    options.forEach((opt) => {
        const lbl = document.createElement('label');
        lbl.style.cssText = 'display:flex; align-items:center; margin-bottom:5px; cursor:pointer; font-weight:normal;';

        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.value = opt.value;
        chk.checked = selected.includes(opt.value);
        chk.style.marginRight = '8px';
        chk.addEventListener('change', () => {
            const idx = selected.indexOf(opt.value);
            if (chk.checked && idx === -1) selected.push(opt.value);
            else if (!chk.checked && idx !== -1) selected.splice(idx, 1);
            onChange([...selected]);
        });

        lbl.appendChild(chk);
        lbl.appendChild(document.createTextNode(opt.label));
        container.appendChild(lbl);
    });

    return container;
}

function renderSettingsPanel(panel, currentConfig) {
    panel.style.cssText = 'padding-top:16px; max-width:420px;';

    const grp = document.createElement('div');
    grp.className = 'form-group';

    const label = document.createElement('label');
    label.textContent = 'Recent records limit per table (0 = unlimited)';
    grp.appendChild(label);

    const input = document.createElement('input');
    input.type = 'number';
    input.min = '0';
    input.value = String(currentConfig.limit ?? 20);
    input.addEventListener('input', () => {
        const n = parseInt(input.value, 10);
        currentConfig.limit = Number.isFinite(n) && n >= 0 ? n : 0;
        markDirty();
    });
    grp.appendChild(input);

    const help = document.createElement('span');
    help.className = 'help-text';
    help.textContent = 'In the "My records" panel, only this many most-recently-assigned records '
        + 'are shown per table (ordered by assignment date). Set to 0 to show all.';
    grp.appendChild(help);

    panel.appendChild(grp);
}
