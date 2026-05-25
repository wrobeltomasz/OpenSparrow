// admin/files.js
// OpenSparrow Files Module Admin Panel
// Responsibilities:
// - Display and manage uploaded files
// - Edit module configuration
// - Render file preview thumbnails
// Pattern: mirrors admin/schema.js and admin/users.js
// Auth: admin session required
// Access: upload available to all logged-in users; delete/config = admin only

'use strict';

// Constants
const FILES_API = 'api_files.php';
const FILES_ICONS = {
    image:       '[IMG]',
    pdf:         '[PDF]',
    doc:         '[DOC]',
    spreadsheet: '[XLS]',
    archive:     '[ZIP]',
    other:       '[FILE]',
};
const FILES_PER_PAGE = 25;

// State
let filesState = {
    files:        [],
    config:       {},
    currentPage:  1,
    totalPages:   1,
    filterType:   'all',
    filterSearch: '',
    loading:      false,
};
let schemaData = null;

// Initialisation
document.addEventListener('DOMContentLoaded', async () => {
    await loadSchema();
    renderFilesTab();
    loadFilesConfig();
    loadFilesList();
    bindFilesEvents();
});

// Load schema for table configuration
async function loadSchema() {
    try {
        const res = await fetch('api_schema.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        schemaData = await res.json();
    } catch (err) {
        console.error('[files.js] Schema load failed', err);
    }
}

// Render tab skeleton
function renderFilesTab() {
    const container = document.getElementById('tab-files');
    if (!container) return;

    container.innerHTML = `
        <div class="admin-section">
            <h2>Files Configuration</h2>
            <form id="files-config-form" class="admin-form">
                <div class="form-group">
                    <label for="cfg-max-size">Max file size (MB)</label>
                    <input type="number" id="cfg-max-size" name="max_file_size_mb" min="1" max="500" class="form-control" />
                </div>
                <div class="form-group">
                    <label>Allowed types</label>
                    <div id="cfg-allowed-types" class="checkbox-group"></div>
                </div>
                <div class="form-group">
                    <label for="cfg-storage-path">Storage path</label>
                    <input type="text" id="cfg-storage-path" name="storage_path" class="form-control" />
                    <small class="form-hint">Relative to project root. Must not be web-accessible.</small>
                </div>
                <div class="form-group" style="margin-top: 15px; border-top: 1px solid #c2dfe3; padding-top: 15px;">
                    <label for="cfg-related-table">Related Table</label>
                    <select id="cfg-related-table" name="related_table" class="form-control"></select>
                </div>
                <div class="form-group">
                    <label for="cfg-display-col-1">Display Column 1</label>
                    <select id="cfg-display-col-1" name="display_column_1" class="form-control"></select>
                </div>
                <div class="form-group">
                    <label for="cfg-display-col-2">Display Column 2 (Optional)</label>
                    <select id="cfg-display-col-2" name="display_column_2" class="form-control"></select>
                </div>
                <button type="submit" class="btn btn-primary">Save configuration</button>
                <span id="files-config-status" class="status-msg"></span>
            </form>
        </div>

        <div class="admin-section">
            <h2>Files Library</h2>
            <div class="files-toolbar">
                <input type="text" id="files-search" placeholder="Search by name..." class="form-control files-search" />
                <select id="files-type-filter" class="form-control files-filter">
                    <option value="all">All types</option>
                    <option value="image">Image</option>
                    <option value="pdf">PDF</option>
                    <option value="doc">Document</option>
                    <option value="spreadsheet">Spreadsheet</option>
                    <option value="archive">Archive</option>
                    <option value="other">Other</option>
                </select>
                <button id="files-refresh-btn" class="btn btn-secondary">Refresh</button>
            </div>

            <div id="files-status-bar" class="files-status-bar"></div>

            <table class="admin-table" id="files-table">
                <thead>
                    <tr>
                        <th style="width:40px"></th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Size</th>
                        <th>Uploaded by</th>
                        <th>Date</th>
                        <th style="width:90px">Actions</th>
                    </tr>
                </thead>
                <tbody id="files-table-body">
                    <tr><td colspan="7" class="loading-row">Loading...</td></tr>
                </tbody>
            </table>

            <div id="files-pagination" class="pagination-bar"></div>
        </div>
    `;
}

// API: load config
async function loadFilesConfig() {
    try {
        const res  = await fetch(FILES_API + '?action=get_config');
        const data = await res.json();

        if (!data.success) {
            showConfigStatus('Failed to load configuration.', 'error');
            return;
        }

        filesState.config = data.config;
        populateConfigForm(data.config);
    } catch (err) {
        showConfigStatus('Network error loading configuration.', 'error');
        console.error('[files.js] loadFilesConfig:', err);
    }
}

// Populate UI form
function populateConfigForm(config) {
    const maxSize     = document.getElementById('cfg-max-size');
    const storagePath = document.getElementById('cfg-storage-path');
    const typesWrap   = document.getElementById('cfg-allowed-types');
    const tableSel    = document.getElementById('cfg-related-table');
    const col1Sel     = document.getElementById('cfg-display-col-1');
    const col2Sel     = document.getElementById('cfg-display-col-2');

    if (maxSize)     maxSize.value     = config.max_file_size_mb ?? 20;
    if (storagePath) storagePath.value = config.storage_path     ?? 'storage/files/';

    if (typesWrap) {
        const allTypes = ['image', 'pdf', 'doc', 'spreadsheet', 'archive', 'other'];
        typesWrap.innerHTML = allTypes.map(t => `
            <label class="checkbox-label">
                <input type="checkbox" name="allowed_types" value="${t}"
                       ${(config.allowed_types || []).includes(t) ? 'checked' : ''} />
                ${FILES_ICONS[t]} ${t.charAt(0).toUpperCase() + t.slice(1)}
            </label>
        `).join('');
    }

    if (tableSel && schemaData && schemaData.tables) {
        tableSel.innerHTML = '<option value="">-- None --</option>';
        Object.keys(schemaData.tables).forEach(t => {
            const opt = document.createElement('option');
            opt.value = t;
            opt.textContent = schemaData.tables[t].display_name || t;
            if (config.related_table === t) opt.selected = true;
            tableSel.appendChild(opt);
        });

        const updateCols = () => {
            col1Sel.innerHTML = '<option value="">-- None --</option>';
            col2Sel.innerHTML = '<option value="">-- None --</option>';
            const t = tableSel.value;
            if (t && schemaData.tables[t] && schemaData.tables[t].columns) {
                Object.keys(schemaData.tables[t].columns).forEach(c => {
                    const o1 = document.createElement('option');
                    o1.value = c;
                    o1.textContent = schemaData.tables[t].columns[c].display_name || c;
                    if (config.display_column_1 === c) o1.selected = true;
                    col1Sel.appendChild(o1);

                    const o2 = document.createElement('option');
                    o2.value = c;
                    o2.textContent = schemaData.tables[t].columns[c].display_name || c;
                    if (config.display_column_2 === c) o2.selected = true;
                    col2Sel.appendChild(o2);
                });
            }
        };

        tableSel.addEventListener('change', updateCols);
        updateCols();
    }
}

// API: save config
async function saveFilesConfig(e) {
    e.preventDefault();
    const form = document.getElementById('files-config-form');
    if (!form) return;

    const formData = new FormData(form);
    const payload  = {
        action:           'save_config',
        max_file_size_mb: parseInt(formData.get('max_file_size_mb'), 10),
        storage_path:     formData.get('storage_path'),
        allowed_types:    formData.getAll('allowed_types'),
        related_table:    formData.get('related_table'),
        display_column_1: formData.get('display_column_1'),
        display_column_2: formData.get('display_column_2'),
    };

    try {
        const res  = await fetch(FILES_API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const data = await res.json();
        showConfigStatus(
            data.success ? 'Configuration saved.' : (data.error || 'Save failed.'),
            data.success ? 'success' : 'error'
        );
    } catch (err) {
        showConfigStatus('Network error.', 'error');
        console.error('[files.js] saveFilesConfig:', err);
    }
}

// API: load files list
async function loadFilesList() {
    if (filesState.loading) return;
    filesState.loading = true;
    setTableLoading(true);

    const params = new URLSearchParams({
        action: 'list',
        page:   filesState.currentPage,
        limit:  FILES_PER_PAGE,
        type:   filesState.filterType,
        search: filesState.filterSearch,
    });

    try {
        const res  = await fetch(FILES_API + '?' + params.toString());
        const data = await res.json();

        if (!data.success) {
            setTableError(data.error || 'Failed to load files.');
            return;
        }

        filesState.files      = data.files       || [];
        filesState.totalPages = data.total_pages || 1;

        renderFilesTable(filesState.files);
        renderPagination();
        updateStatusBar(data.total_count || 0);
    } catch (err) {
        setTableError('Network error.');
        console.error('[files.js] loadFilesList:', err);
    } finally {
        filesState.loading = false;
        setTableLoading(false);
    }
}

// Render: files table rows
function renderFilesTable(files) {
    const tbody = document.getElementById('files-table-body');
    if (!tbody) return;

    if (!files.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-row">No files found.</td></tr>';
        return;
    }

    tbody.innerHTML = files.map(f => `
        <tr data-uuid="${escHtml(f.uuid)}">
            <td class="file-icon">${FILES_ICONS[f.type] ?? FILES_ICONS.other}</td>
            <td class="file-name">
                ${f.type === 'image'
                    ? `<a href="file_download.php?uuid=${escHtml(f.uuid)}" target="_blank" title="Preview">
                           <img src="file_download.php?uuid=${escHtml(f.uuid)}&thumb=1" alt="${escHtml(f.display_name || f.name)}" class="file-thumb" loading="lazy" />
                       </a>`
                    : ''
                }
                <span>${escHtml(f.display_name || f.name)}</span>
            </td>
            <td><span class="badge badge-type badge-${escHtml(f.type)}">${escHtml(f.type)}</span></td>
            <td class="file-size">${formatBytes(f.size_bytes)}</td>
            <td>${escHtml(f.uploaded_by_username || '-')}</td>
            <td>${escHtml(formatDate(f.created_at))}</td>
            <td class="file-actions">
                <a href="file_download.php?uuid=${escHtml(f.uuid)}" class="btn btn-xs btn-secondary" title="Download" download>DL</a>
                <button class="btn btn-xs btn-danger btn-delete-file" data-uuid="${escHtml(f.uuid)}" data-name="${escHtml(f.display_name || f.name)}" title="Delete">Del</button>
            </td>
        </tr>
    `).join('');

    tbody.querySelectorAll('.btn-delete-file').forEach(btn => {
        btn.addEventListener('click', () => confirmDeleteFile(btn.dataset.uuid, btn.dataset.name));
    });
}

// API: delete file
async function confirmDeleteFile(uuid, name) {
    if (!confirm(`Delete file "${name}"?\nThis action cannot be undone.`)) return;

    try {
        const res  = await fetch(FILES_API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'delete', uuid }),
        });
        const data = await res.json();

        if (data.success) {
            loadFilesList();
        } else {
            alert(data.error || 'Delete failed.');
        }
    } catch (err) {
        alert('Network error.');
        console.error('[files.js] confirmDeleteFile:', err);
    }
}

