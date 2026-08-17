// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { createTextInput, createNumberInput, createSelectInput, createCheckbox, createColorInput, createIconPicker, moveObjectKey, createMenuPreview } from './ui.js';
import { showStatusPill, markDirty } from './app.js';

import { escHtml } from '../../assets/js/util/esc.js';

export function renderSchemaGlobalSettings(config, context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '';

    const PAGE_SIZES = [10, 25, 50, 100];
    const current = Number(config.default_page_size) || 25;

    const card = document.createElement('div');
    card.style.cssText = 'max-width:560px;';

    const h3 = document.createElement('h3');
    h3.style.cssText = 'margin:0 0 6px;';
    h3.textContent = 'Global Grid Settings';
    const sub = document.createElement('p');
    sub.style.cssText = '  margin:0 0 24px;';
    sub.textContent = 'Settings that apply to all data grids in the frontend application.';
    card.append(h3, sub);

    const row = document.createElement('div');
    row.style.cssText = 'display:flex; align-items:center; gap:16px; padding:16px; background:white; border:1px solid var(--border); border-radius:6px;';

    const labelWrap = document.createElement('div');
    labelWrap.style.flex = '1';
    const lbl = document.createElement('label');
    lbl.style.cssText = 'display:block; font-weight:600;  margin-bottom:4px;';
    lbl.textContent = 'Default Page Size';
    const hint = document.createElement('span');
    hint.style.cssText = ' ';
    hint.textContent = 'Records shown per page. Users can override this per-session from the grid pagination bar.';
    labelWrap.append(lbl, hint);

    const sel = document.createElement('select');
    sel.className = 'adm-input';
    sel.style.minWidth = '80px';
    PAGE_SIZES.forEach(n => {
        const option = document.createElement('option');
        option.value = n;
        option.textContent = n;
        if (n === current) option.selected = true;
        sel.appendChild(option);
    });
    sel.addEventListener('change', () => {
        config.default_page_size = Number(sel.value);
        markDirty();
    });

    row.append(labelWrap, sel);
    card.appendChild(row);

    const note = document.createElement('p');
    note.style.cssText = '  margin-top:12px;';
    note.textContent = 'Stored in the schema configuration as "default_page_size".';
    card.appendChild(note);

    workspaceElement.appendChild(card);
}

export function createAddTableButton(currentConfig, defaultSchema, onSuccess, onError) {
    const buttonAddTable = document.createElement('button');
    buttonAddTable.type = 'button';
    buttonAddTable.className = 'btn btn-success';
    buttonAddTable.textContent = '+ Add Table';
    buttonAddTable.style.marginLeft = '10px';

    buttonAddTable.onclick = async (e) => {
        e.preventDefault();

        const tableName = prompt('Enter new table name (lowercase, no spaces):');
        if (!tableName) return;

        const formattedName = tableName.toLowerCase().replace(/[^a-z0-9_]/g, '');

        if (currentConfig.tables && currentConfig.tables[formattedName]) {
            onError('Table already exists in configuration.');
            return;
        }

        const schemaInput = prompt('Enter database schema name:', defaultSchema || 'public');
        if (!schemaInput) return;

        const formattedSchema = schemaInput.toLowerCase().replace(/[^a-z0-9_]/g, '');

        try {
            const response = await apiFetch('api.php?action=create_table', {
                method: 'POST',

                body: JSON.stringify({ schema: formattedSchema, table: formattedName })
            });

            const result = await response.json();
            if (result.status === 'success') {
                if (!currentConfig.tables) currentConfig.tables = {};

                currentConfig.tables[formattedName] = {
                    display_name: formattedName.replace(/_/g, ' ').toUpperCase(),
                    schema: formattedSchema,
                    columns: {
                        id: { display_name: 'ID', type: 'integer', not_null: true }
                    }
                };
                onSuccess(formattedName);
            } else {
                onError(result.error || 'Failed to create table.');
            }
        } catch (err) {
            console.error(err);
            onError('Network error occurred.');
        }
    };

    return buttonAddTable;
}

export async function syncSchemaTables(currentConfig, schemaName, onSuccess, onError) {
    try {
        const res = await apiFetch('api.php?action=sync_schema', {
            method: 'POST',
            body: JSON.stringify({ schema_name: schemaName })
        });
        const data = await res.json();

        if (data.status === 'success') {
            let addedCount = 0;
            if (!currentConfig.tables || Array.isArray(currentConfig.tables)) currentConfig.tables = {};

            data.tables.forEach(tbl => {
                if (tbl.startsWith('spw_')) return;
                if (!currentConfig.tables[tbl]) {
                    currentConfig.tables[tbl] = { display_name: tbl.replace(/_/g, ' ').toUpperCase(), schema: schemaName, columns: {} };
                    addedCount++;
                }
            });
            onSuccess(addedCount);
        } else {
            onError(data.error || 'Failed to sync tables.');
        }
    } catch (e) {
        console.error(e);
        onError('Error communicating with database.');
    }
}

