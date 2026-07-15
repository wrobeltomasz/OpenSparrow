// admin/js/add_table.js — "Add Table" wizard: builds a new DB table from column definitions/presets and creates it via api.php (create_table). COLUMN_TYPES list.
import { apiFetch } from '../../assets/js/util/api.js';
import { showStatusPill } from './app.js';

const COLUMN_TYPES = [
    { value: 'varchar(255)', label: 'varchar(255) — short text' },
    { value: 'text',         label: 'text — long text' },
    { value: 'int4',         label: 'int4 — integer' },
    { value: 'int8',         label: 'int8 — big integer' },
    { value: 'boolean',      label: 'boolean' },
    { value: 'date',         label: 'date' },
    { value: 'timestamp',    label: 'timestamp' },
];

// Preset column definitions
const PRESET_TIMESTAMPS = [
    { name: 'created_at', type: 'timestamp', not_null: true,  default: 'now()', index: '', comment: '', fk_table: '', fk_column: '' },
    { name: 'updated_at', type: 'timestamp', not_null: true,  default: 'now()', index: '', comment: '', fk_table: '', fk_column: '' },
];


function post(action, body) {
    return apiFetch('api.php?action=' + action, {
        method: 'POST',
        body: JSON.stringify(body),
    }).then(r => r.json());
}

// Returns all columns to be created: user-defined + active presets
function buildAllColumns(state) {
    const all = [...state.columns];
    if (state.presetTimestamps) all.push(...PRESET_TIMESTAMPS);
    return all;
}

