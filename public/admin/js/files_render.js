// admin/js/files_render.js — Files module UI (admin): lists/filters/paginates files via api/files.php (FILES_API). Text type tags ([IMG]/[PDF]/...), 25 per page.

import { apiFetch } from '../../assets/js/util/api.js';
import { getCsrfToken } from '../../assets/js/util/csrf.js';

const FILES_API = '../api/files.php';

// Text indicators for file types
const TYPE_ICONS = {
    image: '[IMG]', pdf: '[PDF]', doc: '[DOC]',
    spreadsheet: '[XLS]', archive: '[ZIP]', other: '[FILE]',
};
const ALL_TYPES  = ['image', 'pdf', 'doc', 'spreadsheet', 'archive', 'other'];
const PER_PAGE   = 25;

// Module state reset on each render
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

// Entry point called by app.js
export async function renderFilesEditor(ctx) {
    const { workspaceEl, currentConfig, getTableOptions, getColumnOptionsForTable } = ctx;
    resetState();
    _state.getTableOptions = getTableOptions;
    _state.getColumnOptionsForTable = getColumnOptionsForTable;
    
    // Bind directly to global config to avoid overwriting menu settings!
    _state.config = currentConfig; 

    // Ensure defaults exist in global config
    if (!_state.config.max_file_size_mb) _state.config.max_file_size_mb = 20;
    if (!_state.config.storage_path) _state.config.storage_path = 'storage/files/';
    if (!_state.config.allowed_types) _state.config.allowed_types = ['image', 'spreadsheet', 'archive', 'other'];
    if (!_state.config.allowed_extensions) _state.config.allowed_extensions = ["jpg", "jpeg", "png", "gif", "webp", "svg", "pdf", "doc", "docx", "odt", "rtf", "xls", "xlsx", "ods", "csv", "zip", "tar", "gz"];
    if (!_state.config.relations) _state.config.relations = [];

    workspaceEl.innerHTML = '';
    workspaceEl.appendChild(buildSkeleton());

    fillConfigForm(_state.config);
    bindEvents(workspaceEl);

    await loadList();
}

// Skeleton HTML construction
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
                    ${ALL_TYPES.map(t => `<option value="${t}">${cap(t)}</option>`).join('')}
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

// Bind UI events
function bindEvents(root) {
    root.querySelector('#f-save-cfg').addEventListener('click', saveConfig);
    root.querySelector('#f-upload-btn').addEventListener('click', uploadFile);
    root.querySelector('#f-add-relation-btn').addEventListener('click', () => addRelationRow());

    root.querySelector('#f-search').addEventListener('input', debounce(e => {
        _state.search = e.target.value.trim();
        _state.page   = 1;
        loadList();
    }, 350));

    root.querySelector('#f-type-filter').addEventListener('change', e => {
        _state.type = e.target.value;
        _state.page = 1;
        loadList();
    });

    root.querySelector('#f-refresh').addEventListener('click', loadList);
}