function buildDefaultSortUI(tableData) {
    if (!Array.isArray(tableData.default_sort)) tableData.default_sort = [];
    const rules = tableData.default_sort;

    const wrapper = document.createElement('div');
    wrapper.style.cssText = 'margin-bottom:15px;';

    const label = document.createElement('label');
    label.style.cssText = 'display:block;   margin-bottom:6px; font-weight:600;';
    label.textContent = 'Default Sort Order';
    wrapper.appendChild(label);

    const listElement = document.createElement('div');
    listElement.style.cssText = 'display:flex; flex-direction:column; gap:6px;';
    wrapper.appendChild(listElement);

    function renderRules() {
        listElement.replaceChildren();
        rules.forEach((rule, i) => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex; align-items:center; gap:8px;';

            const columnInput = document.createElement('select');
            columnInput.className = 'adm-input w-160';
            const blankOption = document.createElement('option');
            blankOption.value = '';
            blankOption.textContent = '— column —';
            columnInput.appendChild(blankOption);
            ['id', ...Object.keys(tableData.columns || {})].forEach(col => {
                const option = document.createElement('option');
                option.value = col;
                option.textContent = col;
                if (rule.column === col) option.selected = true;
                columnInput.appendChild(option);
            });
            columnInput.addEventListener('change', () => { rules[i].column = columnInput.value; markDirty(); });

            const directorySelect = document.createElement('select');
            directorySelect.className = 'adm-input';
            [['asc', 'ASC ↑'], ['desc', 'DESC ↓']].forEach(([val, lbl]) => {
                const option = document.createElement('option');
                option.value = val;
                option.textContent = lbl;
                if ((rule.dir || 'asc') === val) option.selected = true;
                directorySelect.appendChild(option);
            });
            directorySelect.addEventListener('change', () => { rules[i].dir = directorySelect.value; markDirty(); });

            const buttonRemove = document.createElement('button');
            buttonRemove.type = 'button';
            buttonRemove.textContent = '✕';
            buttonRemove.className = 'btn btn-danger btn-xs';
            buttonRemove.addEventListener('click', () => { rules.splice(i, 1); markDirty(); renderRules(); });

            row.appendChild(columnInput);
            row.appendChild(directorySelect);
            row.appendChild(buttonRemove);
            listElement.appendChild(row);
        });
    }

    renderRules();

    const buttonAdd = document.createElement('button');
    buttonAdd.type = 'button';
    buttonAdd.textContent = '+ Add Sort Rule';
    buttonAdd.className = 'btn btn-primary btn-xs';
    buttonAdd.style.marginTop = '6px';
    buttonAdd.addEventListener('click', () => { rules.push({ column: '', dir: 'asc' }); markDirty(); renderRules(); });
    wrapper.appendChild(buttonAdd);

    return wrapper;
}