// Render: pagination
function renderPagination() {
    const bar = document.getElementById('files-pagination');
    if (!bar) return;

    const { currentPage, totalPages } = filesState;

    if (totalPages <= 1) {
        bar.innerHTML = '';
        return;
    }

    let html = '';
    if (currentPage > 1) {
        html += `<button class="btn btn-xs btn-secondary" data-page="${currentPage - 1}">Prev</button> `;
    }

    for (let p = 1; p <= totalPages; p++) {
        if (p === 1 || p === totalPages || (p >= currentPage - 2 && p <= currentPage + 2)) {
            html += `<button class="btn btn-xs ${p === currentPage ? 'btn-primary' : 'btn-secondary'}" data-page="${p}">${p}</button> `;
        } else if (p === currentPage - 3 || p === currentPage + 3) {
            html += `<span class="pagination-ellipsis">...</span> `;
        }
    }

    if (currentPage < totalPages) {
        html += `<button class="btn btn-xs btn-secondary" data-page="${currentPage + 1}">Next</button>`;
    }

    bar.innerHTML = html;

    bar.querySelectorAll('button[data-page]').forEach(btn => {
        btn.addEventListener('click', () => {
            filesState.currentPage = parseInt(btn.dataset.page, 10);
            loadFilesList();
        });
    });
}