// Add dynamic relation row
function addRelationRow(data = { table: '', col1: '', col2: '' }) {
    const list = document.getElementById('f-relations-list');
    const row = document.createElement('div');
    row.className = 'f-relation-row';
    row.style.cssText = 'display:flex; gap:10px; background:#F4F7F9; padding:10px; border:1px solid #CBD5E1; border-radius:4px; align-items:flex-end;';

    const tables = _state.getTableOptions ? _state.getTableOptions() : [];
    
    let tableOpts = '<option value="">-- Target Table --</option>';
    tables.forEach(t => tableOpts += `<option value="${esc(t.value)}" ${data.table === t.value ? 'selected' : ''}>${esc(t.label)}</option>`);

    row.innerHTML = `
        <div style="flex:1">
            <label style=" display:block; margin-bottom:4px;">Table</label>
            <select class="rel-table adm-input w-full">${tableOpts}</select>
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

    const tableSel = row.querySelector('.rel-table');
    const col1Sel = row.querySelector('.rel-col1');
    const col2Sel = row.querySelector('.rel-col2');

    const updateCols = () => {
        col1Sel.innerHTML = '<option value="">-- None --</option>';
        col2Sel.innerHTML = '<option value="">-- None --</option>';
        const tbl = tableSel.value;
        if (tbl && _state.getColumnOptionsForTable) {
            const cols = _state.getColumnOptionsForTable(tbl);
            cols.forEach(col => {
                col1Sel.innerHTML += `<option value="${esc(col.value)}" ${data.col1 === col.value ? 'selected' : ''}>${esc(col.label)}</option>`;
                col2Sel.innerHTML += `<option value="${esc(col.value)}" ${data.col2 === col.value ? 'selected' : ''}>${esc(col.label)}</option>`;
            });
        }
    };

    tableSel.addEventListener('change', updateCols);
    row.querySelector('.btn-del-rel').addEventListener('click', () => row.remove());
    
    updateCols();
    list.appendChild(row);
}

// Fill UI config form
function fillConfigForm(cfg) {
    const maxEl  = document.getElementById('f-max-size');
    const pathEl = document.getElementById('f-storage-path');
    const extsEl = document.getElementById('f-allowed-exts');
    const typesEl = document.getElementById('f-allowed-types');

    if (!maxEl) return;

    maxEl.value  = cfg.max_file_size_mb;
    pathEl.value = cfg.storage_path;
    extsEl.value = (cfg.allowed_extensions || []).join(', ');

    typesEl.innerHTML = ALL_TYPES.map(t => `
        <label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-weight:normal">
            <input type="checkbox" value="${t}" ${(cfg.allowed_types || []).includes(t) ? 'checked' : ''}>
            <span style="font-weight:bold;color:#64748B">${TYPE_ICONS[t]}</span> ${cap(t)}
        </label>
    `).join('');

    const list = document.getElementById('f-relations-list');
    list.innerHTML = '';
    const relations = cfg.relations || [];
    relations.forEach(r => addRelationRow(r));
}

// Save config handler - syncs with global app state and saves via core api
async function saveConfig() {
    const maxEl   = document.getElementById('f-max-size');
    const pathEl  = document.getElementById('f-storage-path');
    const extsEl  = document.getElementById('f-allowed-exts');
    const checks  = document.querySelectorAll('#f-allowed-types input[type=checkbox]:checked');
    const msgEl   = document.getElementById('f-cfg-msg');

    const relations = Array.from(document.querySelectorAll('.f-relation-row')).map(row => {
        return {
            table: row.querySelector('.rel-table').value,
            col1: row.querySelector('.rel-col1').value,
            col2: row.querySelector('.rel-col2').value
        };
    }).filter(r => r.table !== '');

    const extsArray = extsEl.value.split(',').map(s => s.trim()).filter(s => s.length > 0);

    // Assign directly to _state.config (which is a reference to the global currentConfig)
    _state.config.storage_path       = pathEl?.value || 'storage/files/';
    _state.config.max_file_size_mb   = parseInt(maxEl?.value || '20', 10);
    _state.config.allowed_types      = [...checks].map(c => c.value);
    _state.config.allowed_extensions = extsArray;
    _state.config.relations          = relations;

    try {
        const res  = await apiFetch('api.php?action=save&file=files', {
            method: 'POST',
            body: JSON.stringify(_state.config),
        });
        const data = await res.json();
        showMsg(msgEl, data.status === 'success' ? 'Saved successfully' : (data.error || 'Save failed'), data.status === 'success');
    } catch {
        showMsg(msgEl, 'Network error during save', false);
    }
}

// API Upload file
async function uploadFile() {
    const fileInput = document.getElementById('f-upload-file');
    const nameInput = document.getElementById('f-upload-name');
    const statusEl  = document.getElementById('f-upload-status');

    if (!fileInput.files.length) {
        showMsg(statusEl, 'Please select a file first', false);
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

    statusEl.textContent = 'Uploading...';
    statusEl.style.color = '#64748B';

    try {
        const res = await apiFetch(FILES_API, {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showMsg(statusEl, 'File uploaded successfully', true);
            fileInput.value = '';
            nameInput.value = '';
            loadList();
        } else {
            showMsg(statusEl, data.error || 'Upload failed', false);
        }
    } catch (e) {
        showMsg(statusEl, 'Network error during upload', false);
    }
}

// API File list
async function loadList() {
    if (_state.loading) return;
    _state.loading = true;
    setTbody('<tr><td colspan="8" class="adm-td" style="color:var(--muted)">Loading...</td></tr>');

    const params = new URLSearchParams({
        action: 'list',
        page:   _state.page,
        limit:  PER_PAGE,
        type:   _state.type,
        search: _state.search,
    });

    try {
        const res  = await apiFetch(`${FILES_API}?${params}`);
        const data = await res.json();

        if (!data.success) {
            setTbody(`<tr><td colspan="8" class="adm-td" style="color:var(--danger)">${esc(data.error || 'Failed to load')}</td></tr>`);
            return;
        }

        _state.files = data.files       || [];
        _state.total = data.total_count || 0;
        _state.pages = data.total_pages || 1;

        renderTable(_state.files);
        renderPager();
        const statusEl = document.getElementById('f-status');
        if (statusEl) statusEl.textContent = `${_state.total} file${_state.total !== 1 ? 's' : ''} found`;
    } catch (e) {
        setTbody('<tr><td colspan="8" class="adm-td" style="color:var(--danger)">Network error</td></tr>');
    } finally {
        _state.loading = false;
    }
}

// Render table structure
function renderTable(files) {
    if (!files.length) {
        setTbody('<tr><td colspan="8" class="adm-td" style="color:var(--muted)">No files found.</td></tr>');
        return;
    }

    const rows = files.map(f => `
        <tr data-uuid="${esc(f.uuid)}">
            <td class="adm-td" style="font-weight:bold;text-align:center;color:var(--muted)">${TYPE_ICONS[f.type] ?? TYPE_ICONS.other}</td>
            <td class="adm-td">
                ${f.type === 'image'
                    ? `<img src="../file_download.php?uuid=${esc(f.uuid)}&thumb=1" alt="" style="height:32px;width:32px;object-fit:cover;border-radius:3px;vertical-align:middle;margin-right:6px">`
                    : ''}
                <a href="../file_download.php?uuid=${esc(f.uuid)}" target="_blank">${esc(f.display_name || f.name)}</a>
            </td>
            <td class="adm-td">
                <span class="adm-badge adm-badge-muted">${esc(f.type)}</span>
            </td>
            <td class="adm-td" style="white-space:nowrap">${formatBytes(f.size_bytes)}</td>
            <td class="adm-td">
                ${f.related_table ? `<span class="adm-badge adm-badge-muted">${esc(f.related_table)} #${f.related_id}</span>` : '-'}
            </td>
            <td class="adm-td">${esc(f.uploaded_by_username || '-')}</td>
            <td class="adm-td" style="white-space:nowrap">${formatDate(f.created_at)}</td>
            <td class="adm-td">
                <button class="btn btn-danger btn-xs" data-del="${esc(f.uuid)}" data-name="${esc(f.display_name || f.name)}">Del</button>
            </td>
        </tr>
    `).join('');

    setTbody(rows);

    document.querySelectorAll('[data-del]').forEach(btn => {
        btn.addEventListener('click', () => deleteFile(btn.dataset.del, btn.dataset.name));
    });
}

// Delete file handler
async function deleteFile(uuid, name) {
    if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
    try {
        const csrfToken = getCsrfToken();
        const res  = await apiFetch(FILES_API, {
            method: 'POST',
            body: JSON.stringify({ action: 'delete', uuid, csrf_token: csrfToken }),
        });
        const data = await res.json();
        if (data.success) {
            loadList();
        } else {
            alert(data.error || 'Delete failed.');
        }
    } catch {
        alert('Network error.');
    }
}

// Pagination handler
function renderPager() {
    const bar = document.getElementById('f-pages');
    if (!bar) return;
    const { page, pages } = _state;
    if (pages <= 1) { bar.innerHTML = ''; return; }

    let html = '';
    for (let p = 1; p <= pages; p++) {
        if (p === 1 || p === pages || (p >= page - 2 && p <= page + 2)) {
            html += `<button data-p="${p}" class="btn btn-xs ${p === page ? 'btn-primary' : 'btn-secondary'}">${p}</button>`;
        } else if (p === page - 3 || p === page + 3) {
            html += `<span style="padding:4px 4px;color:#64748B">...</span>`;
        }
    }

    bar.innerHTML = html;
    bar.querySelectorAll('[data-p]').forEach(btn => {
        btn.addEventListener('click', () => {
            _state.page = parseInt(btn.dataset.p, 10);
            loadList();
        });
    });
}

// UI element manipulation
function setTbody(html) {
    const el = document.getElementById('f-tbody');
    if (el) el.innerHTML = html;
}

// Custom UI message
function showMsg(el, text, ok) {
    if (!el) return;
    el.textContent = text;
    el.style.color = ok ? '#2b9348' : '#d00000';
    setTimeout(() => { el.textContent = ''; }, 4000);
}

// Byte format converter
function formatBytes(b) {
    if (!b) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0, v = parseInt(b, 10);
    while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
    return `${v.toFixed(i === 0 ? 0 : 1)} ${u[i]}`;
}

// Date formatter
function formatDate(iso) {
    if (!iso) return '-';
    try { return new Date(iso).toLocaleDateString('en-GB'); } catch { return iso; }
}

// Security HTML escape
import { escHtml as esc } from '../../assets/js/util/esc.js';

// String capitalization
function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

// Debounce util for search
function debounce(fn, ms) {
    let t;
    return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}