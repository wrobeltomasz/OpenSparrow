// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { getGlobalSchema } from './app.js';

const LS_COPY_MODE  = 'csv_import_default_copy';
const LS_DELIMITER  = 'csv_import_delimiter';
const LS_ENCODING   = 'csv_import_encoding';

const DELIMITERS = [
    { value: ',',  label: 'Comma (,)  — CSV' },
    { value: ';',  label: 'Semicolon (;)  — European CSV' },
    { value: '\t', label: 'Tab (\\t)  — TSV' },
    { value: '|',  label: 'Pipe (|)' },
];

const ENCODINGS = [
    { value: 'UTF-8',        label: 'UTF-8 (Universal — recommended)' },
    { value: 'Windows-1250', label: 'Windows-1250 (Polish, Czech, Slovak, Hungarian)' },
    { value: 'Windows-1252', label: 'Windows-1252 (Western European — German, French)' },
    { value: 'ISO-8859-1',   label: 'ISO-8859-1 / Latin-1 (Western European)' },
    { value: 'ISO-8859-2',   label: 'ISO-8859-2 / Latin-2 (Central European)' },
    { value: 'Windows-1251', label: 'Windows-1251 (Cyrillic — Russian, Ukrainian)' },
];

export async function renderCsvImportPage(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '';
    workspaceElement._csvImportGen = (workspaceElement._csvImportGen || 0) + 1;
    const myGen = workspaceElement._csvImportGen;

    let csvHeaders          = [];
    let csvPreview          = [];
    let csvRowCount         = 0;
    let csvTemporaryName          = '';
    let csvOriginalName         = '';
    let selectedTable       = '';
    let tableColumns        = {};
    let createMode          = false;
    let newTableName        = '';
    let newTableDisplayName = '';
    let newTableSchema      = 'public';
    let csvFile             = null;
    let historyLoaded       = false;
    let csvDelimiter        = localStorage.getItem(LS_DELIMITER) || ',';
    let csvEncoding         = localStorage.getItem(LS_ENCODING)  || 'UTF-8';

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    wrap.style.paddingBottom = '60px';

    const heading = document.createElement('h2');
    heading.className = 'admin-page-title';
    heading.textContent = 'CSV Import';
    wrap.appendChild(heading);

    const { panels, activate } = buildCsvTabs(wrap, [
        { id: 'import',  label: 'Import',         icon: 'upload.png' },
        { id: 'config',  label: 'Configuration',  icon: 'car_gear.png' },
        { id: 'history', label: 'Import History', icon: 'manage_history.png' },
    ]);

    const importPanel  = panels['import'];
    const configPanel  = panels['config'];
    const historyPanel = panels['history'];

    const description = document.createElement('p');
    description.style.cssText = 'color:var(--muted);margin:0 0 20px;';
    description.textContent = 'Import rows from a CSV file into an existing table, or create a new table directly from CSV headers.';
    importPanel.appendChild(description);

    const card1 = buildCard('Step 1 — Select Table & Upload CSV');
    importPanel.appendChild(card1.el);

    const tableRow = buildRow();
    const tableLabel = buildLabel('Target table:');
    tableLabel.style.minWidth = '110px';

    const tableSelect = document.createElement('select');
    tableSelect.className = 'adm-input w-220';
    appendOption(tableSelect, '', '— Select table —');

    try {
        const data = await getGlobalSchema();
        if (data?.tables) {
            for (const [name, config] of Object.entries(data.tables)) {
                const option = appendOption(tableSelect, name, config.display_name || name);
                option.dataset.cols = JSON.stringify(config.columns || {});
            }
        }
    } catch (_) {  }

    tableRow.append(tableLabel, tableSelect);
    card1.body.appendChild(tableRow);

    const createToggleRow = buildRow();
    const createCheckbox = document.createElement('input');
    createCheckbox.type  = 'checkbox';
    createCheckbox.id    = 'csv-create-table-chk';
    createCheckbox.className = 'adm-check';
    const createCheckboxLabel = buildLabel('Create new table from CSV');
    createCheckboxLabel.htmlFor = 'csv-create-table-chk';
    createCheckboxLabel.style.cursor = 'pointer';
    createToggleRow.append(createCheckbox, createCheckboxLabel);
    card1.body.appendChild(createToggleRow);

    const delimRow = buildRow();
    const delimLabel = buildLabel('Delimiter:');
    delimLabel.style.minWidth = '80px';
    const delimSelect = document.createElement('select');
    delimSelect.className = 'adm-input';
    DELIMITERS.forEach(({ value, label }) => {
        const option = appendOption(delimSelect, value, label);
        if (value === csvDelimiter) option.selected = true;
    });
    delimRow.append(delimLabel, delimSelect);
    card1.body.appendChild(delimRow);

    const encRow = buildRow();
    const encLabel = buildLabel('Encoding:');
    encLabel.style.minWidth = '80px';
    const encSelect = document.createElement('select');
    encSelect.className = 'adm-input w-260';
    ENCODINGS.forEach(({ value, label }) => {
        const option = appendOption(encSelect, value, label);
        if (value === csvEncoding) option.selected = true;
    });
    encRow.append(encLabel, encSelect);
    card1.body.appendChild(encRow);

    const newTableForm = document.createElement('div');
    newTableForm.style.cssText = 'display:none;grid-template-columns:140px 1fr;gap:10px 16px;align-items:center;margin-bottom:16px;max-width:560px;';

    const schemaLabel = buildLabel('Schema:');

    const schemaSelect = document.createElement('select');
    schemaSelect.className = 'adm-input w-full';
    appendOption(schemaSelect, 'public', 'public');
    schemaSelect.dataset.loaded = '0';

    const nameLabel = buildLabel('Table name (DB):');
    const nameInput = document.createElement('input');
    nameInput.type        = 'text';
    nameInput.placeholder = 'e.g. my_customers';
    nameInput.className = 'adm-input w-full';

    const dispLabel = buildLabel('Display name:');
    const dispInput = document.createElement('input');
    dispInput.type        = 'text';
    dispInput.placeholder = 'e.g. My Customers (optional)';
    dispInput.className = 'adm-input w-full';

    newTableForm.append(schemaLabel, schemaSelect, nameLabel, nameInput, dispLabel, dispInput);
    card1.body.appendChild(newTableForm);

    const dropZone = document.createElement('div');
    dropZone.style.cssText = 'border:2px dashed var(--border);border-radius:8px;padding:32px 20px;text-align:center;background:#fff;cursor:pointer;transition:border-color .2s,background .2s;margin-top:16px;';

    const uploadIcon = document.createElement('img');
    uploadIcon.src = '../assets/icons/upload.png';
    uploadIcon.alt = '';
    uploadIcon.style.cssText = 'width:36px;height:36px;margin-bottom:8px;pointer-events:none;opacity:0.5;';

    const uploadMessage = document.createElement('div');
    uploadMessage.style.cssText = 'color:var(--muted);margin-bottom:4px;pointer-events:none;';
    uploadMessage.textContent = 'Click to select a CSV file or drag & drop here';

    const uploadHint = document.createElement('div');
    uploadHint.style.cssText = 'color:var(--muted);pointer-events:none;';
    uploadHint.textContent = '.csv only · max 500 MB';

    const fileInput = document.createElement('input');
    fileInput.type   = 'file';
    fileInput.accept = '.csv,text/csv';
    fileInput.style.display = 'none';

    dropZone.append(uploadIcon, uploadMessage, uploadHint, fileInput);
    card1.body.appendChild(dropZone);

    const card2 = buildCard('Step 2 — Map Columns & Execute');
    card2.el.style.display = 'none';
    importPanel.appendChild(card2.el);

    const resultArea = document.createElement('div');
    importPanel.appendChild(resultArea);

    const mappingContainer = document.createElement('div');
    card2.body.appendChild(mappingContainer);

    const conflictRow = buildRow();
    conflictRow.style.cssText = 'display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:20px;';

    const conflictLabel = buildLabel('Upsert on conflict:');
    conflictLabel.style.minWidth = '140px';

    const conflictSelect = document.createElement('select');
    conflictSelect.className = 'adm-input w-200';
    appendOption(conflictSelect, '', '— None (insert only) —');

    const conflictNote = document.createElement('span');
    conflictNote.style.cssText = 'color:var(--muted);';
    conflictNote.textContent = 'Matching rows will be updated instead of rejected (requires unique constraint).';

    const conflictWarn = document.createElement('div');
    conflictWarn.style.cssText = 'display:none;margin-top:8px;padding:8px 12px;background:var(--warn-light);border:1px solid var(--warn);border-radius:4px;color:var(--muted);';

    conflictRow.append(conflictLabel, conflictSelect, conflictNote);
    card2.body.appendChild(conflictRow);
    card2.body.appendChild(conflictWarn);

    const modeRow = buildRow();
    modeRow.style.cssText = 'margin-top:16px;margin-bottom:0;gap:10px;align-items:center;';
    const copyModeCheckbox = document.createElement('input');
    copyModeCheckbox.type = 'checkbox';
    copyModeCheckbox.id   = 'csv-copy-mode-chk';
    copyModeCheckbox.style.cssText = 'width:16px;height:16px;cursor:pointer;flex-shrink:0;';
    copyModeCheckbox.checked = localStorage.getItem(LS_COPY_MODE) === '1';
    const copyModeLabel = document.createElement('label');
    copyModeLabel.htmlFor = 'csv-copy-mode-chk';
    copyModeLabel.style.cssText = 'color:var(--muted);cursor:pointer;';
    copyModeLabel.textContent = 'Fast COPY mode';
    modeRow.append(copyModeCheckbox, copyModeLabel);
    card2.body.appendChild(modeRow);

    const execButton = document.createElement('button');
    execButton.type      = 'button';
    execButton.textContent = 'Execute Import';
    execButton.className = 'btn btn-primary';
    execButton.style.marginTop = '20px';
    card2.body.appendChild(execButton);

    const execStatus = document.createElement('div');
    execStatus.style.marginTop = '14px';
    card2.body.appendChild(execStatus);

    if (workspaceElement._csvImportGen !== myGen) return;
    workspaceElement.appendChild(wrap);

    const configHeading = document.createElement('h3');
    configHeading.style.cssText = 'margin:0 0 20px;';
    configHeading.textContent = 'Import Settings';
    configPanel.appendChild(configHeading);

    const modeCard = buildCard('Default Import Mode');
    configPanel.appendChild(modeCard.el);

    const modeDescription = document.createElement('p');
    modeDescription.style.cssText = 'color:var(--muted);margin:0 0 16px;';
    modeDescription.textContent = 'Choose the default mode used when running imports. You can override this per-import in Step 2.';
    modeCard.body.appendChild(modeDescription);

    const savedCopy = localStorage.getItem(LS_COPY_MODE) === '1';

    function buildModeOption(id, value, labelText, descriptionText) {
        const row = document.createElement('label');
        row.style.cssText = 'display:flex;gap:12px;align-items:flex-start;cursor:pointer;margin-bottom:14px;';
        const radio = document.createElement('input');
        radio.type    = 'radio';
        radio.name    = 'csv-default-mode';
        radio.id      = id;
        radio.value   = value;
        radio.checked = value === 'copy' ? savedCopy : !savedCopy;
        radio.style.cssText = 'margin-top:3px;flex-shrink:0;width:15px;height:15px;cursor:pointer;';
        const text = document.createElement('div');
        const strong = document.createElement('strong');
        strong.style.cssText = 'display:block;margin-bottom:2px;';
        strong.textContent = labelText;
        const small = document.createElement('span');
        small.style.cssText = 'color:var(--muted);';
        small.textContent = descriptionText;
        text.append(strong, small);
        row.append(radio, text);
        modeCard.body.appendChild(row);
        return radio;
    }

    const radioNormal = buildModeOption(
        'csv-mode-normal', 'normal',
        'Normal mode (batched INSERT)',
        'Inserts rows in batches of 1000. Tracks per-row errors, supports upsert (ON CONFLICT). Best for smaller files or when error details matter.'
    );
    const radioCopy = buildModeOption(
        'csv-mode-copy', 'copy',
        'Fast COPY mode (PostgreSQL COPY FROM STDIN)',
        'Streams the entire file directly to PostgreSQL. 10-60x faster for large files. No per-row error tracking — a single type mismatch fails the whole import. No upsert support.'
    );

    function syncModeRadios() {
        const isCopy = radioCopy.checked;
        localStorage.setItem(LS_COPY_MODE, isCopy ? '1' : '0');
        copyModeCheckbox.checked = isCopy;
        conflictRow.style.display  = isCopy ? 'none' : '';
        conflictWarn.style.display = 'none';
    }

    radioNormal.addEventListener('change', syncModeRadios);
    radioCopy.addEventListener('change', syncModeRadios);

    if (savedCopy) {
        conflictRow.style.display = 'none';
    }

    const delimCard = buildCard('Default Delimiter');
    configPanel.appendChild(delimCard.el);

    const delimDescription = document.createElement('p');
    delimDescription.style.cssText = 'color:var(--muted);margin:0 0 14px;';
    delimDescription.textContent = 'Column separator used when parsing CSV files. Override per-import in Step 1.';
    delimCard.body.appendChild(delimDescription);

    const configDelimSelect = document.createElement('select');
    configDelimSelect.className = 'adm-input';
    DELIMITERS.forEach(({ value, label }) => {
        const option = appendOption(configDelimSelect, value, label);
        if (value === csvDelimiter) option.selected = true;
    });
    delimCard.body.appendChild(configDelimSelect);

    configDelimSelect.addEventListener('change', () => {
        csvDelimiter = configDelimSelect.value;
        localStorage.setItem(LS_DELIMITER, csvDelimiter);
        for (const option of delimSelect.options) {
            if (option.value === csvDelimiter) { option.selected = true; break; }
        }
    });

    const encCard = buildCard('Default Encoding');
    configPanel.appendChild(encCard.el);

    const encDescription = document.createElement('p');
    encDescription.style.cssText = 'color:var(--muted);margin:0 0 14px;';
    encDescription.textContent = 'Character encoding of the source CSV file. Override per-import in Step 1. Files are converted to UTF-8 before inserting into PostgreSQL.';
    encCard.body.appendChild(encDescription);

    const configEncSelect = document.createElement('select');
    configEncSelect.className = 'adm-input w-260';
    ENCODINGS.forEach(({ value, label }) => {
        const option = appendOption(configEncSelect, value, label);
        if (value === csvEncoding) option.selected = true;
    });
    encCard.body.appendChild(configEncSelect);

    configEncSelect.addEventListener('change', () => {
        csvEncoding = configEncSelect.value;
        localStorage.setItem(LS_ENCODING, csvEncoding);
        for (const option of encSelect.options) {
            if (option.value === csvEncoding) { option.selected = true; break; }
        }
    });

    const limitsCard = buildCard('Server Limits');
    configPanel.appendChild(limitsCard.el);

    const limitsNote = document.createElement('p');
    limitsNote.style.cssText = 'color:var(--muted);margin:0 0 14px;';
    limitsNote.textContent = 'Current server configuration. To change these values, edit docker-php-dev.ini and nginx.conf, then restart the container.';
    limitsCard.body.appendChild(limitsNote);

    const limitsGrid = document.createElement('div');
    limitsGrid.style.cssText = 'display:grid;grid-template-columns:max-content 1fr;gap:6px 20px;';
    limitsCard.body.appendChild(limitsGrid);

    function addLimitRow(label, value, note) {
        const label = document.createElement('span');
        label.style.cssText = 'color:var(--muted);font-weight:600;white-space:nowrap;';
        label.textContent = label;
        const value = document.createElement('span');
        value.style.cssText = 'font-family:var(--font-mono);color:var(--text);';
        value.textContent = value + (note ? ' — ' + note : '');
        limitsGrid.append(label, value);
    }

    try {
        const configResult  = await apiFetch('api_csv_import.php?action=csv_import_config');
        const configData = await configResult.json();
        if (configData.status === 'success') {
            addLimitRow('Max upload size', configData.max_upload_mb + ' MB', 'CSV_MAX_BYTES in api_csv_import.php');
            addLimitRow('Max execution time', configData.max_execution_sec + 's', 'max_execution_time in docker-php-dev.ini');
            addLimitRow('PHP memory limit', configData.memory_limit, 'memory_limit in docker-php-dev.ini');
            addLimitRow('Batch size (normal mode)', configData.batch_size + ' rows/INSERT', 'CSV_BATCH_SIZE in api_csv_import.php');
        }
    } catch (_) {
        const error = document.createElement('p');
        error.style.cssText = 'color:var(--error);';
        error.textContent = 'Could not load server limits.';
        limitsCard.body.appendChild(error);
    }

    const histTitle = document.createElement('h3');
    histTitle.style.cssText = 'margin:0 0 12px;';
    histTitle.textContent = 'Import History';

    const histContainer = document.createElement('div');
    historyPanel.append(histTitle, histContainer);

    tableSelect.addEventListener('change', () => {
        selectedTable = tableSelect.value;
        const option = tableSelect.options[tableSelect.selectedIndex];
        try { tableColumns = option.dataset.cols ? JSON.parse(option.dataset.cols) : {}; }
        catch (_) { tableColumns = {}; }
        rebuildConflictOptions();
        if (csvHeaders.length > 0) renderMapping();
    });

    dropZone.addEventListener('click', () => {
        if (createMode && !newTableName) {
            flashMessage(uploadMessage, 'Enter a table name first.', 'var(--error)');
            return;
        }
        if (!createMode && !selectedTable) {
            flashMessage(uploadMessage, 'Select a target table first.', 'var(--error)');
            return;
        }
        fileInput.click();
    });

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = 'var(--muted)';
        dropZone.style.background  = 'var(--accent-mid)';
    });
    dropZone.addEventListener('dragleave', () => resetDropZone());
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        resetDropZone();
        const f = e.dataTransfer.files[0];
        if (f) handleUpload(f);
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files[0]) handleUpload(fileInput.files[0]);
        fileInput.value = '';
    });

    delimSelect.addEventListener('change', () => {
        csvDelimiter = delimSelect.value;
        localStorage.setItem(LS_DELIMITER, csvDelimiter);
        for (const option of configDelimSelect.options) {
            if (option.value === csvDelimiter) { option.selected = true; break; }
        }
        if (createMode && csvFile) {
            loadCSVPreviewLocal(csvFile, csvDelimiter, csvEncoding).then(({ headers, preview }) => {
                csvHeaders = headers;
                csvPreview = preview;
                renderMapping();
            }).catch(() => {});
        }
    });

    encSelect.addEventListener('change', () => {
        csvEncoding = encSelect.value;
        localStorage.setItem(LS_ENCODING, csvEncoding);
        for (const option of configEncSelect.options) {
            if (option.value === csvEncoding) { option.selected = true; break; }
        }
        if (createMode && csvFile) {
            loadCSVPreviewLocal(csvFile, csvDelimiter, csvEncoding).then(({ headers, preview }) => {
                csvHeaders = headers;
                csvPreview = preview;
                renderMapping();
            }).catch(() => {});
        }
    });

    conflictSelect.addEventListener('change', validateConflict);
    mappingContainer.addEventListener('change', validateConflict);

    copyModeCheckbox.addEventListener('change', () => {
        const isCopy = copyModeCheckbox.checked;
        conflictRow.style.display  = isCopy ? 'none' : '';
        conflictWarn.style.display = 'none';

        radioNormal.checked = !isCopy;
        radioCopy.checked   = isCopy;
        localStorage.setItem(LS_COPY_MODE, isCopy ? '1' : '0');
    });

    createCheckbox.addEventListener('change', () => {
        createMode = createCheckbox.checked;
        tableRow.style.display     = createMode ? 'none' : '';
        newTableForm.style.display = createMode ? 'grid' : 'none';
        if (createMode) loadSchemas();
        conflictRow.style.display   = createMode ? 'none' : '';
        conflictWarn.style.display  = 'none';
        if (createMode) {
            selectedTable = '';
            execButton.textContent = 'Create Table & Import';
        } else {
            selectedTable = tableSelect.value;
            newTableName  = '';
            execButton.textContent = 'Execute Import';
        }
        if (csvHeaders.length) renderMapping();
    });

    nameInput.addEventListener('input', () => {
        newTableName = nameInput.value.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
        nameInput.value = newTableName;
    });

    dispInput.addEventListener('input', () => {
        newTableDisplayName = dispInput.value.trim();
    });

    schemaSelect.addEventListener('change', () => {
        newTableSchema = schemaSelect.value || 'public';
    });

    execButton.addEventListener('click', () => (createMode ? createTableAndImport() : executeImport()));

    const originalActivate = activate;
    const wrappedActivate = (id) => {
        originalActivate(id);
        if (id === 'history' && !historyLoaded) {
            historyLoaded = true;
            loadHistory();
        }
    };

    historyPanel.parentElement?.querySelectorAll('button[data-tab]').forEach(button => {
        if (button.dataset.tab === 'history') {
            button.addEventListener('click', () => { if (!historyLoaded) { historyLoaded = true; loadHistory(); } });
        }
    });

    async function loadSchemas() {
        if (schemaSelect.dataset.loaded === '1') return;
        schemaSelect.dataset.loaded = '1';
        try {
            const result  = await apiFetch('api_csv_import.php?action=csv_schemas');
            const data = await result.json();
            if (data.status === 'success' && Array.isArray(data.schemas) && data.schemas.length) {
                schemaSelect.innerHTML = '';
                data.schemas.forEach(s => appendOption(schemaSelect, s, s));
                for (const option of schemaSelect.options) {
                    if (option.value === 'public') { option.selected = true; break; }
                }
                newTableSchema = schemaSelect.value;
            }
        } catch (_) {  }
    }

    async function handleUpload(file) {
        if (createMode && !newTableName) {
            flashMessage(uploadMessage, 'Enter a table name first.', 'var(--error)');
            return;
        }
        if (!createMode && !selectedTable) {
            flashMessage(uploadMessage, 'Select a target table first.', 'var(--error)');
            return;
        }

        uploadMessage.style.color  = 'var(--muted)';
        uploadHint.textContent = '';

        if (createMode) {
            uploadMessage.textContent = `Reading ${escHtml(file.name)}…`;
            try {
                const { headers, preview } = await loadCSVPreviewLocal(file, csvDelimiter, csvEncoding);
                csvFile     = file;
                csvHeaders  = headers;
                csvPreview  = preview;
                csvTemporaryName  = '';
                csvOriginalName = file.name;

                uploadMessage.textContent = `✓ ${escHtml(file.name)} — ${headers.length} column${headers.length !== 1 ? 's' : ''} detected`;
                uploadMessage.style.color = 'var(--ok)';
                dropZone.style.borderColor = 'var(--muted)';

                renderMapping();
                card2.el.style.display = 'block';
            } catch (e) {
                uploadMessage.textContent = 'Preview failed: ' + escHtml(e.message);
                uploadMessage.style.color = 'var(--error)';
                uploadHint.textContent = 'Try again.';
            }
            return;
        }

        uploadMessage.textContent = `Uploading ${escHtml(file.name)}…`;
        const formData = new FormData();
        formData.append('csv_file', file);
        formData.append('csv_delimiter', csvDelimiter);
        formData.append('csv_encoding', csvEncoding);

        try {
            const result  = await apiFetch('api_csv_import.php?action=csv_import_upload', {
                method: 'POST',
                body: formData,
            });
            const data = await result.json();
            if (data.status !== 'success') throw new Error(data.error || 'Upload failed');

            csvHeaders  = data.headers;
            csvPreview  = data.preview;
            csvRowCount = data.row_count;
            csvTemporaryName  = data.tmp_name;
            csvOriginalName = data.original_name;

            uploadMessage.textContent  = `✓ ${escHtml(file.name)}  —  ${csvRowCount.toLocaleString()} data rows, ${csvHeaders.length} columns`;
            uploadMessage.style.color  = 'var(--ok)';
            dropZone.style.borderColor = 'var(--muted)';

            renderMapping();
            card2.el.style.display = 'block';
        } catch (e) {
            uploadMessage.textContent  = 'Upload failed: ' + escHtml(e.message);
            uploadMessage.style.color  = 'var(--error)';
            uploadHint.textContent = 'Try again.';
        }
    }

    function renderMapping() {
        mappingContainer.innerHTML = '';
        if (!csvHeaders.length) return;

        if (createMode) {
            const typeOptions = [
                { value: 'varchar(255)', label: 'Text' },
                { value: 'text',         label: 'Long Text' },
                { value: 'int4',         label: 'Number (integer)' },
                { value: 'int8',         label: 'Number (big integer)' },
                { value: 'numeric',      label: 'Number (decimal)' },
                { value: 'boolean',      label: 'Boolean' },
                { value: 'date',         label: 'Date' },
                { value: 'timestamp',    label: 'Timestamp' },
            ];

            const note = document.createElement('p');
            note.style.cssText = 'color:var(--muted);margin:0 0 12px;';
            note.textContent = `Rename ${csvHeaders.length} CSV column${csvHeaders.length !== 1 ? 's' : ''} to database column names. The table will be created with an auto-increment id column plus these columns.`;
            mappingContainer.appendChild(note);

            const table = document.createElement('table');
            table.className = 'adm-tbl';

            const thead = table.createTHead();
            const hrow  = thead.insertRow();
            for (const h of ['CSV Header', 'Sample values', 'DB column name', 'Type']) {
                const th = document.createElement('th');
                th.className = 'adm-th';
                th.textContent = h;
                hrow.appendChild(th);
            }

            const tbody = table.createTBody();
            csvHeaders.forEach((hdr, index) => {
                const defaultColumnName = hdr.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || ('col_' + index);

                const tr = tbody.insertRow();
                tr.dataset.csvHeader = hdr;

                const tdH = document.createElement('td');
                tdH.className = 'adm-td mono';
                tdH.style.cssText = 'white-space:nowrap;';
                tdH.textContent = hdr;

                const tdS = document.createElement('td');
                tdS.className = 'adm-td';
                tdS.style.cssText = 'color:var(--muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
                const samples = csvPreview.map(r => r[hdr]).filter(v => v !== null && v !== '').slice(0, 3);
                tdS.textContent = samples.length ? samples.join(', ') : '(empty)';
                tdS.title = samples.join(' | ');

                const tdN = document.createElement('td');
                tdN.className = 'adm-td';
                const nameInput = document.createElement('input');
                nameInput.type  = 'text';
                nameInput.value = defaultColumnName;
                nameInput.className = 'col-name-input adm-input w-full mono';
                nameInput.addEventListener('input', () => {
                    nameInput.value = nameInput.value.toLowerCase().replace(/[^a-z0-9_]/g, '_');
                });
                tdN.appendChild(nameInput);

                const tdT = document.createElement('td');
                tdT.className = 'adm-td';
                const typeSelect = document.createElement('select');
                typeSelect.className = 'col-type-select adm-input w-full';
                const guessedType = guessColumnType(csvPreview.map(r => r[hdr]).filter(v => v !== null && v !== ''));
                typeOptions.forEach(({ value, label }) => {
                    const option = appendOption(typeSelect, value, label);
                    if (value === guessedType) option.selected = true;
                });
                tdT.appendChild(typeSelect);

                tr.append(tdH, tdS, tdN, tdT);
            });

            mappingContainer.appendChild(table);
            return;
        }

        if (!selectedTable) return;

        const note = document.createElement('p');
        note.style.cssText = 'color:var(--muted);margin:0 0 12px;';
        note.textContent   = `Map ${csvHeaders.length} CSV column${csvHeaders.length !== 1 ? 's' : ''} to "${escHtml(selectedTable)}" columns. Leave "— Skip —" to ignore a CSV column.`;
        mappingContainer.appendChild(note);

        const table   = document.createElement('table');
        table.className = 'adm-tbl';

        const thead = table.createTHead();
        const hrow  = thead.insertRow();
        for (const h of ['CSV Header', 'Sample values', 'Target column']) {
            const th = document.createElement('th');
            th.className = 'adm-th';
            th.textContent = h;
            hrow.appendChild(th);
        }

        const tbody   = table.createTBody();
        const dbColumns  = Object.keys(tableColumns).filter(c => (tableColumns[c]?.type ?? '') !== 'virtual');

        csvHeaders.forEach((hdr) => {
            const tr = tbody.insertRow();

            const tdH = document.createElement('td');
            tdH.className = 'adm-td mono';
            tdH.style.cssText = 'white-space:nowrap;';
            tdH.textContent = hdr;

            const tdS = document.createElement('td');
            tdS.className = 'adm-td';
            tdS.style.cssText = 'color:var(--muted);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
            const samples = csvPreview.map(r => r[hdr]).filter(v => v !== null && v !== '').slice(0, 3);
            tdS.textContent = samples.length ? samples.join(', ') : '(empty)';
            tdS.title       = samples.join(' | ');

            const tdC  = document.createElement('td');
            tdC.className = 'adm-td';
            const sel  = document.createElement('select');
            sel.dataset.header = hdr;
            sel.className = 'adm-input w-full';
            appendOption(sel, '', '— Skip —');
            dbColumns.forEach(column => {
                const config = tableColumns[column] || {};
                const option = appendOption(sel, column, (config.display_name || column) + ' (' + (config.type || 'text') + ')');
                if (column.toLowerCase() === hdr.toLowerCase()) option.selected = true;
            });
            tdC.appendChild(sel);

            tr.append(tdH, tdS, tdC);
        });

        mappingContainer.appendChild(table);
        rebuildConflictOptions();
        validateConflict();
    }

    function rebuildConflictOptions() {
        const previous = conflictSelect.value;
        while (conflictSelect.options.length > 1) conflictSelect.remove(1);
        const dbColumns = Object.keys(tableColumns).filter(c => (tableColumns[c]?.type ?? '') !== 'virtual');
        dbColumns.forEach(column => appendOption(conflictSelect, column, tableColumns[column]?.display_name || column));
        if (previous) {
            for (let i = 0; i < conflictSelect.options.length; i++) {
                if (conflictSelect.options[i].value === previous) { conflictSelect.selectedIndex = i; break; }
            }
        }
        validateConflict();
    }

    function validateConflict() {
        const column = conflictSelect.value;
        if (!column) { conflictWarn.style.display = 'none'; return; }
        const mapped = Array.from(mappingContainer.querySelectorAll('select[data-header]'))
            .some(s => s.value === column);
        if (!mapped) {
            conflictWarn.textContent = `⚠ Column "${column}" is not mapped above. Map a CSV header to "${column}" or set conflict handling to "None".`;
            conflictWarn.style.display = 'block';
        } else {
            conflictWarn.style.display = 'none';
        }
    }

    function getMapping() {
        const m = {};
        mappingContainer.querySelectorAll('select[data-header]').forEach(s => {
            m[s.dataset.header] = s.value || null;
        });
        return m;
    }

    async function executeImport() {
        if (!csvTemporaryName || !selectedTable) {
            showBanner(execStatus, 'Upload a CSV file and select a target table first.', 'error');
            return;
        }
        const mapping = getMapping();
        if (!Object.values(mapping).some(v => v !== null && v !== '')) {
            showBanner(execStatus, 'Map at least one CSV column to a database column.', 'error');
            return;
        }

        execButton.disabled    = true;
        execButton.textContent = 'Importing…';
        execStatus.innerHTML = '';
        resultArea.innerHTML = '';

        try {
            const result = await apiFetch('api_csv_import.php?action=csv_import_execute', {
                method: 'POST',
                body: JSON.stringify({
                    tmp_name:        csvTemporaryName,
                    table:           selectedTable,
                    mapping,
                    conflict_column: copyModeCheckbox.checked ? null : (conflictSelect.value || null),
                    copy_mode:       copyModeCheckbox.checked,
                    original_name:   csvOriginalName,
                    delimiter:       csvDelimiter,
                    encoding:        csvEncoding,
                }),
            });
            const data = await result.json();
            if (data.status !== 'success') throw new Error(data.error || 'Import failed');

            renderResult(data);
            resetUploadZone();
            loadHistory();
        } catch (e) {
            showBanner(execStatus, 'Import error: ' + escHtml(e.message), 'error');
        } finally {
            execButton.disabled    = false;
            execButton.textContent = 'Execute Import';
        }
    }

    async function createTableAndImport() {
        if (!newTableName) {
            showBanner(execStatus, 'Enter a table name before importing.', 'error');
            return;
        }
        if (!csvFile && !csvTemporaryName) {
            showBanner(execStatus, 'Select a CSV file first.', 'error');
            return;
        }

        const columnDefs = [];
        const mapping = {};
        mappingContainer.querySelectorAll('tr[data-csv-header]').forEach(tr => {
            const csvHdr = tr.dataset.csvHeader;
            const dbName = (tr.querySelector('.col-name-input')?.value || '').replace(/[^a-z0-9_]/g, '').replace(/^_|_$/g, '');
            const columnType = tr.querySelector('.col-type-select')?.value || 'varchar(255)';
            if (dbName) {
                columnDefs.push({ name: dbName, type: columnType });
                mapping[csvHdr] = dbName;
            }
        });

        if (!columnDefs.length) {
            showBanner(execStatus, 'Define at least one column.', 'error');
            return;
        }

        execButton.disabled     = true;
        execStatus.innerHTML = '';
        resultArea.innerHTML = '';

        try {
            if (!csvTemporaryName && csvFile) {
                execButton.textContent = 'Uploading CSV…';
                const formData = new FormData();
                formData.append('csv_file', csvFile);
                formData.append('csv_delimiter', csvDelimiter);
                formData.append('csv_encoding', csvEncoding);
                const upResult  = await apiFetch('api_csv_import.php?action=csv_import_upload', {
                    method: 'POST',
                    body: formData,
                });
                const upData = await upResult.json();
                if (upData.status !== 'success') throw new Error(upData.error || 'Upload failed.');
                csvTemporaryName  = upData.tmp_name;
                csvRowCount = upData.row_count;
            }

            execButton.textContent = 'Creating table…';
            const ctResult  = await apiFetch('api_csv_import.php?action=csv_create_table', {
                method: 'POST',
                body: JSON.stringify({
                    table:        newTableName,
                    schema:       newTableSchema,
                    display_name: newTableDisplayName || '',
                    columns:      columnDefs,
                }),
            });
            const ctData = await ctResult.json();
            if (ctData.status !== 'success') throw new Error(ctData.error || 'Failed to create table.');

            execButton.textContent = 'Importing…';
            const impResult  = await apiFetch('api_csv_import.php?action=csv_import_execute', {
                method: 'POST',
                body: JSON.stringify({
                    tmp_name:        csvTemporaryName,
                    table:           newTableName,
                    mapping,
                    conflict_column: null,
                    copy_mode:       copyModeCheckbox.checked,
                    original_name:   csvOriginalName,
                    delimiter:       csvDelimiter,
                    encoding:        csvEncoding,
                }),
            });
            const impData = await impResult.json();
            if (impData.status !== 'success') throw new Error(impData.error || 'Import failed.');

            renderResult(impData);
            resetUploadZone();
            loadHistory();
        } catch (e) {
            showBanner(execStatus, 'Error: ' + escHtml(e.message), 'error');
        } finally {
            execButton.disabled    = false;
            execButton.textContent = 'Create Table & Import';
        }
    }

    function renderResult(data) {
        const ok  = data.skipped_rows === 0;
        const background  = ok ? 'var(--ok-light)' : 'var(--warn-light)';
        const bdr = ok ? 'var(--ok)'              : 'var(--warn)';

        const resultElement = document.createElement('div');
        resultElement.style.cssText = `padding:18px 20px;border-radius:8px;background:${background};border:1px solid ${bdr};margin-bottom:8px;`;

        const title = document.createElement('div');
        title.style.cssText = `font-weight:700;margin-bottom:8px;color:${ok ? 'var(--ok)' : 'var(--muted)'};`;
        title.textContent = ok
            ? `✓ Import complete`
            : `⚠ Import finished with issues`;

        const statistics = document.createElement('div');
        statistics.style.cssText = 'display:flex;gap:20px;flex-wrap:wrap;margin-bottom:4px;';

        const statistic = (label, value, accent) => {
            const s = document.createElement('span');
            s.style.cssText = `color:${accent ? 'var(--ok)' : 'var(--muted)'};`;
            const strong = document.createElement('strong');
            strong.textContent = value;
            s.append(strong, ' ' + label);
            return s;
        };

        statistics.append(
            statistic('rows imported', data.imported_rows.toLocaleString(), ok),
            statistic('skipped',       data.skipped_rows.toLocaleString(),  false),
        );

        if (typeof data.elapsed_seconds === 'number') {
            const secs = data.elapsed_seconds;
            const dur  = secs < 60 ? secs.toFixed(1) + ' s' : Math.floor(secs / 60) + ' m ' + (secs % 60).toFixed(0) + ' s';
            statistics.append(statistic('duration', dur, false));
        }

        resultElement.append(title, statistics);

        if (data.has_errors && data.import_id) {
            const logLink = document.createElement('a');
            logLink.href  = '#';
            logLink.style.cssText = 'display:inline-block;margin-top:8px;color:var(--muted);';
            logLink.textContent = 'View skipped row details ↓';
            logLink.addEventListener('click', async (e) => {
                e.preventDefault();
                logLink.remove();
                await appendRowLog(data.import_id, resultElement);
            });
            resultElement.appendChild(logLink);
        }

        resultArea.innerHTML = '';
        resultArea.appendChild(resultElement);
    }

    async function appendRowLog(importId, container) {
        try {
            const result  = await apiFetch(`api_csv_import.php?action=csv_import_log&id=${importId}`);
            const data = await result.json();
            if (data.status !== 'success' || !data.rows.length) {
                const note = document.createElement('p');
                note.style.cssText = 'color:var(--muted);margin-top:8px;';
                note.textContent = 'No row-level errors logged.';
                container.appendChild(note);
                return;
            }
            container.appendChild(buildRowLogTable(data.rows));
        } catch (_) {  }
    }

    function buildRowLogTable(rows) {
        const wrapElement = document.createElement('div');
        wrapElement.style.cssText = 'margin-top:12px;max-height:320px;overflow-y:auto;border:1px solid var(--border);border-radius:4px;';

        const table = document.createElement('table');
        table.className = 'adm-tbl';

        const thead = table.createTHead();
        const hrow  = thead.insertRow();
        for (const h of ['Row #', 'Error', 'Raw data (JSON)']) {
            const th = document.createElement('th');
            th.className = 'adm-th adm-th-sm';
            th.textContent = h;
            hrow.appendChild(th);
        }

        const tbody = table.createTBody();
        rows.forEach((row) => {
            const tr = tbody.insertRow();

            const tdN = td(String(row.row_number), 'white-space:nowrap;');
            const tdE = td(row.error_message || '', 'color:var(--error);');
            const tdR = td(row.raw_data || '', 'font-family:var(--font-mono);max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;');
            tdN.className = 'adm-td adm-td-sm';
            tdE.className = 'adm-td adm-td-sm';
            tdR.className = 'adm-td adm-td-sm';
            tdR.title = row.raw_data || '';

            tr.append(tdN, tdE, tdR);
        });
        wrapElement.appendChild(table);
        return wrapElement;
    }

    async function loadHistory() {
        histContainer.innerHTML = '<p style="color:var(--muted);padding:4px 0;">Loading…</p>';
        try {
            const result  = await apiFetch('api_csv_import.php?action=csv_import_history');
            const data = await result.json();

            if (data.status !== 'success' || !data.imports.length) {
                histContainer.innerHTML = '<p style="color:var(--muted);">No imports yet.</p>';
                return;
            }

            const table = document.createElement('table');
            table.className = 'adm-tbl';

            const thead = table.createTHead();
            const hrow  = thead.insertRow();
            for (const h of ['#', 'File', 'Table', 'Status', 'Imported', 'Skipped', 'By', 'Started', 'Duration']) {
                const th = document.createElement('th');
                th.className = 'adm-th';
                th.textContent = h;
                hrow.appendChild(th);
            }

            const tbody = table.createTBody();
            const clsMap = { done: 'ok', failed: 'danger', running: 'warn' };
            data.imports.forEach((row) => {
                const tr = tbody.insertRow();

                for (const [value, style] of [
                    [row.id,                        'white-space:nowrap;'],
                    [row.filename,                  'max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'],
                    [row.target_table,              ''],
                    [null,                          ''],
                    [row.imported_rows ?? 0,        'text-align:right;'],
                    [row.skipped_rows  ?? 0,        'text-align:right;'],
                    [row.username || '—',           ''],
                    [(row.started_at || '').slice(0, 16), 'white-space:nowrap;'],
                    [formatDuration(row.started_at, row.finished_at), 'white-space:nowrap;text-align:right;color:var(--muted);'],
                ]) {
                    const cell = document.createElement('td');
                    cell.className = 'adm-td';
                    if (style) cell.style.cssText = style;
                    if (value === null) {
                        const badge = document.createElement('span');
                        badge.className = 'adm-badge adm-badge-' + (clsMap[row.status] || 'muted');
                        badge.textContent = row.status;
                        cell.appendChild(badge);
                    } else {
                        cell.textContent = String(value);
                    }
                    tr.appendChild(cell);
                }

                const tdAct = document.createElement('td');
                tdAct.className = 'adm-td';
                if ((row.skipped_rows ?? 0) > 0) {
                    const logButton = document.createElement('button');
                    logButton.type      = 'button';
                    logButton.textContent = 'Log';
                    logButton.className = 'btn btn-secondary btn-xs';
                    logButton.addEventListener('click', async () => {
                        const existing = tr.nextElementSibling;
                        if (existing && existing.dataset.logForId === String(row.id)) {
                            existing.remove();
                            return;
                        }
                        const logTr = document.createElement('tr');
                        logTr.dataset.logForId = String(row.id);
                        const logTd = document.createElement('td');
                        logTd.colSpan = 10;
                        logTd.style.cssText = 'padding:0;background:#fff;';
                        logTr.appendChild(logTd);
                        tr.insertAdjacentElement('afterend', logTr);
                        await appendRowLog(row.id, logTd);
                    });
                    tdAct.appendChild(logButton);
                }
                tr.appendChild(tdAct);
            });

            histContainer.innerHTML = '';
            histContainer.appendChild(table);
        } catch (_) {
            histContainer.innerHTML = '<p style="color:var(--error);">Failed to load history.</p>';
        }
    }

    function resetDropZone() {
        dropZone.style.borderColor = 'var(--accent-mid)';
        dropZone.style.background  = '#fff';
    }

    function resetUploadZone() {
        csvFile     = null;
        csvTemporaryName  = '';
        csvOriginalName = '';
        csvHeaders  = [];
        csvPreview  = [];
        uploadMessage.textContent  = 'Click to select a CSV file or drag & drop here';
        uploadMessage.style.color  = 'var(--muted)';
        uploadHint.textContent = '.csv only · max 500 MB';
        resetDropZone();
        card2.el.style.display = 'none';
        mappingContainer.innerHTML = '';
        execStatus.innerHTML = '';
    }

    function flashMessage(element, text, color) {
        const original  = element.textContent;
        const originalC = element.style.color;
        element.textContent = text;
        element.style.color = color;
        setTimeout(() => { element.textContent = original; element.style.color = originalC; }, 2200);
    }

    function showBanner(container, message, type) {
        const colors = {
            success: { bg: 'var(--ok-light)', fg: 'var(--ok)', border: 'var(--ok)' },
            error:   { bg: 'var(--error-light)', fg: 'var(--error)', border: 'var(--error)' },
        }[type] ?? { bg: 'var(--accent-mid)', fg: 'var(--text)', border: 'var(--accent-mid)' };
        const div = document.createElement('div');
        div.style.cssText = `padding:10px 14px;border-radius:6px;background:${colors.bg};color:${colors.fg};border:1px solid ${colors.border};`;
        div.textContent = message;
        container.innerHTML = '';
        container.appendChild(div);
    }
}

