// assets/js/notes-panel.js — User menu > Notes: private per-user notepad, optionally
// linked to a record (table+id) with an optional reminder date delivered via the bell
// icon (cron/cron_notifications.php -> spw_users_notifications). CRUD via api/notes.php.
// Opened from user-menu.js (notesBtn). CSS prefix: note-.

import { I18n } from './i18n.js';
import { BulkPanel } from './bulk_panel.js';
import { apiFetch } from './util/api.js';

let panel = null;
let tableOptions = null;

async function loadTableOptions() {
    if (tableOptions) return tableOptions;
    try {
        const res = await fetch('api/schema.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        tableOptions = data.tables ?? {};
    } catch (_) {
        tableOptions = {};
    }
    return tableOptions;
}

function noteLink(note) {
    if (!note.related_table || !note.related_id) return null;
    return 'edit.php?table=' + encodeURIComponent(note.related_table) + '&id=' + encodeURIComponent(note.related_id);
}

// Table's record list for the picker (id, label) — mirrors files.php's chained
// table+record <select> pair (public/api/files.php actionGetRelatedRecords).
async function fetchRecordOptions(table) {
    const res = await fetch(
        'api/notes.php?action=list_records&table=' + encodeURIComponent(table),
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    const data = await res.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('common.error_generic'));
    return data.records ?? [];
}

// ── API calls ────────────────────────────────────────────────────────────

async function fetchNotes() {
    const res = await fetch('api/notes.php?action=list', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('common.error_generic'));
    return data.notes ?? [];
}

async function createNote(values) {
    const res = await apiFetch('api/notes.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: { action: 'add', ...values },
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('notes.error_saving'));
    return data.note;
}

async function updateNote(id, values) {
    const res = await apiFetch('api/notes.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: { action: 'update', id, ...values },
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('notes.error_saving'));
}

async function deleteNote(id) {
    const res = await apiFetch('api/notes.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: { action: 'delete', id },
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error ?? I18n.t('notes.error_saving'));
}

// ── DOM builders ─────────────────────────────────────────────────────────

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
    const noneOpt = document.createElement('option');
    noneOpt.value = '';
    noneOpt.textContent = I18n.t('notes.no_link');
    tableSelect.appendChild(noneOpt);
    for (const [name, cfg] of Object.entries(tables)) {
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = cfg.display_name ?? name;
        tableSelect.appendChild(opt);
    }
    tableSelect.value = initial?.related_table ?? '';

    const recordSelect = document.createElement('select');
    recordSelect.className = 'note-record-select';
    recordSelect.disabled = true;

    function setRecordPlaceholder(text) {
        recordSelect.textContent = '';
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = text;
        recordSelect.appendChild(opt);
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
                const opt = document.createElement('option');
                opt.value = r.id;
                opt.textContent = r.label;
                recordSelect.appendChild(opt);
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
    dateInput.type = 'date';
    dateInput.className = 'note-date-input';
    dateInput.min = new Date().toISOString().slice(0, 10);
    dateInput.value = initial?.reminder_date ?? '';
    dateInput.title = I18n.t('notes.reminder_date');

    linkRow.appendChild(tableSelect);
    linkRow.appendChild(recordSelect);
    linkRow.appendChild(dateInput);

    const actionsRow = document.createElement('div');
    actionsRow.className = 'note-form-row';

    const saveBtn = document.createElement('button');
    saveBtn.className = 'btn btn-primary';
    saveBtn.type = 'button';
    saveBtn.textContent = initial ? I18n.t('notes.save') : I18n.t('notes.add');
    actionsRow.appendChild(saveBtn);

    if (initial) {
        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn btn-secondary';
        cancelBtn.type = 'button';
        cancelBtn.textContent = I18n.t('common.cancel');
        cancelBtn.addEventListener('click', () => form.dispatchEvent(new CustomEvent('cancel')));
        actionsRow.appendChild(cancelBtn);
    }

    saveBtn.addEventListener('click', async () => {
        const body = textarea.value.trim();
        if (!body) return;
        saveBtn.disabled = true;
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
            saveBtn.disabled = false;
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

        const bodyEl = document.createElement('p');
        bodyEl.className = 'note-item-body';
        bodyEl.textContent = note.body;
        row.appendChild(bodyEl);

        const meta = document.createElement('div');
        meta.className = 'note-item-meta';

        if (note.reminder_date) {
            const badge = document.createElement('span');
            badge.className = 'note-reminder-badge';
            badge.textContent = I18n.t('notes.reminder_on', { date: note.reminder_date });
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

        const editBtn = document.createElement('button');
        editBtn.className = 'btn btn-secondary';
        editBtn.type = 'button';
        editBtn.textContent = I18n.t('notes.edit');
        editBtn.addEventListener('click', renderEdit);

        const delBtn = document.createElement('button');
        delBtn.className = 'btn btn-danger';
        delBtn.type = 'button';
        delBtn.textContent = I18n.t('notes.delete');
        delBtn.addEventListener('click', async () => {
            if (!confirm(I18n.t('notes.delete_confirm'))) return;
            try {
                await onDelete(note.id);
                row.remove();
            } catch (err) {
                alert(err.message);
            }
        });

        actions.appendChild(editBtn);
        actions.appendChild(delBtn);
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

// ── Panel ────────────────────────────────────────────────────────────────

export async function openNotesPanel() {
    if (!panel) {
        panel = new BulkPanel({ id: 'notesPanel', title: I18n.t('notes.title'), showApply: false });
    }

    panel.open();
    panel.clearStatus();
    panel.setStatus(I18n.t('common.loading'));
    panel.bodyEl.textContent = '';

    const tables = await loadTableOptions();

    const listEl = document.createElement('div');
    listEl.className = 'note-list';

    async function reloadList() {
        listEl.textContent = '';
        const notes = await fetchNotes();
        if (!notes.length) {
            const empty = document.createElement('p');
            empty.className = 'dc-empty';
            empty.textContent = I18n.t('notes.empty');
            listEl.appendChild(empty);
            return;
        }
        for (const note of notes) {
            listEl.appendChild(buildNoteRow(note, tables, {
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
    panel.bodyEl.appendChild(listEl);

    try {
        await reloadList();
        panel.clearStatus();
    } catch (err) {
        panel.setStatus(err.message, true);
    }
}
