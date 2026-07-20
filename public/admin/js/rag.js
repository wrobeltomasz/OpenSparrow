// admin/js/rag.js — RAG knowledge base management page
// Upload/list/delete/rechunk documents, edit settings, test query and Ollama check via api.php (rag_* actions). CSRF via apiFetch().

import { apiFetch } from '../../assets/js/util/api.js';
import { escHtml as ragEsc } from '../../assets/js/util/esc.js';

function ragCard(title, desc) {
    const card = document.createElement('div');
    card.className = 'adm-sec-card';

    const hdr = document.createElement('div');
    hdr.className = 'adm-sec-hdr';
    hdr.style.display = 'block';

    const h3 = document.createElement('h3');
    h3.textContent = title;
    h3.style.cssText = 'margin:0 0 4px;';
    hdr.appendChild(h3);

    if (desc) {
        const p = document.createElement('p');
        p.textContent = desc;
        p.style.cssText = 'margin:0;';
        hdr.appendChild(p);
    }
    card.appendChild(hdr);

    const body = document.createElement('div');
    body.className = 'adm-sec-body';
    card.appendChild(body);

    return { card, body };
}

function ragStatusPill(anchor, msg, type = 'success') {
    const prev = anchor.parentNode?.querySelector('.rag-status-pill');
    if (prev) prev.remove();
    const colors = {
        success: { bg: 'rgba(43,147,72,0.12)', fg: '#2b9348', border: '#64748B' },
        error:   { bg: 'rgba(208,0,0,0.08)', fg: '#a80000', border: '#d00000' },
        info:    { bg: '#DDEAF4', fg: '#1E293B', border: '#DDEAF4' },
    }[type] ?? { bg: '#DDEAF4', fg: '#1E293B', border: '#DDEAF4' };
    const pill = document.createElement('span');
    pill.className = 'rag-status-pill';
    pill.textContent = msg;
    pill.style.cssText = `display:inline-flex;align-items:center;gap:6px;margin-left:10px;padding:4px 10px;`
        + `background:${colors.bg};color:${colors.fg};border:1px solid ${colors.border};`
        + `border-radius:999px;font-weight:600;transition:opacity .3s;`;
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
    bar.className = 'item-panel-items';

    const panels = {};
    const btns   = {};

    tabs.forEach(({ id, label, icon }) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'item-btn';
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
        lbl.className = 'adm-field-label';
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.id = id;
        inp.placeholder = placeholder;
        inp.className = 'adm-input w-full';
        group.appendChild(lbl);
        group.appendChild(inp);
        return { group, inp };
    }

    const fileWrap = document.createElement('div');
    const fileLbl = document.createElement('label');
    fileLbl.htmlFor = 'rag-file-input';
    fileLbl.textContent = 'Text file (.txt)';
    fileLbl.className = 'adm-field-label';
    const fileInp = document.createElement('input');
    fileInp.type = 'file';
    fileInp.id = 'rag-file-input';
    fileInp.accept = '.txt,text/plain';
    fileInp.className = 'adm-input';
    fileWrap.appendChild(fileLbl);
    fileWrap.appendChild(fileInp);

    const { group: tagsGroup, inp: tagsInp } = ragField('Tags (comma-separated)', 'rag-tags-input', 'e.g. legal, faq, policy');
    tagsGroup.style.flex = '1';

    // Language select (optional — stored as lang:xx tag)
    const langGroup = document.createElement('div');
    const langUpLbl = document.createElement('label');
    langUpLbl.htmlFor    = 'rag-lang-select';
    langUpLbl.textContent = 'Language';
    langUpLbl.className = 'adm-field-label';
    const langUpSelect = document.createElement('select');
    langUpSelect.id = 'rag-lang-select';
    langUpSelect.className = 'adm-input';
    const noneOpt = document.createElement('option');
    noneOpt.value = '';
    noneOpt.textContent = '— any —';
    langUpSelect.appendChild(noneOpt);
    langGroup.appendChild(langUpLbl);
    langGroup.appendChild(langUpSelect);

    // Populate language dropdown from settings
    (async () => {
        try {
            const res  = await apiFetch('api.php?action=get_language_setting');
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
    uploadBtn.className = 'btn btn-primary';
    uploadBtn.style.alignSelf = 'flex-end';

    uploadForm.appendChild(fileWrap);
    uploadForm.appendChild(tagsGroup);
    uploadForm.appendChild(langGroup);
    uploadForm.appendChild(uploadBtn);
    uploadBody.appendChild(uploadForm);

    // Preparation guidelines (collapsible)
    const guideWrap = document.createElement('div');
    guideWrap.style.cssText = 'margin-top:16px;border-top:1px solid var(--border);padding-top:12px;';

    const guideHdr = document.createElement('div');
    guideHdr.style.cssText = 'display:inline-flex;align-items:center;gap:6px;cursor:pointer;user-select:none;';
    const guideLbl = document.createElement('span');
    guideLbl.style.cssText = 'font-weight:700;';
    guideLbl.textContent = 'Document preparation guidelines';
    const guideArrow = document.createElement('span');
    guideArrow.textContent = '▾';
    guideArrow.style.cssText = 'display:inline-block;transition:transform .15s;';
    guideHdr.appendChild(guideLbl);
    guideHdr.appendChild(guideArrow);

    const guideBody = document.createElement('div');
    guideBody.style.display = 'none';
    guideBody.style.marginTop = '12px';

    const guideSections = [
        {
            title: 'Format',
            items: [
                '.txt files only — convert PDF or Word to plain text before uploading.',
                'Encoding: UTF-8 without BOM. Files in other encodings will be rejected.',
                'Maximum size: 10 MB by default (configurable in Settings).',
            ],
        },
        {
            title: 'Structure',
            items: [
                'Separate topics with a blank line — this is the primary chunk boundary used by the splitter.',
                'Keep paragraphs under ~900 characters so one topic stays in one chunk.',
                'End every sentence with . ! or ? — required for the sentence splitter to find split points.',
            ],
        },
        {
            title: 'Content',
            items: [
                'Use the exact words users will search for. Search has no stemming — "invoices" does not match "invoice".',
                'Avoid bullet lists without full sentences. Items without end punctuation are treated as one run-on block.',
                'Avoid tables formatted with spaces or pipes — they produce noisy retrieval results.',
                'One language per file. Use the Language field at upload for non-English content.',
            ],
        },
        {
            title: 'Tags',
            items: [
                'Assign at least one category tag (e.g. legal, faq, pricing, technical).',
                'Tags let users filter queries to a specific topic area for more precise answers.',
            ],
        },
    ];

    guideSections.forEach(sec => {
        const secWrap = document.createElement('div');
        secWrap.style.cssText = 'margin-bottom:10px;';
        const secTitle = document.createElement('div');
        secTitle.style.cssText = 'font-weight:700;margin-bottom:4px;';
        secTitle.textContent = sec.title;
        secWrap.appendChild(secTitle);
        sec.items.forEach(item => {
            const line = document.createElement('div');
            line.style.cssText = 'color:var(--text);line-height:1.65;padding-left:10px;';
            line.textContent = '– ' + item;
            secWrap.appendChild(line);
        });
        guideBody.appendChild(secWrap);
    });

    guideHdr.addEventListener('click', () => {
        const open = guideBody.style.display !== 'none';
        guideBody.style.display = open ? 'none' : '';
        guideArrow.style.transform = open ? '' : 'rotate(-90deg)';
    });

    guideWrap.appendChild(guideHdr);
    guideWrap.appendChild(guideBody);
    uploadBody.appendChild(guideWrap);

    // File list card
    const { card: listCard, body: listBody } = ragCard('Uploaded Documents', '');
    panel.appendChild(listCard);

    const tableWrap = document.createElement('div');
    tableWrap.style.cssText = 'overflow-x:auto;';
    listBody.appendChild(tableWrap);

    const table = document.createElement('table');
    table.className = 'adm-tbl';
    const thead = table.createTHead();
    const hdr   = thead.insertRow();
    ['Filename', 'Tags', 'Size', 'Chunks', 'Uploaded', ''].forEach(col => {
        const th = document.createElement('th');
        th.textContent = col;
        th.className = 'adm-th';
        hdr.appendChild(th);
    });
    const tbody = document.createElement('tbody');
    table.appendChild(tbody);
    tableWrap.appendChild(table);

        const rechunkAllBtn = document.createElement('button');
    rechunkAllBtn.type = 'button';
    rechunkAllBtn.textContent = 'Re-chunk All';
    rechunkAllBtn.className = 'btn btn-secondary btn-sm';
    rechunkAllBtn.style.marginBottom = '12px';
    listBody.insertBefore(rechunkAllBtn, tableWrap);

    rechunkAllBtn.addEventListener('click', async () => {
        if (!confirm('Re-chunk all documents from scratch? Existing chunks will be replaced.')) return;
        rechunkAllBtn.disabled = true;
        try {
            const res = await apiFetch('api.php?action=rag_rechunk_all', {
                method: 'POST',
                body: JSON.stringify({}),
            });
            const data = await res.json();
            if (data.status === 'success') {
                ragStatusPill(rechunkAllBtn, `Re-chunked ${data.processed} doc${data.processed !== 1 ? 's' : ''}.`, 'success');
                await loadFiles();
            } else {
                ragStatusPill(rechunkAllBtn, data.error ?? 'Error.', 'error');
            }
        } catch (e) {
            ragStatusPill(rechunkAllBtn, 'Request failed: ' + e.message, 'error');
        } finally {
            rechunkAllBtn.disabled = false;
        }
    });

    // Stats bar above table
    const statsBar = document.createElement('div');
    statsBar.style.cssText = 'margin-bottom:12px;';
    listBody.insertBefore(statsBar, tableWrap);

    async function loadFiles() {
        try {
            const res  = await apiFetch('api.php?action=rag_list');
            const data = await res.json();
            if (data.status === 'error') {
                tbody.innerHTML = '';
                const row = tbody.insertRow();
                const td  = row.insertCell();
                td.colSpan = 7;
                td.textContent = 'Error loading documents: ' + (data.error ?? 'Unknown error');
                td.style.cssText = 'padding:16px;color:var(--danger);text-align:center;';
                return;
            }
            renderFiles(data.files ?? []);
        } catch (e) {
            tbody.innerHTML = '';
            const row = tbody.insertRow();
            const td  = row.insertCell();
            td.colSpan    = 7;
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
            td.colSpan = 6;
            td.textContent = 'No documents uploaded yet.';
            td.style.cssText = 'padding:16px;text-align:center;font-style:italic;';
            return;
        }

        const totalSize = files.reduce((s, f) => s + (parseInt(f.file_size, 10) || 0), 0);
        statsBar.textContent = files.length + ' document' + (files.length !== 1 ? 's' : '') + ' · ' + ragFmtSize(totalSize) + ' total';

        files.forEach(file => {
            const row = tbody.insertRow();
            row.style.transition = 'background .15s';
            row.addEventListener('mouseover', () => { row.style.background = '#DDEAF4'; });
            row.addEventListener('mouseout',  () => { row.style.background = ''; });

            const tdStyle = 'padding:10px 12px;border-bottom:1px solid #CBD5E1;vertical-align:middle;';

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
                        + 'border-radius:999px;font-weight:600;color:var(--accent-dark);';
                    td2.appendChild(chip);
                });
            } else {
                td2.textContent = '—';
            }

            const td3 = row.insertCell();
            td3.style.cssText = tdStyle + '';
            td3.textContent   = ragFmtSize(file.file_size);

            const tdChunks = row.insertCell();
            tdChunks.style.cssText = tdStyle + 'text-align:center;';
            tdChunks.textContent   = file.chunk_count > 0 ? file.chunk_count : '—';

            const td5 = row.insertCell();
            td5.style.cssText = tdStyle + '';
            td5.textContent   = ragFmtDate(file.created_at);

            const td6 = row.insertCell();
            td6.style.cssText = tdStyle;
            const btnGroup = document.createElement('div');
            btnGroup.style.cssText = 'display:flex;gap:6px;';

            const rechunkBtn = document.createElement('button');
            rechunkBtn.type = 'button';
            rechunkBtn.textContent = 'Re-chunk';
            rechunkBtn.className = 'btn btn-secondary btn-xs';
            rechunkBtn.addEventListener('click', async () => {
                rechunkBtn.disabled = true;
                try {
                    const r = await apiFetch('api.php?action=rag_rechunk', {
                        method: 'POST',
                        body: JSON.stringify({ id: file.id }),
                    });
                    const d = await r.json();
                    if (d.status === 'success') {
                        await loadFiles();
                    } else {
                        alert('Re-chunk failed: ' + (d.error ?? 'Unknown error'));
                        rechunkBtn.disabled = false;
                    }
                } catch (e) {
                    alert('Request failed: ' + e.message);
                    rechunkBtn.disabled = false;
                }
            });
            btnGroup.appendChild(rechunkBtn);

            const delBtn = document.createElement('button');
            delBtn.type      = 'button';
            delBtn.textContent = 'Delete';
            delBtn.className = 'btn btn-danger btn-xs';
            delBtn.addEventListener('click', async () => {
                if (!confirm('Delete "' + ragEsc(file.filename) + '"?')) return;
                delBtn.disabled = true;
                try {
                    const r = await apiFetch('api.php?action=rag_delete', {
                        method: 'POST',
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
            btnGroup.appendChild(delBtn);
            td6.appendChild(btnGroup);
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
            const res  = await apiFetch('api.php?action=rag_upload', {
                method: 'POST',
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

    // ── Frontend Chat card ───────────────────────────────────────────────────
    const { card: chatCard, body: chatBody } = ragCard(
        'Frontend Chat',
        'Controls whether the AI chat interface is visible to users on the Knowledge Base page.'
    );
    panel.appendChild(chatCard);

    const chatRow = document.createElement('div');
    chatRow.style.cssText = 'display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-radius:6px;border:1px solid var(--border);background:var(--bg);';

    const chatChk = document.createElement('input');
    chatChk.type    = 'checkbox';
    chatChk.id      = 'rag-chat-enabled';
    chatChk.checked = true;
    chatChk.style.cssText = 'margin-top:3px;flex-shrink:0;cursor:pointer;';

    const chatTextWrap = document.createElement('label');
    chatTextWrap.htmlFor = 'rag-chat-enabled';
    chatTextWrap.style.cssText = 'cursor:pointer;';

    const chatTitle = document.createElement('div');
    chatTitle.style.cssText = 'font-weight:600;';
    chatTitle.textContent = 'Enable AI chat on the front end';

    const chatDesc = document.createElement('div');
    chatDesc.style.cssText = 'margin-top:2px;';
    chatDesc.textContent = 'When unchecked, the chat input and send button are hidden for all users. The document list remains visible. Useful when Ollama is not yet set up or during maintenance.';

    chatTextWrap.appendChild(chatTitle);
    chatTextWrap.appendChild(chatDesc);
    chatRow.appendChild(chatChk);
    chatRow.appendChild(chatTextWrap);
    chatBody.appendChild(chatRow);

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
    urlLbl.className = 'adm-field-label';

    const urlRow = document.createElement('div');
    urlRow.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:16px;';

    const urlInp = document.createElement('input');
    urlInp.type        = 'text';
    urlInp.id          = 'rag-ollama-url';
    urlInp.placeholder = 'http://localhost:11434';
    urlInp.className = 'adm-input flex-1';

    const checkBtn = document.createElement('button');
    checkBtn.type      = 'button';
    checkBtn.textContent = 'Test & load models';
    checkBtn.className = 'btn btn-secondary';
    checkBtn.style.flexShrink = '0';

    urlRow.appendChild(urlInp);
    urlRow.appendChild(checkBtn);
    connBody.appendChild(urlLbl);
    connBody.appendChild(urlRow);

    // Status badge line
    const statusLine = document.createElement('div');
    statusLine.style.cssText = 'display:none;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-weight:600;';
    connBody.appendChild(statusLine);

    // Model row — select (populated after check) + manual fallback input
    const modelLbl = document.createElement('label');
    modelLbl.htmlFor = 'rag-model-select';
    modelLbl.textContent = 'Model';
    modelLbl.className = 'adm-field-label';

    const modelRow = document.createElement('div');
    modelRow.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:16px;';

    const modelSelect = document.createElement('select');
    modelSelect.id = 'rag-model-select';
    modelSelect.className = 'adm-input flex-1';
    const placeholderOpt = document.createElement('option');
    placeholderOpt.value       = '';
    placeholderOpt.textContent = '— click "Test & load models" to populate —';
    modelSelect.appendChild(placeholderOpt);

    const modelManualInp = document.createElement('input');
    modelManualInp.type        = 'text';
    modelManualInp.id          = 'rag-model-manual';
    modelManualInp.placeholder = 'or type model name manually';
    modelManualInp.className = 'adm-input flex-1';
    modelManualInp.style.display = 'none';

    const toggleManualBtn = document.createElement('button');
    toggleManualBtn.type = 'button';
    toggleManualBtn.textContent = 'Type manually';
    toggleManualBtn.className = 'btn btn-secondary btn-sm';
    toggleManualBtn.style.flexShrink = '0';

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

    // SSL verify toggle
    const sslRow = document.createElement('div');
    sslRow.style.cssText = 'display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:10px 14px;border-radius:6px;border:1px solid var(--border);background:var(--bg);';

    const sslChk = document.createElement('input');
    sslChk.type    = 'checkbox';
    sslChk.id      = 'rag-ssl-verify';
    sslChk.checked = true;
    sslChk.style.cssText = 'margin-top:3px;flex-shrink:0;cursor:pointer;';

    const sslTextWrap = document.createElement('label');
    sslTextWrap.htmlFor = 'rag-ssl-verify';
    sslTextWrap.style.cssText = 'cursor:pointer;';

    const sslTitle = document.createElement('div');
    sslTitle.style.cssText = 'font-weight:600;';
    sslTitle.textContent = 'Verify SSL certificate';

    const sslDesc = document.createElement('div');
    sslDesc.style.cssText = 'margin-top:2px;';
    sslDesc.textContent = 'Disable only when using a tunnel (e.g. Serveo, ngrok) that presents a certificate your server cannot verify. Never disable in production.';

    sslTextWrap.appendChild(sslTitle);
    sslTextWrap.appendChild(sslDesc);
    sslRow.appendChild(sslChk);
    sslRow.appendChild(sslTextWrap);
    connBody.appendChild(sslRow);

    // Ollama Cloud API key (write-only — never populated from stored data)
    const apiKeyLbl = document.createElement('label');
    apiKeyLbl.htmlFor = 'rag-ollama-api-key';
    apiKeyLbl.textContent = 'Ollama Cloud API key';
    apiKeyLbl.className = 'adm-field-label';

    const apiKeyRow = document.createElement('div');
    apiKeyRow.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;';

    const apiKeyInp = document.createElement('input');
    apiKeyInp.type        = 'password';
    apiKeyInp.id          = 'rag-ollama-api-key';
    apiKeyInp.placeholder = 'Leave blank to keep the current key';
    apiKeyInp.autocomplete = 'new-password';
    apiKeyInp.className = 'adm-input flex-1';

    const apiKeyClearBtn = document.createElement('button');
    apiKeyClearBtn.type = 'button';
    apiKeyClearBtn.textContent = 'Clear key';
    apiKeyClearBtn.className = 'btn btn-secondary btn-sm';
    apiKeyClearBtn.style.flexShrink = '0';

    apiKeyRow.appendChild(apiKeyInp);
    apiKeyRow.appendChild(apiKeyClearBtn);
    connBody.appendChild(apiKeyLbl);
    connBody.appendChild(apiKeyRow);

    const apiKeyStatus = document.createElement('div');
    apiKeyStatus.style.cssText = 'margin-bottom:16px;';
    connBody.appendChild(apiKeyStatus);

    let apiKeyClearRequested = false;
    function renderApiKeyStatus(configured) {
        apiKeyStatus.textContent = configured ? 'API key configured.' : 'No API key set.';
    }
    apiKeyClearBtn.addEventListener('click', () => {
        apiKeyClearRequested = true;
        apiKeyInp.value = '';
        renderApiKeyStatus(false);
    });
    apiKeyInp.addEventListener('input', () => {
        if (apiKeyInp.value !== '') {
            apiKeyClearRequested = false;
        }
    });

        // Other settings grid
    function ragField(label, id, placeholder) {
        const group = document.createElement('div');
        const lbl = document.createElement('label');
        lbl.htmlFor = id;
        lbl.textContent = label;
        lbl.className = 'adm-field-label';
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.id = id;
        inp.placeholder = placeholder;
        inp.className = 'adm-input w-full';
        group.appendChild(lbl);
        group.appendChild(inp);
        return { group, inp };
    }

    const otherGrid = document.createElement('div');
    otherGrid.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px;margin-bottom:16px;';
    const { group: ctxGroup,     inp: ctxInp }     = ragField('Max context files', 'rag-max-ctx', '3');
    const { group: sizeGroup,    inp: sizeInp }    = ragField('Max file size (MB)', 'rag-max-size', '10');
    const { group: timeoutGroup, inp: timeoutInp } = ragField('Ollama timeout (s)', 'rag-timeout', '120');
    const { group: memGroup,     inp: memInp }     = ragField('Conversation memory (turns)', 'rag-conv-turns', '0');
    memInp.title = '0 = disabled; max 10. Each turn = one user question + one assistant reply.';
    otherGrid.appendChild(ctxGroup);
    otherGrid.appendChild(sizeGroup);
    otherGrid.appendChild(timeoutGroup);
    otherGrid.appendChild(memGroup);
    connBody.appendChild(otherGrid);

    const chunksRow = document.createElement('div');
    chunksRow.style.cssText = 'display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:10px 14px;border-radius:6px;border:1px solid var(--border);background:var(--bg);';

    const chunksChk = document.createElement('input');
    chunksChk.type    = 'checkbox';
    chunksChk.id      = 'rag-use-chunks';
    chunksChk.checked = true;
    chunksChk.style.cssText = 'margin-top:3px;flex-shrink:0;cursor:pointer;';

    const chunksTextWrap = document.createElement('label');
    chunksTextWrap.htmlFor = 'rag-use-chunks';
    chunksTextWrap.style.cssText = 'cursor:pointer;';

    const chunksTitle = document.createElement('div');
    chunksTitle.style.cssText = 'font-weight:600;';
    chunksTitle.textContent = 'Use document chunking';

    const chunksDesc = document.createElement('div');
    chunksDesc.style.cssText = 'margin-top:2px;';
    chunksDesc.textContent = 'When enabled, uploaded documents are split into overlapping chunks for fine-grained retrieval. Disable to send full file content directly — better for small documents.';

    chunksTextWrap.appendChild(chunksTitle);
    chunksTextWrap.appendChild(chunksDesc);
    chunksRow.appendChild(chunksChk);
    chunksRow.appendChild(chunksTextWrap);
    connBody.appendChild(chunksRow);

    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.textContent = 'Save Settings';
    saveBtn.className = 'btn btn-primary';
    connBody.appendChild(saveBtn);

    const pullHint = document.createElement('p');
    pullHint.style.cssText = 'margin:14px 0 0;';
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
        hdr.style.cssText = 'font-weight:700;margin-bottom:8px;';
        hdr.textContent = 'Available local models' + (version ? ' · Ollama ' + version : '');
        modelsTable.appendChild(hdr);

        if (models.length === 0) {
            const none = document.createElement('div');
            none.style.cssText = 'font-style:italic;padding:8px 0;';
            none.textContent = 'No models found. Pull one with: ollama pull llama3';
            modelsTable.appendChild(none);
            return;
        }

        const tbl = document.createElement('table');
        tbl.className = 'adm-tbl';

        const thead = tbl.createTHead();
        const hr    = thead.insertRow();
        ['Model name', 'Size', 'Modified'].forEach(col => {
            const th = document.createElement('th');
            th.textContent = col;
            th.className = 'adm-th adm-th-sm';
            hr.appendChild(th);
        });

        const tbody = tbl.createTBody();
        models.forEach(m => {
            const row = tbody.insertRow();
            const tdStyle = 'padding:8px 10px;border-bottom:1px solid #CBD5E1;';

            const td1 = row.insertCell();
            td1.style.cssText = tdStyle + 'font-weight:500;';
            td1.textContent   = m.name;

            const td2 = row.insertCell();
            td2.style.cssText = tdStyle + '';
            td2.textContent   = ragFmtModelSize(m.size) || '—';

            const td3 = row.insertCell();
            td3.style.cssText = tdStyle + '';
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
        statusLine.style.cssText = 'display:block;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-weight:600;background:rgba(255,195,0,0.12);color:#64748B;border:1px solid #ffc300;';
        statusLine.textContent   = 'Connecting to ' + url + '…';

        try {
            const res  = await apiFetch('api.php?action=rag_ollama_check', {
                method: 'POST',
                body: JSON.stringify({ ollama_url: url, ssl_verify: sslChk.checked }),
            });
            const data = await res.json();

            if (data.status === 'success') {
                const n = (data.models ?? []).length;
                statusLine.style.cssText = 'display:block;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-weight:600;background:rgba(43,147,72,0.12);color:#2b9348;border:1px solid #CBD5E1;';
                statusLine.textContent   = '✓ Connected · ' + n + ' model' + (n !== 1 ? 's' : '') + ' available'
                    + (data.version ? ' · Ollama ' + data.version : '');

                const currentModel = manualMode ? modelManualInp.value.trim() : (modelSelect.value || '');
                populateModelSelect(data.models ?? [], currentModel);
                renderModelsTable(data.models ?? [], data.version ?? '');
            } else {
                statusLine.style.cssText = 'display:block;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-weight:600;background:rgba(208,0,0,0.08);color:#a80000;border:1px solid #d00000;';
                statusLine.textContent   = '✗ ' + (data.error ?? 'Connection failed');
                modelsTable.style.display = 'none';
            }
        } catch (e) {
            statusLine.style.cssText = 'display:block;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-weight:600;background:rgba(208,0,0,0.08);color:#a80000;border:1px solid #d00000;';
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
            const res  = await apiFetch('api.php?action=rag_settings');
            const data = await res.json();
            if (data.status === 'success' && data.settings) {
                const s = data.settings;
                if (s.ollama_url)        urlInp.value         = s.ollama_url;
                if (s.ollama_model)      modelManualInp.value = s.ollama_model;
                if (s.max_context_files) ctxInp.value         = s.max_context_files;
                if (s.max_file_size_mb)  sizeInp.value        = s.max_file_size_mb;
                if (s.ollama_timeout)    timeoutInp.value     = s.ollama_timeout;
                if (s.ollama_ssl_verify !== undefined) sslChk.checked = !!s.ollama_ssl_verify;
                if (s.use_chunks !== undefined) chunksChk.checked = !!s.use_chunks;
                if (s.conversation_turns !== undefined) memInp.value = s.conversation_turns;
                if (s.chat_enabled !== undefined) chatChk.checked = !!s.chat_enabled;
                renderApiKeyStatus(!!s.ollama_api_key_configured);

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
            ollama_url:          urlInp.value.trim(),
            ollama_model:        modelValue,
            max_context_files:   parseInt(ctxInp.value, 10) || 3,
            max_file_size_mb:    parseInt(sizeInp.value, 10) || 10,
            ollama_timeout:      parseInt(timeoutInp.value, 10) || 120,
            ssl_verify:          sslChk.checked,
            use_chunks:          chunksChk.checked,
            conversation_turns:  Math.max(0, Math.min(10, parseInt(memInp.value, 10) || 0)),
            chat_enabled:        chatChk.checked,
        };
        if (apiKeyInp.value !== '') {
            payload.ollama_api_key = apiKeyInp.value;
        } else if (apiKeyClearRequested) {
            payload.ollama_api_key_clear = true;
        }
        if (payload.chat_enabled && (!payload.ollama_url || !payload.ollama_model)) {
            ragStatusPill(saveBtn, 'URL and model are required when chat is enabled.', 'error');
            return;
        }
        try {
            const res  = await apiFetch('api.php?action=rag_settings_save', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            ragStatusPill(saveBtn, data.status === 'success' ? 'Saved.' : (data.error ?? 'Error.'), data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') {
                apiKeyInp.value = '';
                if (apiKeyClearRequested) {
                    renderApiKeyStatus(false);
                } else if (payload.ollama_api_key) {
                    renderApiKeyStatus(true);
                }
                apiKeyClearRequested = false;
            }
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
    tagLbl.style.cssText = 'font-weight:700;margin-bottom:8px;';
    tagRow.appendChild(tagLbl);
    const tagChips = document.createElement('div');
    tagChips.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;min-height:28px;';
    tagChips.innerHTML = '<span style="font-style:italic;">Loading tags…</span>';
    tagRow.appendChild(tagChips);
    testBody.appendChild(tagRow);

    // Load available tags
    (async () => {
        try {
            const res  = await apiFetch('../api/rag.php?action=tags');
            const data = await res.json();
            tagChips.innerHTML = '';
            const tags = data.tags ?? [];
            if (tags.length === 0) {
                tagChips.innerHTML = '<span style="font-style:italic;">No tags yet.</span>';
            } else {
                tags.forEach(tag => {
                    const lbl = document.createElement('label');
                    lbl.style.cssText = 'display:flex;align-items:center;gap:5px;padding:3px 10px;border:1px solid var(--border);border-radius:999px;cursor:pointer;background:#fff;';
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
            tagChips.innerHTML = '<span style="color:var(--danger);">Could not load tags.</span>';
        }
    })();

    // Language hint row
    const langRow = document.createElement('div');
    langRow.style.cssText = 'margin-bottom:14px;';
    const langLbl = document.createElement('div');
    langLbl.textContent = 'Response language (optional):';
    langLbl.style.cssText = 'font-weight:700;margin-bottom:8px;';
    langRow.appendChild(langLbl);
    const langSelect = document.createElement('select');
    langSelect.className = 'adm-input w-180';
    const langNoneOpt = document.createElement('option');
    langNoneOpt.value = '';
    langNoneOpt.textContent = '— auto-detect —';
    langSelect.appendChild(langNoneOpt);
    langRow.appendChild(langSelect);
    testBody.appendChild(langRow);

    // Populate language dropdown from settings
    (async () => {
        try {
            const res  = await apiFetch('api.php?action=get_language_setting');
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
    queryInp.className = 'adm-input flex-1';
    const runBtn = document.createElement('button');
    runBtn.type = 'button';
    runBtn.textContent = 'Run';
    runBtn.className = 'btn btn-primary';
    const testStopBtn = document.createElement('button');
    testStopBtn.type = 'button';
    testStopBtn.textContent = 'Stop';
    testStopBtn.disabled = true;
    testStopBtn.className = 'btn btn-danger';
    testStopBtn.style.opacity = '0.35';
    queryRow.appendChild(queryInp);
    queryRow.appendChild(runBtn);
    queryRow.appendChild(testStopBtn);
    testBody.appendChild(queryRow);

    let testAbortCtrl = null;

    // Result area
    const resultWrap = document.createElement('div');
    resultWrap.style.display = 'none';
    testBody.appendChild(resultWrap);

    const answerLabel = document.createElement('div');
    answerLabel.textContent = 'Answer';
    answerLabel.style.cssText = 'font-weight:700;margin-bottom:6px;';

    const answerBox = document.createElement('div');
    answerBox.style.cssText = 'padding:14px;background:#F4F7F9;border:1px solid var(--border);border-radius:4px;line-height:1.7;white-space:pre-wrap;word-break:break-word;max-height:320px;overflow-y:auto;margin-bottom:12px;';

    const sourcesLabel = document.createElement('div');
    sourcesLabel.textContent = 'Sources used';
    sourcesLabel.style.cssText = 'font-weight:700;margin-bottom:6px;';

    const sourcesRow = document.createElement('div');
    sourcesRow.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;';

    resultWrap.appendChild(answerLabel);
    resultWrap.appendChild(answerBox);
    resultWrap.appendChild(sourcesLabel);
    resultWrap.appendChild(sourcesRow);

    // Prompt preview card — defined before runQuery so the reference is valid at call time
    const { card: promptCard, body: promptBody } = ragCard(
        'Prompt Preview',
        'Shows the exact prompt sent to Ollama for the last query — useful for debugging context quality.'
    );
    panel.appendChild(promptCard);

    const promptBox = document.createElement('pre');
    promptBox.style.cssText = 'margin:0;line-height:1.6;white-space:pre-wrap;word-break:break-word;font-style:italic;';
    promptBox.textContent = 'Run a query above to see the prompt.';
    promptBody.appendChild(promptBox);

    testStopBtn.addEventListener('click', () => {
        testAbortCtrl?.abort();
    });

    async function runQuery() {
        const q = queryInp.value.trim();
        if (!q) return;

        const tags     = Array.from(tagChips.querySelectorAll('input[type=checkbox]:checked')).map(c => c.value);
        const language = langSelect.value;

        testAbortCtrl = new AbortController();
        runBtn.disabled = true;
        testStopBtn.disabled = false;
        testStopBtn.style.opacity = '1';
        resultWrap.style.display = '';
        answerBox.textContent    = 'Querying…';
        sourcesRow.innerHTML     = '';

        try {
            const res  = await apiFetch('api.php?action=rag_test_query', {
                method: 'POST',
                body: JSON.stringify({ query: q, tags, language, return_prompt: true }),
                signal: testAbortCtrl.signal,
            });
            let data;
            try {
                data = await res.json();
            } catch {
                answerBox.textContent = 'The server timed out or returned an unexpected response. Please try again.';
                answerBox.style.color = 'var(--danger)';
                return;
            }
            if (data.status === 'success') {
                answerBox.textContent = data.answer ?? '(empty response)';
                answerBox.style.color = 'var(--text)';
                sourcesRow.innerHTML  = '';
                const srcs = data.sources ?? [];
                if (srcs.length === 0) {
                    const none = document.createElement('span');
                    none.textContent  = 'No documents matched — answered from model knowledge.';
                    none.style.cssText = 'font-style:italic;';
                    sourcesRow.appendChild(none);
                } else {
                    srcs.forEach(s => {
                        const chip = document.createElement('span');
                        chip.textContent = s.filename;
                        chip.style.cssText = 'padding:2px 10px;background:var(--accent-light);border:1px solid var(--accent-mid);border-radius:999px;font-weight:600;color:var(--accent-dark);';
                        sourcesRow.appendChild(chip);
                    });
                }
                if (data.prompt) {
                    promptBox.textContent = data.prompt;
                    promptBox.style.color = 'var(--text)';
                }
            } else {
                answerBox.textContent = 'Error: ' + (data.error ?? 'Unknown error');
                answerBox.style.color = 'var(--danger)';
            }
        } catch (e) {
            if (e.name === 'AbortError') {
                answerBox.textContent = 'Query cancelled.';
            } else {
                answerBox.textContent = 'Request failed: ' + e.message;
            }
            answerBox.style.color = 'var(--danger)';
        } finally {
            testAbortCtrl = null;
            runBtn.disabled = false;
            testStopBtn.disabled = true;
            testStopBtn.style.opacity = '0.35';
        }
    }

    runBtn.addEventListener('click', runQuery);
    queryInp.addEventListener('keydown', e => { if (e.key === 'Enter') runQuery(); });
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

    function statCard(label) {
        const box = document.createElement('div');
        box.style.cssText = 'text-align:center;padding:16px 10px;border:1px solid var(--border);border-radius:8px;background:#F4F7F9;';
        const v = document.createElement('div');
        v.style.cssText = 'font-weight:700;margin-bottom:4px;';
        v.textContent = '—';
        const l = document.createElement('div');
        l.style.cssText = 'font-weight:700;';
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
        'Last 50 queries, newest first. Click a row to see which chunks were used and the full prompt sent to Ollama.'
    );
    panel.appendChild(recentCard);

    const tableWrap = document.createElement('div');
    tableWrap.style.cssText = 'overflow-x:auto;';
    recentBody.appendChild(tableWrap);

    const tbl   = document.createElement('table');
    tbl.className = 'adm-tbl';
    const thead = tbl.createTHead();
    const hr    = thead.insertRow();
    ['Time', 'Query', 'Tags', 'Files', 'Model', 'Prompt T', 'Comp T', 'Time (s)', 'Sources'].forEach(col => {
        const th = document.createElement('th');
        th.textContent = col;
        th.className = 'adm-th adm-th-sm';
        hr.appendChild(th);
    });
    const tbody = tbl.createTBody();
    tbl.appendChild(tbody);
    tableWrap.appendChild(tbl);

    const refreshBtn = document.createElement('button');
    refreshBtn.type = 'button';
    refreshBtn.textContent = 'Refresh';
    refreshBtn.className = 'btn btn-secondary btn-sm';
    refreshBtn.style.marginTop = '14px';
    recentBody.appendChild(refreshBtn);

    async function load() {
        refreshBtn.disabled = true;
        try {
            const res  = await apiFetch('api.php?action=rag_stats');
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
                td.colSpan = 9;
                td.textContent = 'No queries recorded yet.';
                td.style.cssText = 'padding:16px;text-align:center;font-style:italic;';
                return;
            }
            const tdStyle = 'padding:8px 10px;border-bottom:1px solid #CBD5E1;vertical-align:middle;';
            rows.forEach(r => {
                const row = tbody.insertRow();
                row.style.cursor = 'pointer';
                row.title = 'Click to view sources and prompt';
                row.addEventListener('mouseover', () => { if (!row.dataset.expanded) row.style.background = '#DDEAF4'; });
                row.addEventListener('mouseout',  () => { if (!row.dataset.expanded) row.style.background = ''; });

                const td1 = row.insertCell();
                td1.style.cssText = tdStyle + 'white-space:nowrap;';
                td1.textContent   = ragFmtDate(r.created_at);

                const td2 = row.insertCell();
                td2.style.cssText = tdStyle + 'max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
                td2.title         = r.query;
                td2.textContent   = r.query.length > 70 ? r.query.slice(0, 70) + '…' : r.query;

                const td3 = row.insertCell();
                td3.style.cssText = tdStyle;
                const tags = ragParseTags(r.tags ?? '{}');
                if (tags.length > 0) {
                    tags.forEach(tag => {
                        const chip = document.createElement('span');
                        chip.textContent = tag;
                        chip.style.cssText = 'display:inline-block;margin:0 2px 2px 0;padding:1px 7px;background:var(--accent-light);border:1px solid var(--accent-mid);border-radius:999px;font-weight:600;color:var(--accent-dark);white-space:nowrap;';
                        td3.appendChild(chip);
                    });
                } else {
                    td3.textContent = '—';
                }

                const td4 = row.insertCell();
                td4.style.cssText = tdStyle + 'text-align:center;';
                td4.textContent   = r.matched_files;

                const td5 = row.insertCell();
                td5.style.cssText = tdStyle + 'white-space:nowrap;';
                td5.textContent   = r.model || '—';

                const td6 = row.insertCell();
                td6.style.cssText = tdStyle + 'text-align:right;';
                td6.textContent   = r.prompt_tokens ? (parseInt(r.prompt_tokens, 10) / 1000).toFixed(1) + 'k' : '0';

                const td7 = row.insertCell();
                td7.style.cssText = tdStyle + 'text-align:right;';
                td7.textContent   = r.completion_tokens ? (parseInt(r.completion_tokens, 10) / 1000).toFixed(1) + 'k' : '0';

                const td8 = row.insertCell();
                td8.style.cssText = tdStyle + 'text-align:right;font-weight:600;';
                td8.textContent   = r.total_ms ? (parseInt(r.total_ms, 10) / 1000).toFixed(2) + 's' : '0s';

                const td9 = row.insertCell();
                td9.style.cssText = tdStyle + 'text-align:center;';
                const srcs = r.sources ?? [];
                if (srcs.length > 0) {
                    const chunkCount = srcs.filter(s => s.source_type === 'chunk').length;
                    const badge = document.createElement('span');
                    badge.textContent = srcs.length + (chunkCount > 0 ? ' chunk' : ' file') + (srcs.length !== 1 ? 's' : '');
                    badge.style.cssText = 'display:inline-block;padding:1px 8px;background:var(--accent-light);border:1px solid var(--accent-mid);border-radius:999px;font-weight:600;color:var(--accent-dark);';
                    td9.appendChild(badge);
                } else {
                    td9.textContent = '—';
                }

                let detailRow = null;
                row.addEventListener('click', () => {
                    if (detailRow && detailRow.parentNode) {
                        detailRow.remove();
                        detailRow = null;
                        delete row.dataset.expanded;
                        row.style.background = '';
                        return;
                    }
                    row.dataset.expanded = '1';
                    row.style.background = 'var(--accent-light)';

                    detailRow = document.createElement('tr');
                    row.insertAdjacentElement('afterend', detailRow);
                    const dtd = detailRow.insertCell();
                    dtd.colSpan = 9;
                    dtd.style.cssText = 'padding:14px 20px;background:#EEF4FA;border-bottom:2px solid var(--border);';

                    if (srcs.length > 0) {
                        const srcHdr = document.createElement('div');
                        srcHdr.textContent = 'Sources used (' + srcs.length + ')';
                        srcHdr.style.cssText = 'font-weight:700;margin-bottom:8px;';
                        dtd.appendChild(srcHdr);

                        const srcGrid = document.createElement('div');
                        srcGrid.style.cssText = 'display:flex;flex-direction:column;gap:6px;margin-bottom:16px;';
                        srcs.forEach(s => {
                            const card = document.createElement('div');
                            card.style.cssText = 'padding:8px 12px;border:1px solid var(--border);border-radius:4px;background:#fff;';
                            const title = document.createElement('div');
                            title.style.cssText = 'font-weight:600;margin-bottom:4px;';
                            const chunkLabel = s.source_type === 'chunk' && parseInt(s.chunk_index, 10) >= 0
                                ? '  [chunk #' + s.chunk_index + ']'
                                : '  [full file]';
                            title.textContent = (s.filename || '—') + chunkLabel;
                            const snippet = document.createElement('div');
                            snippet.style.cssText = 'white-space:pre-wrap;word-break:break-word;line-height:1.5;';
                            const snipText = s.snippet || '';
                            snippet.textContent = snipText.length > 350 ? snipText.slice(0, 350) + '…' : snipText;
                            card.appendChild(title);
                            card.appendChild(snippet);
                            srcGrid.appendChild(card);
                        });
                        dtd.appendChild(srcGrid);
                    }

                    if (r.prompt_snapshot) {
                        const promptToggle = document.createElement('div');
                        promptToggle.style.cssText = 'display:inline-flex;align-items:center;gap:6px;cursor:pointer;margin-bottom:6px;user-select:none;';
                        const promptLbl = document.createElement('span');
                        promptLbl.style.cssText = 'font-weight:700;';
                        promptLbl.textContent = 'Full prompt sent to Ollama';
                        const promptArrow = document.createElement('span');
                        promptArrow.textContent = '▾';
                        promptArrow.style.cssText = 'transition:transform .15s;display:inline-block;';
                        promptToggle.appendChild(promptLbl);
                        promptToggle.appendChild(promptArrow);

                        const promptBox = document.createElement('pre');
                        promptBox.style.cssText = 'display:none;margin:0;padding:12px;background:#F0F4F8;border:1px solid var(--border);border-radius:4px;line-height:1.5;white-space:pre-wrap;word-break:break-word;max-height:280px;overflow-y:auto;color:var(--text);';
                        promptBox.textContent = r.prompt_snapshot;

                        promptToggle.addEventListener('click', e => {
                            e.stopPropagation();
                            const open = promptBox.style.display !== 'none';
                            promptBox.style.display = open ? 'none' : 'block';
                            promptArrow.style.transform = open ? '' : 'rotate(-90deg)';
                        });

                        dtd.appendChild(promptToggle);
                        dtd.appendChild(promptBox);
                    } else if (srcs.length === 0) {
                        const noData = document.createElement('div');
                        noData.textContent = 'No detail data recorded for this query (pre-2.10.0 entry).';
                        noData.style.cssText = 'font-style:italic;';
                        dtd.appendChild(noData);
                    }
                });
            });
        } catch (e) {
            tbody.innerHTML = '';
            const row = tbody.insertRow();
            const td  = row.insertCell();
            td.colSpan = 9;
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
    wrap.className = 'admin-page';
    wrap.style.paddingBottom = '60px';

    const heading = document.createElement('h2');
    heading.textContent = 'Centrum AI';
    heading.className = 'admin-page-title';

    const intro = document.createElement('p');
    intro.textContent = 'Upload .txt documents, configure Ollama, and test retrieval-augmented queries.';
    intro.className = 'admin-page-desc';

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
