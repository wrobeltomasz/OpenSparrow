// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

document.addEventListener('DOMContentLoaded', function() {
    const tabBtns   = document.querySelectorAll('.tab-btn');
    const tabPanels = document.querySelectorAll('.tab-panel');

    function activateTab(tabId) {
        tabBtns.forEach(tabButton => { tabButton.classList.remove('active'); tabButton.setAttribute('aria-selected', 'false'); });
        tabPanels.forEach(tabPanel => tabPanel.classList.remove('active'));
        const button   = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
        const panel = document.getElementById(tabId);
        if (button)   { button.classList.add('active');   button.setAttribute('aria-selected', 'true'); }
        if (panel) { panel.classList.add('active'); }
    }

    tabBtns.forEach(button => {
        button.addEventListener('click', () => activateTab(button.dataset.tab));
    });

    const hash = window.location.hash.slice(1);
    if (hash && document.getElementById(hash)) {
        activateTab(hash);
    }

    document.querySelectorAll('[data-save-action]').forEach(button => {
        button.addEventListener('click', () => {
            const saveActionElement = document.getElementById('saveAction');
            if (saveActionElement) { saveActionElement.value = button.dataset.saveAction; }
        });
    });

    const buttonDelete = document.getElementById('btnDeleteRecord');
    if (buttonDelete) {
        buttonDelete.addEventListener('click', async () => {
            if (!window.confirm(window.IMAGE_TEXT.confirm_delete_record)) {
                return;
            }
            buttonDelete.disabled = true;
            try {
                const result = await fetch('index.php?api=delete', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.CSRF_TOKEN
                    },
                    body: JSON.stringify({ table: window.EDIT_TABLE, id: window.EDIT_ID })
                });
                let payload = null;
                try { payload = await result.json(); } catch (error) {}
                if (!result.ok || (payload && payload.error)) {
                    window.alert((payload && payload.error) || ('Delete failed (' + result.status + ')'));
                    buttonDelete.disabled = false;
                    return;
                }
                window.location.href = 'index.php?table=' + encodeURIComponent(window.EDIT_TABLE);
            } catch (error) {
                window.alert('Network error during delete.');
                buttonDelete.disabled = false;
            }
        });
    }

    const buttonImageUpload = document.getElementById('btnImageUpload');
    if (buttonImageUpload) {
        buttonImageUpload.addEventListener('click', async () => {
            const input    = document.getElementById('imageInput');
            const statusElement = document.getElementById('imageUploadStatus');

            if (!input.files || !input.files.length) {
                statusElement.textContent = window.IMAGE_TEXT.select_first;
                statusElement.style.color = 'var(--error)';
                return;
            }

            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('csrf_token', window.CSRF_TOKEN);
            formData.append('file', input.files[0]);
            formData.append('related_table', window.EDIT_TABLE);
            formData.append('related_id',    window.EDIT_ID);
            formData.append('related_field', '__image');

            statusElement.textContent = 'Uploading...';
            statusElement.style.color = 'var(--text)';
            buttonImageUpload.disabled = true;

            try {
                const result  = await fetch('api/files.php', { method: 'POST', body: formData });
                const data = await result.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    statusElement.textContent = 'Error: ' + (data.error || 'Upload failed');
                    statusElement.style.color = 'var(--error)';
                    buttonImageUpload.disabled = false;
                }
            } catch (error) {
                statusElement.textContent = 'Network error during upload.';
                statusElement.style.color = 'var(--error)';
                buttonImageUpload.disabled = false;
            }
        });
    }

    document.querySelectorAll('.img-delete-btn').forEach(button => {
        button.addEventListener('click', async () => {
            if (!confirm(window.IMAGE_TEXT.confirm_delete)) return;
            button.disabled = true;
            try {
                const result = await fetch('api/files.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', uuid: button.dataset.uuid, csrf_token: window.CSRF_TOKEN }),
                });
                const data = await result.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    button.disabled = false;
                }
            } catch (error) {
                button.disabled = false;
            }
        });
    });

    const buttonUpload = document.getElementById('btnInlineUpload');
    if (buttonUpload) {
        buttonUpload.addEventListener('click', async () => {
            const fileInput  = document.getElementById('inlineFileInput');
            const nameInput  = document.getElementById('inlineFileName');
            const tagsInput  = document.getElementById('inlineFileTags');
            const statusElement   = document.getElementById('inlineUploadStatus');

            if (!fileInput.files || !fileInput.files.length) {
                statusElement.textContent = 'Please select a file to upload.';
                statusElement.style.color = 'var(--error)';
                return;
            }

            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('csrf_token', window.CSRF_TOKEN);
            formData.append('file', fileInput.files[0]);
            if (nameInput.value.trim()) formData.append('display_name', nameInput.value.trim());
            if (tagsInput && tagsInput.value.trim()) formData.append('tags', tagsInput.value.trim());
            formData.append('related_table', window.EDIT_TABLE);
            formData.append('related_id',    window.EDIT_ID);

            statusElement.textContent = 'Uploading...';
            statusElement.style.color = 'var(--text)';
            buttonUpload.disabled   = true;

            try {
                const result  = await fetch('api/files.php', { method: 'POST', body: formData });
                const data = await result.json();
                if (data.success) {
                    statusElement.textContent = 'Uploaded successfully! Refreshing...';
                    statusElement.style.color = 'var(--ok)';
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    statusElement.textContent = 'Error: ' + (data.error || 'Upload failed');
                    statusElement.style.color = 'var(--error)';
                    buttonUpload.disabled   = false;
                }
            } catch (error) {
                statusElement.textContent = 'Network error during upload.';
                statusElement.style.color = 'var(--error)';
                buttonUpload.disabled   = false;
            }
        });
    }
});