function loadCSVPreviewLocal(file, delimiter = ',', encoding = 'UTF-8') {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = (e) => {
            try {
                let text = e.target.result;
                if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1);
                const lines = text.split(/\r?\n/);
                const rawHeaders = parseCsvLine(lines[0] || '', delimiter);
                const headers = rawHeaders.map(h => h.trim()).filter(h => h !== '');
                if (!headers.length) throw new Error('No headers found in CSV.');
                const preview = [];
                for (let i = 1; i <= 5 && i < lines.length; i++) {
                    if (!lines[i].trim()) continue;
                    const values = parseCsvLine(lines[i], delimiter);
                    const row = {};
                    headers.forEach((h, j) => { row[h] = values[j] ?? ''; });
                    preview.push(row);
                }
                resolve({ headers, preview });
            } catch (error) {
                reject(error);
            }
        };
        reader.onerror = () => reject(new Error('Failed to read file.'));
        reader.readAsText(file.slice(0, 131072), encoding);
    });
}

function formatDuration(startedAt, finishedAt) {
    if (!startedAt || !finishedAt) return '—';
    const secs = Math.round((new Date(finishedAt) - new Date(startedAt)) / 1000);
    if (isNaN(secs) || secs < 0) return '—';
    if (secs < 60) return secs + 's';
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return s > 0 ? `${m}m ${s}s` : `${m}m`;
}

