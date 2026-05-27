// admin/schema.js
import { createTextInput, createSelectInput, createCheckbox, createColorInput, createIconPicker, moveObjectKey, createMenuPreview } from './ui.js';
import { showStatusPill, markDirty } from './app.js';

// Utility function to escape HTML strings safely against XSS
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
}

// Global grid settings form (page size, etc.)
export function renderSchemaGlobalSettings(config, ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '';

    const PAGE_SIZES = [10, 25, 50, 100];
    const current = Number(config.default_page_size) || 25;

    const card = document.createElement('div');
    card.style.cssText = 'max-width:560px;';

    const h3 = document.createElement('h3');
    h3.style.cssText = 'margin:0 0 6px;';
    h3.textContent = 'Global Grid Settings';
    const sub = document.createElement('p');
    sub.style.cssText = 'color:var(--muted); font-size:14px; margin:0 0 24px;';
    sub.textContent = 'Settings that apply to all data grids in the frontend application.';
    card.append(h3, sub);

    // Page size setting
    const row = document.createElement('div');
    row.style.cssText = 'display:flex; align-items:center; gap:16px; padding:16px; background:white; border:1px solid var(--border); border-radius:6px;';

    const labelWrap = document.createElement('div');
    labelWrap.style.flex = '1';
    const lbl = document.createElement('label');
    lbl.style.cssText = 'display:block; font-weight:600; font-size:14px; margin-bottom:4px;';
    lbl.textContent = 'Default Page Size';
    const hint = document.createElement('span');
    hint.style.cssText = 'font-size:12px; color:var(--muted);';
    hint.textContent = 'Records shown per page. Users can override this per-session from the grid pagination bar.';
    labelWrap.append(lbl, hint);

    const sel = document.createElement('select');
    sel.className = 'adm-input';
    sel.style.minWidth = '80px';
    PAGE_SIZES.forEach(n => {
        const opt = document.createElement('option');
        opt.value = n;
        opt.textContent = n;
        if (n === current) opt.selected = true;
        sel.appendChild(opt);
    });
    sel.addEventListener('change', () => {
        config.default_page_size = Number(sel.value);
        markDirty();
    });

    row.append(labelWrap, sel);
    card.appendChild(row);

    const note = document.createElement('p');
    note.style.cssText = 'font-size:12px; color:var(--muted); margin-top:12px;';
    note.textContent = 'Stored in schema.json as "default_page_size". Included in config export/import.';
    card.appendChild(note);

    workspaceEl.appendChild(card);
}