export function renderAddTableEditor(ctx) {
    const { workspaceEl, getTableOptions, getColumnOptionsForTable } = ctx;
    workspaceEl.innerHTML = '';

    const state = {
        tableName:        '',
        displayName:      '',
        schema:           'public',
        columns:          [],
        presetTimestamps: false,
        registerInSchema: true,
    };

    // -- Page header -------------------------------------------------------
    const h2 = document.createElement('h2');
    h2.style.marginTop = '0';
    h2.textContent = 'Add New Table';
    workspaceEl.appendChild(h2);

    const intro = document.createElement('p');
    intro.style.cssText = 'color:var(--muted);font-size:14px;margin-bottom:28px;';
    intro.textContent = 'Creates the table in the database. An id serial primary key column is always added automatically.';
    workspaceEl.appendChild(intro);

    const form = document.createElement('div');
    form.style.maxWidth = '640px';
    workspaceEl.appendChild(form);

    // -- Table Name --------------------------------------------------------
    const nameGroup = document.createElement('div');
    nameGroup.className = 'form-group';
    const nameLabel = document.createElement('label');
    nameLabel.textContent = 'Table Name';
    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.placeholder = 'e.g. products';
    nameInput.style.maxWidth = '320px';
    nameInput.addEventListener('input', () => {
        state.tableName = nameInput.value.toLowerCase().replace(/[^a-z0-9_]/g, '');
        nameInput.value = state.tableName;
        if (!displayNameTouched) {
            state.displayName = state.tableName.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            displayNameInput.value = state.displayName;
        }
    });
    const nameHint = document.createElement('span');
    nameHint.className = 'help-text';
    nameHint.textContent = 'Lowercase, letters, numbers and underscore only.';
    nameGroup.appendChild(nameLabel);
    nameGroup.appendChild(nameInput);
    nameGroup.appendChild(nameHint);
    form.appendChild(nameGroup);

    // -- Database Schema ---------------------------------------------------
    const schemaGroup = document.createElement('div');
    schemaGroup.className = 'form-group';
    const schemaLabel = document.createElement('label');
    schemaLabel.textContent = 'Database Schema';
    const schemaInput = document.createElement('input');
    schemaInput.type = 'text';
    schemaInput.value = 'public';
    schemaInput.style.maxWidth = '200px';
    schemaInput.addEventListener('input', () => {
        state.schema = schemaInput.value.toLowerCase().replace(/[^a-z0-9_]/g, '');
        schemaInput.value = state.schema;
    });
    const schemaHint = document.createElement('span');
    schemaHint.className = 'help-text';
    schemaHint.textContent = 'Usually "public". Must match the PostgreSQL schema.';
    schemaGroup.appendChild(schemaLabel);
    schemaGroup.appendChild(schemaInput);
    schemaGroup.appendChild(schemaHint);
    form.appendChild(schemaGroup);

    // -- Display Name ------------------------------------------------------
    let displayNameTouched = false;
    const displayNameGroup = document.createElement('div');
    displayNameGroup.className = 'form-group';
    const displayNameLabel = document.createElement('label');
    displayNameLabel.textContent = 'Display Name';
    const displayNameInput = document.createElement('input');
    displayNameInput.type = 'text';
    displayNameInput.placeholder = 'e.g. Products';
    displayNameInput.style.maxWidth = '320px';
    displayNameInput.addEventListener('input', () => {
        displayNameTouched = true;
        state.displayName = displayNameInput.value;
    });
    const displayNameHint = document.createElement('span');
    displayNameHint.className = 'help-text';
    displayNameHint.textContent = 'Label shown in menus and headings (auto-filled from table name).';
    displayNameGroup.appendChild(displayNameLabel);
    displayNameGroup.appendChild(displayNameInput);
    displayNameGroup.appendChild(displayNameHint);
    form.appendChild(displayNameGroup);

    // -- Preset Columns ----------------------------------------------------
    const presetsWrap = document.createElement('div');
    presetsWrap.style.cssText = 'margin-top:24px;padding:16px;background:var(--accent-light);border-radius:var(--radius);border:1px solid var(--border-light);';

    const presetsTitle = document.createElement('h4');
    presetsTitle.style.cssText = 'margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);';
    presetsTitle.textContent = 'Column Presets';
    presetsWrap.appendChild(presetsTitle);

    function makePresetRow(labelText, description, onChange) {
        const row = document.createElement('label');
        row.style.cssText = 'display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:8px;';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.style.marginTop = '3px';
        cb.addEventListener('change', () => onChange(cb.checked));
        const textWrap = document.createElement('span');
        const strong = document.createElement('strong');
        strong.style.fontSize = '13px';
        strong.textContent = labelText;
        const desc = document.createElement('span');
        desc.style.cssText = 'display:block;font-size:12px;color:var(--muted);margin-top:2px;';
        desc.textContent = description;
        textWrap.appendChild(strong);
        textWrap.appendChild(desc);
        row.appendChild(cb);
        row.appendChild(textWrap);
        return row;
    }

    presetsWrap.appendChild(makePresetRow(
        'Timestamps',
        'Adds created_at timestamp DEFAULT now() NOT NULL, updated_at timestamp DEFAULT now() NOT NULL',
        checked => { state.presetTimestamps = checked; }
    ));

    form.appendChild(presetsWrap);

    // -- User-defined Columns ----------------------------------------------
    const columnsWrap = document.createElement('div');
    columnsWrap.style.marginTop = '28px';
    form.appendChild(columnsWrap);

    function renderColumns() {
        columnsWrap.innerHTML = '';

        const colTitle = document.createElement('h3');
        colTitle.style.marginBottom = '12px';
        colTitle.textContent = 'Additional Columns';
        columnsWrap.appendChild(colTitle);

        // Fixed id row (read-only)
        const idRow = document.createElement('div');
        idRow.className = 'column-block';
        idRow.style.cssText = 'border-left:4px solid var(--muted);opacity:.7;display:flex;align-items:center;gap:16px;padding:12px 16px;';
        idRow.innerHTML = '<strong style="min-width:80px;">id</strong><span style="font-size:13px;color:var(--muted);">serial PRIMARY KEY — added automatically</span>';
        columnsWrap.appendChild(idRow);

        state.columns.forEach((col, index) => {
            const block = document.createElement('div');
            block.className = 'column-block';
            block.style.borderLeft = '4px solid var(--accent)';

            // Block header
            const blockHead = document.createElement('div');
            blockHead.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;';
            const colNum = document.createElement('h4');
            colNum.style.margin = '0';
            colNum.textContent = col.name ? `Column: ${col.name}` : `Column ${index + 1}`;
            const removeBtn = document.createElement('button');
            removeBtn.textContent = 'Remove';
            removeBtn.style.cssText = 'background:none;border:none;color:var(--danger);cursor:pointer;font-size:13px;font-weight:600;';
            removeBtn.addEventListener('click', () => { state.columns.splice(index, 1); renderColumns(); });
            blockHead.appendChild(colNum);
            blockHead.appendChild(removeBtn);
            block.appendChild(blockHead);

            // Name
            appendField(block, 'Column Name', () => {
                const inp = document.createElement('input');
                inp.type = 'text';
                inp.value = col.name;
                inp.placeholder = 'e.g. email';
                inp.style.maxWidth = '280px';
                inp.addEventListener('input', () => {
                    col.name = inp.value.toLowerCase().replace(/[^a-z0-9_]/g, '');
                    inp.value = col.name;
                    colNum.textContent = col.name ? `Column: ${col.name}` : `Column ${index + 1}`;
                });
                return inp;
            });

            // Type
            appendField(block, 'Type', () => {
                const sel = document.createElement('select');
                sel.style.maxWidth = '300px';
                COLUMN_TYPES.forEach(({ value, label }) => {
                    const opt = document.createElement('option');
                    opt.value = value; opt.textContent = label;
                    if (value === col.type) opt.selected = true;
                    sel.appendChild(opt);
                });
                sel.addEventListener('change', () => { col.type = sel.value; });
                return sel;
            });

            // NOT NULL
            appendField(block, 'Not Null', () => {
                const wrap = document.createElement('div');
                wrap.style.cssText = 'display:flex;align-items:center;gap:8px;';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.checked = !!col.not_null;
                cb.addEventListener('change', () => { col.not_null = cb.checked; });
                const lbl = document.createElement('span');
                lbl.style.cssText = 'font-size:13px;color:var(--muted);';
                lbl.textContent = 'Requires a Default value if the table already has rows.';
                wrap.appendChild(cb);
                wrap.appendChild(lbl);
                return wrap;
            });

            // Default
            appendField(block, 'Default', () => {
                const inp = document.createElement('input');
                inp.type = 'text';
                inp.value = col.default || '';
                inp.placeholder = 'e.g. 0, now(), true, \'active\'';
                inp.style.maxWidth = '280px';
                inp.addEventListener('input', () => { col.default = inp.value; });
                return inp;
            }, 'Expressions: now(), current_timestamp, true, false, null. Numbers and quoted strings also accepted.');

            // Index
            appendField(block, 'Index', () => {
                const sel = document.createElement('select');
                sel.style.maxWidth = '260px';
                [
                    { value: '',       label: 'none' },
                    { value: 'btree',  label: 'btree — standard (=, <, >, LIKE prefix)' },
                    { value: 'hash',   label: 'hash — equality only' },
                    { value: 'unique', label: 'unique — enforces uniqueness' },
                ].forEach(({ value, label }) => {
                    const opt = document.createElement('option');
                    opt.value = value; opt.textContent = label;
                    if (value === (col.index || '')) opt.selected = true;
                    sel.appendChild(opt);
                });
                sel.addEventListener('change', () => { col.index = sel.value; });
                return sel;
            });

            // Comment
            appendField(block, 'Comment', () => {
                const inp = document.createElement('input');
                inp.type = 'text';
                inp.value = col.comment || '';
                inp.placeholder = 'Optional — stored as COMMENT ON COLUMN';
                inp.addEventListener('input', () => { col.comment = inp.value; });
                return inp;
            });

            // Foreign Key
            appendField(block, 'Foreign Key', () => {
                const row = document.createElement('div');
                row.style.cssText = 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;';

                const fkTableSel = document.createElement('select');
                fkTableSel.style.maxWidth = '200px';
                const tableOptions = getTableOptions ? getTableOptions() : [{ value: '', label: '— no schema loaded —' }];
                tableOptions.forEach(({ value, label }) => {
                    const opt = document.createElement('option');
                    opt.value = value; opt.textContent = label;
                    if (value === (col.fk_table || '')) opt.selected = true;
                    fkTableSel.appendChild(opt);
                });

                const fkColSel = document.createElement('select');
                fkColSel.style.maxWidth = '180px';

                function populateFkCols(tableName) {
                    fkColSel.innerHTML = '';
                    const opts = getColumnOptionsForTable ? getColumnOptionsForTable(tableName) : [{ value: '', label: '— select table first —' }];
                    opts.forEach(({ value, label }) => {
                        const opt = document.createElement('option');
                        opt.value = value; opt.textContent = label;
                        if (value === (col.fk_column || '')) opt.selected = true;
                        fkColSel.appendChild(opt);
                    });
                }
                populateFkCols(col.fk_table || '');

                fkTableSel.addEventListener('change', () => {
                    col.fk_table = fkTableSel.value;
                    col.fk_column = '';
                    populateFkCols(col.fk_table);
                });
                fkColSel.addEventListener('change', () => { col.fk_column = fkColSel.value; });

                row.appendChild(fkTableSel);
                row.appendChild(fkColSel);
                return row;
            }, 'Optional — adds FOREIGN KEY constraint referencing the selected table/column.');

            columnsWrap.appendChild(block);
        });

        // + Add Column
        const addColBtn = document.createElement('button');
        addColBtn.className = 'btn-add';
        addColBtn.textContent = '+ Add Column';
        addColBtn.style.marginTop = '8px';
        addColBtn.addEventListener('click', () => {
            state.columns.push({ name: '', type: 'varchar(255)', not_null: false, default: '', index: '', comment: '', fk_table: '', fk_column: '' });
            renderColumns();
            const inputs = columnsWrap.querySelectorAll('input[type="text"]');
            if (inputs.length) inputs[inputs.length - 1].focus();
        });
        columnsWrap.appendChild(addColBtn);
    }

    renderColumns();

    // -- Register in schema.json -------------------------------------------
    const registerWrap = document.createElement('div');
    registerWrap.style.cssText = 'margin-top:24px;padding:16px;background:var(--accent-light);border-radius:var(--radius);border:1px solid var(--border-light);';

    const registerLabel = document.createElement('label');
    registerLabel.style.cssText = 'display:flex;align-items:flex-start;gap:10px;cursor:pointer;';
    const registerCb = document.createElement('input');
    registerCb.type = 'checkbox';
    registerCb.checked = true;
    registerCb.style.marginTop = '3px';
    registerCb.addEventListener('change', () => { state.registerInSchema = registerCb.checked; });
    const registerTextWrap = document.createElement('span');
    const registerStrong = document.createElement('strong');
    registerStrong.style.fontSize = '13px';
    registerStrong.textContent = 'Register in schema.json';
    const registerDesc = document.createElement('span');
    registerDesc.style.cssText = 'display:block;font-size:12px;color:var(--muted);margin-top:2px;';
    registerDesc.textContent = 'Adds the table to config/schema.json so it appears in the admin panel immediately.';
    registerTextWrap.appendChild(registerStrong);
    registerTextWrap.appendChild(registerDesc);
    registerLabel.appendChild(registerCb);
    registerLabel.appendChild(registerTextWrap);
    registerWrap.appendChild(registerLabel);
    form.appendChild(registerWrap);

    // -- Submit ------------------------------------------------------------
    const submitWrap = document.createElement('div');
    submitWrap.style.marginTop = '32px';
    const submitBtn = document.createElement('button');
    submitBtn.className = 'btn-add';
    submitBtn.style.cssText = 'background:var(--accent);font-size:15px;padding:11px 28px;';
    submitBtn.textContent = 'Create Table';
    const statusAnchor = document.createElement('span');
    submitWrap.appendChild(submitBtn);
    submitWrap.appendChild(statusAnchor);
    form.appendChild(submitWrap);

    submitBtn.addEventListener('click', async () => {
        if (!state.tableName) {
            showStatusPill(statusAnchor, 'Table name is required.', 'error');
            nameInput.focus();
            return;
        }
        for (let i = 0; i < state.columns.length; i++) {
            if (!state.columns[i].name) {
                showStatusPill(statusAnchor, `Column ${i + 1} has no name.`, 'error');
                return;
            }
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating…';

        try {
            // 1. Create table (id only)
            const createData = await post('create_table', { schema: state.schema, table: state.tableName });
            if (createData.status !== 'success') {
                showStatusPill(statusAnchor, createData.error || 'Failed to create table.', 'error');
                return;
            }

            // 2. Add all columns (user-defined + presets)
            for (const col of buildAllColumns(state)) {
                const payload = { schema: state.schema, table: state.tableName, column: col.name, type: col.type };
                if (col.not_null)                    payload.not_null  = true;
                if (col.default)                     payload.default   = col.default;
                if (col.index)                       payload.index     = col.index;
                if (col.comment)                     payload.comment   = col.comment;
                if (col.fk_table && col.fk_column) { payload.fk_table = col.fk_table; payload.fk_column = col.fk_column; }

                const colData = await post('add_column', payload);
                if (colData.status !== 'success') {
                    showStatusPill(statusAnchor, `Table created but column "${col.name}" failed: ${colData.error}`, 'error');
                    return;
                }
            }

            // 3. Register in schema.json if requested
            if (state.registerInSchema) {
                const regData = await post('schema_add_table', {
                    table:        state.tableName,
                    schema:       state.schema,
                    display_name: state.displayName || state.tableName,
                    columns:      buildAllColumns(state).map(col => ({
                        name:        col.name,
                        type:        col.type,
                        not_null:    col.not_null || false,
                        display_name: col.name.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
                        description: col.comment || '',
                        fk_table:    col.fk_table  || '',
                        fk_column:   col.fk_column || '',
                    })),
                });
                if (regData.status !== 'success') {
                    showStatusPill(statusAnchor, `Table created but schema.json registration failed: ${regData.error}`, 'error');
                    return;
                }
            }

            showStatusPill(statusAnchor, `Table "${state.tableName}" created successfully!`, 'success');

            // Reset form
            state.tableName = '';
            state.displayName = '';
            state.columns = [];
            state.presetTimestamps = false;
            displayNameTouched = false;
            nameInput.value = '';
            displayNameInput.value = '';
            schemaInput.value = state.schema;
            renderColumns();

        } catch (err) {
            showStatusPill(statusAnchor, 'Network error: ' + err.message, 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create Table';
        }
    });
}

// Helper: appends a form-group with label + control + optional hint to parent
function appendField(parent, labelText, buildControl, hintText) {
    const grp = document.createElement('div');
    grp.className = 'form-group';
    const lbl = document.createElement('label');
    lbl.textContent = labelText;
    grp.appendChild(lbl);
    grp.appendChild(buildControl());
    if (hintText) {
        const hint = document.createElement('span');
        hint.className = 'help-text';
        hint.textContent = hintText;
        grp.appendChild(hint);
    }
    parent.appendChild(grp);
}
