// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { getCsrfToken } from '../../assets/js/util/csrf.js';
import { showStatusPill } from './app.js';

const FILES_API = '../api/files.php';

const TYPE_ICONS = {
    image: '[IMG]', pdf: '[PDF]', doc: '[DOC]',
    spreadsheet: '[XLS]', archive: '[ZIP]', other: '[FILE]',
};
const ALL_TYPES  = ['image', 'pdf', 'doc', 'spreadsheet', 'archive', 'other'];
const PER_PAGE   = 25;

let _state = {};

function resetState() {
    _state = {
        config:  {},
        files:   [],
        page:    1,
        total:   0,
        pages:   1,
        type:    'all',
        search:  '',
        loading: false,
        getTableOptions: null,
        getColumnOptionsForTable: null
    };
}

export async function renderFilesEditor(context) {
    const { workspaceEl: workspaceElement, currentConfig, getTableOptions, getColumnOptionsForTable } = context;
    resetState();
    _state.getTableOptions = getTableOptions;
    _state.getColumnOptionsForTable = getColumnOptionsForTable;

    _state.config = currentConfig;

    if (!_state.config.max_file_size_mb) _state.config.max_file_size_mb = 20;
    if (!_state.config.storage_path) _state.config.storage_path = 'storage/files/';
    if (!_state.config.allowed_types) _state.config.allowed_types = ['image', 'spreadsheet', 'archive', 'other'];

    if (!_state.config.allowed_extensions) _state.config.allowed_extensions = ["jpg", "jpeg", "png", "gif", "webp", "pdf", "doc", "docx", "odt", "rtf", "xls", "xlsx", "ods", "csv", "zip", "tar", "gz"];
    if (!_state.config.relations) _state.config.relations = [];

    workspaceElement.innerHTML = '';
    workspaceElement.appendChild(buildSkeleton());

    fillConfigForm(_state.config);
    bindEvents(workspaceElement);

    await loadList();
}

function buildSkeleton() {
    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    wrap.innerHTML = `
        <div class="adm-sec-card" id="files-cfg-block">
            <div class="adm-sec-hdr" style="display:block;"><h3 style="margin:0;">Configuration</h3></div>
            <div class="adm-sec-body">
            <div class="form-group">
                <label>Max file size (MB)</label>
                <input id="f-max-size" type="number" min="1" max="500" class="adm-input" style="width:120px">
            </div>
            <div class="form-group">
                <label>Storage path <span class="help-text" style="display:inline">(relative to project root, must not be web-accessible)</span></label>
                <input id="f-storage-path" type="text" class="adm-input" style="max-width:340px">
            </div>

            <div class="form-group">
                <label>Allowed types</label>
                <div id="f-allowed-types" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px"></div>
            </div>

            <div class="form-group">
                <label>Allowed Extensions (comma separated)</label>
                <input id="f-allowed-exts" type="text" class="adm-input w-full" placeholder="jpg, png, pdf, zip">
            </div>

            <div class="form-group" style="margin-top:20px; padding-top:15px; border-top:1px solid var(--border-light);">
                <label style="font-weight:bold;">Allowed Record Relations (Auto-Link)</label>
                <div id="f-relations-list" style="display:flex; flex-direction:column; gap:10px; margin-top:10px;"></div>
                <button id="f-add-relation-btn" type="button" class="btn btn-primary btn-xs" style="margin-top:10px;">+ Add Relation</button>
            </div>

            <button type="button" id="f-save-cfg" class="btn btn-success">Save configuration</button>
            <span id="f-cfg-msg" style="margin-left:12px;"></span>
            </div>
        </div>

        <div class="adm-sec-card" id="files-upload-block" style="margin-top: 20px">
            <div class="adm-sec-hdr" style="display:block;"><h3 style="margin:0;">Upload File</h3></div>
            <div class="adm-sec-body">
            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                <div class="form-group" style="margin-bottom:0">
                    <label>Select file</label>
                    <input type="file" id="f-upload-file">
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label>Display name (optional)</label>
                    <input type="text" id="f-upload-name" class="adm-input" placeholder="Leave empty to use original">
                </div>
                <button type="button" id="f-upload-btn" class="btn btn-success">Upload</button>
            </div>
            <div id="f-upload-status" style="margin-top:8px;"></div>
            </div>
        </div>

        <div class="adm-sec-card" id="files-lib-block" style="margin-top: 20px">
            <div class="adm-sec-hdr" style="display:block;"><h3 style="margin:0;">File Library</h3></div>
            <div class="adm-sec-body">
            <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap">
                <input id="f-search" type="search" placeholder="Search by name" class="adm-input flex-1" style="min-width:160px;max-width:300px">
                <select id="f-type-filter" class="adm-input w-160">
                    <option value="all">All types</option>
                    ${ALL_TYPES.map(typeName => `<option value="${typeName}">${cap(typeName)}</option>`).join('')}
                </select>
                <button type="button" id="f-refresh" class="btn btn-success btn-sm">Refresh</button>
            </div>
            <div id="f-status" style="color:var(--muted);margin-bottom:8px"></div>
            <table class="adm-tbl" id="f-table">
                <thead>
                    <tr>
                        <th class="adm-th" style="width:40px"></th>
                        <th class="adm-th">Name</th>
                        <th class="adm-th">Type</th>
                        <th class="adm-th">Size</th>
                        <th class="adm-th">Related To</th>
                        <th class="adm-th">Uploaded by</th>
                        <th class="adm-th">Date</th>
                        <th class="adm-th" style="width:70px">Actions</th>
                    </tr>
                </thead>
                <tbody id="f-tbody">
                    <tr><td colspan="8" class="adm-td" style="color:var(--muted)">Loading...</td></tr>
                </tbody>
            </table>
            <div id="f-pages" style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap"></div>
            </div>
        </div>
    `;

    bindEvents(wrap);
    return wrap;
}

