// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from './i18n.js';
import { BulkPanel } from './bulk_panel.js';
import { apiFetch } from './util/api.js';

let panel = null;
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

function localDateTimeValue(d) {
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function toInputValue(stored) {
    if (!stored) return '';
    const v = String(stored).replace(' ', 'T').slice(0, 16);
    return v.length === 10 ? v + 'T00:00' : v;
}

function formatReminder(stored) {
    return toInputValue(stored).replace('T', ' ');
}

function noteLink(note) {
    if (!note.related_table || !note.related_id) return null;
    return 'edit.php?table=' + encodeURIComponent(note.related_table) + '&id=' + encodeURIComponent(note.related_id);
}

async function fetchRecordOptions(table) {
    const result = await fetch(
        'api/notes.php?action=list_records&table=' + encodeURIComponent(table),
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('common.error_generic'));
    return data.records ?? [];
}

async function fetchNotes() {
    const result = await fetch('api/notes.php?action=list', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('common.error_generic'));
    return data.notes ?? [];
}

async function createNote(values) {
    const result = await apiFetch('api/notes.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: { action: 'add', ...values },
    });
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('notes.error_saving'));
    return data.note;
}

async function updateNote(id, values) {
    const result = await apiFetch('api/notes.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: { action: 'update', id, ...values },
    });
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('notes.error_saving'));
}

async function deleteNote(id) {
    const result = await apiFetch('api/notes.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: { action: 'delete', id },
    });
    const data = await result.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('notes.error_saving'));
}

function buildForm(tables, onSubmit, initial = null) {
    const form = document.createElement('div');
    form.className = 'note-form';

    const textarea = document.createElement('textarea');
    textarea.className = 'note-input';
    textarea.placeholder = I18n.t('notes.body_placeholder');
    textarea.rows = 3;
    textarea.maxLength = 4000;
    textarea.value = initial?.body ?? '';

    const linkRow = document.createElement('div');
    linkRow.className = 'note-form-row';

    const tableSelect = document.createElement('select');
    tableSelect.className = 'note-table-select';
    const noneOption = document.createElement('option');
    noneOption.value = '';
    noneOption.textContent = I18n.t('notes.no_link');
    tableSelect.appendChild(noneOption);
    for (const [name, config] of Object.entries(tables)) {
        const option = document.createElement('option');
        option.value = name;
        option.textContent = config.display_name ?? name;
        tableSelect.appendChild(option);
    }
    tableSelect.value = initial?.related_table ?? '';

    const recordSelect = document.createElement('select');
    recordSelect.className = 'note-record-select';
    recordSelect.disabled = true;

    function setRecordPlaceholder(text) {
        recordSelect.textContent = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = text;
        recordSelect.appendChild(option);
    }
    setRecordPlaceholder(I18n.t('notes.select_table_first'));

    async function loadRecordOptions(preselectId = null) {
        const table = tableSelect.value;
        if (!table) {
            setRecordPlaceholder(I18n.t('notes.select_table_first'));
            recordSelect.disabled = true;
            return;
        }
        recordSelect.disabled = true;
        setRecordPlaceholder(I18n.t('common.loading'));
        try {
            const records = await fetchRecordOptions(table);
            setRecordPlaceholder(I18n.t('notes.select_record'));
            for (const r of records) {
                const option = document.createElement('option');
                option.value = r.id;
                option.textContent = r.label;
                recordSelect.appendChild(option);
            }
            if (preselectId !== null) {
                recordSelect.value = String(preselectId);
            }
            recordSelect.disabled = false;
        } catch (err) {
            setRecordPlaceholder(I18n.t('notes.load_error'));
        }
    }

    tableSelect.addEventListener('change', () => loadRecordOptions());
    if (initial?.related_table) {
        loadRecordOptions(initial.related_id);
    }

    const dateInput = document.createElement('input');
    dateInput.type = 'datetime-local';
    dateInput.className = 'note-date-input';
    dateInput.min = localDateTimeValue(new Date());
    dateInput.value = toInputValue(initial?.reminder_date);
    dateInput.title = I18n.t('notes.reminder_date');

    const dateField = document.createElement('label');
    dateField.className = 'note-date-field';
    const dateLabel = document.createElement('span');
    dateLabel.className = 'note-date-label';
    dateLabel.textContent = I18n.t('notes.reminder_date');
    dateField.appendChild(dateLabel);
    dateField.appendChild(dateInput);

    linkRow.appendChild(tableSelect);
    linkRow.appendChild(recordSelect);
    linkRow.appendChild(dateField);

    const actionsRow = document.createElement('div');
    actionsRow.className = 'note-form-row';

    const saveButton = document.createElement('button');
    saveButton.className = 'btn btn-primary';
    saveButton.type = 'button';
    saveButton.textContent = initial ? I18n.t('notes.save') : I18n.t('notes.add');
    actionsRow.appendChild(saveButton);

    if (initial) {
        const cancelButton = document.createElement('button');
        cancelButton.className = 'btn btn-secondary';
        cancelButton.type = 'button';
        cancelButton.textContent = I18n.t('common.cancel');
        cancelButton.addEventListener('click', () => form.dispatchEvent(new CustomEvent('cancel')));
        actionsRow.appendChild(cancelButton);
    }

    saveButton.addEventListener('click', async () => {
        const body = textarea.value.trim();
        if (!body) return;
        saveButton.disabled = true;
        try {
            await onSubmit({
                body,
                related_table: tableSelect.value,
                related_id: tableSelect.value ? recordSelect.value : '',
                reminder_date: dateInput.value,
            });
        } catch (err) {
            alert(err.message);
        } finally {
            saveButton.disabled = false;
        }
    });

    form.appendChild(textarea);
    form.appendChild(linkRow);
    form.appendChild(actionsRow);
    return form;
}

