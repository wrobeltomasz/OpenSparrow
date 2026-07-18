<?php

// files.php — Files module page (frontend HTML)
// Boots via includes/bootstrap.php: os_page_bootstrap() — auth gate, admin redirect, UA/lifetime enforcement, CSRF token, CSP nonce + headers
// Exposes capability flags (canEdit/canExport) to the client
// Search box + type filter render in the app header (via $headerControls, like the grid page)
// Table mirrors the data grid look/behavior: th-label header pills, column sort, actions bar with
// grid-style pagination (rows-per-page persisted in localStorage). A slim single-row upload bar
// (styled like the actions bar) sits below the table.
// Bulk operations (delete, tagging) over row-checkbox selection — same me-bar/BulkPanel UI as the grid.
// Grid-parity inline editing: the immutable File Name and an editable display Name sit side by side,
// and per-row Tags are edited in place (click-to-edit). Single-file edits go to api/files.php update_meta.
// Renders the file manager UI; data and uploads via api/files.php, downloads via file_download.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$page     = os_page_bootstrap();
$cspNonce = $page['nonce'];
$userRole = $page['role'];
$userCaps = $page['caps'];
$pageTitle = 'OpenSparrow | Files';
$canEdit   = !empty($userCaps['canEdit']);
$headerControls = os_header_search('fileSearch', t('files.search_placeholder'))
    . '<select id="fileTypeFilter">'
    . '<option value="all">' . htmlspecialchars(t('files.filter_all_types'), ENT_QUOTES, 'UTF-8') . '</option>'
    . '<option value="image">' . htmlspecialchars(t('files.filter_images'), ENT_QUOTES, 'UTF-8') . '</option>'
    . '<option value="pdf">' . htmlspecialchars(t('files.filter_pdfs'), ENT_QUOTES, 'UTF-8') . '</option>'
    . '<option value="doc">' . htmlspecialchars(t('files.filter_documents'), ENT_QUOTES, 'UTF-8') . '</option>'
    . '<option value="spreadsheet">' . htmlspecialchars(t('files.filter_spreadsheets'), ENT_QUOTES, 'UTF-8') . '</option>'
    . '<option value="archive">' . htmlspecialchars(t('files.filter_archives'), ENT_QUOTES, 'UTF-8') . '</option>'
    . '</select>'
    . os_header_clear_filters();
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
                            <input type="checkbox" class="select-all-cb"
                                   aria-label="<?php echo htmlspecialchars(t('files.select_all_files'), ENT_QUOTES, 'UTF-8'); ?>"
                                   title="<?php echo htmlspecialchars(t('files.select_all_toggle'), ENT_QUOTES, 'UTF-8'); ?>">
                        </th>
                        <?php endif; ?>
                        <?php
                        $thTips = [
                            'type'    => t('files.tip_type'),
                            'name'    => t('files.tip_name'),
                            'display' => t('files.tip_display'),
                            'tags'    => t('files.tip_tags'),
                            'size'    => t('files.tip_size'),
                            'related' => t('files.tip_related'),
                            'created' => t('files.tip_uploaded'),
                        ];
                        $th = fn(string $sort, string $tipKey, string $label): string =>
                            '<th data-sort="' . $sort . '" data-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
                            . ' title="' . htmlspecialchars($thTips[$tipKey], ENT_QUOTES, 'UTF-8') . '">'
                            . '<span class="th-label th-tip">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></th>';
                        echo $th('type', 'type', t('files.col_type'));
                        echo $th('name', 'name', t('files.col_name'));
                        echo $th('display', 'display', t('files.col_display'));
                        echo $th('tags', 'tags', t('files.col_tags'));
                        echo $th('size', 'size', t('files.col_size'));
                        echo $th('related', 'related', t('files.col_related'));
                        echo $th('created_at', 'created', t('files.col_uploaded'));
                        ?>
                        <th class="th-actions"></th>
                    </tr>
                </thead>
                <tbody id="fileTableBody">
                    <tr><td colspan="<?php echo $canEdit ? 9 : 8; ?>" class="f-td-empty"><?php echo htmlspecialchars(t('files.loading'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                </tbody>
            </table>
        </div>

        <div id="filesActions" class="actions">
            <div class="left">
                <button id="btnRefreshFiles"><?php echo htmlspecialchars(t('files.refresh'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
            <div id="filePagination" class="pagination"></div>
        </div>

        <div id="fileUploadBar" class="f-upload-bar">
            <span class="f-upload-label"><?php echo htmlspecialchars(t('files.upload_new'), ENT_QUOTES, 'UTF-8'); ?></span>
            <input type="file" id="fileInput" class="f-input f-input-file" aria-label="<?php echo htmlspecialchars(t('files.choose_file'), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" id="fileNameInput" class="f-input f-input-w160" placeholder="<?php echo htmlspecialchars(t('files.ph_display_name'), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" id="fileTagsInput" class="f-input f-input-w160" placeholder="<?php echo htmlspecialchars(t('files.ph_tags'), ENT_QUOTES, 'UTF-8'); ?>">
            <select id="fileRelatedTable" class="f-input f-input-w160">
                <option value=""><?php echo htmlspecialchars(t('files.opt_target_table'), ENT_QUOTES, 'UTF-8'); ?></option>
            </select>
            <select id="fileRelatedId" class="f-input f-input-w220" disabled>
                <option value=""><?php echo htmlspecialchars(t('files.opt_select_table_first'), ENT_QUOTES, 'UTF-8'); ?></option>
            </select>
            <button id="btnUpload" class="success"><?php echo htmlspecialchars(t('form.upload_file'), ENT_QUOTES, 'UTF-8'); ?></button>
            <span id="uploadStatus" class="f-upload-status"></span>
        </div>

    </section>
</main>

<?php
$pageContent = ob_get_clean();
ob_start();
?>
<?php echo os_inline_globals([
    'USER_CAPS'  => $userCaps,
    'CSRF_TOKEN' => $_SESSION['csrf_token'],
], $cspNonce); ?>

<script type="module" nonce="<?php echo $cspNonce; ?>">
// Module script: reuses the grid's BulkPanel drawer and toast for bulk operations
import { BulkPanel } from './assets/js/bulk_panel.js';
import { showToast } from './assets/js/toast.js';

// Server-rendered i18n strings for this module (avoids a bundle round-trip).
const T = <?php echo json_encode([
    'delete_error'   => t('files.delete_error'),
    'network_error'  => t('files.network_error'),
    'save_error'     => t('files.save_error'),
    'name_empty'     => t('files.name_empty'),
    'name_updated'   => t('files.name_updated'),
    'tags_updated'   => t('files.tags_updated'),
    'deleted_n'      => t('files.deleted_n'),
    'tagged_n'       => t('files.tagged_n'),
    'unknown'        => t('files.unknown'),
    'failed'         => t('files.failed'),
    'go_to_record'   => t('files.go_to_record'),
    'edit_tags'      => t('files.edit_tags'),
    'select_file'    => t('files.select_file'),
    'delete'         => t('common.delete'),
    'download'       => t('common.download'),
    'bulk_add_tags'  => t('files.bulk_add_tags'),
    'bulk_apply'     => t('files.bulk_apply'),
    'bulk_deselect'  => t('files.bulk_deselect_all'),
    'bulk_n_selected' => t('files.bulk_n_selected'),
    'bulk_tags_scope' => t('files.bulk_tags_scope'),
    'ph_tags'        => t('files.ph_tags'),
    'ph_tags_example' => t('files.ph_tags_example'),
    'applying'       => t('files.applying'),
    'error_generic'  => t('files.error_generic'),
    'rows_per_page'  => t('grid.rows_per_page'),
    'showing'        => t('grid.showing'),
    'pg_prev'        => t('pagination.prev'),
    'pg_next'        => t('pagination.next'),
    'page_of'        => t('pagination.page_of'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

document.addEventListener("DOMContentLoaded", () => {
    const API_URL = 'api/files.php';
    // Grid-parity page size options; persisted like the grid's sparrow_page_size
    const PAGE_SIZE_OPTIONS = [10, 25, 50, 100];
    const LS_PAGE_SIZE = 'sparrow_files_page_size';
    const canEdit = !!(window.USER_CAPS && window.USER_CAPS.canEdit);
    // Extra leading select column for write roles
    const COLSPAN = canEdit ? 9 : 8;

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
                alert(T.delete_error.replace('{error}', data.error || T.unknown));
            }
        } catch (err) {
            alert(T.network_error);
        }
    });

    // ─── Inline editing (grid-parity: editable display Name + click-to-edit Tags) ───

    // Parse a PostgreSQL text[] literal ({tag1,"tag 2"}) into a plain array of tags.
    function tagsToArray(raw) {
        if (!raw || raw === '{}') return [];
        return raw.replace(/(^{|}$)/g, '').replace(/"/g, '').split(',').map(t => t.trim()).filter(Boolean);
    }

    function tagsBadgesHtml(arr) {
        if (arr.length) return arr.map(t => `<span class="tag-badge">${escapeHtml(t)}</span>`).join(' ');
        return canEdit ? '<span class="f-tag-add">+ Add tags</span>' : '-';
    }

    function renderTagsCell(cell, raw) {
        cell.dataset.tags = raw || '{}';
        cell.innerHTML = tagsBadgesHtml(tagsToArray(raw));
    }

    // Persist a single-file metadata patch (display_name and/or tags) via update_meta.
    async function saveMeta(uuid, patch) {
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update_meta', uuid, ...patch, csrf_token: window.CSRF_TOKEN })
            });
            const data = await res.json();
            if (!data.success) {
                showToast(T.save_error.replace('{error}', data.error || T.failed), 'error');
                return null;
            }
            return data.file;
        } catch (err) {
            showToast(T.network_error, 'error');
            return null;
        }
    }

    async function commitDisplay(cell) {
        if (cell.dataset.saving) return;
        const newVal = cell.textContent.trim();
        const orig   = cell.dataset.orig || '';
        if (newVal === orig) { cell.textContent = orig; return; }
        if (newVal === '') { cell.textContent = orig; showToast(T.name_empty, 'error'); return; }
        cell.dataset.saving = '1';
        const file = await saveMeta(cell.dataset.uuid, { display_name: newVal });
        delete cell.dataset.saving;
        if (file) {
            cell.dataset.orig = file.display_name;
            cell.textContent  = file.display_name;
            showToast(T.name_updated, 'success');
        } else {
            cell.textContent = orig;
        }
    }

    async function commitTags(input) {
        const cell = input.closest('td.f-td-tags');
        if (!cell || cell.dataset.saving) return;
        const value = input.value.trim();
        const orig  = tagsToArray(cell.dataset.tags || '').join(', ');
        if (value === orig) { renderTagsCell(cell, cell.dataset.tags || ''); return; }
        cell.dataset.saving = '1';
        const file = await saveMeta(cell.dataset.uuid, { tags: value });
        delete cell.dataset.saving;
        renderTagsCell(cell, file ? (file.tags || '{}') : (cell.dataset.tags || ''));
        if (file) showToast(T.tags_updated, 'success');
    }

    if (canEdit) {
        // Click a tags cell to switch it into an inline text input pre-filled with the tag list.
        tbody.addEventListener('click', (e) => {
            const cell = e.target.closest('td.f-td-tags');
            if (!cell || cell.querySelector('input')) return;
            const arr   = tagsToArray(cell.dataset.tags || '');
            const input = document.createElement('input');
            input.type        = 'text';
            input.className   = 'f-input f-tag-edit';
            input.value       = arr.join(', ');
            input.placeholder = 'Tags (comma separated)';
            cell.innerHTML = '';
            cell.appendChild(input);
            input.focus();
        });

        // Commit on blur for both the display-name cell and the tags input.
        tbody.addEventListener('focusout', (e) => {
            const tagInput = e.target.closest('input.f-tag-edit');
            if (tagInput) { commitTags(tagInput); return; }
            const nameCell = e.target.closest('td[data-edit="display"]');
            if (nameCell) commitDisplay(nameCell);
        });

        // Enter commits (blur), Escape cancels and restores the original value.
        tbody.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                if (e.target.closest('input.f-tag-edit') || e.target.closest('td[data-edit="display"]')) {
                    e.preventDefault();
                    e.target.blur();
                }
            } else if (e.key === 'Escape') {
                const tagInput = e.target.closest('input.f-tag-edit');
                if (tagInput) {
                    const cell = tagInput.closest('td.f-td-tags');
                    renderTagsCell(cell, cell.dataset.tags || '');
                    return;
                }
                const nameCell = e.target.closest('td[data-edit="display"]');
                if (nameCell) { nameCell.textContent = nameCell.dataset.orig || ''; nameCell.blur(); }
            }
        });
    }

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
        tagBtn.textContent = T.bulk_add_tags;
        tagBtn.addEventListener('click', openTagPanel);

        const delBtn = document.createElement('button');
        delBtn.className = 'me-bar-delete-btn';
        delBtn.id = 'fileBulkDeleteBtn';
        delBtn.textContent = T.delete;
        delBtn.addEventListener('click', massDeleteSelected);

        const clearBtn = document.createElement('button');
        clearBtn.className = 'me-bar-clear-btn';
        clearBtn.textContent = T.bulk_deselect;
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
        b.querySelector('#fileBulkCount').textContent = T.bulk_n_selected.replace('{n}', n);
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
                showToast(T.deleted_n.replace('{n}', data.deleted), 'success');
                deselectAll();
                loadFiles();
            } else {
                showToast(T.delete_error.replace('{error}', data.error || T.unknown), 'error');
            }
        } catch (err) {
            showToast(T.network_error, 'error');
        }
    }

    // Bulk tagging drawer — reuses the grid's BulkPanel (bp-) look and flow
    function openTagPanel() {
        if (selectedUuids.size === 0) return;
        if (!tagPanel) {
            tagPanel = new BulkPanel({ id: 'fileTagPanel', title: T.bulk_add_tags, applyLabel: T.bulk_apply });
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
        scope.textContent = T.bulk_tags_scope.replace('{n}', selectedUuids.size);
        body.appendChild(scope);

        const field = document.createElement('div');
        field.className = 'bp-field';
        const label = document.createElement('label');
        label.htmlFor = 'fileBulkTagsInput';
        label.textContent = T.ph_tags;
        const input = document.createElement('input');
        input.type = 'text';
        input.id = 'fileBulkTagsInput';
        input.placeholder = T.ph_tags_example;
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
        panelInstance.setStatus(T.applying, false);
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // Include CSRF token in all mutating requests
                body: JSON.stringify({ action: 'mass_tag', uuids: Array.from(selectedUuids), tags, csrf_token: window.CSRF_TOKEN })
            });
            const data = await res.json();
            if (data.success) {
                showToast(T.tagged_n.replace('{n}', data.tagged), 'success');
                panelInstance.close();
                deselectAll();
                loadFiles();
            } else {
                panelInstance.setStatus(T.error_generic.replace('{error}', data.error || T.failed), true);
                panelInstance.setApplyDisabled(false);
            }
        } catch (err) {
            panelInstance.setStatus(T.network_error, true);
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
        tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="f-td-empty"><?php echo htmlspecialchars(t('files.loading'), ENT_QUOTES, 'UTF-8'); ?></td></tr>`;
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
            tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="f-td-error"><?php echo htmlspecialchars(t('files.network_error'), ENT_QUOTES, 'UTF-8'); ?></td></tr>`;
        }
    }

    function renderTable(files) {
        if (!files || files.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="f-td-empty"><?php echo htmlspecialchars(t('files.no_files_match'), ENT_QUOTES, 'UTF-8'); ?></td></tr>`;
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
                    <a href="edit.php?table=${encodeURIComponent(f.related_table)}&id=${encodeURIComponent(f.related_id)}" class="related-badge" title="${T.go_to_record}">
                        ${escapeHtml(displayLabel)}
                    </a>
                `;
            }

            // File Name is immutable (physical file), the display Name is inline-editable.
            const displayVal  = f.display_name || '';
            const displayCell = canEdit
                ? `<td class="f-td-display editable" data-edit="display" data-uuid="${escapeHtml(f.uuid)}" data-orig="${escapeHtml(displayVal)}" contenteditable="true">${escapeHtml(displayVal)}</td>`
                : `<td class="f-td-display">${escapeHtml(displayVal || '-')}</td>`;

            // Tags cell: read-view badges; click-to-edit (comma-separated input) for write roles.
            const tagsArr  = tagsToArray(f.tags);
            const tagsCell = canEdit
                ? `<td class="f-td-tags editable-tags" data-uuid="${escapeHtml(f.uuid)}" data-tags="${escapeHtml(f.tags || '{}')}" title="${T.edit_tags}">${tagsBadgesHtml(tagsArr)}</td>`
                : `<td class="f-td-tags">${tagsArr.length ? tagsBadgesHtml(tagsArr) : '-'}</td>`;

            // Actions use the grid's icon buttons; delete relies on data-uuid + event
            // delegation — no inline onclick, no global function
            const deleteBtn = window.USER_CAPS.canEdit
                ? `<button class="btn-icon btn-icon-danger" data-action="delete-file" data-uuid="${escapeHtml(f.uuid)}" title="${T.delete}">
                        <img src="assets/img/delete.png" alt="${T.delete}">
                    </button>`
                : '';

            // Leading select checkbox column for write roles (checked state restored by syncSelectionUI)
            const selectTd = canEdit
                ? `<td class="td-select"><input type="checkbox" class="row-select-cb" aria-label="${T.select_file}" data-uuid="${escapeHtml(f.uuid)}"></td>`
                : '';

            return `
                <tr>
                    ${selectTd}
                    <td>
                        <div class="f-type-cell">
                            <img src="${escapeHtml(iconPath)}" alt="" class="f-type-icon">
                            <span class="f-type-label">${escapeHtml(f.type.charAt(0).toUpperCase() + f.type.slice(1))}</span>
                        </div>
                    </td>
                    <td class="f-td-name">${escapeHtml(f.name)}</td>
                    ${displayCell}
                    ${tagsCell}
                    <td>${size}</td>
                    <td>${relatedBadge}</td>
                    <td>${date}</td>
                    <td class="td-actions">
                        <a href="file_download.php?uuid=${encodeURIComponent(f.uuid)}" target="_blank" rel="noopener noreferrer" class="btn-icon" data-action="download-file" title="${T.download}">
                            <img src="assets/icons/download.png" alt="${T.download}">
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
        sizeLabel.textContent = T.rows_per_page + ':';
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
        info.textContent = T.showing.replace('{from}', from).replace('{to}', to).replace('{total}', totalCount);
        pagEl.appendChild(info);

        const prevBtn = document.createElement('button');
        prevBtn.textContent = T.pg_prev;
        prevBtn.disabled = currentPage <= 1;
        prevBtn.addEventListener('click', () => {
            if (currentPage > 1) { currentPage--; loadFiles(); }
        });
        pagEl.appendChild(prevBtn);

        const pageInfo = document.createElement('span');
        pageInfo.textContent = T.page_of.replace('{page}', currentPage).replace('{total}', totalPages);
        pagEl.appendChild(pageInfo);

        const nextBtn = document.createElement('button');
        nextBtn.textContent = T.pg_next;
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