function bindEvents(root) {
    root.querySelector('#f-save-cfg').addEventListener('click', saveConfig);
    root.querySelector('#f-upload-btn').addEventListener('click', uploadFile);
    root.querySelector('#f-add-relation-btn').addEventListener('click', () => addRelationRow());

    root.querySelector('#f-search').addEventListener('input', debounce(event => {
        _state.search = event.target.value.trim();
        _state.page   = 1;
        loadList();
    }, 350));

    root.querySelector('#f-type-filter').addEventListener('change', event => {
        _state.type = event.target.value;
        _state.page = 1;
        loadList();
    });

    root.querySelector('#f-refresh').addEventListener('click', loadList);
}

function addRelationRow(data = { table: '', col1: '', col2: '' }) {
    const list = document.getElementById('f-relations-list');
    const row = document.createElement('div');
    row.className = 'f-relation-row';
    row.style.cssText = 'display:flex; gap:10px; background:var(--bg); padding:10px; border:1px solid var(--border); border-radius:4px; align-items:flex-end;';

    const tables = _state.getTableOptions ? _state.getTableOptions() : [];

    let tableOptions = '<option value="">-- Target Table --</option>';
    tables.forEach(typeName => tableOptions += `<option value="${escHtml(typeName.value)}" ${data.table === typeName.value ? 'selected' : ''}>${escHtml(typeName.label)}</option>`);

    row.innerHTML = `
        <div style="flex:1">
            <label style=" display:block; margin-bottom:4px;">Table</label>
            <select class="rel-table adm-input w-full">${tableOptions}</select>
        </div>
        <div style="flex:1">
            <label style=" display:block; margin-bottom:4px;">Col 1</label>
            <select class="rel-col1 adm-input w-full"></select>
        </div>
        <div style="flex:1">
            <label style=" display:block; margin-bottom:4px;">Col 2 (Opt)</label>
            <select class="rel-col2 adm-input w-full"></select>
        </div>
        <button type="button" class="btn btn-danger btn-xs btn-del-rel">✕</button>
    `;

    const tableSelect = row.querySelector('.rel-table');
    const col1Select = row.querySelector('.rel-col1');
    const col2Select = row.querySelector('.rel-col2');

    const updateColumns = () => {
        col1Select.innerHTML = '<option value="">-- None --</option>';
        col2Select.innerHTML = '<option value="">-- None --</option>';
        const tableElement = tableSelect.value;
        if (tableElement && _state.getColumnOptionsForTable) {
            const columns = _state.getColumnOptionsForTable(tableElement);
            columns.forEach(column => {
                col1Select.innerHTML += `<option value="${escHtml(column.value)}" ${data.col1 === column.value ? 'selected' : ''}>${escHtml(column.label)}</option>`;
                col2Select.innerHTML += `<option value="${escHtml(column.value)}" ${data.col2 === column.value ? 'selected' : ''}>${escHtml(column.label)}</option>`;
            });
        }
    };

    tableSelect.addEventListener('change', updateColumns);
    row.querySelector('.btn-del-rel').addEventListener('click', () => row.remove());

    updateColumns();
    list.appendChild(row);
}

