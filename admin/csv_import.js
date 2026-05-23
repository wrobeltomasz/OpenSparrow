// admin/csv_import.js

export async function renderCsvImportPage(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '';
    workspaceEl._csvImportGen = (workspaceEl._csvImportGen || 0) + 1;
    const myGen = workspaceEl._csvImportGen;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // ── Module state ─────────────────────────────────────────────────────────
    let csvHeaders    = [];
    let csvPreview    = [];
    let csvRowCount   = 0;
    let csvTmpName    = '';
    let csvOrigName   = '';
    let selectedTable = '';
    let tableColumns  = {}; // { colName: { type, display_name, ... } }

    // ── Root ──────────────────────────────────────────────────────────────────
    const wrap = document.createElement('div');
    wrap.style.cssText = 'max-width:960px;padding-bottom:60px;';

    const heading = document.createElement('h2');
    heading.style.marginTop = '0';
    heading.textContent = 'CSV Import';
    wrap.appendChild(heading);

    const desc = document.createElement('p');
    desc.style.cssText = 'color:#64748b;margin-bottom:24px;font-size:14px;';
    desc.textContent = 'Import rows from a CSV file into an existing table. Select a target table, upload the file, map headers to columns, choose optional upsert conflict handling, then run the import.';
    wrap.appendChild(desc);

    // ── Step 1 ────────────────────────────────────────────────────────────────
    const card1 = buildCard('Step 1 — Select Table & Upload CSV');
    wrap.appendChild(card1.el);

    // Table selector row
    const tableRow = buildRow();
    const tableLabel = buildLabel('Target table:');
    tableLabel.style.minWidth = '110px';

    const tableSelect = document.createElement('select');
    tableSelect.style.cssText = 'padding:7px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;min-width:220px;';
    appendOpt(tableSelect, '', '— Select table —');

    try {
        const res  = await fetch('api.php?action=get&file=schema');
        const data = await res.json();
        if (data.tables) {
            for (const [name, cfg] of Object.entries(data.tables)) {
                const opt   = appendOpt(tableSelect, name, cfg.display_name || name);
                opt.dataset.cols = JSON.stringify(cfg.columns || {});
            }
        }
    } catch (_) { /* schema unavailable */ }

    tableRow.append(tableLabel, tableSelect);
    card1.el.appendChild(tableRow);

    // Upload drop zone
    const dropZone = document.createElement('div');
    dropZone.style.cssText = 'border:2px dashed #cbd5e1;border-radius:8px;padding:32px 20px;text-align:center;background:#fff;cursor:pointer;transition:border-color .2s,background .2s;margin-top:16px;';

    const uploadIcon = document.createElement('div');
    uploadIcon.style.cssText = 'font-size:36px;margin-bottom:8px;pointer-events:none;';
    uploadIcon.textContent = '📄';

    const uploadMsg = document.createElement('div');
    uploadMsg.style.cssText = 'font-size:14px;color:#475569;margin-bottom:4px;pointer-events:none;';
    uploadMsg.textContent = 'Click to select a CSV file or drag & drop here';

    const uploadHint = document.createElement('div');
    uploadHint.style.cssText = 'font-size:12px;color:#94a3b8;pointer-events:none;';
    uploadHint.textContent = '.csv only · max 50 MB';

    const fileInput = document.createElement('input');
    fileInput.type   = 'file';
    fileInput.accept = '.csv,text/csv';
    fileInput.style.display = 'none';

    dropZone.append(uploadIcon, uploadMsg, uploadHint, fileInput);
    card1.el.appendChild(dropZone);

    // ── Step 2 ────────────────────────────────────────────────────────────────
    const card2 = buildCard('Step 2 — Map Columns & Execute');
    card2.el.style.display = 'none';
    wrap.appendChild(card2.el);

    const mappingContainer = document.createElement('div');
    card2.el.appendChild(mappingContainer);

    // Conflict column row
    const conflictRow = buildRow();
    conflictRow.style.cssText = 'display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:20px;';

    const conflictLabel = buildLabel('Upsert on conflict:');
    conflictLabel.style.minWidth = '140px';

    const conflictSelect = document.createElement('select');
    conflictSelect.style.cssText = 'padding:7px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;min-width:200px;';
    appendOpt(conflictSelect, '', '— None (insert only) —');

    const conflictNote = document.createElement('span');
    conflictNote.style.cssText = 'font-size:12px;color:#64748b;';
    conflictNote.textContent = 'Matching rows will be updated instead of rejected (requires unique constraint).';

    const conflictWarn = document.createElement('div');
    conflictWarn.style.cssText = 'display:none;margin-top:8px;padding:8px 12px;background:#fef3c7;border:1px solid #fde047;border-radius:4px;font-size:12px;color:#92400e;';

    conflictRow.append(conflictLabel, conflictSelect, conflictNote);
    card2.el.appendChild(conflictRow);
    card2.el.appendChild(conflictWarn);

    const execBtn = document.createElement('button');
    execBtn.type      = 'button';
    execBtn.textContent = 'Execute Import';
    execBtn.style.cssText = 'margin-top:20px;padding:10px 28px;background:#3b82f6;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;';
    card2.el.appendChild(execBtn);

    const execStatus = document.createElement('div');
    execStatus.style.marginTop = '14px';
    card2.el.appendChild(execStatus);

    // ── History section ───────────────────────────────────────────────────────
    const histSection = document.createElement('div');
    histSection.style.marginTop = '36px';

    const histTitle = document.createElement('h3');
    histTitle.style.cssText = 'font-size:15px;margin:0 0 12px;';
    histTitle.textContent = 'Import History';

    const histContainer = document.createElement('div');
    histSection.append(histTitle, histContainer);
    wrap.appendChild(histSection);

    if (workspaceEl._csvImportGen !== myGen) return;
    workspaceEl.appendChild(wrap);

    // ── Bootstrap ─────────────────────────────────────────────────────────────
    await loadHistory();

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
        if (!selectedTable) {
            flashMsg(uploadMsg, 'Select a target table first.', '#ef4444');
            return;
        }
        fileInput.click();
    });

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#3b82f6';
        dropZone.style.background  = '#eff6ff';
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

    conflictSelect.addEventListener('change', validateConflict);
    mappingContainer.addEventListener('change', validateConflict);

    execBtn.addEventListener('click', executeImport);

    // ── Functions ─────────────────────────────────────────────────────────────

    async function handleUpload(file) {
        if (!selectedTable) {
            flashMsg(uploadMsg, 'Select a target table first.', '#ef4444');
            return;
        }

        uploadMsg.textContent  = `Uploading ${esc(file.name)}…`;
        uploadMsg.style.color  = '#3b82f6';
        uploadHint.textContent = '';

        const fd = new FormData();
        fd.append('csv_file', file);

        try {
            const res  = await fetch('api_csv_import.php?action=csv_import_upload', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
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
            uploadMsg.style.color  = '#166534';
            uploadHint.textContent = '';
            dropZone.style.borderColor = '#86efac';

            renderMapping();
            card2.el.style.display = 'block';
        } catch (e) {
            uploadMsg.textContent  = 'Upload failed: ' + esc(e.message);
            uploadMsg.style.color  = '#ef4444';
            uploadHint.textContent = 'Try again.';
        }
    }

    function renderMapping() {
        mappingContainer.innerHTML = '';
        if (!csvHeaders.length || !selectedTable) return;

        const note = document.createElement('p');
        note.style.cssText = 'font-size:13px;color:#64748b;margin:0 0 12px;';
        note.textContent   = `Map ${csvHeaders.length} CSV column${csvHeaders.length !== 1 ? 's' : ''} to "${esc(selectedTable)}" columns. Leave "— Skip —" to ignore a CSV column.`;
        mappingContainer.appendChild(note);

        const tbl   = document.createElement('table');
        tbl.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;';

        const thead = document.createElement('thead');
        const hrow  = document.createElement('tr');
        for (const h of ['CSV Header', 'Sample values', 'Target column']) {
            const th = document.createElement('th');
            th.style.cssText = 'text-align:left;padding:8px 12px;background:#e2e8f0;border:1px solid #cbd5e1;font-weight:600;color:#374151;';
            th.textContent = h;
            hrow.appendChild(th);
        }
        thead.appendChild(hrow);
        tbl.appendChild(thead);

        const tbody   = document.createElement('tbody');
        const dbCols  = Object.keys(tableColumns).filter(c => (tableColumns[c]?.type ?? '') !== 'virtual');

        csvHeaders.forEach((hdr, idx) => {
            const tr = document.createElement('tr');
            tr.style.background = idx % 2 === 0 ? '#fff' : '#f8fafc';

            // CSV header name
            const tdH = document.createElement('td');
            tdH.style.cssText = 'padding:8px 12px;border:1px solid #e2e8f0;font-family:monospace;color:#1e293b;white-space:nowrap;';
            tdH.textContent = hdr;

            // Sample values
            const tdS = document.createElement('td');
            tdS.style.cssText = 'padding:8px 12px;border:1px solid #e2e8f0;color:#64748b;font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
            const samples = csvPreview.map(r => r[hdr]).filter(v => v !== null && v !== '').slice(0, 3);
            tdS.textContent = samples.length ? samples.join(', ') : '(empty)';
            tdS.title       = samples.join(' | ');

            // Target column select
            const tdC  = document.createElement('td');
            tdC.style.cssText = 'padding:8px 12px;border:1px solid #e2e8f0;';
            const sel  = document.createElement('select');
            sel.dataset.header = hdr;
            sel.style.cssText  = 'padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;width:100%;';
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
        // Restore previously selected column if still available
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

        try {
            const res = await fetch('api_csv_import.php?action=csv_import_execute', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
                    tmp_name:        csvTmpName,
                    table:           selectedTable,
                    mapping,
                    conflict_column: conflictSelect.value || null,
                    original_name:   csvOrigName,
                }),
            });
            const data = await res.json();
            if (data.status !== 'success') throw new Error(data.error || 'Import failed');

            renderResult(data);
            resetUploadZone();
            await loadHistory();
        } catch (e) {
            showBanner(execStatus, 'Import error: ' + esc(e.message), 'error');
        } finally {
            execBtn.disabled    = false;
            execBtn.textContent = 'Execute Import';
        }
    }

    function renderResult(data) {
        const ok       = data.skipped_rows === 0;
        const resultEl = document.createElement('div');
        resultEl.style.cssText = `padding:16px;border-radius:6px;background:${ok ? '#dcfce7' : '#fefce8'};border:1px solid ${ok ? '#86efac' : '#fde047'};`;

        const title = document.createElement('div');
        title.style.cssText = 'font-weight:600;font-size:14px;margin-bottom:6px;';
        title.textContent = ok
            ? `✓ Import complete — ${data.imported_rows.toLocaleString()} rows inserted/updated.`
            : `⚠ Import finished with issues — ${data.imported_rows.toLocaleString()} imported, ${data.skipped_rows.toLocaleString()} skipped.`;

        const detail = document.createElement('div');
        detail.style.cssText = 'font-size:13px;color:#475569;';
        detail.textContent = `Total: ${data.total_rows} · Imported: ${data.imported_rows} · Skipped: ${data.skipped_rows}`;

        resultEl.append(title, detail);

        if (data.has_errors && data.import_id) {
            const logLink = document.createElement('a');
            logLink.href  = '#';
            logLink.style.cssText = 'display:inline-block;margin-top:10px;font-size:13px;color:#3b82f6;';
            logLink.textContent = 'View skipped row details ↓';
            logLink.addEventListener('click', async (e) => {
                e.preventDefault();
                logLink.remove();
                await appendRowLog(data.import_id, resultEl);
            });
            resultEl.appendChild(logLink);
        }

        execStatus.innerHTML = '';
        execStatus.appendChild(resultEl);
    }

    async function appendRowLog(importId, container) {
        try {
            const res  = await fetch(`api_csv_import.php?action=csv_import_log&id=${importId}`);
            const data = await res.json();
            if (data.status !== 'success' || !data.rows.length) {
                const note = document.createElement('p');
                note.style.cssText = 'font-size:13px;color:#64748b;margin-top:8px;';
                note.textContent = 'No row-level errors logged.';
                container.appendChild(note);
                return;
            }
            container.appendChild(buildRowLogTable(data.rows));
        } catch (_) { /* ignore */ }
    }

    function buildRowLogTable(rows) {
        const wrap = document.createElement('div');
        wrap.style.cssText = 'margin-top:12px;max-height:320px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:4px;';

        const tbl = document.createElement('table');
        tbl.style.cssText = 'width:100%;border-collapse:collapse;font-size:12px;';

        const thead = document.createElement('thead');
        const hrow  = document.createElement('tr');
        for (const h of ['Row #', 'Error', 'Raw data (JSON)']) {
            const th = document.createElement('th');
            th.style.cssText = 'text-align:left;padding:6px 10px;background:#f1f5f9;border:1px solid #e2e8f0;white-space:nowrap;font-weight:600;';
            th.textContent = h;
            hrow.appendChild(th);
        }
        thead.appendChild(hrow);
        tbl.appendChild(thead);

        const tbody = document.createElement('tbody');
        rows.forEach((row, idx) => {
            const tr = document.createElement('tr');
            tr.style.background = idx % 2 === 0 ? '#fff' : '#fafafa';

            const tdN = td(String(row.row_number), 'padding:5px 10px;border:1px solid #e2e8f0;white-space:nowrap;');
            const tdE = td(row.error_message || '', 'padding:5px 10px;border:1px solid #e2e8f0;color:#ef4444;');
            const tdR = td(row.raw_data || '', 'padding:5px 10px;border:1px solid #e2e8f0;font-family:monospace;font-size:11px;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;');
            tdR.title = row.raw_data || '';

            tr.append(tdN, tdE, tdR);
            tbody.appendChild(tr);
        });
        tbl.appendChild(tbody);
        wrap.appendChild(tbl);
        return wrap;
    }

    async function loadHistory() {
        histContainer.innerHTML = '<p style="color:#94a3b8;font-size:13px;padding:4px 0;">Loading…</p>';
        try {
            const res  = await fetch('api_csv_import.php?action=csv_import_history');
            const data = await res.json();

            if (data.status !== 'success' || !data.imports.length) {
                histContainer.innerHTML = '<p style="color:#94a3b8;font-size:13px;">No imports yet.</p>';
                return;
            }

            const tbl = document.createElement('table');
            tbl.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;';

            const thead = document.createElement('thead');
            const hrow  = document.createElement('tr');
            for (const h of ['#', 'File', 'Table', 'Status', 'Imported', 'Skipped', 'By', 'Started', '']) {
                const th = document.createElement('th');
                th.style.cssText = 'text-align:left;padding:8px 10px;background:#f1f5f9;border:1px solid #e2e8f0;white-space:nowrap;font-weight:600;color:#374151;';
                th.textContent = h;
                hrow.appendChild(th);
            }
            thead.appendChild(hrow);
            tbl.appendChild(thead);

            const tbody = document.createElement('tbody');
            data.imports.forEach((row, idx) => {
                const tr = document.createElement('tr');
                tr.style.background = idx % 2 === 0 ? '#fff' : '#f8fafc';

                const statusCfg = {
                    done:    { bg: '#dcfce7', fg: '#166534' },
                    failed:  { bg: '#fee2e2', fg: '#991b1b' },
                    running: { bg: '#fef3c7', fg: '#92400e' },
                }[row.status] ?? { bg: '#e2e8f0', fg: '#374151' };

                for (const [val, style] of [
                    [row.id,                        'padding:8px 10px;border:1px solid #e2e8f0;white-space:nowrap;'],
                    [row.filename,                  'padding:8px 10px;border:1px solid #e2e8f0;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'],
                    [row.target_table,              'padding:8px 10px;border:1px solid #e2e8f0;'],
                    [null,                          'padding:8px 10px;border:1px solid #e2e8f0;'], // badge placeholder
                    [row.imported_rows ?? 0,        'padding:8px 10px;border:1px solid #e2e8f0;text-align:right;'],
                    [row.skipped_rows  ?? 0,        'padding:8px 10px;border:1px solid #e2e8f0;text-align:right;'],
                    [row.username || '—',           'padding:8px 10px;border:1px solid #e2e8f0;'],
                    [(row.started_at || '').slice(0, 16), 'padding:8px 10px;border:1px solid #e2e8f0;white-space:nowrap;'],
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

                // Actions cell — "Log" toggle
                const tdAct = document.createElement('td');
                tdAct.style.cssText = 'padding:8px 10px;border:1px solid #e2e8f0;';
                if ((row.skipped_rows ?? 0) > 0) {
                    const logBtn = document.createElement('button');
                    logBtn.type      = 'button';
                    logBtn.textContent = 'Log';
                    logBtn.style.cssText = 'padding:3px 10px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px;cursor:pointer;background:#fff;';
                    logBtn.addEventListener('click', async () => {
                        const existing = tr.nextElementSibling;
                        if (existing && existing.dataset.logForId === String(row.id)) {
                            existing.remove();
                            return;
                        }
                        const logTr = document.createElement('tr');
                        logTr.dataset.logForId = String(row.id);
                        const logTd = document.createElement('td');
                        logTd.colSpan = 9;
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
            histContainer.innerHTML = '<p style="color:#ef4444;font-size:13px;">Failed to load history.</p>';
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    function resetDropZone() {
        dropZone.style.borderColor = '#cbd5e1';
        dropZone.style.background  = '#fff';
    }

    function resetUploadZone() {
        csvTmpName  = '';
        csvOrigName = '';
        uploadMsg.textContent  = 'Click to select a CSV file or drag & drop here';
        uploadMsg.style.color  = '#475569';
        uploadHint.textContent = '.csv only · max 50 MB';
        resetDropZone();
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
            success: { bg: '#dcfce7', fg: '#166534', border: '#86efac' },
            error:   { bg: '#fee2e2', fg: '#991b1b', border: '#fca5a5' },
        }[type] ?? { bg: '#e0f2fe', fg: '#075985', border: '#7dd3fc' };
        const div = document.createElement('div');
        div.style.cssText = `padding:10px 14px;border-radius:6px;background:${colors.bg};color:${colors.fg};border:1px solid ${colors.border};font-size:13px;`;
        div.textContent = msg;
        container.innerHTML = '';
        container.appendChild(div);
    }
}

// ── Micro DOM helpers (module-private) ────────────────────────────────────────

function buildCard(title) {
    const el = document.createElement('div');
    el.style.cssText = 'background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:20px;margin-bottom:20px;';
    const h = document.createElement('h3');
    h.style.cssText = 'margin:0 0 16px;font-size:15px;color:#1e293b;';
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
    lbl.style.cssText = 'font-size:13px;font-weight:600;color:#374151;';
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

function esc(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
}
