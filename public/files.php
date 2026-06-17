<?php

// files.php — Files module page (frontend HTML)
// Auth gate: redirect to login if no session; admin redirected to /admin; UA/lifetime enforcement
// Generates CSRF token + CSP nonce + send_security_headers(); exposes capability flags (canEdit/canExport) to the client
// Renders the file manager UI; data and uploads via api/files.php, downloads via file_download.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
start_session();
// Redirect to login if no session
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['role'] ?? 'viewer') === 'admin') {
    header("Location: admin/");
    exit;
}

// Hard session-lifetime + User-Agent enforcement (centralised in session.php).
enforce_session_redirect();

// Generate CSRF token if it does not exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Generate a unique nonce for Content Security Policy
$cspNonce = bin2hex(random_bytes(16));
send_security_headers($cspNonce);
$userRole = $_SESSION['role'] ?? 'viewer';
// Expose only capability flags to the client instead of the raw role name
// to reduce attack surface during reconnaissance
$userCaps = [
    'canEdit'   => $userRole === 'editor',
    'canExport' => in_array($userRole, ['editor', 'export'], true),
];
$pageTitle = 'OpenSparrow | Files';
ob_start();
?>

<main>
    <section id="filesSection">
        <div class="files-container">

            <div class="f-card">
                <h3 class="f-title">Upload New File</h3>
                <div class="file-upload-box">
                    <div class="file-upload-inputs">
                        <input type="file" id="fileInput" class="f-input f-input-file">
                        <input type="text" id="fileNameInput" class="f-input f-input-w200" placeholder="Optional display name">
                        <input type="text" id="fileTagsInput" class="f-input f-input-w200" placeholder="Tags (comma separated)">
                        <select id="fileRelatedTable" class="f-input f-input-w200">
                            <option value="">-- Target table --</option>
                        </select>
                        <select id="fileRelatedId" class="f-input f-input-w280" disabled>
                            <option value="">-- Select table first --</option>
                        </select>
                    </div>
                    <button id="btnUpload" class="f-btn f-btn-primary f-btn-upload">Upload File</button>
                    <div id="uploadStatus" class="f-upload-status"></div>
                </div>
            </div>

            <div class="files-toolbar">
                <input type="text" id="fileSearch" class="f-input f-search-input" placeholder="Search files by name or tag...">
                <select id="fileTypeFilter" class="f-input f-filter-select">
                    <option value="all">All File Types</option>
                    <option value="image">Images</option>
                    <option value="pdf">PDFs</option>
                    <option value="doc">Documents</option>
                    <option value="spreadsheet">Spreadsheets</option>
                    <option value="archive">Archives</option>
                </select>
                <button id="btnRefreshFiles" class="f-btn f-btn-outline">Refresh List</button>
            </div>

            <div class="table-responsive">
                <table class="file-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>File Name</th>
                            <th>Tags</th>
                            <th>Size</th>
                            <th>Related To</th>
                            <th>Uploaded Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="fileTableBody">
                        <tr><td colspan="7" class="f-td-empty">Loading files...</td></tr>
                    </tbody>
                </table>
            </div>

            <div id="filePagination" class="f-pagination"></div>

        </div>
    </section>
</main>

<?php
$pageContent = ob_get_clean();
ob_start();
?>
<script nonce="<?php echo $cspNonce; ?>">
    window.USER_CAPS  = <?php echo json_encode($userCaps, JSON_THROW_ON_ERROR); ?>;
    window.CSRF_TOKEN = <?php echo json_encode($_SESSION['csrf_token'], JSON_THROW_ON_ERROR); ?>;
</script>