function fillConfigForm(config) {
    const maxElement  = document.getElementById('f-max-size');
    const pathElement = document.getElementById('f-storage-path');
    const extensionsElement = document.getElementById('f-allowed-exts');
    const typesElement = document.getElementById('f-allowed-types');

    if (!maxElement) return;

    maxElement.value  = config.max_file_size_mb;
    pathElement.value = config.storage_path;
    extensionsElement.value = (config.allowed_extensions || []).join(', ');

    typesElement.innerHTML = ALL_TYPES.map(typeName => `
        <label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-weight:normal">
            <input type="checkbox" value="${typeName}" ${(config.allowed_types || []).includes(typeName) ? 'checked' : ''}>
            <span style="font-weight:bold;color:var(--muted)">${TYPE_ICONS[typeName]}</span> ${cap(typeName)}
        </label>
    `).join('');

    const list = document.getElementById('f-relations-list');
    list.innerHTML = '';
    const relations = config.relations || [];
    relations.forEach(relation => addRelationRow(relation));
}

async function saveConfig() {
    const maxElement   = document.getElementById('f-max-size');
    const pathElement  = document.getElementById('f-storage-path');
    const extensionsElement  = document.getElementById('f-allowed-exts');
    const checks  = document.querySelectorAll('#f-allowed-types input[type=checkbox]:checked');
    const messageElement   = document.getElementById('f-cfg-msg');

    const relations = Array.from(document.querySelectorAll('.f-relation-row')).map(row => {
        return {
            table: row.querySelector('.rel-table').value,
            col1: row.querySelector('.rel-col1').value,
            col2: row.querySelector('.rel-col2').value
        };
    }).filter(relation => relation.table !== '');

    const extensionsArray = extensionsElement.value.split(',').map(extension => extension.trim()).filter(extension => extension.length > 0);

    _state.config.storage_path       = pathElement?.value || 'storage/files/';
    _state.config.max_file_size_mb   = parseInt(maxElement?.value || '20', 10);
    _state.config.allowed_types      = [...checks].map(checkbox => checkbox.value);
    _state.config.allowed_extensions = extensionsArray;
    _state.config.relations          = relations;

    try {
        const result  = await apiFetch('api.php?action=save&file=files', {
            method: 'POST',
            body: JSON.stringify(_state.config),
        });
        const data = await result.json();
        showMessage(messageElement, data.status === 'success' ? 'Saved successfully' : (data.error || 'Save failed'), data.status === 'success');
    } catch {
        showMessage(messageElement, 'Network error during save', false);
    }
}

async function uploadFile() {
    const fileInput = document.getElementById('f-upload-file');
    const nameInput = document.getElementById('f-upload-name');
    const statusElement  = document.getElementById('f-upload-status');

    if (!fileInput.files.length) {
        showMessage(statusElement, 'Please select a file first', false);
        return;
    }

    const csrfToken = getCsrfToken();
    const file = fileInput.files[0];
    const formData = new FormData();

    formData.append('action', 'upload');
    formData.append('file', file);
    formData.append('csrf_token', csrfToken);

    if (nameInput.value.trim()) {
        formData.append('display_name', nameInput.value.trim());
    }

    statusElement.textContent = 'Uploading...';
    statusElement.style.color = 'var(--muted)';

    try {
        const result = await apiFetch(FILES_API, {
            method: 'POST',
            body: formData
        });
        const data = await result.json();

        if (data.success) {
            showMessage(statusElement, 'File uploaded successfully', true);
            fileInput.value = '';
            nameInput.value = '';
            loadList();
        } else {
            showMessage(statusElement, data.error || 'Upload failed', false);
        }
    } catch (error) {
        showMessage(statusElement, 'Network error during upload', false);
    }
}

async function loadList() {
    if (_state.loading) return;
    _state.loading = true;
    setTbody('<tr><td colspan="8" class="adm-td" style="color:var(--muted)">Loading...</td></tr>');

    const parameters = new URLSearchParams({
        action: 'list',
        page:   _state.page,
        limit:  PER_PAGE,
        type:   _state.type,
        search: _state.search,
    });

    try {
        const result  = await apiFetch(`${FILES_API}?${parameters}`);
        const data = await result.json();

        if (!data.success) {
            setTbody(`<tr><td colspan="8" class="adm-td" style="color:var(--error)">${escHtml(data.error || 'Failed to load')}</td></tr>`);
            return;
        }

        _state.files = data.files       || [];
        _state.total = data.total_count || 0;
        _state.pages = data.total_pages || 1;

        renderTable(_state.files);
        renderPager();
        const statusElement = document.getElementById('f-status');
        if (statusElement) statusElement.textContent = `${_state.total} file${_state.total !== 1 ? 's' : ''} found`;
    } catch (error) {
        setTbody('<tr><td colspan="8" class="adm-td" style="color:var(--error)">Network error</td></tr>');
    } finally {
        _state.loading = false;
    }
}