function parseCsvLine(line, delimiter = ',') {
    const fields = [];
    let current = '';
    let inQ = false;
    for (let i = 0; i < line.length; i++) {
        const c = line[i];
        if (inQ) {
            if (c === '"') {
                if (line[i + 1] === '"') { current += '"'; i++; }
                else inQ = false;
            } else {
                current += c;
            }
        } else if (c === '"') {
            inQ = true;
        } else if (line.startsWith(delimiter, i)) {
            fields.push(current); current = '';
            i += delimiter.length - 1;
        } else {
            current += c;
        }
    }
    fields.push(current);
    return fields;
}

function guessColumnType(samples) {
    const nonEmpty = samples.filter(v => v !== null && v !== '');
    if (!nonEmpty.length) return 'varchar(255)';

    if (nonEmpty.some(v => v.length > 200 || ((v[0] === '{' || v[0] === '[') && v.length > 5))) return 'text';

    if (nonEmpty.every(v => /^\d{4}-\d{2}-\d{2}$/.test(v))) return 'date';
    if (nonEmpty.every(v => /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/.test(v))) return 'timestamp';

    return 'varchar(255)';
}

function buildCsvTabs(wrap, tabs) {
    const bar = document.createElement('div');
    bar.className = 'item-panel-items';

    const panels = {};
    const btns   = {};

    tabs.forEach(({ id, label, icon }) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.tab = id;
        button.className = 'item-btn';
        if (icon) {
            const image = document.createElement('img');
            image.src = '../assets/icons/' + icon;
            image.style.cssText = 'width:15px;height:15px;opacity:.6;';
            button.appendChild(image);
        }
        button.appendChild(document.createTextNode(label));
        bar.appendChild(button);
        btns[id] = button;

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

    tabs.forEach(({ id }) => {
        btns[id].addEventListener('click', () => activate(id));
    });

    activate(tabs[0].id);

    return { panels, activate };
}

function buildCard(title) {
    const element = document.createElement('div');
    element.className = 'adm-sec-card';

    const hdr = document.createElement('div');
    hdr.className = 'adm-sec-hdr';
    hdr.style.display = 'block';
    const h = document.createElement('h3');
    h.style.margin = '0';
    h.textContent = title;
    hdr.appendChild(h);
    element.appendChild(hdr);

    const body = document.createElement('div');
    body.className = 'adm-sec-body';
    element.appendChild(body);

    return { el: element, body };
}

function buildRow() {
    const div = document.createElement('div');
    div.style.cssText = 'display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px;';
    return div;
}

function buildLabel(text) {
    const label = document.createElement('label');
    label.style.cssText = 'font-weight:600;color:var(--muted);';
    label.textContent = text;
    return label;
}

function appendOption(select, value, label) {
    const option = document.createElement('option');
    option.value       = value;
    option.textContent = label;
    select.appendChild(option);
    return option;
}

function td(text, style) {
    const element = document.createElement('td');
    element.className = 'adm-td';
    if (style) element.style.cssText = style;
    element.textContent = text;
    return element;
}

import { escHtml } from '../../assets/js/util/esc.js';
