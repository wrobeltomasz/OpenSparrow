// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { escHtml } from '../../assets/js/util/esc.js';
import { showStatusPill } from './app.js';

function ragCard(title, description) {
    const card = document.createElement('div');
    card.className = 'adm-sec-card';

    const hdr = document.createElement('div');
    hdr.className = 'adm-sec-hdr';
    hdr.style.display = 'block';

    const h3 = document.createElement('h3');
    h3.textContent = title;
    h3.style.cssText = 'margin:0 0 4px;';
    hdr.appendChild(h3);

    if (description) {
        const p = document.createElement('p');
        p.textContent = description;
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
    const previous = anchor.parentNode?.querySelector('.rag-status-pill');
    if (previous) previous.remove();
    const colors = {
        success: { bg: 'var(--ok-light)', fg: 'var(--ok)', border: 'var(--ok)' },
        error:   { bg: 'var(--error-light)', fg: 'var(--error)', border: 'var(--error)' },
        info:    { bg: 'var(--accent-mid)', fg: 'var(--text)', border: 'var(--accent-mid)' },
    }[type] ?? { bg: 'var(--accent-mid)', fg: 'var(--text)', border: 'var(--accent-mid)' };
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

function ragFormatSize(bytes) {
    bytes = parseInt(bytes, 10) || 0;
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

function ragFormatDate(timestamp) {
    if (!timestamp) return '—';
    return new Date(timestamp).toLocaleString();
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

function ragBuildTabs(wrap, tabs) {
    const bar = document.createElement('div');
    bar.className = 'item-panel-items';

    const panels = {};
    const btns   = {};

    tabs.forEach(({ id, label, icon }) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'item-btn';
        if (icon) {
            const image = document.createElement('img');
            image.src   = '../assets/icons/' + icon;
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

function ragBuildDocumentsTab(panel) {
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
        const input = document.createElement('input');
        input.type = 'text';
        input.id = id;
        input.placeholder = placeholder;
        input.className = 'adm-input w-full';
        group.appendChild(lbl);
        group.appendChild(input);
        return { group, inp: input };
    }

    const fileWrap = document.createElement('div');
    const fileLabel = document.createElement('label');
    fileLabel.htmlFor = 'rag-file-input';
    fileLabel.textContent = 'Text file (.txt)';
    fileLabel.className = 'adm-field-label';
    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.id = 'rag-file-input';
    fileInput.accept = '.txt,text/plain';
    fileInput.className = 'adm-input';
    fileWrap.appendChild(fileLabel);
    fileWrap.appendChild(fileInput);

    const { group: tagsGroup, inp: tagsInput } = ragField('Tags (comma-separated)', 'rag-tags-input', 'e.g. legal, faq, policy');
    tagsGroup.style.flex = '1';

    const langGroup = document.createElement('div');
    const langUpLabel = document.createElement('label');
    langUpLabel.htmlFor    = 'rag-lang-select';
    langUpLabel.textContent = 'Language';
    langUpLabel.className = 'adm-field-label';
    const langUpSelect = document.createElement('select');
    langUpSelect.id = 'rag-lang-select';
    langUpSelect.className = 'adm-input';
    const noneOption = document.createElement('option');
    noneOption.value = '';
    noneOption.textContent = '— any —';
    langUpSelect.appendChild(noneOption);
    langGroup.appendChild(langUpLabel);
    langGroup.appendChild(langUpSelect);

    (async () => {
        try {
            const res  = await apiFetch('api.php?action=get_language_setting');
            const data = await res.json();
            (data.available_languages ?? []).forEach(code => {
                const option = document.createElement('option');
                option.value       = code;
                option.textContent = code.toUpperCase();
                langUpSelect.appendChild(option);
            });
        } catch (e) { console.warn('[rag] language list unavailable', e); }
    })();

    const uploadButton = document.createElement('button');
    uploadButton.type = 'button';
    uploadButton.textContent = 'Upload';
    uploadButton.className = 'btn btn-primary';
    uploadButton.style.alignSelf = 'flex-end';

    uploadForm.appendChild(fileWrap);
    uploadForm.appendChild(tagsGroup);
    uploadForm.appendChild(langGroup);
    uploadForm.appendChild(uploadButton);
    uploadBody.appendChild(uploadForm);

    const guideWrap = document.createElement('div');
    guideWrap.style.cssText = 'margin-top:16px;border-top:1px solid var(--border);padding-top:12px;';

    const guideHdr = document.createElement('div');
    guideHdr.style.cssText = 'display:inline-flex;align-items:center;gap:6px;cursor:pointer;user-select:none;';
    const guideLabel = document.createElement('span');
    guideLabel.style.cssText = 'font-weight:700;';
    guideLabel.textContent = 'Document preparation guidelines';
    const guideArrow = document.createElement('span');
    guideArrow.textContent = '▾';
    guideArrow.style.cssText = 'display:inline-block;transition:transform .15s;';
    guideHdr.appendChild(guideLabel);
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

    const { card: listCard, body: listBody } = ragCard('Uploaded Documents', '');
    panel.appendChild(listCard);

    const tableWrap = document.createElement('div');
    tableWrap.style.cssText = 'overflow-x:auto;';
    listBody.appendChild(tableWrap);

    const table = document.createElement('table');
    table.className = 'adm-tbl';
    const thead = table.createTHead();
    const hdr   = thead.insertRow();
    ['Filename', 'Tags', 'Size', 'Chunks', 'Uploaded', ''].forEach(column => {
        const th = document.createElement('th');
        th.textContent = column;
        th.className = 'adm-th';
        hdr.appendChild(th);
    });
    const tbody = document.createElement('tbody');
    table.appendChild(tbody);
    tableWrap.appendChild(table);

        const rechunkAllButton = document.createElement('button');
    rechunkAllButton.type = 'button';
    rechunkAllButton.textContent = 'Re-chunk All';
    rechunkAllButton.className = 'btn btn-secondary btn-sm';
    rechunkAllButton.style.marginBottom = '12px';
    listBody.insertBefore(rechunkAllButton, tableWrap);

    rechunkAllButton.addEventListener('click', async () => {
        if (!confirm('Re-chunk all documents from scratch? Existing chunks will be replaced.')) return;
        rechunkAllButton.disabled = true;
        try {
            const res = await apiFetch('api.php?action=rag_rechunk_all', {
                method: 'POST',
                body: JSON.stringify({}),
            });
            const data = await res.json();
            if (data.status === 'success') {
                ragStatusPill(rechunkAllButton, `Re-chunked ${data.processed} doc${data.processed !== 1 ? 's' : ''}.`, 'success');
                await loadFiles();
            } else {
                ragStatusPill(rechunkAllButton, data.error ?? 'Error.', 'error');
            }
        } catch (e) {
            ragStatusPill(rechunkAllButton, 'Request failed: ' + e.message, 'error');
        } finally {
            rechunkAllButton.disabled = false;
        }
    });

    const statisticsBar = document.createElement('div');
    statisticsBar.style.cssText = 'margin-bottom:12px;';
    listBody.insertBefore(statisticsBar, tableWrap);

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
                td.style.cssText = 'padding:16px;color:var(--error);text-align:center;';
                return;
            }
            renderFiles(data.files ?? []);
        } catch (e) {
            tbody.innerHTML = '';
            const row = tbody.insertRow();
            const td  = row.insertCell();
            td.colSpan    = 7;
            td.textContent = 'Failed to load: ' + e.message;
            td.style.cssText = 'padding:16px;color:var(--error);text-align:center;';
        }
    }

    function renderFiles(files) {
        tbody.innerHTML = '';
        if (files.length === 0) {
            statisticsBar.textContent = '';
            const row = tbody.insertRow();
            const td  = row.insertCell();
            td.colSpan = 6;
            td.textContent = 'No documents uploaded yet.';
            td.style.cssText = 'padding:16px;text-align:center;font-style:italic;';
            return;
        }

        const totalSize = files.reduce((s, f) => s + (parseInt(f.file_size, 10) || 0), 0);
        statisticsBar.textContent = files.length + ' document' + (files.length !== 1 ? 's' : '') + ' · ' + ragFormatSize(totalSize) + ' total';

        files.forEach(file => {
            const row = tbody.insertRow();
            row.style.transition = 'background .15s';
            row.addEventListener('mouseover', () => { row.style.background = 'var(--accent-mid)'; });
            row.addEventListener('mouseout',  () => { row.style.background = ''; });

            const tdStyle = 'padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:middle;';

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
            td3.textContent   = ragFormatSize(file.file_size);

            const tdChunks = row.insertCell();
            tdChunks.style.cssText = tdStyle + 'text-align:center;';
            tdChunks.textContent   = file.chunk_count > 0 ? file.chunk_count : '—';

            const td5 = row.insertCell();
            td5.style.cssText = tdStyle + '';
            td5.textContent   = ragFormatDate(file.created_at);

            const td6 = row.insertCell();
            td6.style.cssText = tdStyle;
            const buttonGroup = document.createElement('div');
            buttonGroup.style.cssText = 'display:flex;gap:6px;';

            const rechunkButton = document.createElement('button');
            rechunkButton.type = 'button';
            rechunkButton.textContent = 'Re-chunk';
            rechunkButton.className = 'btn btn-secondary btn-xs';
            rechunkButton.addEventListener('click', async () => {
                rechunkButton.disabled = true;
                try {
                    const r = await apiFetch('api.php?action=rag_rechunk', {
                        method: 'POST',
                        body: JSON.stringify({ id: file.id }),
                    });
                    const d = await r.json();
                    if (d.status === 'success') {
                        await loadFiles();
                    } else {
                        showStatusPill(rechunkButton, 'Re-chunk failed: ' + (d.error ?? 'Unknown error'), 'error');
                        rechunkButton.disabled = false;
                    }
                } catch (e) {
                    showStatusPill(rechunkButton, 'Request failed: ' + e.message, 'error');
                    rechunkButton.disabled = false;
                }
            });
            buttonGroup.appendChild(rechunkButton);

            const delButton = document.createElement('button');
            delButton.type      = 'button';
            delButton.textContent = 'Delete';
            delButton.className = 'btn btn-danger btn-xs';
            delButton.addEventListener('click', async () => {
                if (!confirm('Delete "' + escHtml(file.filename) + '"?')) return;
                delButton.disabled = true;
                try {
                    const r = await apiFetch('api.php?action=rag_delete', {
                        method: 'POST',
                        body: JSON.stringify({ id: file.id }),
                    });
                    const d = await r.json();
                    if (d.status === 'success') {
                        await loadFiles();
                    } else {
                        showStatusPill(delButton, 'Delete failed: ' + (d.error ?? 'Unknown error'), 'error');
                        delButton.disabled = false;
                    }
                } catch (e) {
                    showStatusPill(delButton, 'Request failed: ' + e.message, 'error');
                    delButton.disabled = false;
                }
            });
            buttonGroup.appendChild(delButton);
            td6.appendChild(buttonGroup);
        });
    }

    uploadButton.addEventListener('click', async () => {
        const file = fileInput.files?.[0];
        if (!file) { ragStatusPill(uploadButton, 'Select a .txt file first.', 'error'); return; }
        if (!file.name.toLowerCase().endsWith('.txt')) { ragStatusPill(uploadButton, 'Only .txt files allowed.', 'error'); return; }

        uploadButton.disabled = true;
        const tags = tagsInput.value.split(',').map(t => t.trim()).filter(t => t !== '');
        if (langUpSelect.value) tags.push('lang:' + langUpSelect.value);
        const formData   = new FormData();
        formData.append('file', file);
        formData.append('tags', JSON.stringify(tags));

        try {
            const res  = await apiFetch('api.php?action=rag_upload', {
                method: 'POST',
                body: formData,
            });
            const data = await res.json();
            if (data.status === 'success') {
                ragStatusPill(uploadButton, 'Uploaded.', 'success');
                fileInput.value = '';
                tagsInput.value = '';
                await loadFiles();
            } else {
                ragStatusPill(uploadButton, data.error ?? 'Upload failed.', 'error');
            }
        } catch (e) {
            ragStatusPill(uploadButton, 'Request failed: ' + e.message, 'error');
        } finally {
            uploadButton.disabled = false;
        }
    });

    loadFiles();
}

function ragFormatModelSize(bytes) {
    bytes = parseInt(bytes, 10) || 0;
    if (bytes === 0) return '';
    const gb = bytes / 1073741824;
    return gb >= 1 ? gb.toFixed(1) + ' GB' : (bytes / 1048576).toFixed(0) + ' MB';
}

function ragBuildSettingsTab(panel) {
    const { card: chatCard, body: chatBody } = ragCard(
        'Frontend Chat',
        'Controls whether the AI chat interface is visible to users on the Knowledge Base page.'
    );
    panel.appendChild(chatCard);

    const chatRow = document.createElement('div');
    chatRow.style.cssText = 'display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-radius:6px;border:1px solid var(--border);background:var(--bg);';

    const chatCheckbox = document.createElement('input');
    chatCheckbox.type    = 'checkbox';
    chatCheckbox.id      = 'rag-chat-enabled';
    chatCheckbox.checked = true;
    chatCheckbox.style.cssText = 'margin-top:3px;flex-shrink:0;cursor:pointer;';

    const chatTextWrap = document.createElement('label');
    chatTextWrap.htmlFor = 'rag-chat-enabled';
    chatTextWrap.style.cssText = 'cursor:pointer;';

    const chatTitle = document.createElement('div');
    chatTitle.style.cssText = 'font-weight:600;';
    chatTitle.textContent = 'Enable AI chat on the front end';

    const chatDescription = document.createElement('div');
    chatDescription.style.cssText = 'margin-top:2px;';
    chatDescription.textContent = 'When unchecked, the chat input and send button are hidden for all users. The document list remains visible. Useful when Ollama is not yet set up or during maintenance.';

    chatTextWrap.appendChild(chatTitle);
    chatTextWrap.appendChild(chatDescription);
    chatRow.appendChild(chatCheckbox);
    chatRow.appendChild(chatTextWrap);
    chatBody.appendChild(chatRow);

    const { card: connCard, body: connBody } = ragCard(
        'Ollama Connection',
        'Configure the local Ollama instance. Run "ollama serve" to start it.'
    );
    panel.appendChild(connCard);

    const urlLabel = document.createElement('label');
    urlLabel.htmlFor = 'rag-ollama-url';
    urlLabel.textContent = 'Ollama URL';
    urlLabel.className = 'adm-field-label';

    const urlRow = document.createElement('div');
    urlRow.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:16px;';

    const urlInput = document.createElement('input');
    urlInput.type        = 'text';
    urlInput.id          = 'rag-ollama-url';
    urlInput.placeholder = 'http://localhost:11434';
    urlInput.className = 'adm-input flex-1';

    const checkButton = document.createElement('button');
    checkButton.type      = 'button';
    checkButton.textContent = 'Test & load models';
    checkButton.className = 'btn btn-secondary';
    checkButton.style.flexShrink = '0';

    urlRow.appendChild(urlInput);
    urlRow.appendChild(checkButton);
    connBody.appendChild(urlLabel);
    connBody.appendChild(urlRow);

    const statusLine = document.createElement('div');
    statusLine.style.cssText = 'display:none;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-weight:600;';
    connBody.appendChild(statusLine);

    const modelLabel = document.createElement('label');
    modelLabel.htmlFor = 'rag-model-select';
    modelLabel.textContent = 'Model';
    modelLabel.className = 'adm-field-label';

    const modelRow = document.createElement('div');
    modelRow.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:16px;';

    const modelSelect = document.createElement('select');
    modelSelect.id = 'rag-model-select';
    modelSelect.className = 'adm-input flex-1';
    const placeholderOption = document.createElement('option');
    placeholderOption.value       = '';
    placeholderOption.textContent = '— click "Test & load models" to populate —';
    modelSelect.appendChild(placeholderOption);

    const modelManualInput = document.createElement('input');
    modelManualInput.type        = 'text';
    modelManualInput.id          = 'rag-model-manual';
    modelManualInput.placeholder = 'or type model name manually';
    modelManualInput.className = 'adm-input flex-1';
    modelManualInput.style.display = 'none';

    const toggleManualButton = document.createElement('button');
    toggleManualButton.type = 'button';
    toggleManualButton.textContent = 'Type manually';
    toggleManualButton.className = 'btn btn-secondary btn-sm';
    toggleManualButton.style.flexShrink = '0';

    let manualMode = false;
    toggleManualButton.addEventListener('click', () => {
        manualMode = !manualMode;
        modelSelect.style.display      = manualMode ? 'none' : '';
        modelManualInput.style.display   = manualMode ? '' : 'none';
        toggleManualButton.textContent    = manualMode ? 'Use dropdown' : 'Type manually';
    });

    modelRow.appendChild(modelSelect);
    modelRow.appendChild(modelManualInput);
    modelRow.appendChild(toggleManualButton);
    connBody.appendChild(modelLabel);
    connBody.appendChild(modelRow);

    const modelsTable = document.createElement('div');
    modelsTable.style.display = 'none';
    modelsTable.style.marginBottom = '16px';
    connBody.appendChild(modelsTable);

    const sslRow = document.createElement('div');
    sslRow.style.cssText = 'display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:10px 14px;border-radius:6px;border:1px solid var(--border);background:var(--bg);';

    const sslCheckbox = document.createElement('input');
    sslCheckbox.type    = 'checkbox';
    sslCheckbox.id      = 'rag-ssl-verify';
    sslCheckbox.checked = true;
    sslCheckbox.style.cssText = 'margin-top:3px;flex-shrink:0;cursor:pointer;';

    const sslTextWrap = document.createElement('label');
    sslTextWrap.htmlFor = 'rag-ssl-verify';
    sslTextWrap.style.cssText = 'cursor:pointer;';

    const sslTitle = document.createElement('div');
    sslTitle.style.cssText = 'font-weight:600;';
    sslTitle.textContent = 'Verify SSL certificate';

    const sslDescription = document.createElement('div');
    sslDescription.style.cssText = 'margin-top:2px;';
    sslDescription.textContent = 'Disable only when using a tunnel (e.g. Serveo, ngrok) that presents a certificate your server cannot verify. Never disable in production.';

    sslTextWrap.appendChild(sslTitle);
    sslTextWrap.appendChild(sslDescription);
    sslRow.appendChild(sslCheckbox);
    sslRow.appendChild(sslTextWrap);
    connBody.appendChild(sslRow);

    const apiKeyLabel = document.createElement('label');
    apiKeyLabel.htmlFor = 'rag-ollama-api-key';
    apiKeyLabel.textContent = 'Ollama Cloud API key';
    apiKeyLabel.className = 'adm-field-label';

    const apiKeyRow = document.createElement('div');
    apiKeyRow.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;';

    const apiKeyInput = document.createElement('input');
    apiKeyInput.type        = 'password';
    apiKeyInput.id          = 'rag-ollama-api-key';
    apiKeyInput.placeholder = 'Leave blank to keep the current key';
    apiKeyInput.autocomplete = 'new-password';
    apiKeyInput.className = 'adm-input flex-1';

    const apiKeyClearButton = document.createElement('button');
    apiKeyClearButton.type = 'button';
    apiKeyClearButton.textContent = 'Clear key';
    apiKeyClearButton.className = 'btn btn-secondary btn-sm';
    apiKeyClearButton.style.flexShrink = '0';

    apiKeyRow.appendChild(apiKeyInput);
    apiKeyRow.appendChild(apiKeyClearButton);
    connBody.appendChild(apiKeyLabel);
    connBody.appendChild(apiKeyRow);

    const apiKeyStatus = document.createElement('div');
    apiKeyStatus.style.cssText = 'margin-bottom:16px;';
    connBody.appendChild(apiKeyStatus);

    let apiKeyClearRequested = false;
    function renderApiKeyStatus(configured) {
        apiKeyStatus.textContent = configured ? 'API key configured.' : 'No API key set.';
    }
    apiKeyClearButton.addEventListener('click', () => {
        apiKeyClearRequested = true;
        apiKeyInput.value = '';
        renderApiKeyStatus(false);
    });
    apiKeyInput.addEventListener('input', () => {
        if (apiKeyInput.value !== '') {
            apiKeyClearRequested = false;
        }
    });

    function ragField(label, id, placeholder) {
        const group = document.createElement('div');
        const lbl = document.createElement('label');
        lbl.htmlFor = id;
        lbl.textContent = label;
        lbl.className = 'adm-field-label';
        const input = document.createElement('input');
        input.type = 'text';
        input.id = id;
        input.placeholder = placeholder;
        input.className = 'adm-input w-full';
        group.appendChild(lbl);
        group.appendChild(input);
        return { group, inp: input };
    }

    const otherGrid = document.createElement('div');
    otherGrid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:16px;';
    const { group: ctxGroup,     inp: ctxInput }     = ragField('Max context files', 'rag-max-ctx', '3');
    const { group: sizeGroup,    inp: sizeInput }    = ragField('Max file size (MB)', 'rag-max-size', '10');
    const { group: timeoutGroup, inp: timeoutInput } = ragField('Ollama timeout (s)', 'rag-timeout', '120');
    const { group: memGroup,     inp: memInput }     = ragField('Conversation memory (turns)', 'rag-conv-turns', '0');
    memInput.title = '0 = disabled; max 10. Each turn = one user question + one assistant reply.';
    const { group: aggGroup, inp: aggInput } = ragField('Aggregate view rows', 'rag-agg-limit', '100');
    aggInput.title = 'Max rows read from an aggregate view and put in the prompt (1-1000). '
        + 'Too low a value truncates the data and the assistant will answer "not in the context" '
        + 'for rows that never reached it.';
    otherGrid.appendChild(ctxGroup);
    otherGrid.appendChild(sizeGroup);
    otherGrid.appendChild(timeoutGroup);
    otherGrid.appendChild(memGroup);
    otherGrid.appendChild(aggGroup);
    connBody.appendChild(otherGrid);

    const chunksRow = document.createElement('div');
    chunksRow.style.cssText = 'display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:10px 14px;border-radius:6px;border:1px solid var(--border);background:var(--bg);';

    const chunksCheckbox = document.createElement('input');
    chunksCheckbox.type    = 'checkbox';
    chunksCheckbox.id      = 'rag-use-chunks';
    chunksCheckbox.checked = true;
    chunksCheckbox.style.cssText = 'margin-top:3px;flex-shrink:0;cursor:pointer;';

    const chunksTextWrap = document.createElement('label');
    chunksTextWrap.htmlFor = 'rag-use-chunks';
    chunksTextWrap.style.cssText = 'cursor:pointer;';

    const chunksTitle = document.createElement('div');
    chunksTitle.style.cssText = 'font-weight:600;';
    chunksTitle.textContent = 'Use document chunking';

    const chunksDescription = document.createElement('div');
    chunksDescription.style.cssText = 'margin-top:2px;';
    chunksDescription.textContent = 'When enabled, uploaded documents are split into overlapping chunks for fine-grained retrieval. Disable to send full file content directly — better for small documents.';

    chunksTextWrap.appendChild(chunksTitle);
    chunksTextWrap.appendChild(chunksDescription);
    chunksRow.appendChild(chunksCheckbox);
    chunksRow.appendChild(chunksTextWrap);
    connBody.appendChild(chunksRow);

    const saveButton = document.createElement('button');
    saveButton.type = 'button';
    saveButton.textContent = 'Save Settings';
    saveButton.className = 'btn btn-primary';
    connBody.appendChild(saveButton);

    const pullHint = document.createElement('p');
    pullHint.style.cssText = 'margin:14px 0 0;';
    pullHint.innerHTML = 'Pull a model: <code>ollama pull llama3</code> &nbsp;|&nbsp; '
        + 'Popular: <code>llama3</code>, <code>mistral</code>, <code>gemma3</code>, <code>phi3</code>, <code>qwen2.5</code>';
    connBody.appendChild(pullHint);

    function populateModelSelect(models, currentModel) {
        while (modelSelect.options.length > 1) modelSelect.remove(1);

        models.forEach(m => {
            const option = document.createElement('option');
            option.value       = m.name;
            option.textContent = m.name + (m.size ? '  (' + ragFormatModelSize(m.size) + ')' : '');
            modelSelect.appendChild(option);
        });

        if (currentModel) {
            for (const option of modelSelect.options) {
                if (option.value === currentModel) { option.selected = true; break; }
            }

            if (!modelSelect.value) {
                const option = document.createElement('option');
                option.value = currentModel;
                option.textContent = currentModel + '  (not pulled locally)';
                modelSelect.insertBefore(option, modelSelect.options[1]);
                option.selected = true;
            }
        }
    }

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
        ['Model name', 'Size', 'Modified'].forEach(column => {
            const th = document.createElement('th');
            th.textContent = column;
            th.className = 'adm-th adm-th-sm';
            hr.appendChild(th);
        });

        const tbody = tbl.createTBody();
        models.forEach(m => {
            const row = tbody.insertRow();
            const tdStyle = 'padding:8px 10px;border-bottom:1px solid var(--border);';

            const td1 = row.insertCell();
            td1.style.cssText = tdStyle + 'font-weight:500;';
            td1.textContent   = m.name;

            const td2 = row.insertCell();
            td2.style.cssText = tdStyle + '';
            td2.textContent   = ragFormatModelSize(m.size) || '—';

            const td3 = row.insertCell();
            td3.style.cssText = tdStyle + '';
            td3.textContent   = m.modified ? new Date(m.modified).toLocaleDateString() : '—';

            row.style.cursor = 'pointer';
            row.title        = 'Click to select this model';
            row.addEventListener('mouseover', () => { row.style.background = 'var(--accent-light)'; });
            row.addEventListener('mouseout',  () => { row.style.background = ''; });
            row.addEventListener('click', () => {
                for (const option of modelSelect.options) {
                    if (option.value === m.name) { option.selected = true; break; }
                }
                if (manualMode) {
                    modelManualInput.value = m.name;
                }
            });
        });

        tbl.appendChild(tbody);
        modelsTable.appendChild(tbl);
    }

    async function doCheck() {
        const url = urlInput.value.trim();
        if (!url) { urlInput.focus(); return; }

        checkButton.disabled    = true;
        checkButton.textContent = 'Connecting…';
        statusLine.style.display = 'block';
        statusLine.style.cssText = 'display:block;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-weight:600;background:var(--warn-light);color:var(--muted);border:1px solid var(--warn);';
        statusLine.textContent   = 'Connecting to ' + url + '…';

        try {
            const res  = await apiFetch('api.php?action=rag_ollama_check', {
                method: 'POST',
                body: JSON.stringify({ ollama_url: url, ssl_verify: sslCheckbox.checked }),
            });
            const data = await res.json();

            if (data.status === 'success') {
                const n = (data.models ?? []).length;
                statusLine.style.cssText = 'display:block;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-weight:600;background:var(--ok-light);color:var(--ok);border:1px solid var(--ok);';
                statusLine.textContent   = '✓ Connected · ' + n + ' model' + (n !== 1 ? 's' : '') + ' available'
                    + (data.version ? ' · Ollama ' + data.version : '');

                const currentModel = manualMode ? modelManualInput.value.trim() : (modelSelect.value || '');
                populateModelSelect(data.models ?? [], currentModel);
                renderModelsTable(data.models ?? [], data.version ?? '');
            } else {
                statusLine.style.cssText = 'display:block;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-weight:600;background:var(--error-light);color:var(--error);border:1px solid var(--error);';
                statusLine.textContent   = '✗ ' + (data.error ?? 'Connection failed');
                modelsTable.style.display = 'none';
            }
        } catch (e) {
            statusLine.style.cssText = 'display:block;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-weight:600;background:var(--error-light);color:var(--error);border:1px solid var(--error);';
            statusLine.textContent   = '✗ Request failed: ' + e.message;
            modelsTable.style.display = 'none';
        } finally {
            checkButton.disabled    = false;
            checkButton.textContent = 'Test & load models';
        }
    }

    checkButton.addEventListener('click', doCheck);

    (async () => {
        try {
            const res  = await apiFetch('api.php?action=rag_settings');
            const data = await res.json();
            if (data.status === 'success' && data.settings) {
                const s = data.settings;
                if (s.ollama_url)        urlInput.value         = s.ollama_url;
                if (s.ollama_model)      modelManualInput.value = s.ollama_model;
                if (s.max_context_files) ctxInput.value         = s.max_context_files;
                if (s.max_file_size_mb)  sizeInput.value        = s.max_file_size_mb;
                if (s.ollama_timeout)    timeoutInput.value     = s.ollama_timeout;
                if (s.ollama_ssl_verify !== undefined) sslCheckbox.checked = !!s.ollama_ssl_verify;
                if (s.use_chunks !== undefined) chunksCheckbox.checked = !!s.use_chunks;
                if (s.conversation_turns !== undefined) memInput.value = s.conversation_turns;
                if (s.aggregate_view_limit !== undefined) aggInput.value = s.aggregate_view_limit;
                if (s.chat_enabled !== undefined) chatCheckbox.checked = !!s.chat_enabled;
                renderApiKeyStatus(!!s.ollama_api_key_configured);

                if (s.ollama_model) {
                    const option = document.createElement('option');
                    option.value = s.ollama_model;
                    option.textContent = s.ollama_model + '  (saved)';
                    option.selected = true;
                    modelSelect.insertBefore(option, modelSelect.options[1] ?? null);
                }
            }
        } catch (e) { console.warn('[rag] settings unavailable, using defaults', e); }
    })();

    saveButton.addEventListener('click', async () => {
        const modelValue = manualMode
            ? modelManualInput.value.trim()
            : (modelSelect.value.trim());

        const payload = {
            ollama_url:          urlInput.value.trim(),
            ollama_model:        modelValue,
            max_context_files:   parseInt(ctxInput.value, 10) || 3,
            max_file_size_mb:    parseInt(sizeInput.value, 10) || 10,
            ollama_timeout:      parseInt(timeoutInput.value, 10) || 120,
            ssl_verify:          sslCheckbox.checked,
            use_chunks:          chunksCheckbox.checked,
            conversation_turns:  Math.max(0, Math.min(10, parseInt(memInput.value, 10) || 0)),
            chat_enabled:        chatCheckbox.checked,
            aggregate_view_limit: Math.max(1, Math.min(1000, parseInt(aggInput.value, 10) || 100)),
        };
        if (apiKeyInput.value !== '') {
            payload.ollama_api_key = apiKeyInput.value;
        } else if (apiKeyClearRequested) {
            payload.ollama_api_key_clear = true;
        }
        if (payload.chat_enabled && (!payload.ollama_url || !payload.ollama_model)) {
            ragStatusPill(saveButton, 'URL and model are required when chat is enabled.', 'error');
            return;
        }
        try {
            const res  = await apiFetch('api.php?action=rag_settings_save', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            ragStatusPill(saveButton, data.status === 'success' ? 'Saved.' : (data.error ?? 'Error.'), data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') {
                apiKeyInput.value = '';
                if (apiKeyClearRequested) {
                    renderApiKeyStatus(false);
                } else if (payload.ollama_api_key) {
                    renderApiKeyStatus(true);
                }
                apiKeyClearRequested = false;
            }
        } catch (e) {
            ragStatusPill(saveButton, 'Request failed: ' + e.message, 'error');
        }
    });

    ragBuildAggregateViewsCard(panel);
}

function ragBuildAggregateViewsCard(panel) {
    const { card, body } = ragCard(
        'Aggregate Views',
        'Attach a SQL view you’ve written (e.g. v_companies_aggr) to a table so the assistant can answer '
            + 'count/sum/average questions with exact totals over the full table, not just the visible page. '
            + 'Only tables without owner-level restriction are eligible — a plain view cannot filter by the current user.'
    );
    panel.appendChild(card);

    const tableWrap = document.createElement('div');
    tableWrap.style.cssText = 'overflow-x:auto;margin-bottom:16px;';
    body.appendChild(tableWrap);

    const table = document.createElement('table');
    table.className = 'adm-tbl';
    const thead = table.createTHead();
    const hdrRow = thead.insertRow();
    ['Table', 'View', ''].forEach(column => {
        const th = document.createElement('th');
        th.textContent = column;
        th.className = 'adm-th';
        hdrRow.appendChild(th);
    });
    const tbody = table.createTBody();
    table.appendChild(tbody);
    tableWrap.appendChild(table);

    const addRow = document.createElement('div');
    addRow.style.cssText = 'display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;';

    const tableGroup = document.createElement('div');
    const tableLabel = document.createElement('label');
    tableLabel.textContent = 'Table';
    tableLabel.className = 'adm-field-label';
    const tableSelect = document.createElement('select');
    tableSelect.className = 'adm-input w-full';
    tableGroup.appendChild(tableLabel);
    tableGroup.appendChild(tableSelect);

    const viewGroup = document.createElement('div');
    viewGroup.style.flex = '1';
    const viewLabel = document.createElement('label');
    viewLabel.textContent = 'View (schema.view)';
    viewLabel.className = 'adm-field-label';
    const viewInput = document.createElement('input');
    viewInput.type = 'text';
    viewInput.placeholder = 'public.v_companies_aggr';
    viewInput.setAttribute('list', 'rag-agg-view-options');
    viewInput.className = 'adm-input w-full';
    const viewList = document.createElement('datalist');
    viewList.id = 'rag-agg-view-options';
    viewGroup.appendChild(viewLabel);
    viewGroup.appendChild(viewInput);
    viewGroup.appendChild(viewList);

    const addButton = document.createElement('button');
    addButton.type = 'button';
    addButton.textContent = 'Attach';
    addButton.className = 'btn btn-primary';

    addRow.appendChild(tableGroup);
    addRow.appendChild(viewGroup);
    addRow.appendChild(addButton);
    body.appendChild(addRow);

    async function loadAndRender() {
        try {
            const res  = await apiFetch('api.php?action=rag_aggregate_view_list');
            const data = await res.json();
            if (data.status !== 'success') {
                ragStatusPill(addButton, data.error ?? 'Failed to load aggregate views.', 'error');
                return;
            }
            renderMappings(data.mappings ?? {});
            renderTableOptions(data.tables ?? []);
            renderViewOptions(data.available_views ?? []);
        } catch (e) {
            ragStatusPill(addButton, 'Request failed: ' + e.message, 'error');
        }
    }

    function renderTableOptions(tables) {
        tableSelect.innerHTML = '';
        if (tables.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = '— no eligible tables —';
            tableSelect.appendChild(option);
            tableSelect.disabled = true;
            return;
        }
        tableSelect.disabled = false;
        tables.forEach(name => {
            const option = document.createElement('option');
            option.value = name;
            option.textContent = name;
            tableSelect.appendChild(option);
        });
    }

    function renderViewOptions(views) {
        viewList.innerHTML = '';
        views.forEach(v => {
            const option = document.createElement('option');

            option.value = v.schema + '.' + v.name;

            if (v.materialized) option.label = 'materialized';
            viewList.appendChild(option);
        });
    }

    function renderMappings(mappings) {
        tbody.innerHTML = '';
        const entries = Object.entries(mappings);
        if (entries.length === 0) {
            const row = tbody.insertRow();
            const td  = row.insertCell();
            td.colSpan = 3;
            td.textContent = 'No aggregate views attached yet.';
            td.style.cssText = 'padding:16px;text-align:center;font-style:italic;';
            return;
        }
        entries.forEach(([tableName, viewName]) => {
            const row = tbody.insertRow();
            const tdStyle = 'padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:middle;';

            const td1 = row.insertCell();
            td1.style.cssText = tdStyle + 'font-weight:500;';
            td1.textContent   = tableName;

            const td2 = row.insertCell();
            td2.style.cssText = tdStyle;
            td2.textContent   = viewName;

            const td3 = row.insertCell();
            td3.style.cssText = tdStyle;
            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.textContent = 'Remove';
            removeButton.className = 'btn btn-danger btn-xs';
            removeButton.addEventListener('click', () => saveMapping(tableName, '', removeButton));
            td3.appendChild(removeButton);
        });
    }

    async function saveMapping(tableName, viewName, anchorButton) {
        anchorButton.disabled = true;
        try {
            const res  = await apiFetch('api.php?action=rag_aggregate_view_save', {
                method: 'POST',
                body: JSON.stringify({ table: tableName, view: viewName }),
            });
            const data = await res.json();
            if (data.status === 'success') {
                ragStatusPill(anchorButton, viewName ? 'Attached.' : 'Removed.', 'success');
                viewInput.value = '';
                await loadAndRender();
            } else {
                ragStatusPill(anchorButton, data.error ?? 'Error.', 'error');
            }
        } catch (e) {
            ragStatusPill(anchorButton, 'Request failed: ' + e.message, 'error');
        } finally {
            anchorButton.disabled = false;
        }
    }

    addButton.addEventListener('click', () => {
        const tableName = tableSelect.value;
        const viewName  = viewInput.value.trim();
        if (!tableName) { ragStatusPill(addButton, 'Select a table first.', 'error'); return; }
        if (!viewName) { ragStatusPill(addButton, 'Enter a view name.', 'error'); return; }
        saveMapping(tableName, viewName, addButton);
    });

    loadAndRender();
}

function ragBuildTestTab(panel) {
    const { card: testCard, body: testBody } = ragCard(
        'Test Query',
        'Send a question to verify retrieval and Ollama response. Optionally filter by tag.'
    );
    panel.appendChild(testCard);

    const tagRow = document.createElement('div');
    tagRow.style.cssText = 'margin-bottom:14px;';
    const tagLabel = document.createElement('div');
    tagLabel.textContent = 'Filter by tag (optional):';
    tagLabel.style.cssText = 'font-weight:700;margin-bottom:8px;';
    tagRow.appendChild(tagLabel);
    const tagChips = document.createElement('div');
    tagChips.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;min-height:28px;';
    tagChips.innerHTML = '<span style="font-style:italic;">Loading tags…</span>';
    tagRow.appendChild(tagChips);
    testBody.appendChild(tagRow);

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
                    const callback = document.createElement('input');
                    callback.type  = 'checkbox';
                    callback.value = tag;
                    callback.style.accentColor = 'var(--accent)';
                    lbl.appendChild(callback);
                    lbl.appendChild(document.createTextNode(tag));
                    tagChips.appendChild(lbl);
                });
            }
        } catch (_) {
            tagChips.innerHTML = '<span style="color:var(--error);">Could not load tags.</span>';
        }
    })();

    const langRow = document.createElement('div');
    langRow.style.cssText = 'margin-bottom:14px;';
    const langLabel = document.createElement('div');
    langLabel.textContent = 'Response language (optional):';
    langLabel.style.cssText = 'font-weight:700;margin-bottom:8px;';
    langRow.appendChild(langLabel);
    const langSelect = document.createElement('select');
    langSelect.className = 'adm-input w-180';
    const langNoneOption = document.createElement('option');
    langNoneOption.value = '';
    langNoneOption.textContent = '— auto-detect —';
    langSelect.appendChild(langNoneOption);
    langRow.appendChild(langSelect);
    testBody.appendChild(langRow);

    (async () => {
        try {
            const res  = await apiFetch('api.php?action=get_language_setting');
            const data = await res.json();
            const available = data.available_languages ?? [];
            const current   = document.documentElement.lang || '';
            available.forEach(code => {
                const option = document.createElement('option');
                option.value       = code;
                option.textContent = code.toUpperCase();
                if (code === current) option.selected = true;
                langSelect.appendChild(option);
            });
        } catch (e) { console.warn('[rag] language hint unavailable', e); }
    })();

    const queryRow = document.createElement('div');
    queryRow.style.cssText = 'display:flex;gap:10px;margin-bottom:16px;';
    const queryInput = document.createElement('input');
    queryInput.type = 'text';
    queryInput.placeholder = 'Enter test question…';
    queryInput.className = 'adm-input flex-1';
    const runButton = document.createElement('button');
    runButton.type = 'button';
    runButton.textContent = 'Run';
    runButton.className = 'btn btn-primary';
    const testStopButton = document.createElement('button');
    testStopButton.type = 'button';
    testStopButton.textContent = 'Stop';
    testStopButton.disabled = true;
    testStopButton.className = 'btn btn-danger';
    testStopButton.style.opacity = '0.35';
    queryRow.appendChild(queryInput);
    queryRow.appendChild(runButton);
    queryRow.appendChild(testStopButton);
    testBody.appendChild(queryRow);

    let testAbortCtrl = null;

    const resultWrap = document.createElement('div');
    resultWrap.style.display = 'none';
    testBody.appendChild(resultWrap);

    const answerLabel = document.createElement('div');
    answerLabel.textContent = 'Answer';
    answerLabel.style.cssText = 'font-weight:700;margin-bottom:6px;';

    const answerBox = document.createElement('div');
    answerBox.style.cssText = 'padding:14px;background:var(--bg);border:1px solid var(--border);border-radius:4px;line-height:1.7;white-space:pre-wrap;word-break:break-word;max-height:320px;overflow-y:auto;margin-bottom:12px;';

    const sourcesLabel = document.createElement('div');
    sourcesLabel.textContent = 'Sources used';
    sourcesLabel.style.cssText = 'font-weight:700;margin-bottom:6px;';

    const sourcesRow = document.createElement('div');
    sourcesRow.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;';

    resultWrap.appendChild(answerLabel);
    resultWrap.appendChild(answerBox);
    resultWrap.appendChild(sourcesLabel);
    resultWrap.appendChild(sourcesRow);

    const { card: promptCard, body: promptBody } = ragCard(
        'Prompt Preview',
        'Shows the exact prompt sent to Ollama for the last query — useful for debugging context quality.'
    );
    panel.appendChild(promptCard);

    const promptBox = document.createElement('pre');
    promptBox.style.cssText = 'margin:0;line-height:1.6;white-space:pre-wrap;word-break:break-word;font-style:italic;';
    promptBox.textContent = 'Run a query above to see the prompt.';
    promptBody.appendChild(promptBox);

    testStopButton.addEventListener('click', () => {
        testAbortCtrl?.abort();
    });

    async function runQuery() {
        const q = queryInput.value.trim();
        if (!q) return;

        const tags     = Array.from(tagChips.querySelectorAll('input[type=checkbox]:checked')).map(c => c.value);
        const language = langSelect.value;

        testAbortCtrl = new AbortController();
        runButton.disabled = true;
        testStopButton.disabled = false;
        testStopButton.style.opacity = '1';
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
                answerBox.style.color = 'var(--error)';
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
                answerBox.style.color = 'var(--error)';
            }
        } catch (e) {
            if (e.name === 'AbortError') {
                answerBox.textContent = 'Query cancelled.';
            } else {
                answerBox.textContent = 'Request failed: ' + e.message;
            }
            answerBox.style.color = 'var(--error)';
        } finally {
            testAbortCtrl = null;
            runButton.disabled = false;
            testStopButton.disabled = true;
            testStopButton.style.opacity = '0.35';
        }
    }

    runButton.addEventListener('click', runQuery);
    queryInput.addEventListener('keydown', e => { if (e.key === 'Enter') runQuery(); });
}

