<?php

// files.php — Files module page (frontend HTML)
// Boots via includes/bootstrap.php: os_page_bootstrap() — auth gate, admin redirect, UA/lifetime enforcement, CSRF token, CSP nonce + headers
// Exposes capability flags (canEdit/canExport) to the client
// Search box + type filter render in the app header (via $headerControls, like the grid page)
// Table mirrors the data grid look/behavior: th-label header pills, column sort, actions bar with
// grid-style pagination (rows-per-page persisted in localStorage). A slim single-row upload bar
// (styled like the actions bar) sits below the table.
// Bulk operations (delete, tagging) over row-checkbox selection — same me-bar/BulkPanel UI as the grid.
// Renders the file manager UI; data and uploads via api/files.php, downloads via file_download.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$page     = os_page_bootstrap();
$cspNonce = $page['nonce'];
$userRole = $page['role'];
$userCaps = $page['caps'];
$pageTitle = 'OpenSparrow | Files';
$canEdit   = !empty($userCaps['canEdit']);
$headerControls = '<input type="search" id="fileSearch" placeholder="Search files by name or tag..."'
    . ' aria-label="Search files by name or tag">'
    . '<select id="fileTypeFilter">'
    . '<option value="all">All File Types</option>'
    . '<option value="image">Images</option>'
    . '<option value="pdf">PDFs</option>'
    . '<option value="doc">Documents</option>'
    . '<option value="spreadsheet">Spreadsheets</option>'
    . '<option value="archive">Archives</option>'
    . '</select>'
    . '<button id="clearFilters" hidden title="'
    . htmlspecialchars(t('grid.clear_filters'), ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars(t('grid.clear_filters'), ENT_QUOTES, 'UTF-8') . '</button>';
ob_start();
?>

<main>
    <section id="filesSection">

        <div id="filesGrid">
            <table class="file-table">
                <thead>
                    <tr>
                        <?php if ($canEdit) : ?>
                        <th class="th-select">
                            <input type="checkbox" class="select-all-cb" aria-label="Select all files"
                                   title="Select / deselect all">
                        </th>
                        <?php endif; ?>
                        <th data-sort="type" data-label="Type"><span class="th-label">Type</span></th>
                        <th data-sort="name" data-label="File Name"><span class="th-label">File Name</span></th>
                        <th data-sort="tags" data-label="Tags"><span class="th-label">Tags</span></th>
                        <th data-sort="size" data-label="Size"><span class="th-label">Size</span></th>
                        <th data-sort="related" data-label="Related To"><span class="th-label">Related To</span></th>
                        <th data-sort="created_at" data-label="Uploaded Date"><span class="th-label">Uploaded Date</span></th>
                        <th class="th-actions"></th>
                    </tr>
                </thead>
                <tbody id="fileTableBody">
                    <tr><td colspan="<?php echo $canEdit ? 8 : 7; ?>" class="f-td-empty">Loading files...</td></tr>
                </tbody>
            </table>
        </div>

        <div id="filesActions" class="actions">
            <div class="left">
                <button id="btnRefreshFiles">Refresh List</button>
            </div>
            <div id="filePagination" class="pagination"></div>
        </div>

        <div id="fileUploadBar" class="f-upload-bar">
            <span class="f-upload-label">Upload new file:</span>
            <input type="file" id="fileInput" class="f-input f-input-file" aria-label="Choose file to upload">
            <input type="text" id="fileNameInput" class="f-input f-input-w160" placeholder="Optional display name">
            <input type="text" id="fileTagsInput" class="f-input f-input-w160" placeholder="Tags (comma separated)">
            <select id="fileRelatedTable" class="f-input f-input-w160">
                <option value="">-- Target table --</option>
            </select>
            <select id="fileRelatedId" class="f-input f-input-w220" disabled>
                <option value="">-- Select table first --</option>
            </select>
            <button id="btnUpload" class="success">Upload File</button>
            <span id="uploadStatus" class="f-upload-status"></span>
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

<script type="module" nonce="<?php echo $cspNonce; ?>">
// Module script: reuses the grid's BulkPanel drawer and toast for bulk operations
import { BulkPanel } from './assets/js/bulk_panel.js';
import { showToast } from './assets/js/toast.js';

document.addEventListener("DOMContentLoaded", () => {
    const API_URL = 'api/files.php';
    // Grid-parity page size options; persisted like the grid's sparrow_page_size
    const PAGE_SIZE_OPTIONS = [10, 25, 50, 100];
    const LS_PAGE_SIZE = 'sparrow_files_page_size';
    const canEdit = !!(window.USER_CAPS && window.USER_CAPS.canEdit);
    // Extra leading select column for write roles
    const COLSPAN = canEdit ? 8 : 7;

    let currentPage = 1;
    let currentSearch = '';
    let currentType = 'all';
    // Same sort semantics as the grid: click = sort asc, click again = toggle
    let sortState = { column: 'created_at', asc: false };
    let pageSize = (() => {
        const saved = Number(localStorage.getItem(LS_PAGE_SIZE));
        return PAGE_SIZE_OPTIONS.includes(saved) ? saved : 25;
    })();
    // Bulk selection over file uuids — survives page/sort/filter changes like the grid's selectedIds
    const selectedUuids = new Set();
    let bulkBar  = null;
    let tagPanel = null;

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
    const btnClearFilters = document.getElementById('clearFilters');
    const btnRefresh      = document.getElementById('btnRefreshFiles');
    const sortHeaders     = document.querySelectorAll('#filesGrid th[data-sort]');
    const selectAllCb     = document.querySelector('#filesGrid .select-all-cb');

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
    updateSortIndicators();
    loadFiles();

    // Events
    btnUpload.addEventListener('click', uploadFile);
    btnRefresh.addEventListener('click', () => loadFiles());
    typeFilter.addEventListener('change', (e) => { currentType = e.target.value; currentPage = 1; loadFiles(); });
    tableSelect.addEventListener('change', loadRelatedRecords);

    // Column sort — same behavior as the grid header (toggleSortState)
    sortHeaders.forEach(th => {
        th.addEventListener('click', () => {
            const col = th.dataset.sort;
            if (sortState.column === col) {
                sortState.asc = !sortState.asc;
            } else {
                sortState = { column: col, asc: true };
            }
            currentPage = 1;
            updateSortIndicators();
            loadFiles();
        });
    });

    // Append the grid's ↑ / ↓ arrow to the active sort column label
    function updateSortIndicators() {
        sortHeaders.forEach(th => {
            const label = th.querySelector('.th-label');
            let text = th.dataset.label;
            if (th.dataset.sort === sortState.column) {
                text += sortState.asc ? ' ↑' : ' ↓';
            }
            label.textContent = text;
        });
    }

    let searchTimeout;
    searchInput.addEventListener('input', (e) => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => { currentSearch = e.target.value; currentPage = 1; loadFiles(); }, 400);
    });

    // Header clear button: reset the search box and the type filter
    if (btnClearFilters) {
        btnClearFilters.addEventListener('click', () => {
            searchInput.value = '';
            currentSearch = '';
            typeFilter.value = 'all';
            currentType = 'all';
            currentPage = 1;
            loadFiles();
        });
    }

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

    // ─── Bulk selection (grid-parity: row checkboxes + select-all + floating me-bar) ───

    if (selectAllCb) {
        selectAllCb.addEventListener('change', () => {
            tbody.querySelectorAll('.row-select-cb').forEach(cb => {
                cb.checked = selectAllCb.checked;
                if (selectAllCb.checked) selectedUuids.add(cb.dataset.uuid);
                else selectedUuids.delete(cb.dataset.uuid);
            });
            updateBulkBar();
        });
    }

    // Row checkbox changes via delegation — rows are re-rendered on every load
    tbody.addEventListener('change', (e) => {
        const cb = e.target.closest('.row-select-cb');
        if (!cb) return;
        if (cb.checked) selectedUuids.add(cb.dataset.uuid);
        else selectedUuids.delete(cb.dataset.uuid);
        syncSelectAll();
        updateBulkBar();
    });

    // Select-all reflects the current page only (files are server-paginated)
    function syncSelectAll() {
        if (!selectAllCb) return;
        const cbs = tbody.querySelectorAll('.row-select-cb');
        selectAllCb.checked = cbs.length > 0 && Array.from(cbs).every(cb => cb.checked);
    }

    // Restore checkbox state after a re-render and refresh the bar
    function syncSelectionUI() {
        tbody.querySelectorAll('.row-select-cb').forEach(cb => {
            cb.checked = selectedUuids.has(cb.dataset.uuid);
        });
        syncSelectAll();
        updateBulkBar();
    }

    function deselectAll() {
        selectedUuids.clear();
        tbody.querySelectorAll('.row-select-cb').forEach(cb => { cb.checked = false; });
        if (selectAllCb) selectAllCb.checked = false;
        updateBulkBar();
    }

    // Floating selection bar — same classes as the grid's mass-edit bar (me-bar)
    function getBulkBar() {
        if (bulkBar) return bulkBar;

        bulkBar = document.createElement('div');
        bulkBar.className = 'me-bar';
        bulkBar.id = 'fileBulkBar';

        const countEl = document.createElement('span');
        countEl.className = 'me-bar-count';
        countEl.id = 'fileBulkCount';

        const actions = document.createElement('div');
        actions.className = 'me-bar-actions';

        const tagBtn = document.createElement('button');
        tagBtn.className = 'me-bar-edit-btn';
        tagBtn.id = 'fileBulkTagBtn';
        tagBtn.textContent = 'Add Tags';
        tagBtn.addEventListener('click', openTagPanel);

        const delBtn = document.createElement('button');
        delBtn.className = 'me-bar-delete-btn';
        delBtn.id = 'fileBulkDeleteBtn';
        delBtn.textContent = 'Delete';
        delBtn.addEventListener('click', massDeleteSelected);

        const clearBtn = document.createElement('button');
        clearBtn.className = 'me-bar-clear-btn';
        clearBtn.textContent = 'Deselect all';
        clearBtn.addEventListener('click', deselectAll);

        actions.appendChild(tagBtn);
        actions.appendChild(delBtn);
        actions.appendChild(clearBtn);
        bulkBar.appendChild(countEl);
        bulkBar.appendChild(actions);

        document.body.appendChild(bulkBar);
        return bulkBar;
    }

    function updateBulkBar() {
        if (!canEdit) return;
        const n = selectedUuids.size;
        const b = getBulkBar();
        b.querySelector('#fileBulkCount').textContent = `${n} selected`;
        if (n > 0) {
            b.classList.add('active');
        } else {
            b.classList.remove('active');
            if (tagPanel?.isOpen()) tagPanel.close();
        }
    }

    async function massDeleteSelected() {
        const n = selectedUuids.size;
        if (n === 0 || !confirm(`Delete ${n} selected file(s)?`)) return;
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // Include CSRF token in all mutating requests
                body: JSON.stringify({ action: 'mass_delete', uuids: Array.from(selectedUuids), csrf_token: window.CSRF_TOKEN })
            });
            const data = await res.json();
            if (data.success) {
                showToast(`Deleted ${data.deleted} file(s).`, 'success');
                deselectAll();
                loadFiles();
            } else {
                showToast('Delete error: ' + (data.error || 'Unknown'), 'error');
            }
        } catch (err) {
            showToast('Network error.', 'error');
        }
    }

    // Bulk tagging drawer — reuses the grid's BulkPanel (bp-) look and flow
    function openTagPanel() {
        if (selectedUuids.size === 0) return;
        if (!tagPanel) {
            tagPanel = new BulkPanel({ id: 'fileTagPanel', title: 'Add Tags', applyLabel: 'Apply' });
            tagPanel.onApply(applyMassTags);
        }
        buildTagPanelBody(tagPanel);
        tagPanel.clearStatus();
        tagPanel.open();
    }

    function buildTagPanelBody(panelInstance) {
        const body = panelInstance.bodyEl;
        body.innerHTML = '';
        panelInstance.setApplyDisabled(true);

        const scope = document.createElement('p');
        scope.className = 'me-scope-info';
        scope.textContent = `Tags will be added to ${selectedUuids.size} selected file(s).`;
        body.appendChild(scope);

        const field = document.createElement('div');
        field.className = 'bp-field';
        const label = document.createElement('label');
        label.htmlFor = 'fileBulkTagsInput';
        label.textContent = 'Tags (comma separated)';
        const input = document.createElement('input');
        input.type = 'text';
        input.id = 'fileBulkTagsInput';
        input.placeholder = 'e.g. invoice, 2026';
        input.addEventListener('input', () => panelInstance.setApplyDisabled(input.value.trim() === ''));
        field.appendChild(label);
        field.appendChild(input);
        body.appendChild(field);
    }

    async function applyMassTags(panelInstance) {
        const input = panelInstance.bodyEl.querySelector('#fileBulkTagsInput');
        const tags  = (input?.value || '').trim();
        if (!tags || selectedUuids.size === 0) return;

        panelInstance.setApplyDisabled(true);
        panelInstance.setStatus('Applying...', false);
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // Include CSRF token in all mutating requests
                body: JSON.stringify({ action: 'mass_tag', uuids: Array.from(selectedUuids), tags, csrf_token: window.CSRF_TOKEN })
            });
            const data = await res.json();
            if (data.success) {
                showToast(`Tagged ${data.tagged} file(s).`, 'success');
                panelInstance.close();
                deselectAll();
                loadFiles();
            } else {
                panelInstance.setStatus('Error: ' + (data.error || 'Failed'), true);
                panelInstance.setApplyDisabled(false);
            }
        } catch (err) {
            panelInstance.setStatus('Network error.', true);
            panelInstance.setApplyDisabled(false);
        }
    }

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
        if (btnClearFilters) btnClearFilters.hidden = !currentSearch && currentType === 'all';
        tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="f-td-empty">Loading files...</td></tr>`;
        try {
            const params = new URLSearchParams({
                action: 'list',
                page: currentPage,
                limit: pageSize,
                type: currentType,
                search: currentSearch,
                sort: sortState.column,
                dir: sortState.asc ? 'asc' : 'desc'
            });
            const res = await fetch(`${API_URL}?${params}`);
            const data = await res.json();

            if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="f-td-error">Error: ${escapeHtml(data.error || 'Unknown')}</td></tr>`;
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
            renderPagination(data.total_pages, data.total_count);
            syncSelectionUI();
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="f-td-error">Network error.</td></tr>`;
        }
    }

    function renderTable(files) {
        if (!files || files.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="f-td-empty">No files found matching your criteria.</td></tr>`;
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

            // Actions use the grid's icon buttons; delete relies on data-uuid + event
            // delegation — no inline onclick, no global function
            const deleteBtn = window.USER_CAPS.canEdit
                ? `<button class="btn-icon btn-icon-danger" data-action="delete-file" data-uuid="${escapeHtml(f.uuid)}" title="Delete">
                        <img src="assets/img/delete.png" alt="Delete">
                    </button>`
                : '';

            // Leading select checkbox column for write roles (checked state restored by syncSelectionUI)
            const selectTd = canEdit
                ? `<td class="td-select"><input type="checkbox" class="row-select-cb" aria-label="Select file" data-uuid="${escapeHtml(f.uuid)}"></td>`
                : '';

            return `
                <tr>
                    ${selectTd}
                    <td>
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
                    <td class="td-actions">
                        <a href="file_download.php?uuid=${encodeURIComponent(f.uuid)}" target="_blank" rel="noopener noreferrer" class="btn-icon" data-action="download-file" title="Download">
                            <img src="assets/icons/download.png" alt="Download">
                        </a>
                        ${deleteBtn}
                    </td>
                </tr>
            `;
        }).join('');
    }

    // Grid-style pagination: rows-per-page select, showing info, prev / page-of / next
    function renderPagination(totalPages, totalCount) {
        const pagEl = document.getElementById('filePagination');
        pagEl.innerHTML = '';
        totalPages = Math.max(1, totalPages || 1);
        totalCount = totalCount || 0;

        const sizeLabel = document.createElement('label');
        sizeLabel.className = 'pag-size';
        sizeLabel.textContent = 'Rows per page:';
        const sizeSelect = document.createElement('select');
        PAGE_SIZE_OPTIONS.forEach(n => {
            const opt = document.createElement('option');
            opt.value = n;
            opt.textContent = n;
            if (n === pageSize) opt.selected = true;
            sizeSelect.appendChild(opt);
        });
        sizeSelect.addEventListener('change', () => {
            pageSize = Number(sizeSelect.value);
            currentPage = 1;
            localStorage.setItem(LS_PAGE_SIZE, pageSize);
            loadFiles();
        });
        sizeLabel.appendChild(sizeSelect);
        pagEl.appendChild(sizeLabel);

        const from = totalCount === 0 ? 0 : (currentPage - 1) * pageSize + 1;
        const to   = Math.min(currentPage * pageSize, totalCount);
        const info = document.createElement('span');
        info.className = 'pag-info';
        info.textContent = `Showing ${from}–${to} of ${totalCount}`;
        pagEl.appendChild(info);

        const prevBtn = document.createElement('button');
        prevBtn.textContent = 'Prev';
        prevBtn.disabled = currentPage <= 1;
        prevBtn.addEventListener('click', () => {
            if (currentPage > 1) { currentPage--; loadFiles(); }
        });
        pagEl.appendChild(prevBtn);

        const pageInfo = document.createElement('span');
        pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        pagEl.appendChild(pageInfo);

        const nextBtn = document.createElement('button');
        nextBtn.textContent = 'Next';
        nextBtn.disabled = currentPage >= totalPages;
        nextBtn.addEventListener('click', () => {
            if (currentPage < totalPages) { currentPage++; loadFiles(); }
        });
        pagEl.appendChild(nextBtn);
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