<script nonce="<?php echo $cspNonce; ?>">
document.addEventListener("DOMContentLoaded", () => {
    const API_URL = 'api/files.php';
    let currentPage = 1;
    let currentSearch = '';
    let currentType = 'all';

    const fileInput       = document.getElementById('fileInput');
    const fileNameInput   = document.getElementById('fileNameInput');
    const fileTagsInput   = document.getElementById('fileTagsInput');
    const tableSelect     = document.getElementById('fileRelatedTable');
    const recordSelect    = document.getElementById('fileRelatedId');
    const btnUpload       = document.getElementById('btnUpload');
    const uploadStatus    = document.getElementById('uploadStatus');
    const tbody           = document.getElementById('fileTableBody');
    const searchInput     = document.getElementById('fileSearch');
    const typeFilter      = document.getElementById('fileTypeFilter');
    const btnRefresh      = document.getElementById('btnRefreshFiles');

    const icons = {
        image:       'assets/icons/image.png',
        pdf:         'assets/icons/picture_as_pdf.png',
        doc:         'assets/icons/docs.png',
        spreadsheet: 'assets/icons/grid_on.png',
        archive:     'assets/icons/folder_zip.png',
        other:       'assets/icons/file_present.png'
    };

    // Cache for related record labels
    const relationCache = {};

    // Initialize lists
    loadConfiguredTables();
    loadFiles();

    // Events
    btnUpload.addEventListener('click', uploadFile);
    btnRefresh.addEventListener('click', () => loadFiles());
    typeFilter.addEventListener('change', (e) => { currentType = e.target.value; currentPage = 1; loadFiles(); });
    tableSelect.addEventListener('change', loadRelatedRecords);

    let searchTimeout;
    searchInput.addEventListener('input', (e) => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => { currentSearch = e.target.value; currentPage = 1; loadFiles(); }, 400);
    });

    // Event delegation for delete buttons — avoids inline onclick handlers blocked by CSP
    // and keeps the delete function out of the global window scope
    tbody.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action="delete-file"]');
        if (!btn) return;
        const uuid = btn.dataset.uuid;
        if (!uuid || !confirm('Are you sure you want to delete this file?')) return;
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // Include CSRF token in all mutating requests
                body: JSON.stringify({ action: 'delete', uuid, csrf_token: window.CSRF_TOKEN })
            });
            const data = await res.json();
            if (data.success) {
                loadFiles();
            } else {
                alert('Delete error: ' + (data.error || 'Unknown'));
            }
        } catch (err) {
            alert('Network error.');
        }
    });

    // Fetch allowed relation tables from config
    async function loadConfiguredTables() {
        try {
            const res = await fetch(API_URL + '?action=get_relations_config');
            const data = await res.json();
            if (data.success && data.relations && data.relations.length > 0) {
                data.relations.forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r.table;
                    opt.textContent = r.table;
                    tableSelect.appendChild(opt);
                });
            } else {
                tableSelect.disabled = true;
                recordSelect.disabled = true;
                tableSelect.innerHTML = '<option value="">-- No relations active --</option>';
            }
        } catch (err) {
            tableSelect.innerHTML = '<option value="">-- Network error --</option>';
        }
    }

    // Fetch records when a table is chosen
    async function loadRelatedRecords() {
        const tableName = tableSelect.value;
        recordSelect.innerHTML = '<option value="">-- Select record --</option>';
        if (!tableName) {
            recordSelect.disabled = true;
            return;
        }
        recordSelect.disabled = true;
        recordSelect.innerHTML = '<option value="">-- Loading... --</option>';
        try {
            const res = await fetch(`${API_URL}?action=get_related_records&table=${encodeURIComponent(tableName)}`);
            const data = await res.json();
            if (data.success && data.records) {
                recordSelect.innerHTML = '<option value="">-- Select record --</option>';
                data.records.forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r.id;
                    opt.textContent = r.label;
                    recordSelect.appendChild(opt);
                });
                recordSelect.disabled = false;
            } else {
                recordSelect.innerHTML = '<option value="">-- Load error --</option>';
            }
        } catch (err) {
            recordSelect.innerHTML = '<option value="">-- Network error --</option>';
        }
    }

    // Fetch paginated files
    async function loadFiles() {
        tbody.innerHTML = '<tr><td colspan="7" class="f-td-empty">Loading files...</td></tr>';
        try {
            const params = new URLSearchParams({ action: 'list', page: currentPage, limit: 20, type: currentType, search: currentSearch });
            const res = await fetch(`${API_URL}?${params}`);
            const data = await res.json();

            if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="7" class="f-td-error">Error: ${escapeHtml(data.error || 'Unknown')}</td></tr>`;
                return;
            }

            // Gather tables to fetch labels
            const tablesToFetch = new Set();
            data.files.forEach(f => {
                if (f.related_table && !relationCache[f.related_table]) {
                    tablesToFetch.add(f.related_table);
                }
            });

            // Fetch labels for related records
            const fetchPromises = Array.from(tablesToFetch).map(async (table) => {
                try {
                    const lRes = await fetch(`${API_URL}?action=get_related_records&table=${encodeURIComponent(table)}`);
                    const lData = await lRes.json();
                    relationCache[table] = {};
                    if (lData.success && lData.records) {
                        lData.records.forEach(r => { relationCache[table][r.id] = r.label; });
                    }
                } catch (e) {
                    console.error('Failed to fetch labels for', table);
                }
            });

            await Promise.all(fetchPromises);
            renderTable(data.files);
            renderPagination(data.total_pages);
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="7" class="f-td-error">Network error.</td></tr>';
        }
    }

    function renderTable(files) {
        if (!files || files.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="f-td-empty">No files found matching your criteria.</td></tr>';
            return;
        }

        tbody.innerHTML = files.map(f => {
            const iconPath = icons[f.type] || icons.other;
            const size     = formatBytes(f.size_bytes);
            const date     = new Date(f.created_at).toLocaleDateString();

            // Render related badge with link and proper label
            let relatedBadge = '-';
            if (f.related_table && f.related_id) {
                const displayLabel = relationCache[f.related_table] && relationCache[f.related_table][f.related_id]
                    ? relationCache[f.related_table][f.related_id]
                    : `${f.related_table} #${f.related_id}`;
                relatedBadge = `
                    <a href="edit.php?table=${encodeURIComponent(f.related_table)}&id=${encodeURIComponent(f.related_id)}" class="related-badge" title="Go to record">
                        ${escapeHtml(displayLabel)}
                    </a>
                `;
            }

            // Parse PostgreSQL array syntax {tag1,tag2}
            let tagsHtml = '-';
            if (f.tags && f.tags !== '{}') {
                const rawTags = f.tags.replace(/(^{|}$)/g, '').replace(/"/g, '').split(',');
                tagsHtml = rawTags.map(t => `<span class="tag-badge">${escapeHtml(t.trim())}</span>`).join(' ');
            }

            // Delete button uses data-uuid + event delegation — no inline onclick, no global function
            const deleteBtn = window.USER_CAPS.canEdit
                ? `<button class="f-btn f-btn-danger f-btn-sm f-btn-sm-ml" data-action="delete-file" data-uuid="${escapeHtml(f.uuid)}">Delete</button>`
                : '';

            return `
                <tr>
                    <td class="f-td-type">
                        <div class="f-type-cell">
                            <img src="${escapeHtml(iconPath)}" alt="" class="f-type-icon">
                            <span class="f-type-label">${escapeHtml(f.type.toUpperCase())}</span>
                        </div>
                    </td>
                    <td class="f-td-name">${escapeHtml(f.display_name || f.name)}</td>
                    <td>${tagsHtml}</td>
                    <td>${size}</td>
                    <td>${relatedBadge}</td>
                    <td>${date}</td>
                    <td>
                        <a href="file_download.php?uuid=${encodeURIComponent(f.uuid)}" target="_blank" rel="noopener noreferrer" class="f-btn f-btn-outline f-btn-sm">Download</a>
                        ${deleteBtn}
                    </td>
                </tr>
            `;
        }).join('');
    }

    function renderPagination(totalPages) {
        const pagEl = document.getElementById('filePagination');
        pagEl.innerHTML = '';
        if (totalPages <= 1) return;
        for (let i = 1; i <= totalPages; i++) {
            const btn = document.createElement('button');
            btn.textContent = i;
            // Use CSS class instead of inline style — avoids CSP violation
            if (i === currentPage) btn.classList.add('active');
            btn.addEventListener('click', () => { currentPage = i; loadFiles(); });
            pagEl.appendChild(btn);
        }
    }

    // Upload with relations, tags, and CSRF token
    async function uploadFile() {
        if (!fileInput.files.length) {
            setUploadStatus('Please select a file.', 'error');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('file', fileInput.files[0]);
        // Include CSRF token in all mutating requests
        formData.append('csrf_token', window.CSRF_TOKEN);
        if (fileNameInput.value.trim()) formData.append('display_name', fileNameInput.value.trim());
        if (fileTagsInput.value.trim()) formData.append('tags', fileTagsInput.value.trim());
        if (!tableSelect.disabled && tableSelect.value.trim()) formData.append('related_table', tableSelect.value.trim());
        if (!recordSelect.disabled && recordSelect.value.trim()) formData.append('related_id', recordSelect.value.trim());

        setUploadStatus('Uploading...', 'neutral');

        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                setUploadStatus('File uploaded successfully!', 'success');
                fileInput.value = '';
                fileNameInput.value = '';
                fileTagsInput.value = '';
                tableSelect.value = '';
                recordSelect.innerHTML = '<option value="">-- Select table first --</option>';
                recordSelect.disabled = true;
                loadFiles();
                setTimeout(() => { uploadStatus.textContent = ''; uploadStatus.className = 'f-upload-status'; }, 4000);
            } else {
                setUploadStatus('Error: ' + (data.error || 'Failed'), 'error');
            }
        } catch (err) {
            setUploadStatus('Network error during upload.', 'error');
        }
    }

    // Helper: set upload status text and CSS state class without inline styles
    function setUploadStatus(message, state) {
        uploadStatus.textContent = message;
        uploadStatus.className = `f-upload-status f-status-${state}`;
    }

    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function escapeHtml(unsafe) {
        return (unsafe || '').toString().replace(/[&<>"']/g, m => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
        ));
    }
});
</script>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/../templates/layout.php';
