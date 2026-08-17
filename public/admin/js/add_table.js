// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

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

const PRESET_TIMESTAMPS = [
    { name: 'created_at', type: 'timestamp', not_null: true,  default: 'now()', index: '', comment: '', fk_table: '', fk_column: '' },
    { name: 'updated_at', type: 'timestamp', not_null: true,  default: 'now()', index: '', comment: '', fk_table: '', fk_column: '' },
];

function post(action, body) {
    return apiFetch('api.php?action=' + action, {
        method: 'POST',
        body: JSON.stringify(body),
    }).then(response => response.json());
}

function buildAllColumns(state) {
    const all = [...state.columns];
    if (state.presetTimestamps) all.push(...PRESET_TIMESTAMPS);
    return all;
}

export function renderAddTableEditor(context) {
    const { workspaceEl: workspaceElement, getTableOptions, getColumnOptionsForTable } = context;
    workspaceElement.innerHTML = '';

    const state = {
        tableName:        '',
        displayName:      '',
        schema:           'public',
        columns:          [],
        presetTimestamps: false,
        registerInSchema: true,
    };

    const h2 = document.createElement('h2');
    h2.style.marginTop = '0';
    h2.textContent = 'Add New Table';
    workspaceElement.appendChild(h2);

    const intro = document.createElement('p');
    intro.style.cssText = 'margin-bottom:28px;';
    intro.textContent = 'Creates the table in the database. An id serial primary key column is always added automatically.';
    workspaceElement.appendChild(intro);

    const form = document.createElement('div');
    form.style.maxWidth = '640px';
    workspaceElement.appendChild(form);

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
            state.displayName = state.tableName.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase());
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

    const presetsWrap = document.createElement('div');
    presetsWrap.style.cssText = 'margin-top:24px;padding:16px;background:var(--accent-light);border-radius:var(--radius);border:1px solid var(--border-light);';

    const presetsTitle = document.createElement('h4');
    presetsTitle.style.cssText = 'margin:0 0 12px;';
    presetsTitle.textContent = 'Column Presets';
    presetsWrap.appendChild(presetsTitle);

    function makePresetRow(labelText, description, onChange) {
        const row = document.createElement('label');
        row.style.cssText = 'display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:8px;';
        const callback = document.createElement('input');
        callback.type = 'checkbox';
        callback.style.marginTop = '3px';
        callback.addEventListener('change', () => onChange(callback.checked));
        const textWrap = document.createElement('span');
        const strong = document.createElement('strong');
        strong.textContent = labelText;
        const desc = document.createElement('span');
        desc.style.cssText = 'display:block;margin-top:2px;';
        desc.textContent = description;
        textWrap.appendChild(strong);
        textWrap.appendChild(desc);
        row.appendChild(callback);
        row.appendChild(textWrap);
        return row;
    }

    presetsWrap.appendChild(makePresetRow(
        'Timestamps',
        'Adds created_at timestamp DEFAULT now() NOT NULL, updated_at timestamp DEFAULT now() NOT NULL',
        checked => { state.presetTimestamps = checked; }
    ));

    form.appendChild(presetsWrap);

    const columnsWrap = document.createElement('div');
    columnsWrap.style.marginTop = '28px';
    form.appendChild(columnsWrap);

    function renderColumns() {
        columnsWrap.innerHTML = '';

        const columnTitle = document.createElement('h3');
        columnTitle.style.marginBottom = '12px';
        columnTitle.textContent = 'Additional Columns';
        columnsWrap.appendChild(columnTitle);

        const idRow = document.createElement('div');
        idRow.className = 'column-block';
        idRow.style.cssText = 'border-left:4px solid var(--muted);opacity:.7;display:flex;align-items:center;gap:16px;padding:12px 16px;';
        idRow.innerHTML = '<strong style="min-width:80px;">id</strong><span style="">serial PRIMARY KEY — added automatically</span>';
        columnsWrap.appendChild(idRow);

        state.columns.forEach((columnName, index) => {
            const block = document.createElement('div');
            block.className = 'column-block';
            block.style.borderLeft = '4px solid var(--accent)';

            const blockHead = document.createElement('div');
            blockHead.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;';
            const columnNumber = document.createElement('h4');
            columnNumber.style.margin = '0';
            columnNumber.textContent = columnName.name ? `Column: ${columnName.name}` : `Column ${index + 1}`;
            const removeButton = document.createElement('button');
            removeButton.textContent = 'Remove';
            removeButton.className = 'btn btn-secondary btn-sm';
            removeButton.addEventListener('click', () => { state.columns.splice(index, 1); renderColumns(); });
            blockHead.appendChild(columnNumber);
            blockHead.appendChild(removeButton);
            block.appendChild(blockHead);

            appendField(block, 'Column Name', () => {
                const input = document.createElement('input');
                input.type = 'text';
                input.value = columnName.name;
                input.placeholder = 'e.g. email';
                input.style.maxWidth = '280px';
                input.addEventListener('input', () => {
                    columnName.name = input.value.toLowerCase().replace(/[^a-z0-9_]/g, '');
                    input.value = columnName.name;
                    columnNumber.textContent = columnName.name ? `Column: ${columnName.name}` : `Column ${index + 1}`;
                });
                return input;
            });

            appendField(block, 'Type', () => {
                const selectElement = document.createElement('select');
                selectElement.style.maxWidth = '300px';
                COLUMN_TYPES.forEach(({ value, label }) => {
                    const option = document.createElement('option');
                    option.value = value; option.textContent = label;
                    if (value === columnName.type) option.selected = true;
                    selectElement.appendChild(option);
                });
                selectElement.addEventListener('change', () => { columnName.type = selectElement.value; });
                return selectElement;
            });

            appendField(block, 'Not Null', () => {
                const wrap = document.createElement('div');
                wrap.style.cssText = 'display:flex;align-items:center;gap:8px;';
                const callback = document.createElement('input');
                callback.type = 'checkbox';
                callback.checked = !!columnName.not_null;
                callback.addEventListener('change', () => { columnName.not_null = callback.checked; });
                const labelElement = document.createElement('span');
                labelElement.style.cssText = '';
                labelElement.textContent = 'Requires a Default value if the table already has rows.';
                wrap.appendChild(callback);
                wrap.appendChild(labelElement);
                return wrap;
            });

            appendField(block, 'Default', () => {
                const input = document.createElement('input');
                input.type = 'text';
                input.value = columnName.default || '';
                input.placeholder = 'e.g. 0, now(), true, \'active\'';
                input.style.maxWidth = '280px';
                input.addEventListener('input', () => { columnName.default = input.value; });
                return input;
            }, 'Expressions: now(), current_timestamp, true, false, null. Numbers and quoted strings also accepted.');

            appendField(block, 'Index', () => {
                const selectElement = document.createElement('select');
                selectElement.style.maxWidth = '260px';
                [
                    { value: '',       label: 'none' },
                    { value: 'btree',  label: 'btree — standard (=, <, >, LIKE prefix)' },
                    { value: 'hash',   label: 'hash — equality only' },
                    { value: 'unique', label: 'unique — enforces uniqueness' },
                ].forEach(({ value, label }) => {
                    const option = document.createElement('option');
                    option.value = value; option.textContent = label;
                    if (value === (columnName.index || '')) option.selected = true;
                    selectElement.appendChild(option);
                });
                selectElement.addEventListener('change', () => { columnName.index = selectElement.value; });
                return selectElement;
            });

            appendField(block, 'Comment', () => {
                const input = document.createElement('input');
                input.type = 'text';
                input.value = columnName.comment || '';
                input.placeholder = 'Optional — stored as COMMENT ON COLUMN';
                input.addEventListener('input', () => { columnName.comment = input.value; });
                return input;
            });

            appendField(block, 'Foreign Key', () => {
                const row = document.createElement('div');
                row.style.cssText = 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;';

                const fkTableSelect = document.createElement('select');
                fkTableSelect.style.maxWidth = '200px';
                const tableOptions = getTableOptions ? getTableOptions() : [{ value: '', label: '— no schema loaded —' }];
                tableOptions.forEach(({ value, label }) => {
                    const option = document.createElement('option');
                    option.value = value; option.textContent = label;
                    if (value === (columnName.fk_table || '')) option.selected = true;
                    fkTableSelect.appendChild(option);
                });

                const fkColumnSelect = document.createElement('select');
                fkColumnSelect.style.maxWidth = '180px';

                function populateFkColumns(tableName) {
                    fkColumnSelect.innerHTML = '';
                    const options = getColumnOptionsForTable ? getColumnOptionsForTable(tableName) : [{ value: '', label: '— select table first —' }];
                    options.forEach(({ value, label }) => {
                        const option = document.createElement('option');
                        option.value = value; option.textContent = label;
                        if (value === (columnName.fk_column || '')) option.selected = true;
                        fkColumnSelect.appendChild(option);
                    });
                }
                populateFkColumns(columnName.fk_table || '');

                fkTableSelect.addEventListener('change', () => {
                    columnName.fk_table = fkTableSelect.value;
                    columnName.fk_column = '';
                    populateFkColumns(columnName.fk_table);
                });
                fkColumnSelect.addEventListener('change', () => { columnName.fk_column = fkColumnSelect.value; });

                row.appendChild(fkTableSelect);
                row.appendChild(fkColumnSelect);
                return row;
            }, 'Optional — adds FOREIGN KEY constraint referencing the selected table/column.');

            columnsWrap.appendChild(block);
        });

        const addColumnButton = document.createElement('button');
        addColumnButton.className = 'btn btn-success';
        addColumnButton.textContent = '+ Add Column';
        addColumnButton.style.marginTop = '8px';
        addColumnButton.addEventListener('click', () => {
            state.columns.push({ name: '', type: 'varchar(255)', not_null: false, default: '', index: '', comment: '', fk_table: '', fk_column: '' });
            renderColumns();
            const inputs = columnsWrap.querySelectorAll('input[type="text"]');
            if (inputs.length) inputs[inputs.length - 1].focus();
        });
        columnsWrap.appendChild(addColumnButton);
    }

    renderColumns();

    const registerWrap = document.createElement('div');
    registerWrap.style.cssText = 'margin-top:24px;padding:16px;background:var(--accent-light);border-radius:var(--radius);border:1px solid var(--border-light);';

    const registerLabel = document.createElement('label');
    registerLabel.style.cssText = 'display:flex;align-items:flex-start;gap:10px;cursor:pointer;';
    const registerCallback = document.createElement('input');
    registerCallback.type = 'checkbox';
    registerCallback.checked = true;
    registerCallback.style.marginTop = '3px';
    registerCallback.addEventListener('change', () => { state.registerInSchema = registerCallback.checked; });
    const registerTextWrap = document.createElement('span');
    const registerStrong = document.createElement('strong');
    registerStrong.textContent = 'Register in app schema';
    const registerDescription = document.createElement('span');
    registerDescription.style.cssText = 'display:block;margin-top:2px;';
    registerDescription.textContent = 'Adds the table to the app schema configuration so it appears in the admin panel immediately.';
    registerTextWrap.appendChild(registerStrong);
    registerTextWrap.appendChild(registerDescription);
    registerLabel.appendChild(registerCallback);
    registerLabel.appendChild(registerTextWrap);
    registerWrap.appendChild(registerLabel);
    form.appendChild(registerWrap);

    const submitWrap = document.createElement('div');
    submitWrap.style.marginTop = '32px';
    const submitButton = document.createElement('button');
    submitButton.className = 'btn btn-primary';
    submitButton.textContent = 'Create Table';
    const statusAnchor = document.createElement('span');
    submitWrap.appendChild(submitButton);
    submitWrap.appendChild(statusAnchor);
    form.appendChild(submitWrap);

    submitButton.addEventListener('click', async () => {
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

        submitButton.disabled = true;
        submitButton.textContent = 'Creating…';

        try {
            const createData = await post('create_table', { schema: state.schema, table: state.tableName });
            if (createData.status !== 'success') {
                showStatusPill(statusAnchor, createData.error || 'Failed to create table.', 'error');
                return;
            }

            for (const columnName of buildAllColumns(state)) {
                const payload = { schema: state.schema, table: state.tableName, column: columnName.name, type: columnName.type };
                if (columnName.not_null)                    payload.not_null  = true;
                if (columnName.default)                     payload.default   = columnName.default;
                if (columnName.index)                       payload.index     = columnName.index;
                if (columnName.comment)                     payload.comment   = columnName.comment;
                if (columnName.fk_table && columnName.fk_column) { payload.fk_table = columnName.fk_table; payload.fk_column = columnName.fk_column; }

                const columnData = await post('add_column', payload);
                if (columnData.status !== 'success') {
                    showStatusPill(statusAnchor, `Table created but column "${columnName.name}" failed: ${columnData.error}`, 'error');
                    return;
                }
            }

            if (state.registerInSchema) {
                const regData = await post('schema_add_table', {
                    table:        state.tableName,
                    schema:       state.schema,
                    display_name: state.displayName || state.tableName,
                    columns:      buildAllColumns(state).map(columnName => ({
                        name:        columnName.name,
                        type:        columnName.type,
                        not_null:    columnName.not_null || false,
                        display_name: columnName.name.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase()),
                        description: columnName.comment || '',
                        fk_table:    columnName.fk_table  || '',
                        fk_column:   columnName.fk_column || '',
                    })),
                });
                if (regData.status !== 'success') {
                    showStatusPill(statusAnchor, `Table created but schema registration failed: ${regData.error}`, 'error');
                    return;
                }
            }

            showStatusPill(statusAnchor, `Table "${state.tableName}" created successfully!`, 'success');

            state.tableName = '';
            state.displayName = '';
            state.columns = [];
            state.presetTimestamps = false;
            displayNameTouched = false;
            nameInput.value = '';
            displayNameInput.value = '';
            schemaInput.value = state.schema;
            renderColumns();
        } catch (error) {
            showStatusPill(statusAnchor, 'Network error: ' + error.message, 'error');
        } finally {
            submitButton.disabled = false;
            submitButton.textContent = 'Create Table';
        }
    });
}

function appendField(parent, labelText, buildControl, hintText) {
    const group = document.createElement('div');
    group.className = 'form-group';
    const labelElement = document.createElement('label');
    labelElement.textContent = labelText;
    group.appendChild(labelElement);
    group.appendChild(buildControl());
    if (hintText) {
        const hint = document.createElement('span');
        hint.className = 'help-text';
        hint.textContent = hintText;
        group.appendChild(hint);
    }
    parent.appendChild(group);
}
