// admin/rag.js — RAG knowledge base management page

function ragEsc(str) {
    return String(str ?? '').replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
}

function ragCsrf() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
}

function ragCard(title, desc) {
    const card = document.createElement('div');
    card.style.cssText = 'border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:20px;';

    const hdr = document.createElement('div');
    hdr.style.cssText = 'padding:14px 18px;background:var(--bg);border-bottom:1px solid var(--border);';

    const h3 = document.createElement('h3');
    h3.textContent = title;
    h3.style.cssText = 'margin:0 0 4px;font-size:15px;';
    hdr.appendChild(h3);

    if (desc) {
        const p = document.createElement('p');
        p.textContent = desc;
        p.style.cssText = 'margin:0;color:var(--muted);font-size:13px;';
        hdr.appendChild(p);
    }
    card.appendChild(hdr);

    const body = document.createElement('div');
    body.style.cssText = 'padding:18px;';
    card.appendChild(body);

    return { card, body };
}

function ragStatusPill(anchor, msg, type = 'success') {
    const prev = anchor.parentNode?.querySelector('.rag-status-pill');
    if (prev) prev.remove();
    const colors = {
        success: { bg: '#dcfce7', fg: '#166534', border: '#86efac' },
        error:   { bg: '#fee2e2', fg: '#991b1b', border: '#fca5a5' },
        info:    { bg: '#e0f2fe', fg: '#075985', border: '#7dd3fc' },
    }[type] ?? { bg: '#e2e8f0', fg: '#0f172a', border: '#cbd5e1' };
    const pill = document.createElement('span');
    pill.className = 'rag-status-pill';
    pill.textContent = msg;
    pill.style.cssText = `display:inline-flex;align-items:center;gap:6px;margin-left:10px;padding:4px 10px;`
        + `background:${colors.bg};color:${colors.fg};border:1px solid ${colors.border};`
        + `border-radius:999px;font-size:12px;font-weight:600;transition:opacity .3s;`;
    anchor.insertAdjacentElement('afterend', pill);
    setTimeout(() => {
        pill.style.opacity = '0';
        setTimeout(() => pill.remove(), 300);
    }, type === 'error' ? 6000 : 3000);
}