function ragBuildStatisticsTab(panel) {
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
        box.style.cssText = 'text-align:center;padding:16px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg);';
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
    ['Time', 'Query', 'Tags', 'Files', 'Model', 'Prompt T', 'Comp T', 'Time (s)', 'Sources'].forEach(column => {
        const th = document.createElement('th');
        th.textContent = column;
        th.className = 'adm-th adm-th-sm';
        hr.appendChild(th);
    });
    const tbody = tbl.createTBody();
    tbl.appendChild(tbody);
    tableWrap.appendChild(tbl);

    const refreshButton = document.createElement('button');
    refreshButton.type = 'button';
    refreshButton.textContent = 'Refresh';
    refreshButton.className = 'btn btn-secondary btn-sm';
    refreshButton.style.marginTop = '14px';
    recentBody.appendChild(refreshButton);

    async function load() {
        refreshButton.disabled = true;
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
            const tdStyle = 'padding:8px 10px;border-bottom:1px solid var(--border);vertical-align:middle;';
            rows.forEach(r => {
                const row = tbody.insertRow();
                row.style.cursor = 'pointer';
                row.title = 'Click to view sources and prompt';
                row.addEventListener('mouseover', () => { if (!row.dataset.expanded) row.style.background = 'var(--accent-mid)'; });
                row.addEventListener('mouseout',  () => { if (!row.dataset.expanded) row.style.background = ''; });

                const td1 = row.insertCell();
                td1.style.cssText = tdStyle + 'white-space:nowrap;';
                td1.textContent   = ragFormatDate(r.created_at);

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
                    dtd.style.cssText = 'padding:14px 20px;background:var(--accent-light);border-bottom:2px solid var(--border);';

                    if (srcs.length > 0) {
                        const sourceHdr = document.createElement('div');
                        sourceHdr.textContent = 'Sources used (' + srcs.length + ')';
                        sourceHdr.style.cssText = 'font-weight:700;margin-bottom:8px;';
                        dtd.appendChild(sourceHdr);

                        const sourceGrid = document.createElement('div');
                        sourceGrid.style.cssText = 'display:flex;flex-direction:column;gap:6px;margin-bottom:16px;';
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
                            sourceGrid.appendChild(card);
                        });
                        dtd.appendChild(sourceGrid);
                    }

                    if (r.prompt_snapshot) {
                        const promptToggle = document.createElement('div');
                        promptToggle.style.cssText = 'display:inline-flex;align-items:center;gap:6px;cursor:pointer;margin-bottom:6px;user-select:none;';
                        const promptLabel = document.createElement('span');
                        promptLabel.style.cssText = 'font-weight:700;';
                        promptLabel.textContent = 'Full prompt sent to Ollama';
                        const promptArrow = document.createElement('span');
                        promptArrow.textContent = '▾';
                        promptArrow.style.cssText = 'transition:transform .15s;display:inline-block;';
                        promptToggle.appendChild(promptLabel);
                        promptToggle.appendChild(promptArrow);

                        const promptBox = document.createElement('pre');
                        promptBox.style.cssText = 'display:none;margin:0;padding:12px;background:var(--accent-light);border:1px solid var(--border);border-radius:4px;line-height:1.5;white-space:pre-wrap;word-break:break-word;max-height:280px;overflow-y:auto;color:var(--text);';
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
            td.style.cssText = 'padding:16px;color:var(--error);text-align:center;';
        } finally {
            refreshButton.disabled = false;
        }
    }

    refreshButton.addEventListener('click', load);
    load();
}

export async function renderRagPage(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '';

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
    workspaceElement.appendChild(wrap);

    const tabDefs = [
        { id: 'documents',  label: 'Documents',   icon: 'docs.png' },
        { id: 'test',       label: 'Test',         icon: 'playground.png' },
        { id: 'statistics', label: 'Statistics',   icon: 'dashboard.png' },
        { id: 'settings',   label: 'Global Settings', icon: 'build.png' },
    ];

    const { panels } = ragBuildTabs(wrap, tabDefs);

    ragBuildDocumentsTab(panels.documents);
    ragBuildSettingsTab(panels.settings);
    ragBuildTestTab(panels.test);
    ragBuildStatisticsTab(panels.statistics);
}
