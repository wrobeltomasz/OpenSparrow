// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { BulkPanel } from './bulk_panel.js';
import { showToast } from './toast.js';
import { escHtml } from './util/esc.js';

const TEXT = window.FILES_TEXT;

document.addEventListener("DOMContentLoaded", () => {
    const API_URL = 'api/files.php';
    const PAGE_SIZE_OPTIONS = [10, 25, 50, 100];
    const LS_PAGE_SIZE = 'sparrow_files_page_size';
    const canEdit = !!(window.USER_CAPS && window.USER_CAPS.canEdit);
    const COLSPAN = canEdit ? 9 : 8;

    let currentPage = 1;
    let currentSearch = '';
    let currentType = 'all';
    let sortState = { column: 'created_at', asc: false };
    let pageSize = (() => {
        const saved = Number(localStorage.getItem(LS_PAGE_SIZE));
        return PAGE_SIZE_OPTIONS.includes(saved) ? saved : 25;
    })();
    const selectedUuids = new Set();
    let bulkBar  = null;
    let tagPanel = null;

    const fileInput       = document.getElementById('fileInput');
    const fileNameInput   = document.getElementById('fileNameInput');
    const fileTagsInput   = document.getElementById('fileTagsInput');
    const tableSelect     = document.getElementById('fileRelatedTable');
    const recordSelect    = document.getElementById('fileRelatedId');
    const buttonUpload       = document.getElementById('btnUpload');
    const uploadStatus    = document.getElementById('uploadStatus');
    const tbody           = document.getElementById('fileTableBody');
    const searchInput     = document.getElementById('fileSearch');
    const typeFilter      = document.getElementById('fileTypeFilter');
    const buttonClearFilters = document.getElementById('clearFilters');
    const buttonRefresh      = document.getElementById('btnRefreshFiles');
    const sortHeaders     = document.querySelectorAll('#filesGrid th[data-sort]');
    const selectAllCallback     = document.querySelector('#filesGrid .select-all-cb');

    const icons = {
        image:       'assets/icons/image.png',
        pdf:         'assets/icons/picture_as_pdf.png',
        doc:         'assets/icons/docs.png',
        spreadsheet: 'assets/icons/grid_on.png',
        archive:     'assets/icons/folder_zip.png',
        other:       'assets/icons/file_present.png'
    };

    const relationCache = {};

    loadConfiguredTables();
    updateSortIndicators();
    loadFiles();

    buttonUpload.addEventListener('click', uploadFile);
    buttonRefresh.addEventListener('click', () => loadFiles());
    typeFilter.addEventListener('change', (labelError) => { currentType = labelError.target.value; currentPage = 1; loadFiles(); });
    tableSelect.addEventListener('change', loadRelatedRecords);

    sortHeaders.forEach(th => {
        th.addEventListener('click', () => {
            const columnName = th.dataset.sort;
            if (sortState.column === columnName) {
                sortState.asc = !sortState.asc;
            } else {
                sortState = { column: columnName, asc: true };
            }
            currentPage = 1;
            updateSortIndicators();
            loadFiles();
        });
    });

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
    searchInput.addEventListener('input', (labelError) => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => { currentSearch = labelError.target.value; currentPage = 1; loadFiles(); }, 400);
    });

    if (buttonClearFilters) {
        buttonClearFilters.addEventListener('click', () => {
            searchInput.value = '';
            currentSearch = '';
            typeFilter.value = 'all';
            currentType = 'all';
            currentPage = 1;
            loadFiles();
        });
    }

    tbody.addEventListener('click', async (labelError) => {
        const button = labelError.target.closest('[data-action="delete-file"]');
        if (!button) return;
        const uuid = button.dataset.uuid;
        if (!uuid || !confirm('Are you sure you want to delete this file?')) return;
        try {
            const result = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', uuid, csrf_token: window.CSRF_TOKEN })
            });
            const data = await result.json();
            if (data.success) {
                loadFiles();
            } else {
                alert(TEXT.delete_error.replace('{error}', data.error || TEXT.unknown));
            }
        } catch (error) {
            alert(TEXT.network_error);
        }
    });

    function tagsToArray(raw) {
        if (!raw || raw === '{}') return [];
        return raw.replace(/(^{|}$)/g, '').replace(/"/g, '').split(',').map(tagName => tagName.trim()).filter(Boolean);
    }

    function tagsBadgesHtml(array) {
        if (array.length) return array.map(tagName => `<span class="tag-badge">${escHtml(tagName)}</span>`).join(' ');
        return canEdit ? '<span class="f-tag-add">+ Add tags</span>' : '-';
    }

    function renderTagsCell(cell, raw) {
        cell.dataset.tags = raw || '{}';
        cell.innerHTML = tagsBadgesHtml(tagsToArray(raw));
    }

    async function saveMeta(uuid, patch) {
        try {
            const result = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update_meta', uuid, ...patch, csrf_token: window.CSRF_TOKEN })
            });
            const data = await result.json();
            if (!data.success) {
                showToast(TEXT.save_error.replace('{error}', data.error || TEXT.failed), 'error');
                return null;
            }
            return data.file;
        } catch (error) {
            showToast(TEXT.network_error, 'error');
            return null;
        }
    }

    async function commitDisplay(cell) {
        if (cell.dataset.saving) return;
        const newValue = cell.textContent.trim();
        const original   = cell.dataset.orig || '';
        if (newValue === original) { cell.textContent = original; return; }
        if (newValue === '') { cell.textContent = original; showToast(TEXT.name_empty, 'error'); return; }
        cell.dataset.saving = '1';
        const file = await saveMeta(cell.dataset.uuid, { display_name: newValue });
        delete cell.dataset.saving;
        if (file) {
            cell.dataset.orig = file.display_name;
            cell.textContent  = file.display_name;
            showToast(TEXT.name_updated, 'success');
        } else {
            cell.textContent = original;
        }
    }

    async function commitTags(input) {
        const cell = input.closest('td.f-td-tags');
        if (!cell || cell.dataset.saving) return;
        const value = input.value.trim();
        const original  = tagsToArray(cell.dataset.tags || '').join(', ');
        if (value === original) { renderTagsCell(cell, cell.dataset.tags || ''); return; }
        cell.dataset.saving = '1';
        const file = await saveMeta(cell.dataset.uuid, { tags: value });
        delete cell.dataset.saving;
        renderTagsCell(cell, file ? (file.tags || '{}') : (cell.dataset.tags || ''));
        if (file) showToast(TEXT.tags_updated, 'success');
    }

    if (canEdit) {
        tbody.addEventListener('click', (labelError) => {
            const cell = labelError.target.closest('td.f-td-tags');
            if (!cell || cell.querySelector('input')) return;
            const array   = tagsToArray(cell.dataset.tags || '');
            const input = document.createElement('input');
            input.type        = 'text';
            input.className   = 'f-input f-tag-edit';
            input.value       = array.join(', ');
            input.placeholder = 'Tags (comma separated)';
            cell.innerHTML = '';
            cell.appendChild(input);
            input.focus();
        });

        tbody.addEventListener('focusout', (labelError) => {
            const tagInput = labelError.target.closest('input.f-tag-edit');
            if (tagInput) { commitTags(tagInput); return; }
            const nameCell = labelError.target.closest('td[data-edit="display"]');
            if (nameCell) commitDisplay(nameCell);
        });

        tbody.addEventListener('keydown', (labelError) => {
            if (labelError.key === 'Enter') {
                if (labelError.target.closest('input.f-tag-edit') || labelError.target.closest('td[data-edit="display"]')) {
                    labelError.preventDefault();
                    labelError.target.blur();
                }
            } else if (labelError.key === 'Escape') {
                const tagInput = labelError.target.closest('input.f-tag-edit');
                if (tagInput) {
                    const cell = tagInput.closest('td.f-td-tags');
                    renderTagsCell(cell, cell.dataset.tags || '');
                    return;
                }
                const nameCell = labelError.target.closest('td[data-edit="display"]');
                if (nameCell) { nameCell.textContent = nameCell.dataset.orig || ''; nameCell.blur(); }
            }
        });
    }

    if (selectAllCallback) {
        selectAllCallback.addEventListener('change', () => {
            tbody.querySelectorAll('.row-select-cb').forEach(callback => {
                callback.checked = selectAllCallback.checked;
                if (selectAllCallback.checked) selectedUuids.add(callback.dataset.uuid);
                else selectedUuids.delete(callback.dataset.uuid);
            });
            updateBulkBar();
        });
    }

    tbody.addEventListener('change', (labelError) => {
        const callback = labelError.target.closest('.row-select-cb');
        if (!callback) return;
        if (callback.checked) selectedUuids.add(callback.dataset.uuid);
        else selectedUuids.delete(callback.dataset.uuid);
        syncSelectAll();
        updateBulkBar();
    });

    function syncSelectAll() {
        if (!selectAllCallback) return;
        const callbacks = tbody.querySelectorAll('.row-select-cb');
        selectAllCallback.checked = callbacks.length > 0 && Array.from(callbacks).every(callback => callback.checked);
    }

    function syncSelectionUI() {
        tbody.querySelectorAll('.row-select-cb').forEach(callback => {
            callback.checked = selectedUuids.has(callback.dataset.uuid);
        });
        syncSelectAll();
        updateBulkBar();
    }

    function deselectAll() {
        selectedUuids.clear();
        tbody.querySelectorAll('.row-select-cb').forEach(callback => { callback.checked = false; });
        if (selectAllCallback) selectAllCallback.checked = false;
        updateBulkBar();
    }

    function getBulkBar() {
        if (bulkBar) return bulkBar;

        bulkBar = document.createElement('div');
        bulkBar.className = 'me-bar';
        bulkBar.id = 'fileBulkBar';

        const countElement = document.createElement('span');
        countElement.className = 'me-bar-count';
        countElement.id = 'fileBulkCount';

        const actions = document.createElement('div');
        actions.className = 'me-bar-actions';

        const tagButton = document.createElement('button');
        tagButton.className = 'me-bar-edit-btn';
        tagButton.id = 'fileBulkTagBtn';
        tagButton.textContent = TEXT.bulk_add_tags;
        tagButton.addEventListener('click', openTagPanel);

        const delButton = document.createElement('button');
        delButton.className = 'me-bar-delete-btn';
        delButton.id = 'fileBulkDeleteBtn';
        delButton.textContent = TEXT.delete;
        delButton.addEventListener('click', massDeleteSelected);

        const clearButton = document.createElement('button');
        clearButton.className = 'me-bar-clear-btn';
        clearButton.textContent = TEXT.bulk_deselect;
        clearButton.addEventListener('click', deselectAll);

        actions.appendChild(tagButton);
        actions.appendChild(delButton);
        actions.appendChild(clearButton);
        bulkBar.appendChild(countElement);
        bulkBar.appendChild(actions);

        document.body.appendChild(bulkBar);
        return bulkBar;
    }

    function updateBulkBar() {
        if (!canEdit) return;
        const selectedCount = selectedUuids.size;
        const bulkBarElement = getBulkBar();
        bulkBarElement.querySelector('#fileBulkCount').textContent = TEXT.bulk_n_selected.replace('{n}', selectedCount);
        if (selectedCount > 0) {
            bulkBarElement.classList.add('active');
        } else {
            bulkBarElement.classList.remove('active');
            if (tagPanel?.isOpen()) tagPanel.close();
        }
    }

    async function massDeleteSelected() {
        const selectedCount = selectedUuids.size;
        if (selectedCount === 0 || !confirm(`Delete ${selectedCount} selected file(s)?`)) return;
        try {
            const result = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mass_delete', uuids: Array.from(selectedUuids), csrf_token: window.CSRF_TOKEN })
            });
            const data = await result.json();
            if (data.success) {
                showToast(TEXT.deleted_n.replace('{n}', data.deleted), 'success');
                deselectAll();
                loadFiles();
            } else {
                showToast(TEXT.delete_error.replace('{error}', data.error || TEXT.unknown), 'error');
            }
        } catch (error) {
            showToast(TEXT.network_error, 'error');
        }
    }

    function openTagPanel() {
        if (selectedUuids.size === 0) return;
        if (!tagPanel) {
            tagPanel = new BulkPanel({ id: 'fileTagPanel', title: TEXT.bulk_add_tags, applyLabel: TEXT.bulk_apply });
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
        scope.textContent = TEXT.bulk_tags_scope.replace('{n}', selectedUuids.size);
        body.appendChild(scope);

        const field = document.createElement('div');
        field.className = 'bp-field';
        const label = document.createElement('label');
        label.htmlFor = 'fileBulkTagsInput';
        label.textContent = TEXT.ph_tags;
        const input = document.createElement('input');
        input.type = 'text';
        input.id = 'fileBulkTagsInput';
        input.placeholder = TEXT.ph_tags_example;
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
        panelInstance.setStatus(TEXT.applying, false);
        try {
            const result = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mass_tag', uuids: Array.from(selectedUuids), tags, csrf_token: window.CSRF_TOKEN })
            });
            const data = await result.json();
            if (data.success) {
                showToast(TEXT.tagged_n.replace('{n}', data.tagged), 'success');
                panelInstance.close();
                deselectAll();
                loadFiles();
            } else {
                panelInstance.setStatus(TEXT.error_generic.replace('{error}', data.error || TEXT.failed), true);
                panelInstance.setApplyDisabled(false);
            }
        } catch (error) {
            panelInstance.setStatus(TEXT.network_error, true);
            panelInstance.setApplyDisabled(false);
        }
    }

    async function loadConfiguredTables() {
        try {
            const result = await fetch(API_URL + '?action=get_relations_config');
            const data = await result.json();
            if (data.success && data.relations && data.relations.length > 0) {
                data.relations.forEach(record => {
                    const option = document.createElement('option');
                    option.value = record.table;
                    option.textContent = record.table;
                    tableSelect.appendChild(option);
                });
            } else {
                tableSelect.disabled = true;
                recordSelect.disabled = true;
                tableSelect.innerHTML = '<option value="">-- No relations active --</option>';
            }
        } catch (error) {
            tableSelect.innerHTML = '<option value="">-- Network error --</option>';
        }
    }

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
            const result = await fetch(`${API_URL}?action=get_related_records&table=${encodeURIComponent(tableName)}`);
            const data = await result.json();
            if (data.success && data.records) {
                recordSelect.innerHTML = '<option value="">-- Select record --</option>';
                data.records.forEach(record => {
                    const option = document.createElement('option');
                    option.value = record.id;
                    option.textContent = record.label;
                    recordSelect.appendChild(option);
                });
                recordSelect.disabled = false;
            } else {
                recordSelect.innerHTML = '<option value="">-- Load error --</option>';
            }
        } catch (error) {
            recordSelect.innerHTML = '<option value="">-- Network error --</option>';
        }
    }

    async function loadFiles() {
        if (buttonClearFilters) buttonClearFilters.hidden = !currentSearch && currentType === 'all';
        tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="f-td-empty">${escHtml(TEXT.loading)}</td></tr>`;
        try {
            const parameters = new URLSearchParams({
                action: 'list',
                page: currentPage,
                limit: pageSize,
                type: currentType,
                search: currentSearch,
                sort: sortState.column,
                dir: sortState.asc ? 'asc' : 'desc'
            });
            const result = await fetch(`${API_URL}?${parameters}`);
            const data = await result.json();

            if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="f-td-error">Error: ${escHtml(data.error || 'Unknown')}</td></tr>`;
                return;
            }

            const tablesToFetch = new Set();
            data.files.forEach(fileEntry => {
                if (fileEntry.related_table && !relationCache[fileEntry.related_table]) {
                    tablesToFetch.add(fileEntry.related_table);
                }
            });

            const fetchPromises = Array.from(tablesToFetch).map(async (table) => {
                try {
                    const lResult = await fetch(`${API_URL}?action=get_related_records&table=${encodeURIComponent(table)}`);
                    const lData = await lResult.json();
                    relationCache[table] = {};
                    if (lData.success && lData.records) {
                        lData.records.forEach(record => { relationCache[table][record.id] = record.label; });
                    }
                } catch (labelError) {
                    console.error('Failed to fetch labels for', table);
                }
            });

            await Promise.all(fetchPromises);
            renderTable(data.files);
            renderPagination(data.total_pages, data.total_count);
            syncSelectionUI();
        } catch (error) {
            tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="f-td-error">${escHtml(TEXT.network_error)}</td></tr>`;
        }
    }

    function renderTable(files) {
        if (!files || files.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="f-td-empty">${escHtml(TEXT.no_files_match)}</td></tr>`;
            return;
        }

        tbody.innerHTML = files.map(fileEntry => {
            const iconPath = icons[fileEntry.type] || icons.other;
            const size     = formatBytes(fileEntry.size_bytes);
            const date     = new Date(fileEntry.created_at).toLocaleDateString();

            let relatedBadge = '-';
            if (fileEntry.related_table && fileEntry.related_id) {
                const displayLabel = relationCache[fileEntry.related_table] && relationCache[fileEntry.related_table][fileEntry.related_id]
                    ? relationCache[fileEntry.related_table][fileEntry.related_id]
                    : `${fileEntry.related_table} #${fileEntry.related_id}`;
                relatedBadge = `
                    <a href="edit.php?table=${encodeURIComponent(fileEntry.related_table)}&id=${encodeURIComponent(fileEntry.related_id)}" class="related-badge" title="${TEXT.go_to_record}">
                        ${escHtml(displayLabel)}
                    </a>
                `;
            }

            const displayValue  = fileEntry.display_name || '';
            const displayCell = canEdit
                ? `<td class="f-td-display editable" data-edit="display" data-uuid="${escHtml(fileEntry.uuid)}" data-orig="${escHtml(displayValue)}" contenteditable="true">${escHtml(displayValue)}</td>`
                : `<td class="f-td-display">${escHtml(displayValue || '-')}</td>`;

            const tagsArr  = tagsToArray(fileEntry.tags);
            const tagsCell = canEdit
                ? `<td class="f-td-tags editable-tags" data-uuid="${escHtml(fileEntry.uuid)}" data-tags="${escHtml(fileEntry.tags || '{}')}" title="${TEXT.edit_tags}">${tagsBadgesHtml(tagsArr)}</td>`
                : `<td class="f-td-tags">${tagsArr.length ? tagsBadgesHtml(tagsArr) : '-'}</td>`;

            const deleteButton = window.USER_CAPS.canEdit
                ? `<button class="btn-icon btn-icon-danger" data-action="delete-file" data-uuid="${escHtml(fileEntry.uuid)}" title="${TEXT.delete}">
                        <img src="assets/icons/delete.png" alt="${TEXT.delete}">
                    </button>`
                : '';

            const selectTd = canEdit
                ? `<td class="td-select"><input type="checkbox" class="row-select-cb" aria-label="${TEXT.select_file}" data-uuid="${escHtml(fileEntry.uuid)}"></td>`
                : '';

            return `
                <tr>
                    ${selectTd}
                    <td>
                        <div class="f-type-cell">
                            <img src="${escHtml(iconPath)}" alt="" class="f-type-icon">
                            <span class="f-type-label">${escHtml(fileEntry.type.charAt(0).toUpperCase() + fileEntry.type.slice(1))}</span>
                        </div>
                    </td>
                    <td class="f-td-name">${escHtml(fileEntry.name)}</td>
                    ${displayCell}
                    ${tagsCell}
                    <td>${size}</td>
                    <td>${relatedBadge}</td>
                    <td>${date}</td>
                    <td class="td-actions">
                        <a href="file_download.php?uuid=${encodeURIComponent(fileEntry.uuid)}" target="_blank" rel="noopener noreferrer" class="btn-icon" data-action="download-file" title="${TEXT.download}">
                            <img src="assets/icons/download.png" alt="${TEXT.download}">
                        </a>
                        ${deleteButton}
                    </td>
                </tr>
            `;
        }).join('');
    }

    function renderPagination(totalPages, totalCount) {
        const pagElement = document.getElementById('filePagination');
        pagElement.innerHTML = '';
        totalPages = Math.max(1, totalPages || 1);
        totalCount = totalCount || 0;

        const sizeLabel = document.createElement('label');
        sizeLabel.className = 'pag-size';
        sizeLabel.textContent = TEXT.rows_per_page + ':';
        const sizeSelect = document.createElement('select');
        PAGE_SIZE_OPTIONS.forEach(selectedCount => {
            const option = document.createElement('option');
            option.value = selectedCount;
            option.textContent = selectedCount;
            if (selectedCount === pageSize) option.selected = true;
            sizeSelect.appendChild(option);
        });
        sizeSelect.addEventListener('change', () => {
            pageSize = Number(sizeSelect.value);
            currentPage = 1;
            localStorage.setItem(LS_PAGE_SIZE, pageSize);
            loadFiles();
        });
        sizeLabel.appendChild(sizeSelect);
        pagElement.appendChild(sizeLabel);

        const from = totalCount === 0 ? 0 : (currentPage - 1) * pageSize + 1;
        const to   = Math.min(currentPage * pageSize, totalCount);
        const information = document.createElement('span');
        information.className = 'pag-info';
        information.textContent = TEXT.showing.replace('{from}', from).replace('{to}', to).replace('{total}', totalCount);
        pagElement.appendChild(information);

        const previousButton = document.createElement('button');
        previousButton.textContent = TEXT.pg_prev;
        previousButton.disabled = currentPage <= 1;
        previousButton.addEventListener('click', () => {
            if (currentPage > 1) { currentPage--; loadFiles(); }
        });
        pagElement.appendChild(previousButton);

        const pageInformation = document.createElement('span');
        pageInformation.textContent = TEXT.page_of.replace('{page}', currentPage).replace('{total}', totalPages);
        pagElement.appendChild(pageInformation);

        const nextButton = document.createElement('button');
        nextButton.textContent = TEXT.pg_next;
        nextButton.disabled = currentPage >= totalPages;
        nextButton.addEventListener('click', () => {
            if (currentPage < totalPages) { currentPage++; loadFiles(); }
        });
        pagElement.appendChild(nextButton);
    }

    async function uploadFile() {
        if (!fileInput.files.length) {
            setUploadStatus('Please select a file.', 'error');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('file', fileInput.files[0]);
        formData.append('csrf_token', window.CSRF_TOKEN);
        if (fileNameInput.value.trim()) formData.append('display_name', fileNameInput.value.trim());
        if (fileTagsInput.value.trim()) formData.append('tags', fileTagsInput.value.trim());
        if (!tableSelect.disabled && tableSelect.value.trim()) formData.append('related_table', tableSelect.value.trim());
        if (!recordSelect.disabled && recordSelect.value.trim()) formData.append('related_id', recordSelect.value.trim());

        setUploadStatus('Uploading...', 'neutral');

        try {
            const result = await fetch(API_URL, { method: 'POST', body: formData });
            const data = await result.json();
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
        } catch (error) {
            setUploadStatus('Network error during upload.', 'error');
        }
    }

    function setUploadStatus(message, state) {
        uploadStatus.textContent = message;
        uploadStatus.className = `f-upload-status f-status-${state}`;
    }

    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        const unitBase = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const unitIndex = Math.floor(Math.log(bytes) / Math.log(unitBase));
        return parseFloat((bytes / Math.pow(unitBase, unitIndex)).toFixed(2)) + ' ' + sizes[unitIndex];
    }
});