// Event bindings
function bindFilesEvents() {
    document.addEventListener('submit', e => {
        if (e.target && e.target.id === 'files-config-form') saveFilesConfig(e);
    });

    document.addEventListener('input', debounce(e => {
        if (e.target && e.target.id === 'files-search') {
            filesState.filterSearch = e.target.value.trim();
            filesState.currentPage  = 1;
            loadFilesList();
        }
    }, 350));

    document.addEventListener('change', e => {
        if (e.target && e.target.id === 'files-type-filter') {
            filesState.filterType  = e.target.value;
            filesState.currentPage = 1;
            loadFilesList();
        }
    });

    document.addEventListener('click', e => {
        if (e.target && e.target.id === 'files-refresh-btn') loadFilesList();
    });
}

// Helpers
function showConfigStatus(msg, type = 'success') {
    const el = document.getElementById('files-config-status');
    if (!el) return;
    el.textContent  = msg;
    el.className    = `status-msg status-${type}`;
    setTimeout(() => { el.textContent = ''; el.className = 'status-msg'; }, 4000);
}

function updateStatusBar(total) {
    const bar = document.getElementById('files-status-bar');
    if (!bar) return;
    bar.textContent = `${total} file${total !== 1 ? 's' : ''} found`;
}

function setTableLoading(on) {
    const tbody = document.getElementById('files-table-body');
    if (tbody && on) tbody.innerHTML = '<tr><td colspan="7" class="loading-row">Loading...</td></tr>';
}

function setTableError(msg) {
    const tbody = document.getElementById('files-table-body');
    if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="error-row">${escHtml(msg)}</td></tr>`;
}

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let v = parseInt(bytes, 10);
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return `${v.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function formatDate(iso) {
    if (!iso) return '-';
    try { return new Date(iso).toLocaleDateString('en-GB'); } catch { return iso; }
}

function escHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function debounce(fn, delay) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
}