function buildNoteRow(note, tables, { onSave, onDelete }) {
    const row = document.createElement('div');
    row.className = 'note-item';

    function renderView() {
        row.textContent = '';

        const bodyElement = document.createElement('p');
        bodyElement.className = 'note-item-body';
        bodyElement.textContent = note.body;
        row.appendChild(bodyElement);

        const meta = document.createElement('div');
        meta.className = 'note-item-meta';

        if (note.reminder_date) {
            const badge = document.createElement('span');
            badge.className = 'note-reminder-badge';
            badge.textContent = I18n.t('notes.reminder_on', { date: formatReminder(note.reminder_date) });
            meta.appendChild(badge);
        }

        const link = noteLink(note);
        if (link) {
            const a = document.createElement('a');
            a.href = link;
            a.textContent = I18n.t('notes.open_record');
            meta.appendChild(a);
        }

        row.appendChild(meta);

        const actions = document.createElement('div');
        actions.className = 'note-item-actions';

        const editButton = document.createElement('button');
        editButton.className = 'btn btn-secondary';
        editButton.type = 'button';
        editButton.textContent = I18n.t('notes.edit');
        editButton.addEventListener('click', renderEdit);

        const delButton = document.createElement('button');
        delButton.className = 'btn btn-danger';
        delButton.type = 'button';
        delButton.textContent = I18n.t('notes.delete');
        delButton.addEventListener('click', async () => {
            if (!confirm(I18n.t('notes.delete_confirm'))) return;
            try {
                await onDelete(note.id);
                row.remove();
            } catch (err) {
                alert(err.message);
            }
        });

        actions.appendChild(editButton);
        actions.appendChild(delButton);
        row.appendChild(actions);
    }

    function renderEdit() {
        row.textContent = '';
        const form = buildForm(tables, async values => {
            await onSave(note.id, values);
            Object.assign(note, values);
            renderView();
        }, note);
        form.addEventListener('cancel', renderView);
        row.appendChild(form);
    }

    renderView();
    return row;
}

export async function openNotesPanel() {
    if (!panel) {
        panel = new BulkPanel({ id: 'notesPanel', title: I18n.t('notes.title'), showApply: false });
    }

    panel.open();
    panel.clearStatus();
    panel.setStatus(I18n.t('common.loading'));
    panel.bodyEl.textContent = '';

    const tables = await loadTableOptions();

    const listElement = document.createElement('div');
    listElement.className = 'note-list';

    async function reloadList() {
        listElement.textContent = '';
        const notes = await fetchNotes();
        if (!notes.length) {
            const empty = document.createElement('p');
            empty.className = 'dc-empty';
            empty.textContent = I18n.t('notes.empty');
            listElement.appendChild(empty);
            return;
        }
        for (const note of notes) {
            listElement.appendChild(buildNoteRow(note, tables, {
                onSave: (id, values) => updateNote(id, values),
                onDelete: id => deleteNote(id),
            }));
        }
    }

    const addForm = buildForm(tables, async values => {
        await createNote(values);
        addForm.querySelector('.note-input').value = '';
        await reloadList();
    });

    panel.bodyEl.appendChild(addForm);
    panel.bodyEl.appendChild(listElement);

    try {
        await reloadList();
        panel.clearStatus();
    } catch (err) {
        panel.setStatus(err.message, true);
    }
}