function ragFmtSize(bytes) {
    bytes = parseInt(bytes, 10) || 0;
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

function ragFmtDate(ts) {
    if (!ts) return '—';
    return new Date(ts).toLocaleString();
}

function ragParseTags(pgArray) {
    if (!pgArray || pgArray === '{}') return [];
    const inner = pgArray.replace(/^\{|\}$/g, '');
    if (!inner) return [];
    const result = [];
    let cur = '';
    let inQuote = false;
    for (let i = 0; i < inner.length; i++) {
        const c = inner[i];
        if (c === '"') { inQuote = !inQuote; }
        else if (c === ',' && !inQuote) { result.push(cur); cur = ''; }
        else { cur += c; }
    }
    if (cur !== '') result.push(cur);
    return result;
}

// ── Tab bar ──────────────────────────────────────────────────────────────────

function ragBuildTabs(wrap, tabs) {
    const bar = document.createElement('div');
    bar.style.cssText = 'display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:24px;';

    const panels = {};
    const btns   = {};

    tabs.forEach(({ id, label, icon }) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.style.cssText = 'display:flex;align-items:center;gap:7px;padding:10px 20px;background:none;border:none;'
            + 'border-bottom:2px solid transparent;margin-bottom:-2px;cursor:pointer;font-size:13px;font-weight:600;'
            + 'color:var(--muted);transition:color .15s,border-color .15s;';
        if (icon) {
            const img = document.createElement('img');
            img.src   = '../assets/icons/' + icon;
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
            const active = k === id;
            b.style.color        = active ? 'var(--accent)' : 'var(--muted)';
            b.style.borderColor  = active ? 'var(--accent)' : 'transparent';
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

// ── Documents tab ────────────────────────────────────────────────────────────

function ragBuildDocumentsTab(panel) {
    // Upload card
    const { card: uploadCard, body: uploadBody } = ragCard(
        'Upload Document',
        'Only .txt files accepted. Tags comma-separated, used to filter queries.'
    );
    panel.appendChild(uploadCard);

    const uploadForm = document.createElement('div');
    uploadForm.style.cssText = 'display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;';

    function ragField(label, id, placeholder) {
        const group = document.createElement('div');
        const lbl = document.createElement('label');
        lbl.htmlFor = id;
        lbl.textContent = label;
        lbl.style.cssText = 'display:block;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;';
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.id = id;
        inp.placeholder = placeholder;
        inp.style.cssText = 'width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px;';
        group.appendChild(lbl);
        group.appendChild(inp);
        return { group, inp };
    }

    const fileWrap = document.createElement('div');
    const fileLbl = document.createElement('label');
    fileLbl.htmlFor = 'rag-file-input';
    fileLbl.textContent = 'Text file (.txt)';
    fileLbl.style.cssText = 'display:block;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;';
    const fileInp = document.createElement('input');
    fileInp.type = 'file';
    fileInp.id = 'rag-file-input';
    fileInp.accept = '.txt,text/plain';
    fileInp.style.cssText = 'padding:6px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px;cursor:pointer;';
    fileWrap.appendChild(fileLbl);
    fileWrap.appendChild(fileInp);

    const { group: tagsGroup, inp: tagsInp } = ragField('Tags (comma-separated)', 'rag-tags-input', 'e.g. legal, faq, policy');
    tagsGroup.style.flex = '1';

    // Language select (optional — stored as lang:xx tag)
    const langGroup = document.createElement('div');
    const langUpLbl = document.createElement('label');
    langUpLbl.htmlFor    = 'rag-lang-select';
    langUpLbl.textContent = 'Language';
    langUpLbl.style.cssText = 'display:block;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;';
    const langUpSelect = document.createElement('select');
    langUpSelect.id = 'rag-lang-select';
    langUpSelect.style.cssText = 'padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px;';
    const noneOpt = document.createElement('option');
    noneOpt.value = '';
    noneOpt.textContent = '— any —';
    langUpSelect.appendChild(noneOpt);
    langGroup.appendChild(langUpLbl);
    langGroup.appendChild(langUpSelect);

    // Populate language dropdown from settings
    (async () => {
        try {
            const res  = await fetch('api.php?action=get_language_setting');
            const data = await res.json();
            (data.available_languages ?? []).forEach(code => {
                const opt = document.createElement('option');
                opt.value       = code;
                opt.textContent = code.toUpperCase();
                langUpSelect.appendChild(opt);
            });
        } catch (_) { /* optional */ }
    })();

    const uploadBtn = document.createElement('button');
    uploadBtn.type = 'button';
    uploadBtn.textContent = 'Upload';
    uploadBtn.style.cssText = 'padding:8px 20px;background:var(--accent);color:#fff;border:none;border-radius:4px;font-size:13px;cursor:pointer;font-weight:600;height:36px;align-self:flex-end;';

    uploadForm.appendChild(fileWrap);
    uploadForm.appendChild(tagsGroup);
    uploadForm.appendChild(langGroup);
    uploadForm.appendChild(uploadBtn);
    uploadBody.appendChild(uploadForm);

    // File list card
    const { card: listCard, body: listBody } = ragCard('Uploaded Documents', '');
    panel.appendChild(listCard);

    const tableWrap = document.createElement('div');
    tableWrap.style.cssText = 'overflow-x:auto;';
    listBody.appendChild(tableWrap);

    const table = document.createElement('table');
    table.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;';
    const thead = table.createTHead();
    const hdr   = thead.insertRow();
    ['Filename', 'Tags', 'Size', 'Uploaded', ''].forEach(col => {
        const th = document.createElement('th');
        th.textContent = col;
        th.style.cssText = 'text-align:left;padding:8px 12px;background:#f8fafc;border-bottom:1px solid var(--border);color:var(--muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;';
        hdr.appendChild(th);
    });
    const tbody = document.createElement('tbody');
    table.appendChild(tbody);
    tableWrap.appendChild(table);

    // Stats bar above table
    const statsBar = document.createElement('div');
    statsBar.style.cssText = 'font-size:12px;color:var(--muted);margin-bottom:12px;';
    listBody.insertBefore(statsBar, tableWrap);

    async function loadFiles() {
        try {
            const res  = await fetch('api.php?action=rag_list');
            const data = await res.json();
            renderFiles(data.files ?? []);
        } catch (e) {
            tbody.innerHTML = '';
            const row = tbody.insertRow();
            const td  = row.insertCell();
            td.colSpan    = 5;
            td.textContent = 'Failed to load: ' + e.message;
            td.style.cssText = 'padding:16px;color:var(--danger);text-align:center;';
        }
    }

    function renderFiles(files) {
        tbody.innerHTML = '';
        if (files.length === 0) {
            statsBar.textContent = '';
            const row = tbody.insertRow();
            const td  = row.insertCell();
            td.colSpan = 5;
            td.textContent = 'No documents uploaded yet.';
            td.style.cssText = 'padding:16px;color:var(--muted);text-align:center;font-style:italic;';
            return;
        }

        const totalSize = files.reduce((s, f) => s + (parseInt(f.file_size, 10) || 0), 0);
        statsBar.textContent = files.length + ' document' + (files.length !== 1 ? 's' : '') + ' · ' + ragFmtSize(totalSize) + ' total';

        files.forEach(file => {
            const row = tbody.insertRow();
            row.style.transition = 'background .15s';
            row.addEventListener('mouseover', () => { row.style.background = '#f8fafc'; });
            row.addEventListener('mouseout',  () => { row.style.background = ''; });

            const tdStyle = 'padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;';

            const td1 = row.insertCell();
            td1.style.cssText  = tdStyle + 'font-weight:500;';
            td1.textContent    = file.filename;

            const td2 = row.insertCell();
            td2.style.cssText  = tdStyle;
            const tags = ragParseTags(file.tags ?? '{}');
            if (tags.length > 0) {
                tags.forEach(tag => {
                    const chip = document.createElement('span');
                    chip.textContent = tag;
                    chip.style.cssText = 'display:inline-block;margin:0 3px 3px 0;padding:1px 8px;'
                        + 'background:var(--accent-light);border:1px solid var(--accent-mid);'
                        + 'border-radius:999px;font-size:11px;font-weight:600;color:var(--accent-dark);';
                    td2.appendChild(chip);
                });
            } else {
                td2.textContent = '—';
                td2.style.color = 'var(--muted)';
            }

            const td3 = row.insertCell();
            td3.style.cssText = tdStyle + 'color:var(--muted);';
            td3.textContent   = ragFmtSize(file.file_size);

            const td4 = row.insertCell();
            td4.style.cssText = tdStyle + 'color:var(--muted);font-size:12px;';
            td4.textContent   = ragFmtDate(file.created_at);

            const td5 = row.insertCell();
            td5.style.cssText = tdStyle;
            const delBtn = document.createElement('button');
            delBtn.type      = 'button';
            delBtn.textContent = 'Delete';
            delBtn.style.cssText = 'padding:4px 12px;background:transparent;color:var(--danger);border:1px solid var(--danger);border-radius:4px;font-size:12px;cursor:pointer;';
            delBtn.addEventListener('click', async () => {
                if (!confirm('Delete "' + file.filename + '"?')) return;
                delBtn.disabled = true;
                try {
                    const r = await fetch('api.php?action=rag_delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ragCsrf() },
                        body: JSON.stringify({ id: file.id }),
                    });
                    const d = await r.json();
                    if (d.status === 'success') {
                        await loadFiles();
                    } else {
                        alert('Delete failed: ' + (d.error ?? 'Unknown error'));
                        delBtn.disabled = false;
                    }
                } catch (e) {
                    alert('Request failed: ' + e.message);
                    delBtn.disabled = false;
                }
            });
            td5.appendChild(delBtn);
        });
    }

    uploadBtn.addEventListener('click', async () => {
        const file = fileInp.files?.[0];
        if (!file) { ragStatusPill(uploadBtn, 'Select a .txt file first.', 'error'); return; }
        if (!file.name.toLowerCase().endsWith('.txt')) { ragStatusPill(uploadBtn, 'Only .txt files allowed.', 'error'); return; }

        uploadBtn.disabled = true;
        const tags = tagsInp.value.split(',').map(t => t.trim()).filter(t => t !== '');
        if (langUpSelect.value) tags.push('lang:' + langUpSelect.value);
        const fd   = new FormData();
        fd.append('file', file);
        fd.append('tags', JSON.stringify(tags));

        try {
            const res  = await fetch('api.php?action=rag_upload', {
                method: 'POST',
                headers: { 'X-CSRF-Token': ragCsrf() },
                body: fd,
            });
            const data = await res.json();
            if (data.status === 'success') {
                ragStatusPill(uploadBtn, 'Uploaded.', 'success');
                fileInp.value = '';
                tagsInp.value = '';
                await loadFiles();
            } else {
                ragStatusPill(uploadBtn, data.error ?? 'Upload failed.', 'error');
            }
        } catch (e) {
            ragStatusPill(uploadBtn, 'Request failed: ' + e.message, 'error');
        } finally {
            uploadBtn.disabled = false;
        }
    });

    loadFiles();
}

// ── Settings tab ─────────────────────────────────────────────────────────────

function ragFmtModelSize(bytes) {
    bytes = parseInt(bytes, 10) || 0;
    if (bytes === 0) return '';
    const gb = bytes / 1073741824;
    return gb >= 1 ? gb.toFixed(1) + ' GB' : (bytes / 1048576).toFixed(0) + ' MB';
}

function ragBuildSettingsTab(panel) {

    // ── Connection card ──────────────────────────────────────────────────────
    const { card: connCard, body: connBody } = ragCard(
        'Ollama Connection',
        'Configure the local Ollama instance. Run "ollama serve" to start it.'
    );
    panel.appendChild(connCard);

    // URL row with inline "Check & load models" button
    const urlLbl = document.createElement('label');
    urlLbl.htmlFor = 'rag-ollama-url';
    urlLbl.textContent = 'Ollama URL';
    urlLbl.style.cssText = 'display:block;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;';

    const urlRow = document.createElement('div');
    urlRow.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:16px;';

    const urlInp = document.createElement('input');
    urlInp.type        = 'text';
    urlInp.id          = 'rag-ollama-url';
    urlInp.placeholder = 'http://localhost:11434';
    urlInp.style.cssText = 'flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px;';

    const checkBtn = document.createElement('button');
    checkBtn.type      = 'button';
    checkBtn.textContent = 'Test & load models';
    checkBtn.style.cssText = 'white-space:nowrap;padding:8px 14px;background:transparent;color:var(--accent);border:1px solid var(--accent);border-radius:4px;font-size:13px;cursor:pointer;font-weight:600;flex-shrink:0;';

    urlRow.appendChild(urlInp);
    urlRow.appendChild(checkBtn);
    connBody.appendChild(urlLbl);
    connBody.appendChild(urlRow);

    // Status badge line
    const statusLine = document.createElement('div');
    statusLine.style.cssText = 'display:none;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;';
    connBody.appendChild(statusLine);

    // Model row — select (populated after check) + manual fallback input
    const modelLbl = document.createElement('label');
    modelLbl.htmlFor = 'rag-model-select';
    modelLbl.textContent = 'Model';
    modelLbl.style.cssText = 'display:block;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;';

    const modelRow = document.createElement('div');
    modelRow.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:16px;';

    const modelSelect = document.createElement('select');
    modelSelect.id = 'rag-model-select';
    modelSelect.style.cssText = 'flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px;';
    const placeholderOpt = document.createElement('option');
    placeholderOpt.value       = '';
    placeholderOpt.textContent = '— click "Test & load models" to populate —';
    modelSelect.appendChild(placeholderOpt);

    const modelManualInp = document.createElement('input');
    modelManualInp.type        = 'text';
    modelManualInp.id          = 'rag-model-manual';
    modelManualInp.placeholder = 'or type model name manually';
    modelManualInp.style.cssText = 'flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px;display:none;';

    const toggleManualBtn = document.createElement('button');
    toggleManualBtn.type = 'button';
    toggleManualBtn.textContent = 'Type manually';
    toggleManualBtn.style.cssText = 'white-space:nowrap;padding:7px 12px;background:transparent;color:var(--muted);border:1px solid var(--border);border-radius:4px;font-size:12px;cursor:pointer;flex-shrink:0;';

    let manualMode = false;
    toggleManualBtn.addEventListener('click', () => {
        manualMode = !manualMode;
        modelSelect.style.display      = manualMode ? 'none' : '';
        modelManualInp.style.display   = manualMode ? '' : 'none';
        toggleManualBtn.textContent    = manualMode ? 'Use dropdown' : 'Type manually';
    });

    modelRow.appendChild(modelSelect);
    modelRow.appendChild(modelManualInp);
    modelRow.appendChild(toggleManualBtn);
    connBody.appendChild(modelLbl);
    connBody.appendChild(modelRow);

    // Models detail table (hidden until check runs)
    const modelsTable = document.createElement('div');
    modelsTable.style.display = 'none';
    modelsTable.style.marginBottom = '16px';
    connBody.appendChild(modelsTable);

    // Other settings grid
    function ragField(label, id, placeholder) {
        const group = document.createElement('div');
        const lbl = document.createElement('label');
        lbl.htmlFor = id;
        lbl.textContent = label;
        lbl.style.cssText = 'display:block;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;';
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.id = id;
        inp.placeholder = placeholder;
        inp.style.cssText = 'width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px;';
        group.appendChild(lbl);
        group.appendChild(inp);
        return { group, inp };
    }

    const otherGrid = document.createElement('div');
    otherGrid.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;';
    const { group: ctxGroup,     inp: ctxInp }     = ragField('Max context files', 'rag-max-ctx', '3');
    const { group: sizeGroup,    inp: sizeInp }    = ragField('Max file size (MB)', 'rag-max-size', '10');
    const { group: timeoutGroup, inp: timeoutInp } = ragField('Ollama timeout (s)', 'rag-timeout', '120');
    otherGrid.appendChild(ctxGroup);
    otherGrid.appendChild(sizeGroup);
    otherGrid.appendChild(timeoutGroup);
    connBody.appendChild(otherGrid);

    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.textContent = 'Save Settings';
    saveBtn.style.cssText = 'padding:8px 20px;background:var(--accent);color:#fff;border:none;border-radius:4px;font-size:13px;cursor:pointer;font-weight:600;';
    connBody.appendChild(saveBtn);

    const pullHint = document.createElement('p');
    pullHint.style.cssText = 'margin:14px 0 0;font-size:12px;color:var(--muted);';
    pullHint.innerHTML = 'Pull a model: <code>ollama pull llama3</code> &nbsp;|&nbsp; '
        + 'Popular: <code>llama3</code>, <code>mistral</code>, <code>gemma3</code>, <code>phi3</code>, <code>qwen2.5</code>';
    connBody.appendChild(pullHint);

    // ── Helper: populate model select ────────────────────────────────────────

    function populateModelSelect(models, currentModel) {
        // Clear all but placeholder
        while (modelSelect.options.length > 1) modelSelect.remove(1);

        models.forEach(m => {
            const opt = document.createElement('option');
            opt.value       = m.name;
            opt.textContent = m.name + (m.size ? '  (' + ragFmtModelSize(m.size) + ')' : '');
            modelSelect.appendChild(opt);
        });

        // Select current model if present
        if (currentModel) {
            for (const opt of modelSelect.options) {
                if (opt.value === currentModel) { opt.selected = true; break; }
            }
            // If not found, add it
            if (!modelSelect.value) {
                const opt = document.createElement('option');
                opt.value = currentModel;
                opt.textContent = currentModel + '  (not pulled locally)';
                modelSelect.insertBefore(opt, modelSelect.options[1]);
                opt.selected = true;
            }
        }
    }

    // ── Helper: render models table ──────────────────────────────────────────

    function renderModelsTable(models, version) {
        modelsTable.innerHTML = '';
        modelsTable.style.display = '';

        const hdr = document.createElement('div');
        hdr.style.cssText = 'font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;';
        hdr.textContent = 'Available local models' + (version ? ' · Ollama ' + version : '');
        modelsTable.appendChild(hdr);

        if (models.length === 0) {
            const none = document.createElement('div');
            none.style.cssText = 'font-size:13px;color:var(--muted);font-style:italic;padding:8px 0;';
            none.textContent = 'No models found. Pull one with: ollama pull llama3';
            modelsTable.appendChild(none);
            return;
        }

        const tbl = document.createElement('table');
        tbl.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;';

        const thead = tbl.createTHead();
        const hr    = thead.insertRow();
        ['Model name', 'Size', 'Modified'].forEach(col => {
            const th = document.createElement('th');
            th.textContent = col;
            th.style.cssText = 'text-align:left;padding:6px 10px;background:#f8fafc;border-bottom:1px solid var(--border);color:var(--muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;';
            hr.appendChild(th);
        });

        const tbody = tbl.createTBody();
        models.forEach(m => {
            const row = tbody.insertRow();
            const tdStyle = 'padding:8px 10px;border-bottom:1px solid #f1f5f9;';

            const td1 = row.insertCell();
            td1.style.cssText = tdStyle + 'font-weight:500;';
            td1.textContent   = m.name;

            const td2 = row.insertCell();
            td2.style.cssText = tdStyle + 'color:var(--muted);';
            td2.textContent   = ragFmtModelSize(m.size) || '—';

            const td3 = row.insertCell();
            td3.style.cssText = tdStyle + 'color:var(--muted);font-size:12px;';
            td3.textContent   = m.modified ? new Date(m.modified).toLocaleDateString() : '—';

            // Click to select
            row.style.cursor = 'pointer';
            row.title        = 'Click to select this model';
            row.addEventListener('mouseover', () => { row.style.background = 'var(--accent-light)'; });
            row.addEventListener('mouseout',  () => { row.style.background = ''; });
            row.addEventListener('click', () => {
                for (const opt of modelSelect.options) {
                    if (opt.value === m.name) { opt.selected = true; break; }
                }
                if (manualMode) {
                    modelManualInp.value = m.name;
                }
            });
        });

        tbl.appendChild(tbody);
        modelsTable.appendChild(tbl);
    }

    // ── Check connection handler ─────────────────────────────────────────────

    async function doCheck() {
        const url = urlInp.value.trim();
        if (!url) { urlInp.focus(); return; }

        checkBtn.disabled    = true;
        checkBtn.textContent = 'Connecting…';
        statusLine.style.display = 'block';
        statusLine.style.cssText = 'display:block;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;background:#fef3c7;color:#92400e;border:1px solid #fbbf24;';
        statusLine.textContent   = 'Connecting to ' + url + '…';

        try {
            const res  = await fetch('api.php?action=rag_ollama_check', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ragCsrf() },
                body: JSON.stringify({ ollama_url: url }),
            });
            const data = await res.json();

            if (data.status === 'success') {
                const n = (data.models ?? []).length;
                statusLine.style.cssText = 'display:block;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;background:#dcfce7;color:#166534;border:1px solid #86efac;';
                statusLine.textContent   = '✓ Connected · ' + n + ' model' + (n !== 1 ? 's' : '') + ' available'
                    + (data.version ? ' · Ollama ' + data.version : '');

                const currentModel = manualMode ? modelManualInp.value.trim() : (modelSelect.value || '');
                populateModelSelect(data.models ?? [], currentModel);
                renderModelsTable(data.models ?? [], data.version ?? '');
            } else {
                statusLine.style.cssText = 'display:block;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;';
                statusLine.textContent   = '✗ ' + (data.error ?? 'Connection failed');
                modelsTable.style.display = 'none';
            }
        } catch (e) {
            statusLine.style.cssText = 'display:block;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;';
            statusLine.textContent   = '✗ Request failed: ' + e.message;
            modelsTable.style.display = 'none';
        } finally {
            checkBtn.disabled    = false;
            checkBtn.textContent = 'Test & load models';
        }
    }

    checkBtn.addEventListener('click', doCheck);

    // ── Load saved settings ──────────────────────────────────────────────────

    (async () => {
        try {
            const res  = await fetch('api.php?action=rag_settings');
            const data = await res.json();
            if (data.status === 'success' && data.settings) {
                const s = data.settings;
                if (s.ollama_url)        urlInp.value         = s.ollama_url;
                if (s.ollama_model)      modelManualInp.value = s.ollama_model;
                if (s.max_context_files) ctxInp.value         = s.max_context_files;
                if (s.max_file_size_mb)  sizeInp.value        = s.max_file_size_mb;
                if (s.ollama_timeout)    timeoutInp.value     = s.ollama_timeout;

                // Pre-populate select with saved model as single option
                if (s.ollama_model) {
                    const opt = document.createElement('option');
                    opt.value = s.ollama_model;
                    opt.textContent = s.ollama_model + '  (saved)';
                    opt.selected = true;
                    modelSelect.insertBefore(opt, modelSelect.options[1] ?? null);
                }
            }
        } catch (_) { /* use defaults */ }
    })();

    // ── Save ─────────────────────────────────────────────────────────────────

    saveBtn.addEventListener('click', async () => {
        const modelValue = manualMode
            ? modelManualInp.value.trim()
            : (modelSelect.value.trim());

        const payload = {
            ollama_url:        urlInp.value.trim(),
            ollama_model:      modelValue,
            max_context_files: parseInt(ctxInp.value, 10) || 3,
            max_file_size_mb:  parseInt(sizeInp.value, 10) || 10,
            ollama_timeout:    parseInt(timeoutInp.value, 10) || 120,
        };
        if (!payload.ollama_url || !payload.ollama_model) {
            ragStatusPill(saveBtn, 'URL and model are required.', 'error');
            return;
        }
        try {
            const res  = await fetch('api.php?action=rag_settings_save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ragCsrf() },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            ragStatusPill(saveBtn, data.status === 'success' ? 'Saved.' : (data.error ?? 'Error.'), data.status === 'success' ? 'success' : 'error');
        } catch (e) {
            ragStatusPill(saveBtn, 'Request failed: ' + e.message, 'error');
        }
    });
}

// ── Test tab ─────────────────────────────────────────────────────────────────

function ragBuildTestTab(panel) {
    const { card: testCard, body: testBody } = ragCard(
        'Test Query',
        'Send a question to verify retrieval and Ollama response. Optionally filter by tag.'
    );
    panel.appendChild(testCard);

    // Tag filter row
    const tagRow = document.createElement('div');
    tagRow.style.cssText = 'margin-bottom:14px;';
    const tagLbl = document.createElement('div');
    tagLbl.textContent = 'Filter by tag (optional):';
    tagLbl.style.cssText = 'font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;';
    tagRow.appendChild(tagLbl);
    const tagChips = document.createElement('div');
    tagChips.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;min-height:28px;';
    tagChips.innerHTML = '<span style="font-size:12px;color:var(--muted);font-style:italic;">Loading tags…</span>';
    tagRow.appendChild(tagChips);
    testBody.appendChild(tagRow);

    // Load available tags
    (async () => {
        try {
            const res  = await fetch('../api_rag.php?action=tags');
            const data = await res.json();
            tagChips.innerHTML = '';
            const tags = data.tags ?? [];
            if (tags.length === 0) {
                tagChips.innerHTML = '<span style="font-size:12px;color:var(--muted);font-style:italic;">No tags yet.</span>';
            } else {
                tags.forEach(tag => {
                    const lbl = document.createElement('label');
                    lbl.style.cssText = 'display:flex;align-items:center;gap:5px;padding:3px 10px;border:1px solid var(--border);border-radius:999px;font-size:12px;cursor:pointer;background:#fff;';
                    const cb = document.createElement('input');
                    cb.type  = 'checkbox';
                    cb.value = tag;
                    cb.style.accentColor = 'var(--accent)';
                    lbl.appendChild(cb);
                    lbl.appendChild(document.createTextNode(tag));
                    tagChips.appendChild(lbl);
                });
            }
        } catch (_) {
            tagChips.innerHTML = '<span style="font-size:12px;color:var(--danger);">Could not load tags.</span>';
        }
    })();

    // Language hint row
    const langRow = document.createElement('div');
    langRow.style.cssText = 'margin-bottom:14px;';
    const langLbl = document.createElement('div');
    langLbl.textContent = 'Response language (optional):';
    langLbl.style.cssText = 'font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;';
    langRow.appendChild(langLbl);
    const langSelect = document.createElement('select');
    langSelect.style.cssText = 'padding:6px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px;min-width:180px;';
    const langNoneOpt = document.createElement('option');
    langNoneOpt.value = '';
    langNoneOpt.textContent = '— auto-detect —';
    langSelect.appendChild(langNoneOpt);
    langRow.appendChild(langSelect);
    testBody.appendChild(langRow);

    // Populate language dropdown from settings
    (async () => {
        try {
            const res  = await fetch('api.php?action=get_language_setting');
            const data = await res.json();
            const available = data.available_languages ?? [];
            const current   = document.documentElement.lang || '';
            available.forEach(code => {
                const opt = document.createElement('option');
                opt.value       = code;
                opt.textContent = code.toUpperCase();
                if (code === current) opt.selected = true;
                langSelect.appendChild(opt);
            });
        } catch (_) { /* language hint is optional */ }
    })();

    // Query input
    const queryRow = document.createElement('div');
    queryRow.style.cssText = 'display:flex;gap:10px;margin-bottom:16px;';
    const queryInp = document.createElement('input');
    queryInp.type = 'text';
    queryInp.placeholder = 'Enter test question…';
    queryInp.style.cssText = 'flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px;';
    const runBtn = document.createElement('button');
    runBtn.type = 'button';
    runBtn.textContent = 'Run';
    runBtn.style.cssText = 'padding:8px 20px;background:var(--accent);color:#fff;border:none;border-radius:4px;font-size:13px;cursor:pointer;font-weight:600;';
    queryRow.appendChild(queryInp);
    queryRow.appendChild(runBtn);
    testBody.appendChild(queryRow);

    // Result area
    const resultWrap = document.createElement('div');
    resultWrap.style.display = 'none';
    testBody.appendChild(resultWrap);

    const answerLabel = document.createElement('div');
    answerLabel.textContent = 'Answer';
    answerLabel.style.cssText = 'font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;';

    const answerBox = document.createElement('div');
    answerBox.style.cssText = 'padding:14px;background:#f8fafc;border:1px solid var(--border);border-radius:4px;font-size:13px;line-height:1.7;white-space:pre-wrap;word-break:break-word;max-height:320px;overflow-y:auto;margin-bottom:12px;';

    const sourcesLabel = document.createElement('div');
    sourcesLabel.textContent = 'Sources used';
    sourcesLabel.style.cssText = 'font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;';

    const sourcesRow = document.createElement('div');
    sourcesRow.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;';

    resultWrap.appendChild(answerLabel);
    resultWrap.appendChild(answerBox);
    resultWrap.appendChild(sourcesLabel);
    resultWrap.appendChild(sourcesRow);

    async function runQuery() {
        const q = queryInp.value.trim();
        if (!q) return;

        const tags     = Array.from(tagChips.querySelectorAll('input[type=checkbox]:checked')).map(c => c.value);
        const language = langSelect.value;

        runBtn.disabled = true;
        resultWrap.style.display = '';
        answerBox.textContent    = 'Querying…';
        answerBox.style.color    = 'var(--muted)';
        sourcesRow.innerHTML     = '';

        try {
            const res  = await fetch('api.php?action=rag_test_query', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ragCsrf() },
                body: JSON.stringify({ query: q, tags, language }),
            });
            const data = await res.json();
            if (data.status === 'success') {
                answerBox.textContent = data.answer ?? '(empty response)';
                answerBox.style.color = 'var(--text)';
                sourcesRow.innerHTML  = '';
                const srcs = data.sources ?? [];
                if (srcs.length === 0) {
                    const none = document.createElement('span');
                    none.textContent  = 'No documents matched — answered from model knowledge.';
                    none.style.cssText = 'font-size:12px;color:var(--muted);font-style:italic;';
                    sourcesRow.appendChild(none);
                } else {
                    srcs.forEach(s => {
                        const chip = document.createElement('span');
                        chip.textContent = s.filename;
                        chip.style.cssText = 'padding:2px 10px;background:var(--accent-light);border:1px solid var(--accent-mid);border-radius:999px;font-size:12px;font-weight:600;color:var(--accent-dark);';
                        sourcesRow.appendChild(chip);
                    });
                }
            } else {
                answerBox.textContent = 'Error: ' + (data.error ?? 'Unknown error');
                answerBox.style.color = 'var(--danger)';
            }
        } catch (e) {
            answerBox.textContent = 'Request failed: ' + e.message;
            answerBox.style.color = 'var(--danger)';
        } finally {
            runBtn.disabled = false;
        }
    }

    runBtn.addEventListener('click', runQuery);
    queryInp.addEventListener('keydown', e => { if (e.key === 'Enter') runQuery(); });

    // Prompt preview card
    const { card: promptCard, body: promptBody } = ragCard(
        'Prompt Preview',
        'Shows the exact prompt sent to Ollama for the last query — useful for debugging context quality.'
    );
    panel.appendChild(promptCard);

    const promptBox = document.createElement('pre');
    promptBox.style.cssText = 'margin:0;font-size:12px;line-height:1.6;white-space:pre-wrap;word-break:break-word;color:var(--muted);font-style:italic;';
    promptBox.textContent = 'Run a query above to see the prompt.';
    promptBody.appendChild(promptBox);

    // Intercept to also show prompt
    const origRun = runBtn.onclick;
    runBtn.addEventListener('click', async () => {
        const q = queryInp.value.trim();
        if (!q) return;
        const tags     = Array.from(tagChips.querySelectorAll('input[type=checkbox]:checked')).map(c => c.value);
        const language = langSelect.value;
        try {
            const res  = await fetch('api.php?action=rag_test_query', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ragCsrf() },
                body: JSON.stringify({ query: q, tags, language, return_prompt: true }),
            });
            const data = await res.json();
            if (data.prompt) {
                promptBox.textContent = data.prompt;
                promptBox.style.color = 'var(--text)';
            }
        } catch (_) { /* prompt preview optional */ }
    });
}