// Function to generate the Add Table button and handle its logic
export function createAddTableButton(currentConfig, defaultSchema, onSuccess, onError) {
    const btnAddTable = document.createElement('button');
    btnAddTable.type = 'button';
    btnAddTable.className = 'btn btn-success';
    btnAddTable.textContent = '+ Add Table';
    btnAddTable.style.marginLeft = '10px';

    btnAddTable.onclick = async (e) => {
        e.preventDefault();

        const tableName = prompt('Enter new table name (lowercase, no spaces):');
        if (!tableName) return;

        // Force proper formatting
        const formattedName = tableName.toLowerCase().replace(/[^a-z0-9_]/g, '');

        // Prevent duplicates
        if (currentConfig.tables && currentConfig.tables[formattedName]) {
            onError('Table already exists in configuration.');
            return;
        }

        // Prompt user for schema name using the provided default
        const schemaInput = prompt('Enter database schema name:', defaultSchema || 'public');
        if (!schemaInput) return;
        
        // Format schema name safely
        const formattedSchema = schemaInput.toLowerCase().replace(/[^a-z0-9_]/g, '');

        try {
            const response = await fetch('api.php?action=create_table', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                // Send formatted schema and table name
                body: JSON.stringify({ schema: formattedSchema, table: formattedName })
            });

            const result = await response.json();
            if (result.status === 'success') {
                if (!currentConfig.tables) currentConfig.tables = {};
                
                // Initialize basic table structure in memory with the chosen schema
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

    return btnAddTable;
}

// Sync tables from database
// Uses POST with JSON body so that shared-hosting WAFs (ModSecurity / OWASP CRS)
// do not flag the request as SQL injection based on the query string.
export async function syncSchemaTables(currentConfig, schemaName, onSuccess, onError) {
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const res = await fetch('api.php?action=sync_schema', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ schema_name: schemaName })
        });
        const data = await res.json();
        
        if (data.status === 'success') {
            let addedCount = 0;
            if (!currentConfig.tables || Array.isArray(currentConfig.tables)) currentConfig.tables = {};
            
            data.tables.forEach(tbl => {
                // Skip OpenSparrow system tables — they must never appear as user tables.
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
    label.style.cssText = 'display:block; font-size:13px; color:var(--muted); margin-bottom:6px; font-weight:600;';
    label.textContent = 'Default Sort Order';
    wrapper.appendChild(label);

    const listEl = document.createElement('div');
    listEl.style.cssText = 'display:flex; flex-direction:column; gap:6px;';
    wrapper.appendChild(listEl);

    function renderRules() {
        listEl.replaceChildren();
        rules.forEach((rule, i) => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex; align-items:center; gap:8px;';

            const colInput = document.createElement('select');
            colInput.className = 'adm-input';
            colInput.style.width = '160px';
            const blankOpt = document.createElement('option');
            blankOpt.value = '';
            blankOpt.textContent = '— column —';
            colInput.appendChild(blankOpt);
            ['id', ...Object.keys(tableData.columns || {})].forEach(col => {
                const opt = document.createElement('option');
                opt.value = col;
                opt.textContent = col;
                if (rule.column === col) opt.selected = true;
                colInput.appendChild(opt);
            });
            colInput.addEventListener('change', () => { rules[i].column = colInput.value; markDirty(); });

            const dirSel = document.createElement('select');
            dirSel.className = 'adm-input';
            [['asc', 'ASC ↑'], ['desc', 'DESC ↓']].forEach(([val, lbl]) => {
                const opt = document.createElement('option');
                opt.value = val;
                opt.textContent = lbl;
                if ((rule.dir || 'asc') === val) opt.selected = true;
                dirSel.appendChild(opt);
            });
            dirSel.addEventListener('change', () => { rules[i].dir = dirSel.value; markDirty(); });

            const btnRemove = document.createElement('button');
            btnRemove.type = 'button';
            btnRemove.textContent = '✕';
            btnRemove.className = 'btn btn-danger btn-xs';
            btnRemove.addEventListener('click', () => { rules.splice(i, 1); markDirty(); renderRules(); });

            row.appendChild(colInput);
            row.appendChild(dirSel);
            row.appendChild(btnRemove);
            listEl.appendChild(row);
        });
    }

    renderRules();

    const btnAdd = document.createElement('button');
    btnAdd.type = 'button';
    btnAdd.textContent = '+ Add Sort Rule';
    btnAdd.className = 'btn btn-primary btn-xs';
    btnAdd.style.marginTop = '6px';
    btnAdd.addEventListener('click', () => { rules.push({ column: '', dir: 'asc' }); markDirty(); renderRules(); });
    wrapper.appendChild(btnAdd);

    return wrapper;
}

// Render the schema editor UI
export function renderSchemaEditor(tableName, tableData, ctx) {
    const { workspaceEl, getTableOptions, renderEditor } = ctx;
    
    workspaceEl.innerHTML = '';

    // Create header with title and delete button
    const headerContainer = document.createElement('div');
    headerContainer.style.display = 'flex';
    headerContainer.style.justifyContent = 'space-between';
    headerContainer.style.alignItems = 'center';
    headerContainer.style.marginBottom = '20px';

    const titleEl = document.createElement('h3');
    titleEl.innerHTML = `Table Properties: ${escapeHtml(tableName)}`;
    titleEl.style.margin = '0';

    const btnDeleteTable = document.createElement('button');
    btnDeleteTable.type = 'button';
    btnDeleteTable.textContent = 'Delete Table';
    btnDeleteTable.className = 'btn btn-danger btn-sm';
    btnDeleteTable.onclick = () => {
        if (confirm('Are you sure you want to remove this table from the configuration?')) {
            if (ctx.currentConfig && ctx.currentConfig.tables) {
                delete ctx.currentConfig.tables[tableName];
            }
            workspaceEl.innerHTML = '<h3 style="color: #d00000;">Table removed from configuration. Please click "Save File" to apply changes.</h3>';
            
            markDirty();
            if (typeof ctx.renderSidebar === 'function') {
                ctx.renderSidebar();
            }
        }
    };

    headerContainer.appendChild(titleEl);
    headerContainer.appendChild(btnDeleteTable);
    workspaceEl.appendChild(headerContainer);

    if (!tableData.columns || Array.isArray(tableData.columns)) tableData.columns = {};
    if (!tableData.foreign_keys || Array.isArray(tableData.foreign_keys)) tableData.foreign_keys = {};
    if (!tableData.subtables || !Array.isArray(tableData.subtables)) tableData.subtables = [];

    const btnSyncCols = document.createElement('button');
    btnSyncCols.type = 'button';
    btnSyncCols.className = 'btn btn-sm';
    btnSyncCols.innerHTML = 'Sync Columns from DB';
    
    // Fetch and sync columns from database
    btnSyncCols.onclick = async () => {
        try {
            const schemaName = tableData.schema || 'app';
            // POST with JSON body — avoids WAF false positives on GET query strings.
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const res = await fetch('api.php?action=get_db_columns', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ schema_name: schemaName, table: tableName })
            });

            const rawText = await res.text();

            if (!res.ok) {
                showStatusPill(btnSyncCols, `HTTP Error ${res.status}`, 'error');
                console.error('Sync columns HTTP error:', res.status, rawText);
                return;
            }

            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseErr) {
                showStatusPill(btnSyncCols, 'Server returned invalid JSON. Check console.', 'error');
                console.error('RAW RESPONSE:', rawText);
                return;
            }

            if (data.status === 'success') {
                let added = 0;
                data.columns.forEach(col => {
                    if (!tableData.columns[col.column_name]) {
                        
                        const isEnum = Array.isArray(col.enum_values);
                        const isNotNull = col.is_nullable === 'NO'; 
                        
                        // Extremely robust mapping catching different backend array keys
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

                        // Make ID readonly by default
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
                showStatusPill(btnSyncCols, `Added ${added} new column${added === 1 ? '' : 's'}.`, added > 0 ? 'success' : 'info');
                renderEditor(tableName, tableData, false);
            } else {
                showStatusPill(btnSyncCols, 'API Error: ' + (data.error || 'Failed to sync columns.'), 'error');
            }
        } catch (err) {
            console.error(err);
            showStatusPill(btnSyncCols, 'Communication error. Check console.', 'error');
        }
    };
    workspaceEl.appendChild(btnSyncCols);

    // New code for dynamic column addition
    const btnAddCol = document.createElement('button');
    btnAddCol.type = 'button';
    btnAddCol.className = 'btn btn-sm';
    btnAddCol.textContent = '+ Add Column';
    btnAddCol.style.marginLeft = '10px';

    btnAddCol.onclick = async (e) => {
        e.preventDefault();

        const colName = prompt('Enter new column name (lowercase, no spaces):');
        if (!colName) return;

        // Force proper formatting
        const formattedColName = colName.toLowerCase().replace(/[^a-z0-9_]/g, '');

        // Prevent duplicates
        if (tableData.columns && tableData.columns[formattedColName]) {
            showStatusPill(btnAddCol, 'Column already exists.', 'error');
            return;
        }

        const colType = prompt('Enter data type (e.g., varchar(255), int4, boolean):', 'varchar(255)');
        if (!colType) return;

        try {
            const response = await fetch('api.php?action=add_column', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                // Pass schema context accurately to backend
                body: JSON.stringify({ schema: tableData.schema || 'app', table: tableName, column: formattedColName, type: colType })
            });

            const result = await response.json();
            if (result.status === 'success') {
                tableData.columns[formattedColName] = {
                    display_name: formattedColName.replace(/_/g, ' ').charAt(0).toUpperCase() + formattedColName.replace(/_/g, ' ').slice(1),
                    type: 'text'
                };
                markDirty();
                renderEditor(tableName, tableData, false);
            } else {
                showStatusPill(btnAddCol, 'Error adding column: ' + result.error, 'error');
            }
        } catch (err) {
            console.error(err);
            showStatusPill(btnAddCol, 'Network error occurred.', 'error');
        }
    };
    workspaceEl.appendChild(btnAddCol);

    // Add Virtual Column — schema-only, no DB interaction
    const btnAddVirtual = document.createElement('button');
    btnAddVirtual.type = 'button';
    btnAddVirtual.className = 'btn btn-primary btn-sm';
    btnAddVirtual.textContent = '+ Add Virtual Column';
    btnAddVirtual.style.marginLeft = '10px';
    btnAddVirtual.onclick = () => {
        const colName = prompt('Enter virtual column name (lowercase, no spaces):');
        if (!colName) return;
        const formattedColName = colName.toLowerCase().replace(/[^a-z0-9_]/g, '');
        if (!formattedColName) return;
        if (tableData.columns[formattedColName]) {
            showStatusPill(btnAddVirtual, 'Column already exists.', 'error');
            return;
        }
        tableData.columns[formattedColName] = {
            display_name: formattedColName.replace(/_/g, ' ').charAt(0).toUpperCase() + formattedColName.replace(/_/g, ' ').slice(1),
            type: 'virtual',
            show_in_grid: true,
            formula: { op: 'sum', cols: [] },
        };
        markDirty();
        renderEditor(tableName, tableData, false);
    };
    workspaceEl.appendChild(btnAddVirtual);

    workspaceEl.appendChild(createTextInput('display_name', 'Display Name', tableData.display_name, (val) => {
        tableData.display_name = val;
    }));
    workspaceEl.appendChild(createTextInput('schema', 'Database Schema', tableData.schema || 'app', (val) => tableData.schema = val));

    workspaceEl.appendChild(createIconPicker('icon', 'Icon Path', tableData.icon, (val) => {
        if (val) tableData.icon = val;
        else delete tableData.icon;
    }));

    workspaceEl.appendChild(createCheckbox('hidden', 'Hide from Sidebar Menu', tableData.hidden, (val) => {
        tableData.hidden = val;
    }, false));

    // Default Sort
    workspaceEl.appendChild(buildDefaultSortUI(tableData));

    // Initial Load Limit
    workspaceEl.appendChild(createTextInput(
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

    const colsTitle = document.createElement('h3');
    colsTitle.textContent = 'Columns Configuration';
    colsTitle.style.marginTop = '30px';
    workspaceEl.appendChild(colsTitle);

    if (!tableData.columns || !tableData.columns['id']) {
        const idWarn = document.createElement('div');
        idWarn.className = 'status-pill error';
        idWarn.style.cssText = 'display:block; margin-bottom:16px; padding:10px 14px; line-height:1.5;';
        idWarn.innerHTML = '<strong>Missing required <code>id</code> column.</strong> OpenSparrow requires a column named <code>id</code> of type <code>serial4</code> (auto-increment integer primary key). Without it the grid, edit forms, and relations will not work. Add it via your database tool or by running: <code>ALTER TABLE &lt;table&gt; ADD COLUMN id serial4 PRIMARY KEY;</code> then click <em>Sync Columns from DB</em>.';
        workspaceEl.appendChild(idWarn);
    }

    // Standard field types allowed in the application
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

    const colKeys = Object.keys(tableData.columns);
    colKeys.forEach((colName, index) => {
        const colCfg = tableData.columns[colName];
        const block = document.createElement('div');
        block.className = 'column-block collapsed';

        const headerDiv = document.createElement('div');
        headerDiv.className = 'block-header';
        headerDiv.style.display = 'flex';
        headerDiv.style.alignItems = 'center';
        headerDiv.style.gap = '8px';
        headerDiv.style.borderBottom = '1px solid #DDEAF4';
        headerDiv.style.paddingBottom = '5px';
        headerDiv.style.marginBottom = '15px';
        headerDiv.addEventListener('click', (e) => {
            if (e.target.closest('button')) return;
            block.classList.toggle('collapsed');
        });

        const chevron = document.createElement('span');
        chevron.className = 'block-chevron';
        chevron.textContent = '▶';

        const h4 = document.createElement('h4');
        h4.textContent = `Column: ${colName}`;
        h4.style.margin = '0';
        h4.style.borderBottom = 'none';
        h4.style.flex = '1';

        const moveControls = document.createElement('div');
        
        const btnUp = document.createElement('button');
        btnUp.type = 'button';
        btnUp.innerHTML = 'Up'; 
        btnUp.title = 'Move Up';
        btnUp.style.cssText = 'background:none; border:none; cursor:pointer; font-size:14px; margin-right:10px; text-decoration: underline;';
        if (index === 0) { btnUp.disabled = true; btnUp.style.opacity = '0.3'; btnUp.style.cursor = 'default'; }
        btnUp.onclick = () => {
            tableData.columns = moveObjectKey(tableData.columns, colName, -1);
            renderEditor(tableName, tableData, false);
        };

        const btnDown = document.createElement('button');
        btnDown.type = 'button';
        btnDown.innerHTML = 'Down'; 
        btnDown.title = 'Move Down';
        btnDown.style.cssText = 'background:none; border:none; cursor:pointer; font-size:14px; text-decoration: underline;';
        if (index === colKeys.length - 1) { btnDown.disabled = true; btnDown.style.opacity = '0.3'; btnDown.style.cursor = 'default'; }
        btnDown.onclick = () => {
            tableData.columns = moveObjectKey(tableData.columns, colName, 1);
            renderEditor(tableName, tableData, false);
        };

        moveControls.appendChild(btnUp);
        moveControls.appendChild(btnDown);
        headerDiv.appendChild(chevron);
        headerDiv.appendChild(h4);
        headerDiv.appendChild(moveControls);
        block.appendChild(headerDiv);

        block.appendChild(createTextInput('display_name', 'Display Name', colCfg.display_name, (val) => colCfg.display_name = val));
        block.appendChild(createTextInput('description', 'Column Description (tooltip)', colCfg.description || '', (val) => {
            if (val) colCfg.description = val;
            else delete colCfg.description;
        }));

        // Clean up any rogue legacy DB types just in case they slipped through earlier
        let currentType = String(colCfg.type || 'text').toLowerCase();
        if (!['text', 'number', 'boolean', 'date', 'timestamp', 'enum', 'virtual'].includes(currentType)) {
            if (/int|num|float|double|real|serial|dec/i.test(currentType)) currentType = 'number';
            else if (/bool/i.test(currentType)) currentType = 'boolean';
            else if (/timestamp|timestamptz/i.test(currentType)) currentType = 'timestamp';
            else if (/date|time/i.test(currentType)) currentType = 'date';
            else currentType = 'text';
            colCfg.type = currentType;
        }

        // Dropdown instead of input field for clean type selection
        block.appendChild(createSelectInput('type', 'Data Type', dataTypeOptions, currentType, (val) => {
            colCfg.type = val;
            if (val === 'virtual' && !colCfg.formula) {
                colCfg.formula = { op: 'sum', cols: [] };
            }
            renderEditor(tableName, tableData, false);
        }));
        
        // ── Virtual column formula builder ────────────────────────────────────
        if (currentType === 'virtual') {
            if (!colCfg.formula || typeof colCfg.formula !== 'object') {
                colCfg.formula = { op: 'sum', cols: [] };
            }
            const f = colCfg.formula;

            const vBlock = document.createElement('div');
            vBlock.style.cssText = 'margin-left:20px;padding-left:10px;border-left:2px solid #64748B;margin-bottom:15px;';

            const vTitle = document.createElement('h5');
            vTitle.textContent = 'Formula Configuration';
            vTitle.style.cssText = 'margin-top:0;margin-bottom:10px;color:#64748B;';
            vBlock.appendChild(vTitle);

            // Operation selector
            vBlock.appendChild(createSelectInput('v_op', 'Operation', virtualOpsNumeric, f.op || 'sum', val => {
                f.op = val;
            }));

            // Available non-virtual columns for this table
            const nonVirtualCols = Object.entries(tableData.columns)
                .filter(([n, c]) => c.type !== 'virtual' && n !== colName)
                .map(([n, c]) => ({ value: n, label: c.display_name || n }));

            // Selected columns list
            const colsContainer = document.createElement('div');
            colsContainer.style.cssText = 'margin-top:4px;';

            const colsLabel = document.createElement('label');
            colsLabel.style.cssText = 'font-size:13px;font-weight:600;display:block;margin-bottom:6px;';
            colsLabel.textContent = 'Source Columns (in order)';
            colsContainer.appendChild(colsLabel);

            const selectedList = document.createElement('div');
            selectedList.style.cssText = 'display:flex;flex-direction:column;gap:4px;margin-bottom:6px;';

            function rebuildSelectedList() {
                selectedList.innerHTML = '';
                (f.cols || []).forEach((c, i) => {
                    const row = document.createElement('div');
                    row.style.cssText = 'display:flex;gap:6px;align-items:center;';

                    const lbl = document.createElement('span');
                    lbl.style.cssText = 'flex:1;font-size:13px;background:var(--bg);padding:3px 8px;border-radius:4px;border:1px solid var(--border-light);';
                    lbl.textContent = nonVirtualCols.find(o => o.value === c)?.label ?? c;

                    const rmBtn = document.createElement('button');
                    rmBtn.type = 'button';
                    rmBtn.textContent = '✕';
                    rmBtn.className = 'btn btn-danger btn-xs';
                    rmBtn.addEventListener('click', () => {
                        f.cols.splice(i, 1);
                        rebuildSelectedList();
                        markDirty();
                    });

                    row.append(lbl, rmBtn);
                    selectedList.appendChild(row);
                });
            }

            rebuildSelectedList();
            colsContainer.appendChild(selectedList);

            // Add column picker
            const addRow = document.createElement('div');
            addRow.style.cssText = 'display:flex;gap:6px;align-items:center;';

            const colPicker = document.createElement('select');
            colPicker.style.cssText = 'flex:1;font-size:13px;';
            nonVirtualCols.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt.value; o.textContent = opt.label;
                colPicker.appendChild(o);
            });

            const addColBtn = document.createElement('button');
            addColBtn.type = 'button';
            addColBtn.textContent = '+ Add';
            addColBtn.style.cssText = 'font-size:13px;padding:3px 10px;cursor:pointer;';
            addColBtn.addEventListener('click', () => {
                if (!colPicker.value) return;
                if (!Array.isArray(f.cols)) f.cols = [];
                f.cols.push(colPicker.value);
                rebuildSelectedList();
                markDirty();
            });

            addRow.append(colPicker, addColBtn);
            colsContainer.appendChild(addRow);
            vBlock.appendChild(colsContainer);

            // Separator (concat only)
            const sepWrapper = document.createElement('div');
            sepWrapper.style.display = (f.op === 'concat') ? '' : 'none';
            sepWrapper.appendChild(createTextInput('v_sep', 'Separator', f.separator ?? ' ', val => {
                f.separator = val;
            }));
            vBlock.appendChild(sepWrapper);

            // Show/hide separator when op changes
            const opSel = vBlock.querySelector('select');
            opSel?.addEventListener('change', e => {
                sepWrapper.style.display = e.target.value === 'concat' ? '' : 'none';
            });

            block.appendChild(vBlock);

            // Virtual columns: skip enum, FK, regex, not_null, readonly sections
            block.appendChild(createCheckbox('show_in_grid', 'Show in Grid', colCfg.show_in_grid, val => colCfg.show_in_grid = val, true));

            const btnDelVirtual = document.createElement('button');
            btnDelVirtual.type = 'button';
            btnDelVirtual.textContent = 'Delete Virtual Column';
            btnDelVirtual.className = 'btn btn-danger btn-sm';
            btnDelVirtual.style.marginTop = '8px';
            btnDelVirtual.addEventListener('click', () => {
                if (confirm(`Delete virtual column "${colName}"?`)) {
                    delete tableData.columns[colName];
                    markDirty();
                    renderEditor(tableName, tableData, false);
                }
            });
            block.appendChild(btnDelVirtual);

            makeCollapsible(block);
            workspaceEl.appendChild(block);
            return; // skip remaining non-virtual fields for this column
        }

        const optsStr = colCfg.options ? colCfg.options.join(', ') : '';
        // Build this input inline (instead of createTextInput) so we can update
        // the model on every keystroke without re-rendering the whole editor —
        // the full re-render was stealing focus and resetting the scroll on
        // every keypress. Color pickers depend on colCfg.options, so the
        // re-render is deferred to the `change` event (fires on blur).
        const enumWrapper = document.createElement('div');
        enumWrapper.className = 'form-group';
        const enumLabel = document.createElement('label');
        enumLabel.textContent = 'Enum Options (Comma separated)';
        enumWrapper.appendChild(enumLabel);
        const enumInput = document.createElement('input');
        enumInput.type = 'text';
        enumInput.value = optsStr;

        const applyEnumValue = (val) => {
            if (val) {
                colCfg.options = val.split(',').map(s => s.trim()).filter(Boolean);
            } else {
                delete colCfg.options;
                delete colCfg.enum_colors;
            }
        };

        enumInput.addEventListener('input', (e) => {
            // Keep the in-memory config in sync so that clicking "Save File"
            // mid-typing does not lose the current value.
            applyEnumValue(e.target.value);
        });
        enumInput.addEventListener('change', (e) => {
            // Blur / Enter — now it is safe to re-render the editor so the
            // color-picker rows appear for the freshly entered options.
            applyEnumValue(e.target.value);
            renderEditor(tableName, tableData, false);
        });

        enumWrapper.appendChild(enumInput);
        block.appendChild(enumWrapper);

        const isTypeEnum = String(colCfg.type || '').toLowerCase() === 'enum';

        // Render color picker for ENUM types
        if (isTypeEnum && colCfg.options && colCfg.options.length > 0) {
            const colorsContainer = document.createElement('div');
            colorsContainer.style.marginLeft = '20px';
            colorsContainer.style.paddingLeft = '10px';
            colorsContainer.style.borderLeft = '2px solid #64748B';
            colorsContainer.style.marginBottom = '15px';
            
            const colorsTitle = document.createElement('h5');
            colorsTitle.textContent = 'Enum Colors (Optional)';
            colorsTitle.style.marginTop = '0';
            colorsTitle.style.marginBottom = '10px';
            colorsContainer.appendChild(colorsTitle);

            if (!colCfg.enum_colors) colCfg.enum_colors = {};

            colCfg.options.forEach(optVal => {
                colorsContainer.appendChild(createColorInput(
                    `enum_color`,
                    `Color: ${optVal}`,
                    colCfg.enum_colors[optVal] || '#ffffff',
                    (val) => { colCfg.enum_colors[optVal] = val; }
                ));
            });
            block.appendChild(colorsContainer);
        }

        const fkData = tableData.foreign_keys[colName] || {};
        block.appendChild(createSelectInput('fk_ref', 'Foreign Key Reference Table', getTableOptions(), fkData.reference_table || '', (val) => {
            if (val) tableData.foreign_keys[colName] = { reference_table: val, reference_column: fkData.reference_column || 'id', display_column: fkData.display_column || ['name'] };
            else delete tableData.foreign_keys[colName];
            renderEditor(tableName, tableData, false); 
        }));

        // Render additional foreign key settings if referenced table is chosen
        if (tableData.foreign_keys[colName] && tableData.foreign_keys[colName].reference_table) {
            const fkContainer = document.createElement('div');
            fkContainer.style.marginLeft = '20px'; fkContainer.style.paddingLeft = '10px'; fkContainer.style.borderLeft = '2px solid var(--accent)'; fkContainer.style.marginBottom = '15px';
            fkContainer.appendChild(createTextInput('fk_ref_col', 'Reference Column (e.g., id)', tableData.foreign_keys[colName].reference_column, (val) => tableData.foreign_keys[colName].reference_column = val));
            
            const fkDispData = tableData.foreign_keys[colName].display_column;
            const fkDispStr = Array.isArray(fkDispData) ? fkDispData.join(', ') : (fkDispData || '');
            
            fkContainer.appendChild(createTextInput('fk_disp_col', 'Display Columns (Comma separated, e.g., first_name, last_name)', fkDispStr, (val) => {
                if(val) {
                    tableData.foreign_keys[colName].display_column = val.split(',').map(s => s.trim()).filter(s => s !== '');
                } else {
                    tableData.foreign_keys[colName].display_column = [];
                }
            }));
            
            block.appendChild(fkContainer);
        }

        // New feature: Validation Regex and Message configuration block
        const regexContainer = document.createElement('div');
        regexContainer.style.marginLeft = '20px'; 
        regexContainer.style.paddingLeft = '10px'; 
        regexContainer.style.borderLeft = '2px solid #64748B'; 
        regexContainer.style.marginBottom = '15px';

        const regexTitle = document.createElement('h5');
        regexTitle.textContent = 'Validation Rules (Optional)';
        regexTitle.style.marginTop = '0';
        regexTitle.style.marginBottom = '10px';
        regexContainer.appendChild(regexTitle);

        regexContainer.appendChild(createTextInput(
            'validation_regexp', 
            'RegExp Pattern (e.g., ^[A-Z]{2}\\d{4}$)', 
            colCfg.validation_regexp || '', 
            (val) => { 
                if (val) colCfg.validation_regexp = val; 
                else delete colCfg.validation_regexp; 
            }
        ));

        regexContainer.appendChild(createTextInput(
            'validation_message', 
            'Error Message (e.g., Invalid code format)', 
            colCfg.validation_message || '', 
            (val) => { 
                if (val) colCfg.validation_message = val; 
                else delete colCfg.validation_message; 
            }
        ));

        block.appendChild(regexContainer);
        
        block.appendChild(createCheckbox('show_in_grid', 'Show in Grid', colCfg.show_in_grid, (val) => colCfg.show_in_grid = val, true));
        block.appendChild(createCheckbox('show_in_edit', 'Show in Edit Form', colCfg.show_in_edit, (val) => colCfg.show_in_edit = val, true));
        block.appendChild(createCheckbox('not_null', 'Is Required (Not Null)', colCfg.not_null, (val) => colCfg.not_null = val, false));
        block.appendChild(createCheckbox('readonly', 'Read Only', colCfg.readonly, (val) => colCfg.readonly = val, false));

        makeCollapsible(block);
        workspaceEl.appendChild(block);
    });

    const subTitle = document.createElement('h3');
    subTitle.textContent = 'Subtables Configuration (Has Many Relationships)';
    subTitle.style.marginTop = '40px';
    workspaceEl.appendChild(subTitle);

    const subContainer = document.createElement('div');
    workspaceEl.appendChild(subContainer);

    // Render configuration for subtables
    const renderSubtables = () => {
        subContainer.innerHTML = '';
        tableData.subtables.forEach((subCfg, index) => {
            const block = document.createElement('div');
            block.className = 'column-block collapsed';
            block.style.borderLeft = '4px solid #2b9348';

            const headerDiv = document.createElement('div');
            headerDiv.className = 'block-header';
            headerDiv.style.display = 'flex';
            headerDiv.style.alignItems = 'center';
            headerDiv.style.gap = '8px';
            headerDiv.style.marginBottom = '15px';
            headerDiv.addEventListener('click', (e) => {
                if (e.target.closest('button')) return;
                block.classList.toggle('collapsed');
            });

            const chevron = document.createElement('span');
            chevron.className = 'block-chevron';
            chevron.textContent = '▶';

            const h4 = document.createElement('h4');
            h4.textContent = `Subtable #${index + 1}`;
            h4.style.margin = '0';
            h4.style.flex = '1';

            const btnDel = document.createElement('button');
            btnDel.type = 'button';
            btnDel.textContent = 'Delete';
            btnDel.style.cssText = 'background:none; border:none; color:var(--danger); cursor:pointer; font-weight:bold; text-decoration: underline;';
            btnDel.onclick = () => {
                tableData.subtables.splice(index, 1);
                renderSubtables();
            };

            headerDiv.appendChild(chevron);
            headerDiv.appendChild(h4);
            headerDiv.appendChild(btnDel);
            block.appendChild(headerDiv);

            block.appendChild(createSelectInput('sub_table', 'Child Table (Target)', getTableOptions(), subCfg.table || '', (val) => subCfg.table = val));
            block.appendChild(createTextInput('sub_fk', 'Foreign Key Column in Child Table', subCfg.foreign_key, (val) => subCfg.foreign_key = val));
            block.appendChild(createTextInput('sub_label', 'Display Label', subCfg.label, (val) => subCfg.label = val));
            
            const colsStr = subCfg.columns_to_show ? subCfg.columns_to_show.join(', ') : '';
            block.appendChild(createTextInput('sub_cols', 'Columns to Show (Comma separated)', colsStr, (val) => {
                if(val) {
                    subCfg.columns_to_show = val.split(',').map(s => s.trim()).filter(s => s !== ''); 
                } else {
                    subCfg.columns_to_show = [];
                }
            }));

            makeCollapsible(block);
            subContainer.appendChild(block);
        });

        const btnAddSub = document.createElement('button');
        btnAddSub.type = 'button';
        btnAddSub.className = 'btn btn-success btn-sm';
        btnAddSub.textContent = '+ Add Subtable';
        btnAddSub.onclick = () => {
            tableData.subtables.push({ table: '', foreign_key: '', label: '', columns_to_show: ['id'] });
            renderSubtables();
        };
        subContainer.appendChild(btnAddSub);
    };

    renderSubtables();

    // ── Many-to-Many Relationships ────────────────────────────────────────────
    if (!Array.isArray(tableData.many_to_many)) tableData.many_to_many = [];

    const m2mTitle = document.createElement('h3');
    m2mTitle.textContent = 'Many-to-Many Relationships';
    m2mTitle.style.marginTop = '40px';
    workspaceEl.appendChild(m2mTitle);

    const m2mHint = document.createElement('p');
    m2mHint.style.cssText = 'color:var(--muted); font-size:13px; margin:-8px 0 14px;';
    m2mHint.textContent = 'Checkbox panels shown in edit/create forms. Each entry links this table to another via a junction table.';
    workspaceEl.appendChild(m2mHint);

    const m2mContainer = document.createElement('div');
    workspaceEl.appendChild(m2mContainer);

    const renderM2m = () => {
        m2mContainer.replaceChildren();

        tableData.many_to_many.forEach((cfg, index) => {
            const block = document.createElement('div');
            block.className = 'column-block collapsed';
            block.style.borderLeft = '4px solid #64748B';

            const headerDiv = document.createElement('div');
            headerDiv.className = 'block-header';
            headerDiv.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:15px;';
            headerDiv.addEventListener('click', (e) => {
                if (e.target.closest('button')) return;
                block.classList.toggle('collapsed');
            });

            const chevron = document.createElement('span');
            chevron.className = 'block-chevron';
            chevron.textContent = '▶';

            const h4 = document.createElement('h4');
            h4.style.cssText = 'margin:0; flex:1;';
            h4.textContent = cfg.label || `M2M #${index + 1}`;

            const btnDel = document.createElement('button');
            btnDel.type = 'button';
            btnDel.textContent = 'Delete';
            btnDel.style.cssText = 'background:none; border:none; color:var(--danger); cursor:pointer; font-weight:bold; text-decoration:underline;';
            btnDel.onclick = () => { tableData.many_to_many.splice(index, 1); markDirty(); renderM2m(); };

            headerDiv.append(chevron, h4, btnDel);
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

        const btnAdd = document.createElement('button');
        btnAdd.type = 'button';
        btnAdd.className = 'btn btn-sm';
        btnAdd.textContent = '+ Add Many-to-Many';
        btnAdd.onclick = () => {
            tableData.many_to_many.push({
                label: '', junction_table: '', self_fk: '',
                other_fk: '', other_table: '', display_column: 'name'
            });
            markDirty();
            renderM2m();
        };
        m2mContainer.appendChild(btnAdd);
    };

    renderM2m();
}