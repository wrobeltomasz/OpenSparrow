// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from '../i18n.js';

let tableOptions = null;

async function loadTableOptions() {
    if (tableOptions) return tableOptions;
    try {
        const result = await fetch('api/schema.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await result.json();
        tableOptions = data.tables ?? {};
    } catch (_) {
        tableOptions = {};
    }
    return tableOptions;
}

async function fetchRecordOptions(table) {
    const result = await fetch(
        'api/notes.php?action=list_records&table=' + encodeURIComponent(table),
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('comments.error_load'));
    return data.records ?? [];
}

export function buildRecordLinkPicker(onInsert) {
    const overlay = document.createElement('div');
    overlay.className = 'c-link-overlay';

    const box = document.createElement('div');
    box.className = 'c-link-box';

    const closeButton = document.createElement('button');
    closeButton.className = 'c-link-close';
    closeButton.type = 'button';
    closeButton.textContent = '×';
    closeButton.setAttribute('aria-label', I18n.t('header.close'));
    closeButton.addEventListener('click', () => overlay.remove());

    const title = document.createElement('h3');
    title.textContent = I18n.t('comments.insert_link');

    const tableSelect = document.createElement('select');
    tableSelect.className = 'c-link-select';

    const recordSelect = document.createElement('select');
    recordSelect.className = 'c-link-select';
    recordSelect.disabled = true;

    function setRecordPlaceholder(text) {
        recordSelect.textContent = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = text;
        recordSelect.appendChild(option);
    }
    setRecordPlaceholder(I18n.t('comments.select_table'));

    async function populateTables() {
        const tables = await loadTableOptions();
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = I18n.t('comments.select_table');
        tableSelect.appendChild(placeholder);
        for (const [name, config] of Object.entries(tables)) {
            const option = document.createElement('option');
            option.value = name;
            option.textContent = config.display_name ?? name;
            tableSelect.appendChild(option);
        }
    }
    populateTables();

    async function loadRecords() {
        const selectedTable = tableSelect.value;
        if (!selectedTable) {
            setRecordPlaceholder(I18n.t('comments.select_table'));
            recordSelect.disabled = true;
            return;
        }
        recordSelect.disabled = true;
        setRecordPlaceholder(I18n.t('common.loading'));
        try {
            const records = await fetchRecordOptions(selectedTable);
            setRecordPlaceholder(I18n.t('comments.select_record'));
            let hasRecords = false;
            for (const record of records) {
                const option = document.createElement('option');
                option.value = record.id;
                option.textContent = record.label;
                recordSelect.appendChild(option);
                hasRecords = true;
            }
            if (!hasRecords) {
                setRecordPlaceholder(I18n.t('form.no_records'));
                recordSelect.disabled = true;
                return;
            }
            recordSelect.disabled = false;
        } catch (_) {
            setRecordPlaceholder(I18n.t('comments.error_load'));
        }
    }

    tableSelect.addEventListener('change', loadRecords);

    const actions = document.createElement('div');
    actions.className = 'c-link-actions';

    const cancelButton = document.createElement('button');
    cancelButton.className = 'btn btn-secondary';
    cancelButton.type = 'button';
    cancelButton.textContent = I18n.t('common.cancel');
    cancelButton.addEventListener('click', () => overlay.remove());

    const insertButton = document.createElement('button');
    insertButton.className = 'btn btn-primary';
    insertButton.type = 'button';
    insertButton.textContent = I18n.t('comments.insert');
    insertButton.disabled = true;
    insertButton.addEventListener('click', () => {
        const selectedTable = tableSelect.value;
        const selectedId = recordSelect.value;
        if (!selectedTable || !selectedId) return;
        onInsert(`[Record: ${selectedTable}:${selectedId}]`);
        overlay.remove();
    });

    recordSelect.addEventListener('change', () => {
        insertButton.disabled = !recordSelect.value;
    });

    actions.appendChild(cancelButton);
    actions.appendChild(insertButton);

    box.appendChild(closeButton);
    box.appendChild(title);
    box.appendChild(tableSelect);
    box.appendChild(recordSelect);
    box.appendChild(actions);
    overlay.appendChild(box);
    return overlay;
}
