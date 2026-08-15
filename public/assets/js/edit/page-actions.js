// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

document.addEventListener('DOMContentLoaded', function() {
    const tabBtns   = document.querySelectorAll('.tab-btn');
    const tabPanels = document.querySelectorAll('.tab-panel');

    function activateTab(tabId) {
        tabBtns.forEach(b => { b.classList.remove('active'); b.setAttribute('aria-selected', 'false'); });
        tabPanels.forEach(p => p.classList.remove('active'));
        const btn   = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
        const panel = document.getElementById(tabId);
        if (btn)   { btn.classList.add('active');   btn.setAttribute('aria-selected', 'true'); }
        if (panel) { panel.classList.add('active'); }
    }

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => activateTab(btn.dataset.tab));
    });

    const hash = window.location.hash.slice(1);
    if (hash && document.getElementById(hash)) {
        activateTab(hash);
    }

    document.querySelectorAll('[data-save-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const sa = document.getElementById('saveAction');
            if (sa) { sa.value = btn.dataset.saveAction; }
        });
    });

    const btnDelete = document.getElementById('btnDeleteRecord');
    if (btnDelete) {
        btnDelete.addEventListener('click', async () => {
            if (!window.confirm(window.IMAGE_TEXT.confirm_delete_record)) {
                return;
            }
            btnDelete.disabled = true;
            try {
                const res = await fetch('index.php?api=delete', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.CSRF_TOKEN
                    },
                    body: JSON.stringify({ table: window.EDIT_TABLE, id: window.EDIT_ID })
                });
                let payload = null;
                try { payload = await res.json(); } catch (e) {}
                if (!res.ok || (payload && payload.error)) {
                    window.alert((payload && payload.error) || ('Delete failed (' + res.status + ')'));
                    btnDelete.disabled = false;
                    return;
                }
                window.location.href = 'index.php?table=' + encodeURIComponent(window.EDIT_TABLE);
            } catch (err) {
                window.alert('Network error during delete.');
                btnDelete.disabled = false;
            }
        });
    }

    const btnImgUpload = document.getElementById('btnImageUpload');
    if (btnImgUpload) {
        btnImgUpload.addEventListener('click', async () => {
            const input    = document.getElementById('imageInput');
            const statusEl = document.getElementById('imageUploadStatus');

            if (!input.files || !input.files.length) {
                statusEl.textContent = window.IMAGE_TEXT.select_first;
                statusEl.style.color = 'var(--error)';
                return;
            }

            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('csrf_token', window.CSRF_TOKEN);
            formData.append('file', input.files[0]);
            formData.append('related_table', window.EDIT_TABLE);
            formData.append('related_id',    window.EDIT_ID);
            formData.append('related_field', '__image');

            statusEl.textContent = 'Uploading...';
            statusEl.style.color = 'var(--text)';
            btnImgUpload.disabled = true;

            try {
                const res  = await fetch('api/files.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    statusEl.textContent = 'Error: ' + (data.error || 'Upload failed');
                    statusEl.style.color = 'var(--error)';
                    btnImgUpload.disabled = false;
                }
            } catch (err) {
                statusEl.textContent = 'Network error during upload.';
                statusEl.style.color = 'var(--error)';
                btnImgUpload.disabled = false;
            }
        });
    }

    document.querySelectorAll('.img-delete-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm(window.IMAGE_TEXT.confirm_delete)) return;
            btn.disabled = true;
            try {
                const res = await fetch('api/files.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', uuid: btn.dataset.uuid, csrf_token: window.CSRF_TOKEN }),
                });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    btn.disabled = false;
                }
            } catch (err) {
                btn.disabled = false;
            }
        });
    });

    const btnUpload = document.getElementById('btnInlineUpload');
    if (btnUpload) {
        btnUpload.addEventListener('click', async () => {
            const fileInput  = document.getElementById('inlineFileInput');
            const nameInput  = document.getElementById('inlineFileName');
            const tagsInput  = document.getElementById('inlineFileTags');
            const statusEl   = document.getElementById('inlineUploadStatus');

            if (!fileInput.files || !fileInput.files.length) {
                statusEl.textContent = 'Please select a file to upload.';
                statusEl.style.color = 'var(--error)';
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

            statusEl.textContent = 'Uploading...';
            statusEl.style.color = 'var(--text)';
            btnUpload.disabled   = true;

            try {
                const res  = await fetch('api/files.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    statusEl.textContent = 'Uploaded successfully! Refreshing...';
                    statusEl.style.color = 'var(--ok)';
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    statusEl.textContent = 'Error: ' + (data.error || 'Upload failed');
                    statusEl.style.color = 'var(--error)';
                    btnUpload.disabled   = false;
                }
            } catch (err) {
                statusEl.textContent = 'Network error during upload.';
                statusEl.style.color = 'var(--error)';
                btnUpload.disabled   = false;
            }
        });
    }
});