function renderTable(files) {
    if (!files.length) {
        setTbody('<tr><td colspan="8" class="adm-td" style="color:var(--muted)">No files found.</td></tr>');
        return;
    }

    const rows = files.map(fileEntry => `
        <tr data-uuid="${escHtml(fileEntry.uuid)}">
            <td class="adm-td" style="font-weight:bold;text-align:center;color:var(--muted)">${TYPE_ICONS[fileEntry.type] ?? TYPE_ICONS.other}</td>
            <td class="adm-td">
                ${fileEntry.type === 'image'
                    ? `<img src="../file_download.php?uuid=${escHtml(fileEntry.uuid)}&thumb=1" alt="" style="height:32px;width:32px;object-fit:cover;border-radius:3px;vertical-align:middle;margin-right:6px">`
                    : ''}
                <a href="../file_download.php?uuid=${escHtml(fileEntry.uuid)}" target="_blank">${escHtml(fileEntry.display_name || fileEntry.name)}</a>
            </td>
            <td class="adm-td">
                <span class="adm-badge adm-badge-muted">${escHtml(fileEntry.type)}</span>
            </td>
            <td class="adm-td" style="white-space:nowrap">${formatBytes(fileEntry.size_bytes)}</td>
            <td class="adm-td">
                ${fileEntry.related_table ? `<span class="adm-badge adm-badge-muted">${escHtml(fileEntry.related_table)} #${fileEntry.related_id}</span>` : '-'}
            </td>
            <td class="adm-td">${escHtml(fileEntry.uploaded_by_username || '-')}</td>
            <td class="adm-td" style="white-space:nowrap">${formatDate(fileEntry.created_at)}</td>
            <td class="adm-td">
                <button class="btn btn-danger btn-xs" data-del="${escHtml(fileEntry.uuid)}" data-name="${escHtml(fileEntry.display_name || fileEntry.name)}">Del</button>
            </td>
        </tr>
    `).join('');

    setTbody(rows);

    document.querySelectorAll('[data-del]').forEach(button => {
        button.addEventListener('click', () => deleteFile(button.dataset.del, button.dataset.name));
    });
}

async function deleteFile(uuid, name) {
    if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
    try {
        const csrfToken = getCsrfToken();
        const result  = await apiFetch(FILES_API, {
            method: 'POST',
            body: JSON.stringify({ action: 'delete', uuid, csrf_token: csrfToken }),
        });
        const data = await result.json();
        if (data.success) {
            loadList();
        } else {
            showStatusPill(document.getElementById('workspace'), data.error || 'Delete failed.', 'error');
        }
    } catch {
        showStatusPill(document.getElementById('workspace'), 'Network error.', 'error');
    }
}

function renderPager() {
    const bar = document.getElementById('f-pages');
    if (!bar) return;
    const { page, pages } = _state;
    if (pages <= 1) { bar.innerHTML = ''; return; }

    let html = '';
    for (let pageNumber = 1; pageNumber <= pages; pageNumber++) {
        if (pageNumber === 1 || pageNumber === pages || (pageNumber >= page - 2 && pageNumber <= page + 2)) {
            html += `<button data-p="${pageNumber}" class="btn btn-xs ${pageNumber === page ? 'btn-primary' : 'btn-secondary'}">${pageNumber}</button>`;
        } else if (pageNumber === page - 3 || pageNumber === page + 3) {
            html += `<span style="padding:4px 4px;color:var(--muted)">...</span>`;
        }
    }

    bar.innerHTML = html;
    bar.querySelectorAll('[data-p]').forEach(button => {
        button.addEventListener('click', () => {
            _state.page = parseInt(button.dataset.p, 10);
            loadList();
        });
    });
}

function setTbody(html) {
    const element = document.getElementById('f-tbody');
    if (element) element.innerHTML = html;
}

function showMessage(element, text, ok) {
    if (!element) return;
    element.textContent = text;
    element.style.color = ok ? 'var(--ok)' : 'var(--error)';
    setTimeout(() => { element.textContent = ''; }, 4000);
}

function formatBytes(byteCount) {
    if (!byteCount) return '0 B';
    const unitLabels = ['B', 'KB', 'MB', 'GB'];
    let unitIndex = 0, sizeValue = parseInt(byteCount, 10);
    while (sizeValue >= 1024 && unitIndex < unitLabels.length - 1) { sizeValue /= 1024; unitIndex++; }
    return `${sizeValue.toFixed(unitIndex === 0 ? 0 : 1)} ${unitLabels[unitIndex]}`;
}

function formatDate(iso) {
    if (!iso) return '-';
    try { return new Date(iso).toLocaleDateString('en-GB'); } catch { return iso; }
}

import { escHtml } from '../../assets/js/util/esc.js';

function cap(extension) { return extension.charAt(0).toUpperCase() + extension.slice(1); }

function debounce(handler, ms) {
    let typeName;
    return (...a) => { clearTimeout(typeName); typeName = setTimeout(() => handler(...a), ms); };
}