export function renderSchemaEditor(tableName, tableData, context) {
    const { workspaceEl: workspaceElement, getTableOptions, renderEditor } = context;

    workspaceElement.innerHTML = '';

    const titleElement = document.createElement('h3');
    titleElement.innerHTML = `Table Properties: ${escHtml(tableName)}`;
    titleElement.style.margin = '0 0 20px';
    workspaceElement.appendChild(titleElement);

    if (!tableData.columns || Array.isArray(tableData.columns)) tableData.columns = {};
    if (!tableData.foreign_keys || Array.isArray(tableData.foreign_keys)) tableData.foreign_keys = {};
    if (!tableData.subtables || !Array.isArray(tableData.subtables)) tableData.subtables = [];

    const buttonSyncColumns = document.createElement('button');
    buttonSyncColumns.type = 'button';
    buttonSyncColumns.className = 'btn btn-sm';
    buttonSyncColumns.innerHTML = 'Sync Columns from DB';

    buttonSyncColumns.onclick = async () => {
        try {
            const schemaName = tableData.schema || 'app';

            const res = await apiFetch('api.php?action=get_db_columns', {
                method: 'POST',
                body: JSON.stringify({ schema_name: schemaName, table: tableName })
            });

            const rawText = await res.text();

            if (!res.ok) {
                showStatusPill(buttonSyncColumns, `HTTP Error ${res.status}`, 'error');
                console.error('Sync columns HTTP error:', res.status, rawText);
                return;
            }

            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseError) {
                showStatusPill(buttonSyncColumns, 'Server returned invalid JSON. Check console.', 'error');
                console.error('RAW RESPONSE:', rawText);
                return;
            }

            if (data.status === 'success') {
                let added = 0;
                data.columns.forEach(col => {
                    if (!tableData.columns[col.column_name]) {
                        const isEnum = Array.isArray(col.enum_values);
                        const isNotNull = col.is_nullable === 'NO';

                        const rawType = String(col.data_type || col.type || col.udt_name || col.datatype || '').toLowerCase();
                        let mappedType = 'text';

                        if (isEnum || rawType === 'user-defined' || rawType.includes('enum')) {
                            mappedType = 'enum';
                        } else if (/int|num|float|double|real|serial|dec/i.test(rawType)) {
                            mappedType = 'number';
                        } else if (/bool/i.test(rawType)) {
                            mappedType = 'boolean';
                        } else if (/timestamp|timestamptz/i.test(rawType)) {
                            mappedType = 'timestamp';
                        } else if (/date|time/i.test(rawType)) {
                            mappedType = 'date';
                        }

                        const isIdColumn = col.column_name.toLowerCase() === 'id';

                        tableData.columns[col.column_name] = {
                            display_name: col.column_name.replace(/_/g, ' ').toUpperCase(),
                            type: mappedType,
                            show_in_grid: true,
                            show_in_edit: true,
                            not_null: isNotNull,
                            readonly: isIdColumn
                        };

                        if (isEnum) tableData.columns[col.column_name].options = col.enum_values;
                        if (col.description) tableData.columns[col.column_name].description = col.description;
                        added++;
                    } else if (col.description) {
                        tableData.columns[col.column_name].description = col.description;
                    }
                });
                if (added > 0) markDirty();
                showStatusPill(buttonSyncColumns, `Added ${added} new column${added === 1 ? '' : 's'}.`, added > 0 ? 'success' : 'info');

                setTimeout(() => renderEditor(tableName, tableData, false), 900);
            } else {
                showStatusPill(buttonSyncColumns, 'API Error: ' + (data.error || 'Failed to sync columns.'), 'error');
            }
        } catch (err) {
            console.error(err);
            showStatusPill(buttonSyncColumns, 'Communication error. Check console.', 'error');
        }
    };
    workspaceElement.appendChild(buttonSyncColumns);

    const buttonAddColumn = document.createElement('button');
    buttonAddColumn.type = 'button';
    buttonAddColumn.className = 'btn btn-sm';
    buttonAddColumn.textContent = '+ Add Column';
    buttonAddColumn.style.marginLeft = '10px';

    buttonAddColumn.onclick = async (e) => {
        e.preventDefault();

        const columnName = prompt('Enter new column name (lowercase, no spaces):');
        if (!columnName) return;

        const formattedColumnName = columnName.toLowerCase().replace(/[^a-z0-9_]/g, '');

        if (tableData.columns && tableData.columns[formattedColumnName]) {
            showStatusPill(buttonAddColumn, 'Column already exists.', 'error');
            return;
        }

        const columnType = prompt('Enter data type (e.g., varchar(255), int4, boolean):', 'varchar(255)');
        if (!columnType) return;

        try {
            const response = await apiFetch('api.php?action=add_column', {
                method: 'POST',

                body: JSON.stringify({ schema: tableData.schema || 'app', table: tableName, column: formattedColumnName, type: columnType })
            });

            const result = await response.json();
            if (result.status === 'success') {
                tableData.columns[formattedColumnName] = {
                    display_name: formattedColumnName.replace(/_/g, ' ').charAt(0).toUpperCase() + formattedColumnName.replace(/_/g, ' ').slice(1),
                    type: 'text'
                };
                markDirty();
                renderEditor(tableName, tableData, false);
            } else {
                showStatusPill(buttonAddColumn, 'Error adding column: ' + result.error, 'error');
            }
        } catch (err) {
            console.error(err);
            showStatusPill(buttonAddColumn, 'Network error occurred.', 'error');
        }
    };
    workspaceElement.appendChild(buttonAddColumn);

    const buttonAddVirtual = document.createElement('button');
    buttonAddVirtual.type = 'button';
    buttonAddVirtual.className = 'btn btn-primary btn-sm';
    buttonAddVirtual.textContent = '+ Add Virtual Column';
    buttonAddVirtual.style.marginLeft = '10px';
    buttonAddVirtual.onclick = () => {
        const columnName = prompt('Enter virtual column name (lowercase, no spaces):');
        if (!columnName) return;
        const formattedColumnName = columnName.toLowerCase().replace(/[^a-z0-9_]/g, '');
        if (!formattedColumnName) return;
        if (tableData.columns[formattedColumnName]) {
            showStatusPill(buttonAddVirtual, 'Column already exists.', 'error');
            return;
        }
        tableData.columns[formattedColumnName] = {
            display_name: formattedColumnName.replace(/_/g, ' ').charAt(0).toUpperCase() + formattedColumnName.replace(/_/g, ' ').slice(1),
            type: 'virtual',
            show_in_grid: true,
            formula: { op: 'sum', cols: [] },
        };
        markDirty();
        renderEditor(tableName, tableData, false);
    };
    workspaceElement.appendChild(buttonAddVirtual);

    workspaceElement.appendChild(createTextInput('display_name', 'Display Name', tableData.display_name, (val) => {
        tableData.display_name = val;
    }));
    workspaceElement.appendChild(createTextInput('schema', 'Database Schema', tableData.schema || 'app', (val) => tableData.schema = val));

    workspaceElement.appendChild(createIconPicker('icon', 'Icon Path', tableData.icon, (val) => {
        if (val) tableData.icon = val;
        else delete tableData.icon;
    }));

    workspaceElement.appendChild(createCheckbox('hidden', 'Hide from Sidebar Menu', tableData.hidden, (val) => {
        tableData.hidden = val;
    }, false));

    workspaceElement.appendChild(buildDefaultSortUI(tableData));

    workspaceElement.appendChild(createTextInput(
        'initial_limit',
        'Initial Load Limit (rows, 0 = unlimited)',
        String(tableData.initial_limit ?? 0),
        (val) => {
            const n = parseInt(val, 10);
            if (n > 0) tableData.initial_limit = n;
            else delete tableData.initial_limit;
            markDirty();
        }
    ));

    const columnsTitle = document.createElement('h3');
    columnsTitle.textContent = 'Columns Configuration';
    columnsTitle.style.marginTop = '30px';
    workspaceElement.appendChild(columnsTitle);

    if (!tableData.columns || !tableData.columns['id']) {
        const idWarn = document.createElement('div');
        idWarn.className = 'status-pill error';
        idWarn.style.cssText = 'display:block; margin-bottom:16px; padding:10px 14px; line-height:1.5;';
        idWarn.innerHTML = '<strong>Missing required <code>id</code> column.</strong> OpenSparrow requires a column named <code>id</code> of type <code>serial4</code> (auto-increment integer primary key). Without it the grid, edit forms, and relations will not work. Add it via your database tool or by running: <code>ALTER TABLE &lt;table&gt; ADD COLUMN id serial4 PRIMARY KEY;</code> then click <em>Sync Columns from DB</em>.';
        workspaceElement.appendChild(idWarn);
    }

    const dataTypeOptions = [
        { value: 'text',      label: 'Text' },
        { value: 'number',    label: 'Number' },
        { value: 'boolean',   label: 'Boolean' },
        { value: 'date',      label: 'Date' },
        { value: 'timestamp', label: 'Timestamp (Date + Time)' },
        { value: 'enum',      label: 'Enum' },
        { value: 'virtual',   label: 'Virtual (Computed)' },
    ];

    const virtualOpsNumeric = [
        { value: 'sum',      label: 'Sum (col1 + col2 + …)' },
        { value: 'subtract', label: 'Subtract (col1 − col2)' },
        { value: 'multiply', label: 'Multiply (col1 × col2 × …)' },
        { value: 'divide',   label: 'Divide (col1 ÷ col2)' },
        { value: 'average',  label: 'Average' },
        { value: 'concat',   label: 'Concat (text join)' },
    ];

    function makeCollapsible(block) {
        const bodyDiv = document.createElement('div');
        bodyDiv.className = 'block-body';
        while (block.children.length > 1) {
            bodyDiv.appendChild(block.children[1]);
        }
        block.appendChild(bodyDiv);
    }

    const columnKeys = Object.keys(tableData.columns);
    columnKeys.forEach((columnName, index) => {
        const columnConfig = tableData.columns[columnName];
        const block = document.createElement('div');
        block.className = 'column-block collapsed';

        const headerDiv = document.createElement('div');
        headerDiv.className = 'block-header';
        headerDiv.addEventListener('click', (e) => {
            if (e.target.closest('button')) return;
            block.classList.toggle('collapsed');
        });

        const chevron = document.createElement('span');
        chevron.className = 'block-chevron';
        chevron.textContent = '▶';

        const h4 = document.createElement('h4');
        h4.textContent = `Column: ${columnName}`;

        const moveControls = document.createElement('div');

        const buttonUp = document.createElement('button');
        buttonUp.type = 'button';
        buttonUp.textContent = '▲';
        buttonUp.title = 'Move Up';
        buttonUp.className = 'icon-btn';
        if (index === 0) { buttonUp.disabled = true; buttonUp.style.opacity = '0.3'; }
        buttonUp.onclick = () => {
            tableData.columns = moveObjectKey(tableData.columns, columnName, -1);
            renderEditor(tableName, tableData, false);
        };

        const buttonDown = document.createElement('button');
        buttonDown.type = 'button';
        buttonDown.textContent = '▼';
        buttonDown.title = 'Move Down';
        buttonDown.className = 'icon-btn';
        if (index === columnKeys.length - 1) { buttonDown.disabled = true; buttonDown.style.opacity = '0.3'; }
        buttonDown.onclick = () => {
            tableData.columns = moveObjectKey(tableData.columns, columnName, 1);
            renderEditor(tableName, tableData, false);
        };

        moveControls.appendChild(buttonUp);
        moveControls.appendChild(buttonDown);
        headerDiv.appendChild(chevron);
        headerDiv.appendChild(h4);
        headerDiv.appendChild(moveControls);
        block.appendChild(headerDiv);

        block.appendChild(createTextInput('display_name', 'Display Name', columnConfig.display_name, (val) => columnConfig.display_name = val));
        block.appendChild(createTextInput('description', 'Column Description (tooltip)', columnConfig.description || '', (val) => {
            if (val) columnConfig.description = val;
            else delete columnConfig.description;
        }));

        let currentType = String(columnConfig.type || 'text').toLowerCase();
        if (!['text', 'number', 'boolean', 'date', 'timestamp', 'enum', 'virtual'].includes(currentType)) {
            if (/int|num|float|double|real|serial|dec/i.test(currentType)) currentType = 'number';
            else if (/bool/i.test(currentType)) currentType = 'boolean';
            else if (/timestamp|timestamptz/i.test(currentType)) currentType = 'timestamp';
            else if (/date|time/i.test(currentType)) currentType = 'date';
            else currentType = 'text';
            columnConfig.type = currentType;
        }

        block.appendChild(createSelectInput('type', 'Data Type', dataTypeOptions, currentType, (val) => {
            columnConfig.type = val;
            if (val === 'virtual' && !columnConfig.formula) {
                columnConfig.formula = { op: 'sum', cols: [] };
            }
            renderEditor(tableName, tableData, false);
        }));

        if (currentType === 'virtual') {
            if (!columnConfig.formula || typeof columnConfig.formula !== 'object') {
                columnConfig.formula = { op: 'sum', cols: [] };
            }
            const f = columnConfig.formula;

            const vBlock = document.createElement('div');
            vBlock.style.cssText = 'margin-left:20px;padding-left:10px;border-left:2px solid var(--muted);margin-bottom:15px;';

            const vTitle = document.createElement('h5');
            vTitle.textContent = 'Formula Configuration';
            vTitle.style.cssText = 'margin-top:0;margin-bottom:10px;color:var(--muted);';
            vBlock.appendChild(vTitle);

            vBlock.appendChild(createSelectInput('v_op', 'Operation', virtualOpsNumeric, f.op || 'sum', val => {
                f.op = val;
            }));

            const nonVirtualColumns = Object.entries(tableData.columns)
                .filter(([n, c]) => c.type !== 'virtual' && n !== columnName)
                .map(([n, c]) => ({ value: n, label: c.display_name || n }));

            const columnsContainer = document.createElement('div');
            columnsContainer.style.cssText = 'margin-top:4px;';

            const columnsLabel = document.createElement('label');
            columnsLabel.style.cssText = 'font-weight:600;display:block;margin-bottom:6px;';
            columnsLabel.textContent = 'Source Columns (in order)';
            columnsContainer.appendChild(columnsLabel);

            const selectedList = document.createElement('div');
            selectedList.style.cssText = 'display:flex;flex-direction:column;gap:4px;margin-bottom:6px;';

            function rebuildSelectedList() {
                selectedList.innerHTML = '';
                (f.cols || []).forEach((c, i) => {
                    const row = document.createElement('div');
                    row.style.cssText = 'display:flex;gap:6px;align-items:center;';

                    const lbl = document.createElement('span');
                    lbl.style.cssText = 'flex:1;background:var(--bg);padding:3px 8px;border-radius:4px;border:1px solid var(--border-light);';
                    lbl.textContent = nonVirtualColumns.find(o => o.value === c)?.label ?? c;

                    const rmButton = document.createElement('button');
                    rmButton.type = 'button';
                    rmButton.textContent = '✕';
                    rmButton.className = 'btn btn-danger btn-xs';
                    rmButton.addEventListener('click', () => {
                        f.cols.splice(i, 1);
                        rebuildSelectedList();
                        markDirty();
                    });

                    row.append(lbl, rmButton);
                    selectedList.appendChild(row);
                });
            }

            rebuildSelectedList();
            columnsContainer.appendChild(selectedList);

            const addRow = document.createElement('div');
            addRow.style.cssText = 'display:flex;gap:6px;align-items:center;';

            const columnPicker = document.createElement('select');
            columnPicker.className = 'adm-input flex-1';
            nonVirtualColumns.forEach(option => {
                const o = document.createElement('option');
                o.value = option.value; o.textContent = option.label;
                columnPicker.appendChild(o);
            });

            const addColumnButton = document.createElement('button');
            addColumnButton.type = 'button';
            addColumnButton.textContent = '+ Add';
            addColumnButton.className = 'btn btn-secondary btn-sm';
            addColumnButton.addEventListener('click', () => {
                if (!columnPicker.value) return;
                if (!Array.isArray(f.cols)) f.cols = [];
                f.cols.push(columnPicker.value);
                rebuildSelectedList();
                markDirty();
            });

            addRow.append(columnPicker, addColumnButton);
            columnsContainer.appendChild(addRow);
            vBlock.appendChild(columnsContainer);

            const sepWrapper = document.createElement('div');
            sepWrapper.style.display = (f.op === 'concat') ? '' : 'none';
            sepWrapper.appendChild(createTextInput('v_sep', 'Separator', f.separator ?? ' ', val => {
                f.separator = val;
            }));
            vBlock.appendChild(sepWrapper);

            const opSelect = vBlock.querySelector('select');
            opSelect?.addEventListener('change', e => {
                sepWrapper.style.display = e.target.value === 'concat' ? '' : 'none';
            });

            block.appendChild(vBlock);

            block.appendChild(createCheckbox('show_in_grid', 'Show in Grid', columnConfig.show_in_grid, val => columnConfig.show_in_grid = val, true));

            const buttonDelVirtual = document.createElement('button');
            buttonDelVirtual.type = 'button';
            buttonDelVirtual.textContent = 'Delete Virtual Column';
            buttonDelVirtual.className = 'btn btn-danger btn-sm';
            buttonDelVirtual.style.marginTop = '8px';
            buttonDelVirtual.addEventListener('click', () => {
                if (confirm(`Delete virtual column "${columnName}"?`)) {
                    delete tableData.columns[columnName];
                    markDirty();
                    renderEditor(tableName, tableData, false);
                }
            });
            block.appendChild(buttonDelVirtual);

            makeCollapsible(block);
            workspaceElement.appendChild(block);
            return;
        }

        const optionsString = columnConfig.options ? columnConfig.options.join(', ') : '';

        const enumWrapper = document.createElement('div');
        enumWrapper.className = 'form-group';
        const enumLabel = document.createElement('label');
        enumLabel.textContent = 'Enum Options (Comma separated)';
        enumWrapper.appendChild(enumLabel);
        const enumInput = document.createElement('input');
        enumInput.type = 'text';
        enumInput.value = optionsString;

        const applyEnumValue = (val) => {
            if (val) {
                columnConfig.options = val.split(',').map(s => s.trim()).filter(Boolean);
            } else {
                delete columnConfig.options;
                delete columnConfig.enum_colors;
            }
        };

        enumInput.addEventListener('input', (e) => {
            applyEnumValue(e.target.value);
        });
        enumInput.addEventListener('change', (e) => {
            applyEnumValue(e.target.value);
            renderEditor(tableName, tableData, false);
        });

        enumWrapper.appendChild(enumInput);
        block.appendChild(enumWrapper);

        const isTypeEnum = String(columnConfig.type || '').toLowerCase() === 'enum';

        if (isTypeEnum && columnConfig.options && columnConfig.options.length > 0) {
            const colorsContainer = document.createElement('div');
            colorsContainer.style.marginLeft = '20px';
            colorsContainer.style.paddingLeft = '10px';
            colorsContainer.style.borderLeft = '2px solid var(--muted)';
            colorsContainer.style.marginBottom = '15px';

            const colorsTitle = document.createElement('h5');
            colorsTitle.textContent = 'Enum Colors (Optional)';
            colorsTitle.style.marginTop = '0';
            colorsTitle.style.marginBottom = '10px';
            colorsContainer.appendChild(colorsTitle);

            if (!columnConfig.enum_colors || Array.isArray(columnConfig.enum_colors)) columnConfig.enum_colors = {};

            columnConfig.options.forEach(optionValue => {
                colorsContainer.appendChild(createColorInput(
                    `enum_color`,
                    `Color: ${optionValue}`,
                    columnConfig.enum_colors[optionValue] || '#ffffff',
                    (val) => { columnConfig.enum_colors[optionValue] = val; }
                ));
            });
            block.appendChild(colorsContainer);
        }

        const fkData = tableData.foreign_keys[columnName] || {};
        block.appendChild(createSelectInput('fk_ref', 'Foreign Key Reference Table', getTableOptions(), fkData.reference_table || '', (val) => {
            if (val) tableData.foreign_keys[columnName] = { reference_table: val, reference_column: fkData.reference_column || 'id', display_column: fkData.display_column || ['name'] };
            else delete tableData.foreign_keys[columnName];
            renderEditor(tableName, tableData, false);
        }));

        if (tableData.foreign_keys[columnName] && tableData.foreign_keys[columnName].reference_table) {
            const fkContainer = document.createElement('div');
            fkContainer.style.marginLeft = '20px'; fkContainer.style.paddingLeft = '10px'; fkContainer.style.borderLeft = '2px solid var(--accent)'; fkContainer.style.marginBottom = '15px';
            fkContainer.appendChild(createTextInput('fk_ref_col', 'Reference Column (e.g., id)', tableData.foreign_keys[columnName].reference_column, (val) => tableData.foreign_keys[columnName].reference_column = val));

            const fkDispData = tableData.foreign_keys[columnName].display_column;
            const fkDispString = Array.isArray(fkDispData) ? fkDispData.join(', ') : (fkDispData || '');

            fkContainer.appendChild(createTextInput('fk_disp_col', 'Display Columns (Comma separated, e.g., first_name, last_name)', fkDispString, (val) => {
                if(val) {
                    tableData.foreign_keys[columnName].display_column = val.split(',').map(s => s.trim()).filter(s => s !== '');
                } else {
                    tableData.foreign_keys[columnName].display_column = [];
                }
            }));

            block.appendChild(fkContainer);
        }

        const regexContainer = document.createElement('div');
        regexContainer.style.marginLeft = '20px';
        regexContainer.style.paddingLeft = '10px';
        regexContainer.style.borderLeft = '2px solid var(--muted)';
        regexContainer.style.marginBottom = '15px';

        const regexTitle = document.createElement('h5');
        regexTitle.textContent = 'Validation Rules (Optional)';
        regexTitle.style.marginTop = '0';
        regexTitle.style.marginBottom = '10px';
        regexContainer.appendChild(regexTitle);

        regexContainer.appendChild(createTextInput(
            'validation_regexp',
            'RegExp Pattern (e.g., ^[A-Z]{2}\\d{4}$)',
            columnConfig.validation_regexp || '',
            (val) => {
                if (val) columnConfig.validation_regexp = val;
                else delete columnConfig.validation_regexp;
            }
        ));

        regexContainer.appendChild(createTextInput(
            'validation_message',
            'Error Message (e.g., Invalid code format)',
            columnConfig.validation_message || '',
            (val) => {
                if (val) columnConfig.validation_message = val;
                else delete columnConfig.validation_message;
            }
        ));

        block.appendChild(regexContainer);

        block.appendChild(createCheckbox('show_in_grid', 'Show in Grid', columnConfig.show_in_grid, (val) => columnConfig.show_in_grid = val, true));
        block.appendChild(createCheckbox('show_in_edit', 'Show in Edit Form', columnConfig.show_in_edit, (val) => columnConfig.show_in_edit = val, true));
        block.appendChild(createCheckbox('not_null', 'Is Required (Not Null)', columnConfig.not_null, (val) => columnConfig.not_null = val, false));
        block.appendChild(createCheckbox('readonly', 'Read Only', columnConfig.readonly, (val) => columnConfig.readonly = val, false));

        makeCollapsible(block);
        workspaceElement.appendChild(block);
    });

    const subTitle = document.createElement('h3');
    subTitle.textContent = 'Subtables Configuration (Has Many Relationships)';
    subTitle.style.marginTop = '40px';
    workspaceElement.appendChild(subTitle);

    const subContainer = document.createElement('div');
    workspaceElement.appendChild(subContainer);

    const renderSubtables = () => {
        subContainer.innerHTML = '';
        tableData.subtables.forEach((subConfig, index) => {
            const block = document.createElement('div');
            block.className = 'column-block collapsed';

            const headerDiv = document.createElement('div');
            headerDiv.className = 'block-header';
            headerDiv.addEventListener('click', (e) => {
                if (e.target.closest('button')) return;
                block.classList.toggle('collapsed');
            });

            const chevron = document.createElement('span');
            chevron.className = 'block-chevron';
            chevron.textContent = '▶';

            const h4 = document.createElement('h4');
            h4.textContent = `Subtable #${index + 1}`;

            const buttonDel = document.createElement('button');
            buttonDel.type = 'button';
            buttonDel.title = 'Delete';
            buttonDel.textContent = '✕';
            buttonDel.className = 'icon-btn icon-btn-danger';
            buttonDel.onclick = () => {
                tableData.subtables.splice(index, 1);
                renderSubtables();
            };

            headerDiv.appendChild(chevron);
            headerDiv.appendChild(h4);
            headerDiv.appendChild(buttonDel);
            block.appendChild(headerDiv);

            block.appendChild(createSelectInput('sub_table', 'Child Table (Target)', getTableOptions(), subConfig.table || '', (val) => subConfig.table = val));
            block.appendChild(createTextInput('sub_fk', 'Foreign Key Column in Child Table', subConfig.foreign_key, (val) => subConfig.foreign_key = val));
            block.appendChild(createTextInput('sub_label', 'Display Label', subConfig.label, (val) => subConfig.label = val));

            const columnsString = subConfig.columns_to_show ? subConfig.columns_to_show.join(', ') : '';
            block.appendChild(createTextInput('sub_cols', 'Columns to Show (Comma separated)', columnsString, (val) => {
                if(val) {
                    subConfig.columns_to_show = val.split(',').map(s => s.trim()).filter(s => s !== '');
                } else {
                    subConfig.columns_to_show = [];
                }
            }));

            makeCollapsible(block);
            subContainer.appendChild(block);
        });

        const buttonAddSub = document.createElement('button');
        buttonAddSub.type = 'button';
        buttonAddSub.className = 'btn btn-success btn-sm';
        buttonAddSub.textContent = '+ Add Subtable';
        buttonAddSub.onclick = () => {
            tableData.subtables.push({ table: '', foreign_key: '', label: '', columns_to_show: ['id'] });
            renderSubtables();
        };
        subContainer.appendChild(buttonAddSub);
    };

    renderSubtables();

    if (!Array.isArray(tableData.many_to_many)) tableData.many_to_many = [];

    const m2mTitle = document.createElement('h3');
    m2mTitle.textContent = 'Many-to-Many Relationships';
    m2mTitle.style.marginTop = '40px';
    workspaceElement.appendChild(m2mTitle);

    const m2mHint = document.createElement('p');
    m2mHint.style.cssText = '  margin:-8px 0 14px;';
    m2mHint.textContent = 'Checkbox panels shown in edit/create forms. Each entry links this table to another via a junction table.';
    workspaceElement.appendChild(m2mHint);

    const m2mContainer = document.createElement('div');
    workspaceElement.appendChild(m2mContainer);

    const renderM2m = () => {
        m2mContainer.replaceChildren();

        tableData.many_to_many.forEach((cfg, index) => {
            const block = document.createElement('div');
            block.className = 'column-block collapsed';

            const headerDiv = document.createElement('div');
            headerDiv.className = 'block-header';
            headerDiv.addEventListener('click', (e) => {
                if (e.target.closest('button')) return;
                block.classList.toggle('collapsed');
            });

            const chevron = document.createElement('span');
            chevron.className = 'block-chevron';
            chevron.textContent = '▶';

            const h4 = document.createElement('h4');
            h4.textContent = cfg.label || `M2M #${index + 1}`;

            const buttonDel = document.createElement('button');
            buttonDel.type = 'button';
            buttonDel.title = 'Delete';
            buttonDel.textContent = '✕';
            buttonDel.className = 'icon-btn icon-btn-danger';
            buttonDel.onclick = () => { tableData.many_to_many.splice(index, 1); markDirty(); renderM2m(); };

            headerDiv.append(chevron, h4, buttonDel);
            block.appendChild(headerDiv);

            block.appendChild(createTextInput(
                `m2m_label_${index}`, 'Display Label',
                cfg.label || '',
                (val) => { cfg.label = val; h4.textContent = val || `M2M #${index + 1}`; markDirty(); }
            ));
            block.appendChild(createSelectInput(
                `m2m_jt_${index}`, 'Junction Table',
                getTableOptions(), cfg.junction_table || '',
                (val) => { cfg.junction_table = val; markDirty(); }
            ));
            block.appendChild(createTextInput(
                `m2m_sfk_${index}`, 'Self FK — this table\'s ID column in junction',
                cfg.self_fk || '',
                (val) => { cfg.self_fk = val; markDirty(); }
            ));
            block.appendChild(createTextInput(
                `m2m_ofk_${index}`, 'Other FK — related table\'s ID column in junction',
                cfg.other_fk || '',
                (val) => { cfg.other_fk = val; markDirty(); }
            ));
            block.appendChild(createSelectInput(
                `m2m_ot_${index}`, 'Other Table (the related entity)',
                getTableOptions(), cfg.other_table || '',
                (val) => { cfg.other_table = val; markDirty(); }
            ));
            block.appendChild(createTextInput(
                `m2m_dc_${index}`, 'Display Column (from Other Table)',
                cfg.display_column || '',
                (val) => { cfg.display_column = val; markDirty(); }
            ));

            makeCollapsible(block);
            m2mContainer.appendChild(block);
        });

        const buttonAdd = document.createElement('button');
        buttonAdd.type = 'button';
        buttonAdd.className = 'btn btn-sm';
        buttonAdd.textContent = '+ Add Many-to-Many';
        buttonAdd.onclick = () => {
            tableData.many_to_many.push({
                label: '', junction_table: '', self_fk: '',
                other_fk: '', other_table: '', display_column: 'name'
            });
            markDirty();
            renderM2m();
        };
        m2mContainer.appendChild(buttonAdd);
    };

    renderM2m();

    const imagesConfig = (typeof tableData.images === 'object' && tableData.images !== null)
        ? tableData.images
        : {};

    let imagesReady = false;
    const touchImages = () => {
        if (!imagesReady) return;
        tableData.images = imagesConfig;
        markDirty();
    };

    const imageTitle = document.createElement('h3');
    imageTitle.textContent = 'Images';
    imageTitle.style.marginTop = '40px';
    workspaceElement.appendChild(imageTitle);

    const imageHint = document.createElement('p');
    imageHint.style.cssText = '  margin:-8px 0 14px;';
    imageHint.textContent = 'Lets users attach images to each record of this table from the edit form, with a thumbnail column in the grid.';
    workspaceElement.appendChild(imageHint);

    const imageBlock = document.createElement('div');
    imageBlock.className = 'column-block';
    imageBlock.appendChild(createCheckbox('images_enabled', 'Enable Images For This Table', imagesConfig.enabled, (val) => {
        imagesConfig.enabled = val;
        touchImages();
    }, false));
    imageBlock.appendChild(createTextInput(
        'images_label', 'Display Label',
        imagesConfig.label || '',
        (val) => { imagesConfig.label = val; touchImages(); }
    ));
    imageBlock.appendChild(createNumberInput(
        'images_max', 'Max Images Per Record (1-50)',
        imagesConfig.max_per_record ?? 10,
        (val) => { imagesConfig.max_per_record = Math.min(50, Math.max(1, parseInt(val, 10) || 1)); touchImages(); }
    ));
    imageBlock.appendChild(createCheckbox('images_grid', 'Show Thumbnail Column In Grid', imagesConfig.show_in_grid, (val) => {
        imagesConfig.show_in_grid = val;
        touchImages();
    }, true));
    workspaceElement.appendChild(imageBlock);
    imagesReady = true;

    const hlRules = Array.isArray(tableData.highlight_rules) ? tableData.highlight_rules : [];
    const touchHighlights = () => { tableData.highlight_rules = hlRules; markDirty(); };

    const hlTitle = document.createElement('h3');
    hlTitle.textContent = 'Highlight Rules';
    hlTitle.style.marginTop = '40px';
    workspaceElement.appendChild(hlTitle);

    const hlHint = document.createElement('p');
    hlHint.style.cssText = '  margin:-8px 0 14px;';
    hlHint.textContent = 'Colors an entire grid row when the chosen column matches the condition. Rules are evaluated in order; the first match wins.';
    workspaceElement.appendChild(hlHint);

    const hlContainer = document.createElement('div');
    workspaceElement.appendChild(hlContainer);

    const renderHighlightRules = () => {
        hlContainer.innerHTML = '';
        const columnNames = Object.keys(tableData.columns);
        const rules = hlRules;

        rules.forEach((rule, idx) => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:8px;';

            const columnSelect = document.createElement('select');
            columnSelect.className = 'adm-input w-160';
            columnNames.forEach(columnName => {
                const o = document.createElement('option');
                o.value = columnName;
                o.textContent = columnName;
                if (rule.column === columnName) o.selected = true;
                columnSelect.appendChild(o);
            });
            columnSelect.addEventListener('change', () => { rules[idx].column = columnSelect.value; touchHighlights(); });

            const opSelect = document.createElement('select');
            opSelect.className = 'adm-input w-80';
            ['==', '!=', '>', '>=', '<', '<=', 'contains'].forEach(op => {
                const o = document.createElement('option');
                o.value = op;
                o.textContent = op;
                if (rule.op === op) o.selected = true;
                opSelect.appendChild(o);
            });
            opSelect.addEventListener('change', () => { rules[idx].op = opSelect.value; touchHighlights(); });

            const valueInput = document.createElement('input');
            valueInput.type = 'text';
            valueInput.className = 'adm-input w-110';
            valueInput.value = rule.value ?? '';
            valueInput.placeholder = 'Value';
            valueInput.addEventListener('input', () => { rules[idx].value = valueInput.value; touchHighlights(); });

            const colorInput = document.createElement('input');
            colorInput.type = 'color';
            colorInput.className = 'adm-color';
            colorInput.value = rule.color ?? '#FBEDED';
            colorInput.addEventListener('input', () => { rules[idx].color = colorInput.value; touchHighlights(); });

            const buttonDel = document.createElement('button');
            buttonDel.type = 'button';
            buttonDel.className = 'btn btn-danger btn-xs';
            buttonDel.textContent = '✕ Remove';
            buttonDel.addEventListener('click', () => { rules.splice(idx, 1); touchHighlights(); renderHighlightRules(); });

            row.append(columnSelect, opSelect, valueInput, colorInput, buttonDel);
            hlContainer.appendChild(row);
        });

        const buttonAdd = document.createElement('button');
        buttonAdd.type = 'button';
        buttonAdd.className = 'btn btn-success btn-sm';
        buttonAdd.textContent = '+ Add Highlight Rule';
        buttonAdd.addEventListener('click', () => {
            rules.push({ column: columnNames[0] || '', op: '==', value: '', color: '#FBEDED' });
            touchHighlights();
            renderHighlightRules();
        });
        hlContainer.appendChild(buttonAdd);
    };

    renderHighlightRules();
}