// ── Statistics tab ───────────────────────────────────────────────────────────

function ragBuildStatsTab(panel) {
    const { card: summaryCard, body: summaryBody } = ragCard(
        'Query Statistics',
        'Aggregated metrics from all RAG queries processed by Ollama.'
    );
    panel.appendChild(summaryCard);

    const cardsGrid = document.createElement('div');
    cardsGrid.style.cssText = 'display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:4px;';
    summaryBody.appendChild(cardsGrid);

    function statCard(label, valueEl) {
        const box = document.createElement('div');
        box.style.cssText = 'text-align:center;padding:16px 10px;border:1px solid var(--border);border-radius:8px;background:#f8fafc;';
        const v = document.createElement('div');
        v.style.cssText = 'font-size:28px;font-weight:700;color:var(--accent);margin-bottom:4px;';
        v.textContent = '—';
        const l = document.createElement('div');
        l.style.cssText = 'font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;';
        l.textContent = label;
        box.appendChild(v);
        box.appendChild(l);
        cardsGrid.appendChild(box);
        return v;
    }

    const vTotal   = statCard('Total Queries');
    const vAvgMs   = statCard('Avg Response (s)');
    const vAvgPt   = statCard('Avg Prompt Tokens');
    const vAvgCt   = statCard('Avg Completion Tokens');

    const { card: recentCard, body: recentBody } = ragCard(
        'Recent Queries',
        'Last 50 queries, newest first.'
    );
    panel.appendChild(recentCard);

    const tableWrap = document.createElement('div');
    tableWrap.style.cssText = 'overflow-x:auto;';
    recentBody.appendChild(tableWrap);

    const tbl   = document.createElement('table');
    tbl.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;';
    const thead = tbl.createTHead();
    const hr    = thead.insertRow();
    ['Time', 'Query', 'Tags', 'Files', 'Model', 'Prompt T', 'Comp T', 'Time (s)'].forEach(col => {
        const th = document.createElement('th');
        th.textContent = col;
        th.style.cssText = 'text-align:left;padding:8px 10px;background:#f8fafc;border-bottom:1px solid var(--border);color:var(--muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;';
        hr.appendChild(th);
    });
    const tbody = tbl.createTBody();
    tbl.appendChild(tbody);
    tableWrap.appendChild(tbl);

    const refreshBtn = document.createElement('button');
    refreshBtn.type = 'button';
    refreshBtn.textContent = 'Refresh';
    refreshBtn.style.cssText = 'margin-top:14px;padding:7px 16px;background:transparent;color:var(--accent);border:1px solid var(--accent);border-radius:4px;font-size:12px;cursor:pointer;font-weight:600;';
    recentBody.appendChild(refreshBtn);

    async function load() {
        refreshBtn.disabled = true;
        try {
            const res  = await fetch('api.php?action=rag_stats');
            const data = await res.json();
            if (data.status !== 'success') {
                throw new Error(data.error ?? 'Load failed.');
            }
            const s = data.summary ?? {};
            vTotal.textContent  = s.total_queries ?? '0';
            vAvgMs.textContent  = s.avg_ms ? (parseInt(s.avg_ms, 10) / 1000).toFixed(2) + 's' : '0s';
            vAvgPt.textContent  = s.avg_prompt_tokens ? (parseInt(s.avg_prompt_tokens, 10) / 1000).toFixed(1) + 'k' : '0';
            vAvgCt.textContent  = s.avg_completion_tokens ? (parseInt(s.avg_completion_tokens, 10) / 1000).toFixed(1) + 'k' : '0';

            tbody.innerHTML = '';
            const rows = data.recent ?? [];
            if (rows.length === 0) {
                const row = tbody.insertRow();
                const td  = row.insertCell();
                td.colSpan = 8;
                td.textContent = 'No queries recorded yet.';
                td.style.cssText = 'padding:16px;color:var(--muted);text-align:center;font-style:italic;';
                return;
            }
            const tdStyle = 'padding:8px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;';
            rows.forEach(r => {
                const row = tbody.insertRow();
                row.addEventListener('mouseover', () => { row.style.background = '#f8fafc'; });
                row.addEventListener('mouseout',  () => { row.style.background = ''; });

                const td1 = row.insertCell();
                td1.style.cssText = tdStyle + 'font-size:12px;color:var(--muted);white-space:nowrap;';
                td1.textContent   = ragFmtDate(r.created_at);

                const td2 = row.insertCell();
                td2.style.cssText = tdStyle + 'max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
                td2.title         = r.query;
                td2.textContent   = r.query.length > 80 ? r.query.slice(0, 80) + '…' : r.query;

                const td3 = row.insertCell();
                td3.style.cssText = tdStyle;
                const tags = ragParseTags(r.tags ?? '{}');
                if (tags.length > 0) {
                    tags.forEach(tag => {
                        const chip = document.createElement('span');
                        chip.textContent = tag;
                        chip.style.cssText = 'display:inline-block;margin:0 2px 2px 0;padding:1px 7px;background:var(--accent-light);border:1px solid var(--accent-mid);border-radius:999px;font-size:11px;font-weight:600;color:var(--accent-dark);white-space:nowrap;';
                        td3.appendChild(chip);
                    });
                } else {
                    td3.textContent = '—';
                    td3.style.color = 'var(--muted)';
                }

                const td4 = row.insertCell();
                td4.style.cssText = tdStyle + 'text-align:center;color:var(--muted);';
                td4.textContent   = r.matched_files;

                const td5 = row.insertCell();
                td5.style.cssText = tdStyle + 'font-size:12px;color:var(--muted);white-space:nowrap;';
                td5.textContent   = r.model || '—';

                const td6 = row.insertCell();
                td6.style.cssText = tdStyle + 'text-align:right;color:var(--muted);';
                td6.textContent   = r.prompt_tokens ? (parseInt(r.prompt_tokens, 10) / 1000).toFixed(1) + 'k' : '0';

                const td7 = row.insertCell();
                td7.style.cssText = tdStyle + 'text-align:right;color:var(--muted);';
                td7.textContent   = r.completion_tokens ? (parseInt(r.completion_tokens, 10) / 1000).toFixed(1) + 'k' : '0';

                const td8 = row.insertCell();
                td8.style.cssText = tdStyle + 'text-align:right;font-weight:600;';
                td8.textContent   = r.total_ms ? (parseInt(r.total_ms, 10) / 1000).toFixed(2) + 's' : '0s';
            });
        } catch (e) {
            tbody.innerHTML = '';
            const row = tbody.insertRow();
            const td  = row.insertCell();
            td.colSpan = 8;
            td.textContent = 'Failed to load: ' + e.message;
            td.style.cssText = 'padding:16px;color:var(--danger);text-align:center;';
        } finally {
            refreshBtn.disabled = false;
        }
    }

    refreshBtn.addEventListener('click', load);
    load();
}

