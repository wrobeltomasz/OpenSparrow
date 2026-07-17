// admin/js/csv_import.js — CSV import UI (delimiter/encoding/copy-mode persisted in localStorage)
// Upload + preview, then execute import or create-table via admin/api_csv_import.php (csv_import_*, csv_create_table).

import { apiFetch } from '../../assets/js/util/api.js';

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

export async function renderCsvImportPage(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '';
    workspaceEl._csvImportGen = (workspaceEl._csvImportGen || 0) + 1;
    const myGen = workspaceEl._csvImportGen;


    // ── Module state ──────────────────────────────────────────────────────────
    let csvHeaders          = [];
    let csvPreview          = [];
    let csvRowCount         = 0;
    let csvTmpName          = '';
    let csvOrigName         = '';
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

    // ── Root ──────────────────────────────────────────────────────────────────
    const wrap = document.createElement('div');
    wrap.style.cssText = 'max-width:960px;padding-bottom:60px;';

    const heading = document.createElement('h2');
    heading.style.marginTop = '0';
    heading.textContent = 'CSV Import';
    wrap.appendChild(heading);

    // ── Tabs ──────────────────────────────────────────────────────────────────
    const { panels, activate } = buildCsvTabs(wrap, [
        { id: 'import',  label: 'Import',         icon: 'upload.png' },
        { id: 'config',  label: 'Configuration',  icon: 'car_gear.png' },
        { id: 'history', label: 'Import History', icon: 'manage_history.png' },
    ]);

    const importPanel  = panels['import'];
    const configPanel  = panels['config'];
    const historyPanel = panels['history'];

    // ── TAB 1: Import ─────────────────────────────────────────────────────────

    const desc = document.createElement('p');
    desc.style.cssText = 'color:#64748B;margin:0 0 20px;font-size:14px;';
    desc.textContent = 'Import rows from a CSV file into an existing table, or create a new table directly from CSV headers.';
    importPanel.appendChild(desc);

    // Step 1 card
    const card1 = buildCard('Step 1 — Select Table & Upload CSV');
    importPanel.appendChild(card1.el);

    // Table selector row
    const tableRow = buildRow();
    const tableLabel = buildLabel('Target table:');
    tableLabel.style.minWidth = '110px';

    const tableSelect = document.createElement('select');
    tableSelect.style.cssText = 'padding:7px 10px;border:1px solid #CBD5E1;border-radius:4px;font-size:13px;min-width:220px;';
    appendOpt(tableSelect, '', '— Select table —');

    try {
        const res  = await apiFetch('api.php?action=get&file=schema');
        const data = await res.json();
        if (data.tables) {
            for (const [name, cfg] of Object.entries(data.tables)) {
                const opt = appendOpt(tableSelect, name, cfg.display_name || name);
                opt.dataset.cols = JSON.stringify(cfg.columns || {});
            }
        }
    } catch (_) { /* schema unavailable */ }

    tableRow.append(tableLabel, tableSelect);
    card1.el.appendChild(tableRow);

    // "Create new table" checkbox
    const createToggleRow = buildRow();
    const createChk = document.createElement('input');
    createChk.type  = 'checkbox';
    createChk.id    = 'csv-create-table-chk';
    createChk.style.cssText = 'width:16px;height:16px;cursor:pointer;flex-shrink:0;';
    const createChkLabel = buildLabel('Create new table from CSV');
    createChkLabel.htmlFor = 'csv-create-table-chk';
    createChkLabel.style.cursor = 'pointer';
    createToggleRow.append(createChk, createChkLabel);
    card1.el.appendChild(createToggleRow);

    // Delimiter selector
    const delimRow = buildRow();
    const delimLabel = buildLabel('Delimiter:');
    delimLabel.style.minWidth = '80px';
    const delimSelect = document.createElement('select');
    delimSelect.style.cssText = 'padding:7px 10px;border:1px solid #CBD5E1;border-radius:4px;font-size:13px;';
    DELIMITERS.forEach(({ value, label }) => {
        const opt = appendOpt(delimSelect, value, label);
        if (value === csvDelimiter) opt.selected = true;
    });
    delimRow.append(delimLabel, delimSelect);
    card1.el.appendChild(delimRow);

    // Encoding selector
    const encRow = buildRow();
    const encLabel = buildLabel('Encoding:');
    encLabel.style.minWidth = '80px';
    const encSelect = document.createElement('select');
    encSelect.style.cssText = 'padding:7px 10px;border:1px solid #CBD5E1;border-radius:4px;font-size:13px;min-width:260px;';
    ENCODINGS.forEach(({ value, label }) => {
        const opt = appendOpt(encSelect, value, label);
        if (value === csvEncoding) opt.selected = true;
    });
    encRow.append(encLabel, encSelect);
    card1.el.appendChild(encRow);

    // New-table form — stacked grid: label column | input column
    const newTableForm = document.createElement('div');
    newTableForm.style.cssText = 'display:none;grid-template-columns:140px 1fr;gap:10px 16px;align-items:center;margin-bottom:16px;max-width:560px;';

    const schemaLabel = buildLabel('Schema:');

    const schemaSelect = document.createElement('select');
    schemaSelect.style.cssText = 'padding:7px 10px;border:1px solid #CBD5E1;border-radius:4px;font-size:13px;width:100%;';
    appendOpt(schemaSelect, 'public', 'public');
    schemaSelect.dataset.loaded = '0';

    const nameLabel = buildLabel('Table name (DB):');
    const nameInput = document.createElement('input');
    nameInput.type        = 'text';
    nameInput.placeholder = 'e.g. my_customers';
    nameInput.style.cssText = 'padding:7px 10px;border:1px solid #CBD5E1;border-radius:4px;font-size:13px;width:100%;box-sizing:border-box;';

    const dispLabel = buildLabel('Display name:');
    const dispInput = document.createElement('input');
    dispInput.type        = 'text';
    dispInput.placeholder = 'e.g. My Customers (optional)';
    dispInput.style.cssText = 'padding:7px 10px;border:1px solid #CBD5E1;border-radius:4px;font-size:13px;width:100%;box-sizing:border-box;';

    newTableForm.append(schemaLabel, schemaSelect, nameLabel, nameInput, dispLabel, dispInput);
    card1.el.appendChild(newTableForm);

    // Upload drop zone
    const dropZone = document.createElement('div');
    dropZone.style.cssText = 'border:2px dashed var(--border,#CBD5E1);border-radius:8px;padding:32px 20px;text-align:center;background:#fff;cursor:pointer;transition:border-color .2s,background .2s;margin-top:16px;';

    const uploadIcon = document.createElement('img');
    uploadIcon.src = '../assets/icons/upload.png';
    uploadIcon.alt = '';
    uploadIcon.style.cssText = 'width:36px;height:36px;margin-bottom:8px;pointer-events:none;opacity:0.5;';

    const uploadMsg = document.createElement('div');
    uploadMsg.style.cssText = 'font-size:14px;color:var(--muted,#64748B);margin-bottom:4px;pointer-events:none;';
    uploadMsg.textContent = 'Click to select a CSV file or drag & drop here';

    const uploadHint = document.createElement('div');
    uploadHint.style.cssText = 'font-size:12px;color:var(--muted,#64748B);pointer-events:none;';
    uploadHint.textContent = '.csv only · max 500 MB';

    const fileInput = document.createElement('input');
    fileInput.type   = 'file';
    fileInput.accept = '.csv,text/csv';
    fileInput.style.display = 'none';

    dropZone.append(uploadIcon, uploadMsg, uploadHint, fileInput);
    card1.el.appendChild(dropZone);

    // Step 2 card
    const card2 = buildCard('Step 2 — Map Columns & Execute');
    card2.el.style.display = 'none';
    importPanel.appendChild(card2.el);

    // Persistent result banner — lives outside card2 so resetUploadZone() doesn't erase it
    const resultArea = document.createElement('div');
    importPanel.appendChild(resultArea);

    const mappingContainer = document.createElement('div');
    card2.el.appendChild(mappingContainer);

    // Conflict column row
    const conflictRow = buildRow();
    conflictRow.style.cssText = 'display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:20px;';

    const conflictLabel = buildLabel('Upsert on conflict:');
    conflictLabel.style.minWidth = '140px';

    const conflictSelect = document.createElement('select');
    conflictSelect.style.cssText = 'padding:7px 10px;border:1px solid #CBD5E1;border-radius:4px;font-size:13px;min-width:200px;';
    appendOpt(conflictSelect, '', '— None (insert only) —');

    const conflictNote = document.createElement('span');
    conflictNote.style.cssText = 'font-size:12px;color:#64748B;';
    conflictNote.textContent = 'Matching rows will be updated instead of rejected (requires unique constraint).';

    const conflictWarn = document.createElement('div');
    conflictWarn.style.cssText = 'display:none;margin-top:8px;padding:8px 12px;background:rgba(255,195,0,0.12);border:1px solid #ffc300;border-radius:4px;font-size:12px;color:#64748B;';

    conflictRow.append(conflictLabel, conflictSelect, conflictNote);
    card2.el.appendChild(conflictRow);
    card2.el.appendChild(conflictWarn);

    // Import mode indicator (reads default from config tab / localStorage)
    const modeRow = buildRow();
    modeRow.style.cssText = 'margin-top:16px;margin-bottom:0;gap:10px;align-items:center;';
    const copyModeChk = document.createElement('input');
    copyModeChk.type = 'checkbox';
    copyModeChk.id   = 'csv-copy-mode-chk';
    copyModeChk.style.cssText = 'width:16px;height:16px;cursor:pointer;flex-shrink:0;';
    copyModeChk.checked = localStorage.getItem(LS_COPY_MODE) === '1';
    const copyModeLabel = document.createElement('label');
    copyModeLabel.htmlFor = 'csv-copy-mode-chk';
    copyModeLabel.style.cssText = 'font-size:13px;color:#64748B;cursor:pointer;';
    copyModeLabel.textContent = 'Fast COPY mode';
    modeRow.append(copyModeChk, copyModeLabel);
    card2.el.appendChild(modeRow);

    const execBtn = document.createElement('button');
    execBtn.type      = 'button';
    execBtn.textContent = 'Execute Import';
    execBtn.className = 'btn btn-primary';
    execBtn.style.marginTop = '20px';
    card2.el.appendChild(execBtn);

    const execStatus = document.createElement('div');
    execStatus.style.marginTop = '14px';
    card2.el.appendChild(execStatus);

    if (workspaceEl._csvImportGen !== myGen) return;
    workspaceEl.appendChild(wrap);

    // ── TAB 2: Configuration ──────────────────────────────────────────────────

    const cfgHeading = document.createElement('h3');
    cfgHeading.style.cssText = 'margin:0 0 20px;font-size:15px;';
    cfgHeading.textContent = 'Import Settings';
    configPanel.appendChild(cfgHeading);

    // Default import mode
    const modeCard = buildCard('Default Import Mode');
    configPanel.appendChild(modeCard.el);

    const modeDesc = document.createElement('p');
    modeDesc.style.cssText = 'font-size:13px;color:#64748B;margin:0 0 16px;';
    modeDesc.textContent = 'Choose the default mode used when running imports. You can override this per-import in Step 2.';
    modeCard.el.appendChild(modeDesc);

    const savedCopy = localStorage.getItem(LS_COPY_MODE) === '1';

    function buildModeOption(id, value, labelText, descText) {
        const row = document.createElement('label');
        row.style.cssText = 'display:flex;gap:12px;align-items:flex-start;cursor:pointer;margin-bottom:14px;';
        const radio = document.createElement('input');
        radio.type    = 'radio';
        radio.name    = 'csv-default-mode';
        radio.id      = id;
        radio.value   = value;
        radio.checked = value === 'copy' ? savedCopy : !savedCopy;
        radio.style.cssText = 'margin-top:3px;flex-shrink:0;width:15px;height:15px;cursor:pointer;';
        const txt = document.createElement('div');
        const strong = document.createElement('strong');
        strong.style.cssText = 'font-size:13px;display:block;margin-bottom:2px;';
        strong.textContent = labelText;
        const small = document.createElement('span');
        small.style.cssText = 'font-size:12px;color:#64748B;';
        small.textContent = descText;
        txt.append(strong, small);
        row.append(radio, txt);
        modeCard.el.appendChild(row);
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
        copyModeChk.checked = isCopy;
        conflictRow.style.display  = isCopy ? 'none' : '';
        conflictWarn.style.display = 'none';
    }

    radioNormal.addEventListener('change', syncModeRadios);
    radioCopy.addEventListener('change', syncModeRadios);

    // Apply initial state from saved default
    if (savedCopy) {
        conflictRow.style.display = 'none';
    }

    // Default delimiter section
    const delimCard = buildCard('Default Delimiter');
    configPanel.appendChild(delimCard.el);

    const delimDesc = document.createElement('p');
    delimDesc.style.cssText = 'font-size:13px;color:#64748B;margin:0 0 14px;';
    delimDesc.textContent = 'Column separator used when parsing CSV files. Override per-import in Step 1.';
    delimCard.el.appendChild(delimDesc);

    const cfgDelimSelect = document.createElement('select');
    cfgDelimSelect.style.cssText = 'padding:7px 10px;border:1px solid #CBD5E1;border-radius:4px;font-size:13px;';
    DELIMITERS.forEach(({ value, label }) => {
        const opt = appendOpt(cfgDelimSelect, value, label);
        if (value === csvDelimiter) opt.selected = true;
    });
    delimCard.el.appendChild(cfgDelimSelect);

    cfgDelimSelect.addEventListener('change', () => {
        csvDelimiter = cfgDelimSelect.value;
        localStorage.setItem(LS_DELIMITER, csvDelimiter);
        for (const opt of delimSelect.options) {
            if (opt.value === csvDelimiter) { opt.selected = true; break; }
        }
    });

    // Default encoding section
    const encCard = buildCard('Default Encoding');
    configPanel.appendChild(encCard.el);

    const encDesc = document.createElement('p');
    encDesc.style.cssText = 'font-size:13px;color:#64748B;margin:0 0 14px;';
    encDesc.textContent = 'Character encoding of the source CSV file. Override per-import in Step 1. Files are converted to UTF-8 before inserting into PostgreSQL.';
    encCard.el.appendChild(encDesc);

    const cfgEncSelect = document.createElement('select');
    cfgEncSelect.style.cssText = 'padding:7px 10px;border:1px solid #CBD5E1;border-radius:4px;font-size:13px;min-width:260px;';
    ENCODINGS.forEach(({ value, label }) => {
        const opt = appendOpt(cfgEncSelect, value, label);
        if (value === csvEncoding) opt.selected = true;
    });
    encCard.el.appendChild(cfgEncSelect);

    cfgEncSelect.addEventListener('change', () => {
        csvEncoding = cfgEncSelect.value;
        localStorage.setItem(LS_ENCODING, csvEncoding);
        for (const opt of encSelect.options) {
            if (opt.value === csvEncoding) { opt.selected = true; break; }
        }
    });

    // Server limits section
    const limitsCard = buildCard('Server Limits');
    configPanel.appendChild(limitsCard.el);

    const limitsNote = document.createElement('p');
    limitsNote.style.cssText = 'font-size:13px;color:#64748B;margin:0 0 14px;';
    limitsNote.textContent = 'Current server configuration. To change these values, edit docker-php-dev.ini and nginx.conf, then restart the container.';
    limitsCard.el.appendChild(limitsNote);

    const limitsGrid = document.createElement('div');
    limitsGrid.style.cssText = 'display:grid;grid-template-columns:max-content 1fr;gap:6px 20px;font-size:13px;';
    limitsCard.el.appendChild(limitsGrid);

    function addLimitRow(label, value, note) {
        const lbl = document.createElement('span');
        lbl.style.cssText = 'color:#64748B;font-weight:600;white-space:nowrap;';
        lbl.textContent = label;
        const val = document.createElement('span');
        val.style.cssText = 'font-family:monospace;color:#1E293B;';
        val.textContent = value + (note ? ' — ' + note : '');
        limitsGrid.append(lbl, val);
    }

    // Fetch live limits from server
    try {
        const cfgRes  = await apiFetch('api_csv_import.php?action=csv_import_config');
        const cfgData = await cfgRes.json();
        if (cfgData.status === 'success') {
            addLimitRow('Max upload size', cfgData.max_upload_mb + ' MB', 'CSV_MAX_BYTES in api_csv_import.php');
            addLimitRow('Max execution time', cfgData.max_execution_sec + 's', 'max_execution_time in docker-php-dev.ini');
            addLimitRow('PHP memory limit', cfgData.memory_limit, 'memory_limit in docker-php-dev.ini');
            addLimitRow('Batch size (normal mode)', cfgData.batch_size + ' rows/INSERT', 'CSV_BATCH_SIZE in api_csv_import.php');
        }
    } catch (_) {
        const err = document.createElement('p');
        err.style.cssText = 'font-size:13px;color:#d00000;';
        err.textContent = 'Could not load server limits.';
        limitsCard.el.appendChild(err);
    }

    // ── TAB 3: History ────────────────────────────────────────────────────────

    const histTitle = document.createElement('h3');
    histTitle.style.cssText = 'font-size:15px;margin:0 0 12px;';
    histTitle.textContent = 'Import History';

    const histContainer = document.createElement('div');
    historyPanel.append(histTitle, histContainer);

    // ── Event wiring ──────────────────────────────────────────────────────────

    tableSelect.addEventListener('change', () => {
        selectedTable = tableSelect.value;
        const opt = tableSelect.options[tableSelect.selectedIndex];
        try { tableColumns = opt.dataset.cols ? JSON.parse(opt.dataset.cols) : {}; }
        catch (_) { tableColumns = {}; }
        rebuildConflictOptions();
        if (csvHeaders.length > 0) renderMapping();
    });

    dropZone.addEventListener('click', () => {
        if (createMode && !newTableName) {
            flashMsg(uploadMsg, 'Enter a table name first.', '#d00000');
            return;
        }
        if (!createMode && !selectedTable) {
            flashMsg(uploadMsg, 'Select a target table first.', '#d00000');
            return;
        }
        fileInput.click();
    });

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#64748B';
        dropZone.style.background  = '#DDEAF4';
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
        for (const opt of cfgDelimSelect.options) {
            if (opt.value === csvDelimiter) { opt.selected = true; break; }
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
        for (const opt of cfgEncSelect.options) {
            if (opt.value === csvEncoding) { opt.selected = true; break; }
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

    copyModeChk.addEventListener('change', () => {
        const isCopy = copyModeChk.checked;
        conflictRow.style.display  = isCopy ? 'none' : '';
        conflictWarn.style.display = 'none';
        // Sync config tab radios
        radioNormal.checked = !isCopy;
        radioCopy.checked   = isCopy;
        localStorage.setItem(LS_COPY_MODE, isCopy ? '1' : '0');
    });

    createChk.addEventListener('change', () => {
        createMode = createChk.checked;
        tableRow.style.display     = createMode ? 'none' : '';
        newTableForm.style.display = createMode ? 'grid' : 'none';
        if (createMode) loadSchemas();
        conflictRow.style.display   = createMode ? 'none' : '';
        conflictWarn.style.display  = 'none';
        if (createMode) {
            selectedTable = '';
            execBtn.textContent = 'Create Table & Import';
        } else {
            selectedTable = tableSelect.value;
            newTableName  = '';
            execBtn.textContent = 'Execute Import';
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

    execBtn.addEventListener('click', () => (createMode ? createTableAndImport() : executeImport()));

    // Lazy-load history when tab is activated
    const origActivate = activate;
    const wrappedActivate = (id) => {
        origActivate(id);
        if (id === 'history' && !historyLoaded) {
            historyLoaded = true;
            loadHistory();
        }
    };
    // Patch tab buttons (re-register after building)
    historyPanel.parentElement?.querySelectorAll('button[data-tab]').forEach(btn => {
        if (btn.dataset.tab === 'history') {
            btn.addEventListener('click', () => { if (!historyLoaded) { historyLoaded = true; loadHistory(); } });
        }
    });

    // ── Functions ─────────────────────────────────────────────────────────────

    async function loadSchemas() {
        if (schemaSelect.dataset.loaded === '1') return;
        schemaSelect.dataset.loaded = '1';
        try {
            const res  = await apiFetch('api_csv_import.php?action=csv_schemas');
            const data = await res.json();
            if (data.status === 'success' && Array.isArray(data.schemas) && data.schemas.length) {
                schemaSelect.innerHTML = '';
                data.schemas.forEach(s => appendOpt(schemaSelect, s, s));
                for (const opt of schemaSelect.options) {
                    if (opt.value === 'public') { opt.selected = true; break; }
                }
                newTableSchema = schemaSelect.value;
            }
        } catch (_) { /* keep default */ }
    }

    async function handleUpload(file) {
        if (createMode && !newTableName) {
            flashMsg(uploadMsg, 'Enter a table name first.', '#d00000');
            return;
        }
        if (!createMode && !selectedTable) {
            flashMsg(uploadMsg, 'Select a target table first.', '#d00000');
            return;
        }

        uploadMsg.style.color  = '#64748B';
        uploadHint.textContent = '';

        if (createMode) {
            uploadMsg.textContent = `Reading ${esc(file.name)}…`;
            try {
                const { headers, preview } = await loadCSVPreviewLocal(file, csvDelimiter, csvEncoding);
                csvFile     = file;
                csvHeaders  = headers;
                csvPreview  = preview;
                csvTmpName  = '';
                csvOrigName = file.name;

                uploadMsg.textContent = `✓ ${esc(file.name)} — ${headers.length} column${headers.length !== 1 ? 's' : ''} detected`;
                uploadMsg.style.color = '#2b9348';
                dropZone.style.borderColor = '#64748B';

                renderMapping();
                card2.el.style.display = 'block';
            } catch (e) {
                uploadMsg.textContent = 'Preview failed: ' + esc(e.message);
                uploadMsg.style.color = '#d00000';
                uploadHint.textContent = 'Try again.';
            }
            return;
        }

        uploadMsg.textContent = `Uploading ${esc(file.name)}…`;
        const fd = new FormData();
        fd.append('csv_file', file);
        fd.append('csv_delimiter', csvDelimiter);
        fd.append('csv_encoding', csvEncoding);

        try {
            const res  = await apiFetch('api_csv_import.php?action=csv_import_upload', {
                method: 'POST',
                body: fd,
            });
            const data = await res.json();
            if (data.status !== 'success') throw new Error(data.error || 'Upload failed');

            csvHeaders  = data.headers;
            csvPreview  = data.preview;
            csvRowCount = data.row_count;
            csvTmpName  = data.tmp_name;
            csvOrigName = data.original_name;

            uploadMsg.textContent  = `✓ ${esc(file.name)}  —  ${csvRowCount.toLocaleString()} data rows, ${csvHeaders.length} columns`;
            uploadMsg.style.color  = '#2b9348';
            dropZone.style.borderColor = '#64748B';

            renderMapping();
            card2.el.style.display = 'block';
        } catch (e) {
            uploadMsg.textContent  = 'Upload failed: ' + esc(e.message);
            uploadMsg.style.color  = '#d00000';
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
            note.style.cssText = 'font-size:13px;color:#64748B;margin:0 0 12px;';
            note.textContent = `Rename ${csvHeaders.length} CSV column${csvHeaders.length !== 1 ? 's' : ''} to database column names. The table will be created with an auto-increment id column plus these columns.`;
            mappingContainer.appendChild(note);

            const tbl = document.createElement('table');
            tbl.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;';

            const thead = document.createElement('thead');
            const hrow  = document.createElement('tr');
            for (const h of ['CSV Header', 'Sample values', 'DB column name', 'Type']) {
                const th = document.createElement('th');
                th.style.cssText = 'text-align:left;padding:8px 12px;background:#F4F7F9;border:1px solid #CBD5E1;font-weight:600;color:#64748B;';
                th.textContent = h;
                hrow.appendChild(th);
            }
            thead.appendChild(hrow);
            tbl.appendChild(thead);

            const tbody = document.createElement('tbody');
            csvHeaders.forEach((hdr, idx) => {
                const defaultColName = hdr.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || ('col_' + idx);

                const tr = document.createElement('tr');
                tr.dataset.csvHeader = hdr;
                tr.style.background  = idx % 2 === 0 ? '#fff' : '#DDEAF4';

                const tdH = document.createElement('td');
                tdH.style.cssText = 'padding:8px 12px;border:1px solid #CBD5E1;font-family:monospace;color:#1E293B;white-space:nowrap;';
                tdH.textContent = hdr;

                const tdS = document.createElement('td');
                tdS.style.cssText = 'padding:8px 12px;border:1px solid #CBD5E1;color:#64748B;font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
                const samples = csvPreview.map(r => r[hdr]).filter(v => v !== null && v !== '').slice(0, 3);
                tdS.textContent = samples.length ? samples.join(', ') : '(empty)';
                tdS.title = samples.join(' | ');

                const tdN = document.createElement('td');
                tdN.style.cssText = 'padding:8px 12px;border:1px solid #CBD5E1;';
                const nameInp = document.createElement('input');
                nameInp.type  = 'text';
                nameInp.value = defaultColName;
                nameInp.className = 'col-name-input';
                nameInp.style.cssText = 'padding:5px 8px;border:1px solid #CBD5E1;border-radius:4px;font-size:13px;width:100%;box-sizing:border-box;font-family:monospace;';
                nameInp.addEventListener('input', () => {
                    nameInp.value = nameInp.value.toLowerCase().replace(/[^a-z0-9_]/g, '_');
                });
                tdN.appendChild(nameInp);

                const tdT = document.createElement('td');
                tdT.style.cssText = 'padding:8px 12px;border:1px solid #CBD5E1;';
                const typeSelect = document.createElement('select');
                typeSelect.className = 'col-type-select';
                typeSelect.style.cssText = 'padding:5px 8px;border:1px solid #CBD5E1;border-radius:4px;font-size:13px;width:100%;';
                const guessedType = guessColType(csvPreview.map(r => r[hdr]).filter(v => v !== null && v !== ''));
                typeOptions.forEach(({ value, label }) => {
                    const opt = appendOpt(typeSelect, value, label);
                    if (value === guessedType) opt.selected = true;
                });
                tdT.appendChild(typeSelect);

                tr.append(tdH, tdS, tdN, tdT);
                tbody.appendChild(tr);
            });

            tbl.appendChild(tbody);
            mappingContainer.appendChild(tbl);
            return;
        }

        if (!selectedTable) return;

        const note = document.createElement('p');
        note.style.cssText = 'font-size:13px;color:#64748B;margin:0 0 12px;';
        note.textContent   = `Map ${csvHeaders.length} CSV column${csvHeaders.length !== 1 ? 's' : ''} to "${esc(selectedTable)}" columns. Leave "— Skip —" to ignore a CSV column.`;
        mappingContainer.appendChild(note);

        const tbl   = document.createElement('table');
        tbl.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;';

        const thead = document.createElement('thead');
        const hrow  = document.createElement('tr');
        for (const h of ['CSV Header', 'Sample values', 'Target column']) {
            const th = document.createElement('th');
            th.style.cssText = 'text-align:left;padding:8px 12px;background:#F4F7F9;border:1px solid #CBD5E1;font-weight:600;color:#64748B;';
            th.textContent = h;
            hrow.appendChild(th);
        }
        thead.appendChild(hrow);
        tbl.appendChild(thead);

        const tbody   = document.createElement('tbody');
        const dbCols  = Object.keys(tableColumns).filter(c => (tableColumns[c]?.type ?? '') !== 'virtual');

        csvHeaders.forEach((hdr, idx) => {
            const tr = document.createElement('tr');
            tr.style.background = idx % 2 === 0 ? '#fff' : '#DDEAF4';

            const tdH = document.createElement('td');
            tdH.style.cssText = 'padding:8px 12px;border:1px solid #CBD5E1;font-family:monospace;color:#1E293B;white-space:nowrap;';
            tdH.textContent = hdr;

            const tdS = document.createElement('td');
            tdS.style.cssText = 'padding:8px 12px;border:1px solid #CBD5E1;color:#64748B;font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
            const samples = csvPreview.map(r => r[hdr]).filter(v => v !== null && v !== '').slice(0, 3);
            tdS.textContent = samples.length ? samples.join(', ') : '(empty)';
            tdS.title       = samples.join(' | ');

            const tdC  = document.createElement('td');
            tdC.style.cssText = 'padding:8px 12px;border:1px solid #CBD5E1;';
            const sel  = document.createElement('select');
            sel.dataset.header = hdr;
            sel.style.cssText  = 'padding:5px 8px;border:1px solid #CBD5E1;border-radius:4px;font-size:13px;width:100%;';
            appendOpt(sel, '', '— Skip —');
            dbCols.forEach(col => {
                const cfg = tableColumns[col] || {};
                const opt = appendOpt(sel, col, (cfg.display_name || col) + ' (' + (cfg.type || 'text') + ')');
                if (col.toLowerCase() === hdr.toLowerCase()) opt.selected = true;
            });
            tdC.appendChild(sel);

            tr.append(tdH, tdS, tdC);
            tbody.appendChild(tr);
        });

        tbl.appendChild(tbody);
        mappingContainer.appendChild(tbl);
        rebuildConflictOptions();
        validateConflict();
    }

    function rebuildConflictOptions() {
        const prev = conflictSelect.value;
        while (conflictSelect.options.length > 1) conflictSelect.remove(1);
        const dbCols = Object.keys(tableColumns).filter(c => (tableColumns[c]?.type ?? '') !== 'virtual');
        dbCols.forEach(col => appendOpt(conflictSelect, col, tableColumns[col]?.display_name || col));
        if (prev) {
            for (let i = 0; i < conflictSelect.options.length; i++) {
                if (conflictSelect.options[i].value === prev) { conflictSelect.selectedIndex = i; break; }
            }
        }
        validateConflict();
    }

    function validateConflict() {
        const col = conflictSelect.value;
        if (!col) { conflictWarn.style.display = 'none'; return; }
        const mapped = Array.from(mappingContainer.querySelectorAll('select[data-header]'))
            .some(s => s.value === col);
        if (!mapped) {
            conflictWarn.textContent = `⚠ Column "${col}" is not mapped above. Map a CSV header to "${col}" or set conflict handling to "None".`;
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
        if (!csvTmpName || !selectedTable) {
            showBanner(execStatus, 'Upload a CSV file and select a target table first.', 'error');
            return;
        }
        const mapping = getMapping();
        if (!Object.values(mapping).some(v => v !== null && v !== '')) {
            showBanner(execStatus, 'Map at least one CSV column to a database column.', 'error');
            return;
        }

        execBtn.disabled    = true;
        execBtn.textContent = 'Importing…';
        execStatus.innerHTML = '';
        resultArea.innerHTML = '';

        try {
            const res = await apiFetch('api_csv_import.php?action=csv_import_execute', {
                method: 'POST',
                body: JSON.stringify({
                    tmp_name:        csvTmpName,
                    table:           selectedTable,
                    mapping,
                    conflict_column: copyModeChk.checked ? null : (conflictSelect.value || null),
                    copy_mode:       copyModeChk.checked,
                    original_name:   csvOrigName,
                    delimiter:       csvDelimiter,
                    encoding:        csvEncoding,
                }),
            });
            const data = await res.json();
            if (data.status !== 'success') throw new Error(data.error || 'Import failed');

            renderResult(data);
            resetUploadZone();
            loadHistory();
        } catch (e) {
            showBanner(execStatus, 'Import error: ' + esc(e.message), 'error');
        } finally {
            execBtn.disabled    = false;
            execBtn.textContent = 'Execute Import';
        }
    }

    async function createTableAndImport() {
        if (!newTableName) {
            showBanner(execStatus, 'Enter a table name before importing.', 'error');
            return;
        }
        if (!csvFile && !csvTmpName) {
            showBanner(execStatus, 'Select a CSV file first.', 'error');
            return;
        }

        const colDefs = [];
        const mapping = {};
        mappingContainer.querySelectorAll('tr[data-csv-header]').forEach(tr => {
            const csvHdr = tr.dataset.csvHeader;
            const dbName = (tr.querySelector('.col-name-input')?.value || '').replace(/[^a-z0-9_]/g, '').replace(/^_|_$/g, '');
            const colType = tr.querySelector('.col-type-select')?.value || 'varchar(255)';
            if (dbName) {
                colDefs.push({ name: dbName, type: colType });
                mapping[csvHdr] = dbName;
            }
        });

        if (!colDefs.length) {
            showBanner(execStatus, 'Define at least one column.', 'error');
            return;
        }

        execBtn.disabled     = true;
        execStatus.innerHTML = '';
        resultArea.innerHTML = '';

        try {
            if (!csvTmpName && csvFile) {
                execBtn.textContent = 'Uploading CSV…';
                const fd = new FormData();
                fd.append('csv_file', csvFile);
                fd.append('csv_delimiter', csvDelimiter);
                fd.append('csv_encoding', csvEncoding);
                const upRes  = await apiFetch('api_csv_import.php?action=csv_import_upload', {
                    method: 'POST',
                    body: fd,
                });
                const upData = await upRes.json();
                if (upData.status !== 'success') throw new Error(upData.error || 'Upload failed.');
                csvTmpName  = upData.tmp_name;
                csvRowCount = upData.row_count;
            }

            execBtn.textContent = 'Creating table…';
            const ctRes  = await apiFetch('api_csv_import.php?action=csv_create_table', {
                method: 'POST',
                body: JSON.stringify({
                    table:        newTableName,
                    schema:       newTableSchema,
                    display_name: newTableDisplayName || '',
                    columns:      colDefs,
                }),
            });
            const ctData = await ctRes.json();
            if (ctData.status !== 'success') throw new Error(ctData.error || 'Failed to create table.');

            execBtn.textContent = 'Importing…';
            const impRes  = await apiFetch('api_csv_import.php?action=csv_import_execute', {
                method: 'POST',
                body: JSON.stringify({
                    tmp_name:        csvTmpName,
                    table:           newTableName,
                    mapping,
                    conflict_column: null,
                    copy_mode:       copyModeChk.checked,
                    original_name:   csvOrigName,
                    delimiter:       csvDelimiter,
                    encoding:        csvEncoding,
                }),
            });
            const impData = await impRes.json();
            if (impData.status !== 'success') throw new Error(impData.error || 'Import failed.');

            renderResult(impData);
            resetUploadZone();
            loadHistory();
        } catch (e) {
            showBanner(execStatus, 'Error: ' + esc(e.message), 'error');
        } finally {
            execBtn.disabled    = false;
            execBtn.textContent = 'Create Table & Import';
        }
    }

    function renderResult(data) {
        const ok  = data.skipped_rows === 0;
        const bg  = ok ? 'rgba(43,147,72,0.12)' : 'rgba(255,195,0,0.08)';
        const bdr = ok ? '#2b9348'              : '#ffc300';

        const resultEl = document.createElement('div');
        resultEl.style.cssText = `padding:18px 20px;border-radius:8px;background:${bg};border:1px solid ${bdr};margin-bottom:8px;`;

        const title = document.createElement('div');
        title.style.cssText = `font-weight:700;font-size:15px;margin-bottom:8px;color:${ok ? '#1a6b35' : '#64748B'};`;
        title.textContent = ok
            ? `✓ Import complete`
            : `⚠ Import finished with issues`;

        const stats = document.createElement('div');
        stats.style.cssText = 'display:flex;gap:20px;flex-wrap:wrap;font-size:13px;margin-bottom:4px;';

        const stat = (label, value, accent) => {
            const s = document.createElement('span');
            s.style.cssText = `color:${accent ? '#1a6b35' : '#64748B'};`;
            s.innerHTML = `<strong>${value}</strong> ${label}`;
            return s;
        };

        stats.append(
            stat('rows imported', data.imported_rows.toLocaleString(), ok),
            stat('skipped',       data.skipped_rows.toLocaleString(),  false),
        );

        if (typeof data.elapsed_seconds === 'number') {
            const secs = data.elapsed_seconds;
            const dur  = secs < 60 ? secs.toFixed(1) + ' s' : Math.floor(secs / 60) + ' m ' + (secs % 60).toFixed(0) + ' s';
            stats.append(stat('duration', dur, false));
        }

        resultEl.append(title, stats);

        if (data.has_errors && data.import_id) {
            const logLink = document.createElement('a');
            logLink.href  = '#';
            logLink.style.cssText = 'display:inline-block;margin-top:8px;font-size:13px;color:#64748B;';
            logLink.textContent = 'View skipped row details ↓';
            logLink.addEventListener('click', async (e) => {
                e.preventDefault();
                logLink.remove();
                await appendRowLog(data.import_id, resultEl);
            });
            resultEl.appendChild(logLink);
        }

        resultArea.innerHTML = '';
        resultArea.appendChild(resultEl);
    }

    async function appendRowLog(importId, container) {
        try {
            const res  = await apiFetch(`api_csv_import.php?action=csv_import_log&id=${importId}`);
            const data = await res.json();
            if (data.status !== 'success' || !data.rows.length) {
                const note = document.createElement('p');
                note.style.cssText = 'font-size:13px;color:#64748B;margin-top:8px;';
                note.textContent = 'No row-level errors logged.';
                container.appendChild(note);
                return;
            }
            container.appendChild(buildRowLogTable(data.rows));
        } catch (_) { /* ignore */ }
    }

    function buildRowLogTable(rows) {
        const wrapEl = document.createElement('div');
        wrapEl.style.cssText = 'margin-top:12px;max-height:320px;overflow-y:auto;border:1px solid #CBD5E1;border-radius:4px;';

        const tbl = document.createElement('table');
        tbl.style.cssText = 'width:100%;border-collapse:collapse;font-size:12px;';

        const thead = document.createElement('thead');
        const hrow  = document.createElement('tr');
        for (const h of ['Row #', 'Error', 'Raw data (JSON)']) {
            const th = document.createElement('th');
            th.style.cssText = 'text-align:left;padding:6px 10px;background:#F4F7F9;border:1px solid #CBD5E1;white-space:nowrap;font-weight:600;';
            th.textContent = h;
            hrow.appendChild(th);
        }
        thead.appendChild(hrow);
        tbl.appendChild(thead);

        const tbody = document.createElement('tbody');
        rows.forEach((row, idx) => {
            const tr = document.createElement('tr');
            tr.style.background = idx % 2 === 0 ? '#fff' : '#DDEAF4';

            const tdN = td(String(row.row_number), 'padding:5px 10px;border:1px solid #CBD5E1;white-space:nowrap;');
            const tdE = td(row.error_message || '', 'padding:5px 10px;border:1px solid #CBD5E1;color:#d00000;');
            const tdR = td(row.raw_data || '', 'padding:5px 10px;border:1px solid #CBD5E1;font-family:monospace;font-size:11px;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;');
            tdR.title = row.raw_data || '';

            tr.append(tdN, tdE, tdR);
            tbody.appendChild(tr);
        });
        tbl.appendChild(tbody);
        wrapEl.appendChild(tbl);
        return wrapEl;
    }

    async function loadHistory() {
        histContainer.innerHTML = '<p style="color:#64748B;font-size:13px;padding:4px 0;">Loading…</p>';
        try {
            const res  = await apiFetch('api_csv_import.php?action=csv_import_history');
            const data = await res.json();

            if (data.status !== 'success' || !data.imports.length) {
                histContainer.innerHTML = '<p style="color:#64748B;font-size:13px;">No imports yet.</p>';
                return;
            }

            const tbl = document.createElement('table');
            tbl.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;';

            const thead = document.createElement('thead');
            const hrow  = document.createElement('tr');
            for (const h of ['#', 'File', 'Table', 'Status', 'Imported', 'Skipped', 'By', 'Started', 'Duration']) {
                const th = document.createElement('th');
                th.style.cssText = 'text-align:left;padding:8px 10px;background:#F4F7F9;border:1px solid #CBD5E1;white-space:nowrap;font-weight:600;color:#64748B;';
                th.textContent = h;
                hrow.appendChild(th);
            }
            thead.appendChild(hrow);
            tbl.appendChild(thead);

            const tbody = document.createElement('tbody');
            data.imports.forEach((row, idx) => {
                const tr = document.createElement('tr');
                tr.style.background = idx % 2 === 0 ? '#fff' : '#DDEAF4';

                const statusCfg = {
                    done:    { bg: 'rgba(43,147,72,0.12)', fg: '#2b9348' },
                    failed:  { bg: 'rgba(208,0,0,0.08)', fg: '#a80000' },
                    running: { bg: 'rgba(255,195,0,0.12)', fg: '#64748B' },
                }[row.status] ?? { bg: '#DDEAF4', fg: '#64748B' };

                for (const [val, style] of [
                    [row.id,                        'padding:8px 10px;border:1px solid #CBD5E1;white-space:nowrap;'],
                    [row.filename,                  'padding:8px 10px;border:1px solid #CBD5E1;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'],
                    [row.target_table,              'padding:8px 10px;border:1px solid #CBD5E1;'],
                    [null,                          'padding:8px 10px;border:1px solid #CBD5E1;'],
                    [row.imported_rows ?? 0,        'padding:8px 10px;border:1px solid #CBD5E1;text-align:right;'],
                    [row.skipped_rows  ?? 0,        'padding:8px 10px;border:1px solid #CBD5E1;text-align:right;'],
                    [row.username || '—',           'padding:8px 10px;border:1px solid #CBD5E1;'],
                    [(row.started_at || '').slice(0, 16), 'padding:8px 10px;border:1px solid #CBD5E1;white-space:nowrap;'],
                    [fmtDuration(row.started_at, row.finished_at), 'padding:8px 10px;border:1px solid #CBD5E1;white-space:nowrap;text-align:right;color:#64748B;font-size:12px;'],
                ]) {
                    const cell = document.createElement('td');
                    cell.style.cssText = style;
                    if (val === null) {
                        const badge = document.createElement('span');
                        badge.style.cssText = `padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;background:${statusCfg.bg};color:${statusCfg.fg};`;
                        badge.textContent = row.status;
                        cell.appendChild(badge);
                    } else {
                        cell.textContent = String(val);
                    }
                    tr.appendChild(cell);
                }

                const tdAct = document.createElement('td');
                tdAct.style.cssText = 'padding:8px 10px;border:1px solid #CBD5E1;';
                if ((row.skipped_rows ?? 0) > 0) {
                    const logBtn = document.createElement('button');
                    logBtn.type      = 'button';
                    logBtn.textContent = 'Log';
                    logBtn.style.cssText = 'padding:3px 10px;font-size:12px;border:1px solid #CBD5E1;border-radius:4px;cursor:pointer;background:#fff;';
                    logBtn.addEventListener('click', async () => {
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
                    tdAct.appendChild(logBtn);
                }
                tr.appendChild(tdAct);
                tbody.appendChild(tr);
            });

            tbl.appendChild(tbody);
            histContainer.innerHTML = '';
            histContainer.appendChild(tbl);
        } catch (_) {
            histContainer.innerHTML = '<p style="color:#d00000;font-size:13px;">Failed to load history.</p>';
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    function resetDropZone() {
        dropZone.style.borderColor = '#DDEAF4';
        dropZone.style.background  = '#fff';
    }

    function resetUploadZone() {
        csvFile     = null;
        csvTmpName  = '';
        csvOrigName = '';
        csvHeaders  = [];
        csvPreview  = [];
        uploadMsg.textContent  = 'Click to select a CSV file or drag & drop here';
        uploadMsg.style.color  = '#64748B';
        uploadHint.textContent = '.csv only · max 500 MB';
        resetDropZone();
        card2.el.style.display = 'none';
        mappingContainer.innerHTML = '';
        execStatus.innerHTML = '';
    }

    function flashMsg(el, text, color) {
        const orig  = el.textContent;
        const origC = el.style.color;
        el.textContent = text;
        el.style.color = color;
        setTimeout(() => { el.textContent = orig; el.style.color = origC; }, 2200);
    }

    function showBanner(container, msg, type) {
        const colors = {
            success: { bg: 'rgba(43,147,72,0.12)', fg: '#2b9348', border: '#64748B' },
            error:   { bg: 'rgba(208,0,0,0.08)', fg: '#a80000', border: '#d00000' },
        }[type] ?? { bg: '#DDEAF4', fg: '#1E293B', border: '#DDEAF4' };
        const div = document.createElement('div');
        div.style.cssText = `padding:10px 14px;border-radius:6px;background:${colors.bg};color:${colors.fg};border:1px solid ${colors.border};font-size:13px;`;
        div.textContent = msg;
        container.innerHTML = '';
        container.appendChild(div);
    }
}

// ── CSV client-side preview helpers ──────────────────────────────────────────

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
                    const vals = parseCsvLine(lines[i], delimiter);
                    const row = {};
                    headers.forEach((h, j) => { row[h] = vals[j] ?? ''; });
                    preview.push(row);
                }
                resolve({ headers, preview });
            } catch (err) {
                reject(err);
            }
        };
        reader.onerror = () => reject(new Error('Failed to read file.'));
        reader.readAsText(file.slice(0, 131072), encoding);
    });
}

function fmtDuration(startedAt, finishedAt) {
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
    let cur = '';
    let inQ = false;
    for (let i = 0; i < line.length; i++) {
        const c = line[i];
        if (inQ) {
            if (c === '"') {
                if (line[i + 1] === '"') { cur += '"'; i++; }
                else inQ = false;
            } else {
                cur += c;
            }
        } else if (c === '"') {
            inQ = true;
        } else if (line.startsWith(delimiter, i)) {
            fields.push(cur); cur = '';
            i += delimiter.length - 1;
        } else {
            cur += c;
        }
    }
    fields.push(cur);
    return fields;
}

function guessColType(samples) {
    const nonEmpty = samples.filter(v => v !== null && v !== '');
    if (!nonEmpty.length) return 'varchar(255)';
    // JSON-like or long value → text (safe: no false positives)
    if (nonEmpty.some(v => v.length > 200 || ((v[0] === '{' || v[0] === '[') && v.length > 5))) return 'text';
    // Date/timestamp patterns are distinctive enough to be reliable
    if (nonEmpty.every(v => /^\d{4}-\d{2}-\d{2}$/.test(v))) return 'date';
    if (nonEmpty.every(v => /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/.test(v))) return 'timestamp';
    // int/boolean skipped — 5-row sample is not representative enough;
    // a column with "0"/"1" in early rows may hold strings like "Physical/motor disability" later.
    // User must select int4/int8/boolean manually when needed.
    return 'varchar(255)';
}

// ── Tab builder (same pattern as ragBuildTabs) ────────────────────────────────

function buildCsvTabs(wrap, tabs) {
    const bar = document.createElement('div');
    bar.className = 'item-panel-items';

    const panels = {};
    const btns   = {};

    tabs.forEach(({ id, label, icon }) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.dataset.tab = id;
        btn.className = 'item-btn';
        if (icon) {
            const img = document.createElement('img');
            img.src = '../assets/icons/' + icon;
            img.style.cssText = 'width:15px;height:15px;opacity:.6;';
            btn.appendChild(img);
        }
        btn.appendChild(document.createTextNode(label));
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

    tabs.forEach(({ id }) => {
        btns[id].addEventListener('click', () => activate(id));
    });

    activate(tabs[0].id);

    return { panels, activate };
}

// ── Micro DOM helpers (module-private) ────────────────────────────────────────

function buildCard(title) {
    const el = document.createElement('div');
    el.style.cssText = 'background:#F4F7F9;border:1px solid #CBD5E1;border-radius:8px;padding:20px;margin-bottom:20px;';
    const h = document.createElement('h3');
    h.style.cssText = 'margin:0 0 16px;font-size:15px;color:#1E293B;';
    h.textContent = title;
    el.appendChild(h);
    return { el };
}

function buildRow() {
    const div = document.createElement('div');
    div.style.cssText = 'display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px;';
    return div;
}

function buildLabel(text) {
    const lbl = document.createElement('label');
    lbl.style.cssText = 'font-size:13px;font-weight:600;color:#64748B;';
    lbl.textContent = text;
    return lbl;
}

function appendOpt(select, value, label) {
    const opt = document.createElement('option');
    opt.value       = value;
    opt.textContent = label;
    select.appendChild(opt);
    return opt;
}

function td(text, style) {
    const el = document.createElement('td');
    el.style.cssText = style;
    el.textContent   = text;
    return el;
}

import { escHtml as esc } from '../../assets/js/util/esc.js';