// ── Main export ───────────────────────────────────────────────────────────────

export async function renderRagPage(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.style.cssText = 'max-width:960px;padding-bottom:60px;';

    const heading = document.createElement('h2');
    heading.textContent = 'Centrum AI';
    heading.style.cssText = 'margin-top:0;margin-bottom:4px;';

    const intro = document.createElement('p');
    intro.textContent = 'Upload .txt documents, configure Ollama, and test retrieval-augmented queries.';
    intro.style.cssText = 'color:var(--muted);margin-bottom:24px;font-size:14px;';

    wrap.appendChild(heading);
    wrap.appendChild(intro);
    workspaceEl.appendChild(wrap);

    const tabDefs = [
        { id: 'documents',  label: 'Documents',   icon: 'docs.png' },
        { id: 'settings',   label: 'Settings',    icon: 'build.png' },
        { id: 'test',       label: 'Test',         icon: 'playground.png' },
        { id: 'statistics', label: 'Statistics',   icon: 'dashboard.png' },
    ];

    const { panels } = ragBuildTabs(wrap, tabDefs);

    ragBuildDocumentsTab(panels.documents);
    ragBuildSettingsTab(panels.settings);
    ragBuildTestTab(panels.test);
    ragBuildStatsTab(panels.statistics);
}
