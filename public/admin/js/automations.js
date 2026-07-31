// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// admin/js/automations.js — Automation rules management UI
// Two views over the same "automations" config: record automations (update / notify /
// create record / email) and n8n automations (outgoing webhooks). Which one renders is
// decided by the item-panel tab bar in app.js, which passes `mode` in. CRUD via api.php
// (automations_list/save/delete) plus run history (automations_runs). CSRF via apiFetch().
// Field layout follows the Workflows editor: stacked .form-group fields, and the
// .auto-* classes in admin/style.css for the repeatable builders.

import { apiFetch } from '../../assets/js/util/api.js';
import { createPageHeader } from './ui.js';
import { getGlobalSchema } from './app.js';

function autoStatusPill(anchor, msg, type = 'success') {
    const prev = anchor.parentNode?.querySelector('.auto-status-pill');
    if (prev) prev.remove();
    const pill = document.createElement('span');
    pill.className = 'auto-status-pill ' + type;
    pill.textContent = msg;
    anchor.insertAdjacentElement('afterend', pill);
    setTimeout(() => { pill.style.opacity = '0'; setTimeout(() => pill.remove(), 300); },
        type === 'error' ? 6000 : 3000);
}

const AUTO_EVENTS = [
    { value: 'create', label: 'After create' },
    { value: 'update', label: 'After update' },
    { value: 'delete', label: 'After delete' },
];

const AUTO_OPS = [
    { value: '=',            label: 'equals' },
    { value: '!=',           label: 'not equals' },
    { value: 'contains',     label: 'contains' },
    { value: 'not_contains', label: 'not contains' },
    { value: 'is_empty',     label: 'is empty' },
    { value: 'is_not_empty', label: 'is not empty' },
    { value: '>',            label: 'greater than' },
    { value: '<',            label: 'less than' },
    { value: '>=',           label: 'greater or equal' },
    { value: '<=',           label: 'less or equal' },
    { value: 'changed',      label: 'changed' },
    { value: 'not_changed',  label: 'not changed' },
    { value: 'changed_from', label: 'changed from' },
    { value: 'changed_to',   label: 'changed to' },
];

// Operators that ignore the value input.
const AUTO_OPS_NO_VALUE = ['is_empty', 'is_not_empty', 'changed', 'not_changed'];

const AUTO_ACTION_LABELS = {
    update:        'Update fields on this record',
    notify:        'Send notification',
    create_record: 'Create record in another table',
    webhook:       'Send webhook (HTTP request)',
    email:         'Send email (via cron)',
};

// Which action types each tab offers. A rule is filed under the n8n tab as soon as
// it carries one webhook action, so the two lists never overlap.
const AUTO_TYPES_BY_MODE = {
    record: ['update', 'notify', 'create_record', 'email'],
    n8n:    ['webhook'],
};

const AUTO_WEBHOOK_METHODS = [
    { value: 'POST',   label: 'POST' },
    { value: 'PUT',    label: 'PUT' },
    { value: 'PATCH',  label: 'PATCH' },
    { value: 'DELETE', label: 'DELETE' },
];

const AUTO_WEBHOOK_RETRIES = [
    { value: '0', label: 'No retry' },
    { value: '1', label: 'Retry once' },
    { value: '2', label: 'Retry twice' },
];

// Kept in sync with AUTO_WEBHOOK_RESERVED_HEADERS in includes/automations.php.
const AUTO_RESERVED_HEADERS = ['content-type', 'content-length', 'user-agent', 'host', 'x-sparrow-signature'];

const AUTO_RUN_CLASS = {
    ok:      'auto-run-ok',
    error:   'auto-run-error',
    skipped: 'auto-run-skipped',
};

// PHP encodes an empty associative array as JSON `[]`, so a map that was saved
// empty comes back as a JS Array. Assigning string keys to an Array works in
// memory and even renders, but JSON.stringify() serialises only the indexed
// elements — the entries silently vanish on save. Normalise before editing.
function autoAsMap(value) {
    return (value && typeof value === 'object' && !Array.isArray(value)) ? value : {};
}

function autoDefaultAction(type, tableOptions) {
    const defaults = {
        update:        { type: 'update', set: {} },
        notify:        { type: 'notify', user_ids: ['{{ current_user.id }}'], title: '', link: '' },
        create_record: { type: 'create_record', target_table: tableOptions[0]?.value ?? '', set: {} },
        webhook:       { type: 'webhook', method: 'POST', url: '', payload: {}, headers: {}, retries: 0 },
        email:         { type: 'email', recipients: [], subject: '', body: '' },
    };
    return defaults[type] ?? defaults.update;
}

// A rule belongs to the n8n tab when at least one of its actions calls a webhook.
function autoIsWebhookRule(rule) {
    const actions = typeof rule.actions === 'string' ? safeParse(rule.actions, []) : (rule.actions ?? []);
    return Array.isArray(actions) && actions.some(a => a && a.type === 'webhook');
}

function safeParse(text, fallback) {
    try { return JSON.parse(text); } catch (_) { return fallback; }
}

// ── Small element builders ────────────────────────────────────────
function makeSelect(options, current, onChange, className = '') {
    const sel = document.createElement('select');
    if (className) sel.className = className;
    options.forEach(opt => {
        const o   = document.createElement('option');
        o.value   = opt.value;
        o.text    = opt.label;
        if (opt.value === current) o.selected = true;
        sel.appendChild(o);
    });
    sel.addEventListener('change', () => onChange(sel.value));
    return sel;
}

// Stacked label + control, matching the Workflows editor's .form-group look.
function autoField(label, control, help) {
    const wrap = document.createElement('div');
    wrap.className = 'form-group';
    const lbl = document.createElement('label');
    lbl.textContent = label;
    wrap.appendChild(lbl);
    wrap.appendChild(control);
    if (help) {
        const hint = document.createElement('span');
        hint.className = 'help-text';
        hint.textContent = help;
        wrap.appendChild(hint);
    }
    return wrap;
}

function autoTextField(label, placeholder, value, onInput, help, type = 'text') {
    const inp = document.createElement('input');
    inp.type        = type;
    inp.placeholder = placeholder;
    inp.value       = value || '';
    inp.addEventListener('input', () => onInput(inp.value));
    const field = autoField(label, inp, help);
    return { field, input: inp };
}

function autoSelectField(label, options, current, onChange, help) {
    return autoField(label, makeSelect(options, current, onChange), help);
}

function autoSectionEl(title) {
    const el = document.createElement('div');
    el.className = 'auto-section';
    const lbl = document.createElement('div');
    lbl.className   = 'auto-section-title';
    lbl.textContent = title;
    el.appendChild(lbl);
    return el;
}

function autoSubTitle(text) {
    const el = document.createElement('div');
    el.className   = 'auto-section-sub';
    el.textContent = text;
    return el;
}

function autoHintText(text) {
    const hint = document.createElement('div');
    hint.className   = 'auto-hint';
    hint.textContent = text;
    return hint;
}

function autoRemoveBtn(onClick, label = '×') {
    const btn = document.createElement('button');
    btn.type        = 'button';
    btn.className   = 'btn btn-sm btn-danger';
    btn.textContent = label;
    btn.addEventListener('click', onClick);
    return btn;
}

function autoAddBtn(label, onClick) {
    const btn = document.createElement('button');
    btn.type        = 'button';
    btn.className   = 'btn btn-sm';
    btn.textContent = label;
    btn.addEventListener('click', onClick);
    return btn;
}

// ── Conditions builder (recursive AND/OR groups) ──────────────────
function buildConditionsSection(parsed, getColumns) {
    const el = autoSectionEl('Conditions');
    el.appendChild(autoHintText(
        'The rule runs only when these conditions match the saved record. Leave empty to run on every change.'
    ));

    const groupContainer = document.createElement('div');
    el.appendChild(groupContainer);

    function renderGroup(group, container, depth, onRemove) {
        container.innerHTML = '';

        // Group header: [Match] [AND|OR] [× Group — only if nested]
        const groupHdr = document.createElement('div');
        groupHdr.className = 'auto-group-header';

        const matchLbl = document.createElement('span');
        matchLbl.textContent = 'Match';

        const typeToggle = makeSelect(
            [{ value: 'AND', label: 'AND' }, { value: 'OR', label: 'OR' }],
            group.type || 'AND',
            (v) => { group.type = v; }
        );

        groupHdr.appendChild(matchLbl);
        groupHdr.appendChild(typeToggle);

        if (depth > 0 && onRemove) {
            const btnRmGroup = autoRemoveBtn(onRemove, '× Group');
            btnRmGroup.classList.add('auto-group-remove');
            groupHdr.appendChild(btnRmGroup);
        }

        container.appendChild(groupHdr);

        const rowsEl = document.createElement('div');
        container.appendChild(rowsEl);

        function rerenderRows() {
            rowsEl.innerHTML = '';
            group.rules.forEach((item, i) => {
                if (item.type !== undefined && item.rules !== undefined) {
                    // Sub-group
                    const subWrap = document.createElement('div');
                    subWrap.className = 'auto-group-nested';
                    renderGroup(item, subWrap, depth + 1, () => {
                        group.rules.splice(i, 1);
                        rerenderRows();
                    });
                    rowsEl.appendChild(subWrap);
                } else {
                    // Leaf condition row
                    const row = document.createElement('div');
                    row.className = 'auto-row';

                    const cols   = getColumns(parsed.trigger_table);
                    const fldSel = makeSelect(cols, item.field, (v) => { item.field = v; });

                    const valInp = document.createElement('input');
                    valInp.type        = 'text';
                    valInp.placeholder = 'value';
                    valInp.value       = item.value || '';
                    valInp.addEventListener('input', () => { item.value = valInp.value; });

                    const syncValInp = () => {
                        valInp.disabled = AUTO_OPS_NO_VALUE.includes(item.operator);
                    };

                    const opSel = makeSelect(AUTO_OPS, item.operator, (v) => {
                        item.operator = v;
                        syncValInp();
                    }, 'auto-row-op');
                    syncValInp();

                    row.appendChild(fldSel);
                    row.appendChild(opSel);
                    row.appendChild(valInp);
                    row.appendChild(autoRemoveBtn(() => {
                        group.rules.splice(i, 1);
                        rerenderRows();
                    }));
                    rowsEl.appendChild(row);
                }
            });
        }

        rerenderRows();

        const addBtns = document.createElement('div');
        addBtns.className = 'auto-row-actions';
        addBtns.appendChild(autoAddBtn('+ Condition', () => {
            const firstField = getColumns(parsed.trigger_table)[0]?.value || '';
            group.rules.push({ field: firstField, operator: '=', value: '' });
            rerenderRows();
        }));
        addBtns.appendChild(autoAddBtn('+ Group', () => {
            group.rules.push({ type: 'AND', rules: [] });
            rerenderRows();
        }));
        container.appendChild(addBtns);
    }

    renderGroup(parsed.conditions, groupContainer, 0, null);

    return {
        el,
        refresh: () => renderGroup(parsed.conditions, groupContainer, 0, null),
    };
}

// ── Actions builder ───────────────────────────────────────────────
// `mode` ('record' | 'n8n') decides which action types are offered. In n8n mode a
// webhook action needs no type picker at all — the tab is the type.
function buildActionsSection(parsed, tableOptions, getColumns, users, mode) {
    const allowed  = AUTO_TYPES_BY_MODE[mode] ?? AUTO_TYPES_BY_MODE.record;
    const defType  = allowed[0];
    const el = autoSectionEl(mode === 'n8n' ? 'Webhook calls' : 'Actions');

    if (mode === 'n8n') {
        el.appendChild(autoHintText(
            'Each call posts to one HTTP endpoint — point it at an n8n Webhook node, Make, or any receiver.'
        ));
    }

    const rows = document.createElement('div');
    el.appendChild(rows);

    el.appendChild(autoAddBtn(mode === 'n8n' ? '+ Add Webhook Call' : '+ Add Action', () => {
        parsed.actions.push(autoDefaultAction(defType, tableOptions));
        renderActRows();
    }));

    function renderActRows() {
        rows.innerHTML = '';
        parsed.actions.forEach((action, i) => {
            const aType = action.type || defType;

            const actWrap = document.createElement('div');
            actWrap.className = 'auto-action';

            const actHdr = document.createElement('div');
            actHdr.className = 'auto-action-header';

            // A single-option dropdown is noise: in n8n mode the type is fixed, so
            // show a plain title instead. Legacy rules holding an action type this
            // tab does not own still get the picker so the value stays editable.
            if (allowed.length > 1 || !allowed.includes(aType)) {
                const options = allowed.map(t => ({ value: t, label: AUTO_ACTION_LABELS[t] }));
                if (!allowed.includes(aType)) {
                    options.unshift({ value: aType, label: AUTO_ACTION_LABELS[aType] ?? aType });
                }
                actHdr.appendChild(makeSelect(options, aType, (v) => {
                    parsed.actions[i] = autoDefaultAction(v, tableOptions);
                    renderActRows();
                }));
            } else {
                const title = document.createElement('span');
                title.className   = 'auto-action-name';
                title.textContent = AUTO_ACTION_LABELS[aType] ?? aType;
                actHdr.appendChild(title);
            }

            actHdr.appendChild(autoRemoveBtn(() => {
                parsed.actions.splice(i, 1);
                renderActRows();
            }, '× Remove'));
            actWrap.appendChild(actHdr);

            const bodyEl = document.createElement('div');
            actWrap.appendChild(bodyEl);

            if (aType === 'update') {
                renderUpdateBody(bodyEl, action, parsed.trigger_table, getColumns);
            } else if (aType === 'notify') {
                renderNotifyBody(bodyEl, action, users);
            } else if (aType === 'create_record') {
                renderCreateRecordBody(bodyEl, action, tableOptions, getColumns);
            } else if (aType === 'webhook') {
                renderWebhookBody(bodyEl, action);
            } else if (aType === 'email') {
                renderEmailBody(bodyEl, action);
            }

            rows.appendChild(actWrap);
        });
    }

    renderActRows();
    return { el, refresh: renderActRows };
}

// Shared "column = value" editor used by the update and create_record actions.
function renderSetMap(bodyEl, action, getTable, getColumns, valuePlaceholder) {
    action.set = autoAsMap(action.set);

    bodyEl.appendChild(autoSubTitle('Field values'));

    const setRows = document.createElement('div');
    bodyEl.appendChild(setRows);

    bodyEl.appendChild(autoAddBtn('+ Add Field', () => {
        const firstCol = getColumns(getTable())[0]?.value || '';
        if (firstCol && action.set[firstCol] === undefined) {
            action.set[firstCol] = '';
        }
        renderSetRows();
    }));

    function renderSetRows() {
        setRows.innerHTML = '';
        Object.entries(action.set ?? {}).forEach(([col, val]) => {
            const row = document.createElement('div');
            row.className = 'auto-row';

            const fldSel = makeSelect(getColumns(getTable()), col, (newCol) => {
                const oldVal = action.set[col];
                delete action.set[col];
                action.set[newCol] = oldVal;
                renderSetRows();
            });

            const eq = document.createElement('span');
            eq.className   = 'auto-row-eq';
            eq.textContent = '=';

            const valInp = document.createElement('input');
            valInp.type        = 'text';
            valInp.className   = 'auto-row-wide';
            valInp.placeholder = valuePlaceholder;
            valInp.value       = val || '';
            valInp.addEventListener('input', () => { action.set[col] = valInp.value; });

            row.appendChild(fldSel);
            row.appendChild(eq);
            row.appendChild(valInp);
            row.appendChild(autoRemoveBtn(() => {
                delete action.set[col];
                renderSetRows();
            }));
            setRows.appendChild(row);
        });
    }

    renderSetRows();
    return renderSetRows;
}

function renderUpdateBody(bodyEl, action, triggerTable, getColumns) {
    renderSetMap(
        bodyEl,
        action,
        () => triggerTable,
        getColumns,
        'value or {{ current_user.id }} / {{ record.field }}'
    );
}

function renderNotifyBody(bodyEl, action, users) {
    // Migrate legacy single user_id → user_ids array.
    if (!Array.isArray(action.user_ids)) {
        action.user_ids = action.user_id !== undefined
            ? [action.user_id]
            : ['{{ current_user.id }}'];
        delete action.user_id;
    }

    // All selectable options: special "current user" + real users from DB.
    const allOptions = [
        { id: '{{ current_user.id }}', label: 'Current user ({{ current_user.id }})' },
        ...users.map(u => ({
            id:    String(u.id),
            label: u.username + (u.is_active === false || u.is_active === 'f' ? ' [inactive]' : ''),
        })),
    ];

    bodyEl.appendChild(autoSubTitle('Recipients'));

    const chipsEl = document.createElement('div');
    chipsEl.className = 'auto-chips';
    bodyEl.appendChild(chipsEl);

    const listEl = document.createElement('div');
    listEl.className = 'auto-picker';
    bodyEl.appendChild(listEl);

    function renderChips() {
        chipsEl.innerHTML = '';
        if (action.user_ids.length === 0) {
            const empty = document.createElement('span');
            empty.textContent = 'No recipients selected';
            chipsEl.appendChild(empty);
            return;
        }
        action.user_ids.forEach((uid, i) => {
            const opt = allOptions.find(o => o.id === String(uid)) ?? { label: String(uid) };
            const chip = document.createElement('span');
            chip.className = 'auto-chip';
            const txt = document.createElement('span');
            txt.textContent = opt.label;
            const rm = document.createElement('button');
            rm.type        = 'button';
            rm.className   = 'auto-chip-remove';
            rm.textContent = '×';
            rm.addEventListener('click', () => {
                action.user_ids.splice(i, 1);
                renderChips();
                renderList();
            });
            chip.appendChild(txt);
            chip.appendChild(rm);
            chipsEl.appendChild(chip);
        });
    }

    function renderList() {
        listEl.innerHTML = '';
        allOptions.forEach(opt => {
            const isSelected = action.user_ids.some(u => String(u) === opt.id);
            const row = document.createElement('label');
            row.className = 'auto-picker-row' + (isSelected ? ' selected' : '');

            const cb = document.createElement('input');
            cb.type    = 'checkbox';
            cb.checked = isSelected;
            cb.addEventListener('change', () => {
                if (cb.checked) {
                    if (!action.user_ids.some(u => String(u) === opt.id)) {
                        // Keep template var as string; real user IDs as integers.
                        action.user_ids.push(
                            opt.id === '{{ current_user.id }}' ? opt.id : parseInt(opt.id, 10)
                        );
                    }
                } else {
                    action.user_ids = action.user_ids.filter(u => String(u) !== opt.id);
                }
                renderChips();
                renderList();
            });

            const lbl = document.createElement('span');
            lbl.textContent = opt.label;

            row.appendChild(cb);
            row.appendChild(lbl);
            listEl.appendChild(row);
        });
    }

    renderChips();
    renderList();

    bodyEl.appendChild(autoTextField(
        'Title',
        'e.g. New lead: {{ record.name }}',
        action.title,
        (v) => { action.title = v; }
    ).field);

    bodyEl.appendChild(autoTextField(
        'Link',
        'e.g. /edit.php?table=leads&id={{ record.id }}',
        action.link,
        (v) => { action.link = v; }
    ).field);
}

function renderCreateRecordBody(bodyEl, action, tableOptions, getColumns) {
    if (!action.target_table && tableOptions.length > 0) {
        action.target_table = tableOptions[0].value;
    }

    let refreshRows = () => {};
    bodyEl.appendChild(autoSelectField('Into table', tableOptions, action.target_table ?? '', (v) => {
        action.target_table = v;
        refreshRows();
    }));

    refreshRows = renderSetMap(
        bodyEl,
        action,
        () => action.target_table,
        getColumns,
        'value or {{ record.field }} / {{ current_user.id }}'
    );
}

// Editor for a free-form { key: value } map on an action (payload fields, headers).
// opts: { label, addLabel, newKey, keyPlaceholder, valuePlaceholder, validateKey,
//         configuredValues } — configuredValues marks keys whose value is stored
// server-side and never sent to the browser (header credentials): the input shows a
// placeholder instead, and staying blank keeps the saved value.
function autoMapEditor(bodyEl, map, opts) {
    bodyEl.appendChild(autoSubTitle(opts.label));

    const mapRows = document.createElement('div');
    bodyEl.appendChild(mapRows);

    bodyEl.appendChild(autoAddBtn(opts.addLabel, () => {
        let key = opts.newKey;
        let n = 1;
        while (map[key] !== undefined) { key = opts.newKey + '_' + (++n); }
        map[key] = '';
        renderRows();
    }));

    function renderRows() {
        mapRows.innerHTML = '';
        Object.entries(map).forEach(([key, val]) => {
            const row = document.createElement('div');
            row.className = 'auto-row';

            const keyInp = document.createElement('input');
            keyInp.type        = 'text';
            keyInp.placeholder = opts.keyPlaceholder;
            keyInp.value       = key;
            keyInp.addEventListener('change', () => {
                const newKey = keyInp.value.trim();
                if (newKey === key) return;
                // Tell the user why the name bounced back — a silent revert reads
                // as "nothing happened" and is impossible to debug from the UI.
                let reason = '';
                if (!newKey) {
                    reason = 'Name cannot be empty.';
                } else if (map[newKey] !== undefined) {
                    reason = `"${newKey}" is already used.`;
                } else if (opts.validateKey && !opts.validateKey(newKey)) {
                    reason = opts.invalidKeyHint ?? `"${newKey}" is not a valid name.`;
                }
                if (reason) {
                    keyInp.value = key;
                    autoStatusPill(keyInp, reason, 'error');
                    return;
                }
                const oldVal = map[key];
                delete map[key];
                map[newKey] = oldVal;
                // The server carries stored values over by name, so a rename loses it.
                if (opts.configuredValues) delete opts.configuredValues[key];
                renderRows();
            });

            const eq = document.createElement('span');
            eq.className   = 'auto-row-eq';
            eq.textContent = '=';

            const isStored = Boolean(opts.configuredValues?.[key]);
            const valInp = document.createElement('input');
            valInp.type        = 'text';
            valInp.className   = 'auto-row-wide';
            valInp.placeholder = isStored ? 'saved — type a new value to replace it' : opts.valuePlaceholder;
            valInp.value       = val || '';
            valInp.addEventListener('input', () => { map[key] = valInp.value; });

            row.appendChild(keyInp);
            row.appendChild(eq);
            row.appendChild(valInp);
            row.appendChild(autoRemoveBtn(() => {
                delete map[key];
                if (opts.configuredValues) delete opts.configuredValues[key];
                renderRows();
            }));
            mapRows.appendChild(row);
        });
    }

    renderRows();
}

function autoIsValidHeaderName(name) {
    return /^[A-Za-z0-9!#$%&'*+.^_`|~-]+$/.test(name)
        && !AUTO_RESERVED_HEADERS.includes(name.toLowerCase());
}

function renderWebhookBody(bodyEl, action) {
    action.payload            = autoAsMap(action.payload);
    action.headers            = autoAsMap(action.headers);
    action.headers_configured = autoAsMap(action.headers_configured);
    if (!action.method) action.method = 'POST';

    // Method + URL
    const reqRow = document.createElement('div');
    reqRow.className = 'auto-form-row';
    const methodField = autoSelectField('Method', AUTO_WEBHOOK_METHODS, action.method, (v) => {
        action.method = v;
    });
    methodField.classList.add('auto-form-narrow');
    reqRow.appendChild(methodField);
    const url = autoTextField(
        'Endpoint URL',
        'https://n8n.example.com/webhook/opensparrow',
        action.url,
        (v) => { action.url = v; },
        null,
        'url'
    );
    reqRow.appendChild(url.field);
    bodyEl.appendChild(reqRow);

    // The stored secret is never sent back to the browser — the server only reports
    // whether one exists. Blank keeps it; "Clear" removes it on save.
    const secret = autoTextField(
        'Secret',
        action.secret_configured
            ? 'a secret is saved — type a new one to replace it'
            : 'optional',
        '',
        (v) => { action.secret = v; if (v) action.secret_clear = false; },
        'Adds an X-Sparrow-Signature header (HMAC SHA-256 of the JSON body).'
    );
    if (action.secret_configured) {
        const btnClear = autoAddBtn('Clear secret', () => {
            action.secret            = '';
            action.secret_clear      = true;
            secret.input.value       = '';
            secret.input.placeholder = 'will be cleared on save';
        });
        secret.field.appendChild(btnClear);
    }
    bodyEl.appendChild(secret.field);

    // Each retry attempt blocks the saving user, hence the low ceiling.
    bodyEl.appendChild(autoSelectField(
        'On failure',
        AUTO_WEBHOOK_RETRIES,
        String(action.retries ?? 0),
        (v) => { action.retries = parseInt(v, 10); },
        'Retries apply only to timeouts and 5xx/429 responses. A 4xx is never repeated.'
    ));

    autoMapEditor(bodyEl, action.payload, {
        label:            'Payload fields',
        addLabel:         '+ Add Field',
        newKey:           'field',
        keyPlaceholder:   'json_key',
        valuePlaceholder: 'value or {{ record.field }} / {{ current_user.id }}',
    });
    bodyEl.appendChild(autoHintText(
        'Payload fields map JSON keys to values (templates allowed). Leave the mapping empty to send the full record.'
    ));

    autoMapEditor(bodyEl, action.headers, {
        label:            'Headers',
        addLabel:         '+ Add Header',
        newKey:           'X-Custom-Header',
        keyPlaceholder:   'Header-Name',
        valuePlaceholder: 'value or {{ record.field }}',
        validateKey:      autoIsValidHeaderName,
        invalidKeyHint:   'Invalid or reserved header name — letters, digits and - _ only, no spaces or colons.',
        configuredValues: action.headers_configured,
    });
    bodyEl.appendChild(autoHintText(
        'Use headers for the receiver’s auth, e.g. an n8n Header Auth credential. '
        + 'Values are stored encrypted and never sent back to this page: leave one blank to keep '
        + 'the saved value, or remove the row to delete it. Renaming a header clears its value. '
        + 'Content-Type, User-Agent and X-Sparrow-Signature are set by OpenSparrow and cannot be overridden.'
    ));
}

function renderEmailBody(bodyEl, action) {
    // Normalize recipients to an array (backend accepts array or comma string).
    if (!Array.isArray(action.recipients)) {
        action.recipients = typeof action.recipients === 'string' && action.recipients !== ''
            ? action.recipients.split(',').map(s => s.trim()).filter(Boolean)
            : [];
    }

    bodyEl.appendChild(autoTextField(
        'Recipients',
        'e.g. sales@example.com, {{ record.email }}',
        action.recipients.join(', '),
        (v) => { action.recipients = v.split(',').map(s => s.trim()).filter(Boolean); },
        'Comma-separated. Literal addresses or templates like {{ record.email }}.'
    ).field);

    bodyEl.appendChild(autoTextField(
        'Subject',
        'e.g. New lead: {{ record.name }}',
        action.subject,
        (v) => { action.subject = v; }
    ).field);

    const bodyTa = document.createElement('textarea');
    bodyTa.rows        = 4;
    bodyTa.placeholder = 'Plain-text message. Templates allowed, e.g. Status changed to {{ record.status }}.';
    bodyTa.value       = action.body || '';
    bodyTa.addEventListener('input', () => { action.body = bodyTa.value; });
    bodyEl.appendChild(autoField(
        'Message',
        bodyTa,
        'Delivered by the notification cron (cron_notifications.php).'
    ));
}

// ── Main page ─────────────────────────────────────────────────────
// `mode` ('record' | 'n8n') comes from the item-panel tab bar in app.js — that bar
// owns the split, so this page renders exactly one of the two lists and never builds
// a tab strip of its own.
export async function renderAutomationsPage(ctx, mode = 'record') {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    workspaceEl.appendChild(wrap);

    wrap.appendChild(createPageHeader(
        mode === 'n8n' ? 'n8n Automations' : 'Record Automations',
        mode === 'n8n'
            ? 'Send record changes to n8n, Make or any HTTP receiver. Configure the endpoint, payload '
              + 'mapping, authentication headers and retry behaviour, then review the delivery history.'
            : 'React to record changes inside OpenSparrow — update fields, notify users, create a linked '
              + 'record or queue an email. Configure conditions and actions, then review run history.'
    ));

    // ── Shared data ──────────────────────────────────────────────
    let schemaObj = {};
    try {
        schemaObj = (await getGlobalSchema())?.tables ?? {};
    } catch (_) {}

    let users = [];
    try {
        const ur = await apiFetch('api.php?action=users_list');
        const ud = await ur.json();
        users = ud.users ?? [];
    } catch (_) {}

    const tableOptions = Object.keys(schemaObj).map(k => ({
        value: k,
        label: schemaObj[k].display_name || k,
    }));

    function getColumns(tableName) {
        const tbl = schemaObj[tableName];
        if (!tbl || !tbl.columns) return [];
        return Object.entries(tbl.columns)
            .filter(([, cfg]) => (cfg.type ?? '') !== 'virtual')
            .map(([col, cfg]) => ({ value: col, label: cfg.display_name || col }));
    }

    const loadList = buildAutomationsTab(wrap, mode, {
        schemaObj, tableOptions, getColumns, users,
    });
    await loadList();
}

// The list of rules + inline editor + run-history panel, scoped to `mode`.
// Returns the list loader so the caller can do the initial fetch.
function buildAutomationsTab(panel, mode, shared) {
    const { schemaObj, tableOptions, getColumns, users } = shared;
    const isN8n = mode === 'n8n';

    const listWrap = document.createElement('div');
    panel.appendChild(listWrap);

    const formWrap = document.createElement('div');
    formWrap.style.display = 'none';
    panel.appendChild(formWrap);

    const histWrap = document.createElement('div');
    histWrap.style.display = 'none';
    panel.appendChild(histWrap);

    // ── List ────────────────────────────────────────────────────
    async function loadList() {
        listWrap.innerHTML = '';

        const bar = document.createElement('div');
        bar.className = 'auto-bar';
        const btnNew = document.createElement('button');
        btnNew.type        = 'button';
        btnNew.className   = 'btn btn-success';
        btnNew.textContent = isN8n ? '+ New n8n Automation' : '+ New Automation';
        btnNew.onclick     = () => openForm(null);
        bar.appendChild(btnNew);
        listWrap.appendChild(bar);

        let rules = [];
        try {
            const r    = await apiFetch('api.php?action=automations_list');
            const data = await r.json();
            rules = (data.automations ?? []).filter(rule => autoIsWebhookRule(rule) === isN8n);
        } catch (_) {
            rules = [];
        }

        if (rules.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = isN8n
                ? 'No n8n automations yet. Create one to push record changes to an external endpoint.'
                : 'No automations yet. Create one to get started.';
            listWrap.appendChild(empty);
            return;
        }

        const cardList = document.createElement('div');
        cardList.className = 'auto-list';

        for (const rule of rules) {
            const card = document.createElement('div');
            card.className = 'column-block collapsed';

            const hdr = document.createElement('div');
            hdr.className = 'block-header';

            const chevron = document.createElement('span');
            chevron.className   = 'block-chevron';
            chevron.textContent = '▶';

            const nameSpan = document.createElement('strong');
            nameSpan.className   = 'block-title';
            nameSpan.textContent = rule.name;

            const tableMeta = document.createElement('span');
            tableMeta.className   = 'auto-meta';
            tableMeta.textContent = (schemaObj[rule.trigger_table]?.display_name || rule.trigger_table)
                + ' · ' + (AUTO_EVENTS.find(e => e.value === rule.trigger_event)?.label ?? rule.trigger_event);

            const badge = document.createElement('span');
            badge.className   = (rule.enabled ? 'adm-badge adm-badge-ok' : 'adm-badge adm-badge-muted')
                + ' auto-shrink';
            badge.textContent = rule.enabled ? 'Active' : 'Disabled';
            badge.style.cursor = 'pointer';
            badge.title = rule.enabled ? 'Click to disable' : 'Click to enable';
            badge.addEventListener('click', e => {
                e.stopPropagation();
                saveRulePayload(rulePayload(rule, { enabled: !rule.enabled }), badge);
            });

            const btnDup = document.createElement('button');
            btnDup.type        = 'button';
            btnDup.className   = 'btn btn-sm auto-shrink';
            btnDup.textContent = 'Duplicate';
            btnDup.title       = 'Create a disabled copy of this rule';
            btnDup.addEventListener('click', e => {
                e.stopPropagation();
                saveRulePayload(
                    rulePayload(rule, { id: null, name: rule.name + ' (copy)', enabled: false }),
                    btnDup
                );
            });

            const btnHist = document.createElement('button');
            btnHist.type        = 'button';
            btnHist.className   = 'btn btn-sm auto-shrink';
            btnHist.textContent = 'History';
            btnHist.addEventListener('click', e => { e.stopPropagation(); showRunHistory(rule); });

            const btnDel = document.createElement('button');
            btnDel.type        = 'button';
            btnDel.title       = 'Delete';
            btnDel.textContent = '✕';
            btnDel.className   = 'icon-btn icon-btn-danger';
            btnDel.addEventListener('click', e => { e.stopPropagation(); deleteRule(rule.id, btnDel); });

            hdr.appendChild(chevron);
            hdr.appendChild(nameSpan);
            hdr.appendChild(tableMeta);
            hdr.appendChild(badge);
            hdr.appendChild(btnDup);
            hdr.appendChild(btnHist);
            hdr.appendChild(btnDel);
            card.appendChild(hdr);

            // Body (lazy render)
            const body = document.createElement('div');
            body.className = 'block-body';
            card.appendChild(body);

            let rendered = false;

            function openCard() {
                card.classList.remove('collapsed');
                if (!rendered) {
                    rendered = true;
                    buildFormContent(body, rule, async () => {
                        card.classList.add('collapsed');
                        rendered = false;
                        await loadList();
                    }, () => {
                        card.classList.add('collapsed');
                    });
                }
            }

            hdr.addEventListener('click', e => {
                if (e.target.closest('button, input, label')) return;
                if (card.classList.contains('collapsed')) openCard();
                else card.classList.add('collapsed');
            });

            cardList.appendChild(card);
        }

        listWrap.appendChild(cardList);
    }

    // ── Run History panel ────────────────────────────────────────
    async function showRunHistory(rule) {
        listWrap.style.display = 'none';
        formWrap.style.display = 'none';
        histWrap.style.display = '';
        histWrap.innerHTML = '';

        const card = document.createElement('div');
        card.className = 'adm-sec-card';

        const cardHdr = document.createElement('div');
        cardHdr.className = 'adm-sec-hdr';
        const cardTitle = document.createElement('h3');
        cardTitle.textContent = 'Run History: ' + rule.name;
        cardTitle.style.margin = '0';
        const btnBack = document.createElement('button');
        btnBack.type        = 'button';
        btnBack.className   = 'btn btn-sm';
        btnBack.textContent = '← Back';
        btnBack.addEventListener('click', () => {
            histWrap.style.display = 'none';
            histWrap.innerHTML = '';
            listWrap.style.display = '';
        });
        cardHdr.appendChild(cardTitle);
        cardHdr.appendChild(btnBack);
        card.appendChild(cardHdr);

        const cardBody = document.createElement('div');
        cardBody.className = 'adm-sec-body';
        card.appendChild(cardBody);
        histWrap.appendChild(card);

        const loading = document.createElement('p');
        loading.textContent = 'Loading...';
        cardBody.appendChild(loading);

        try {
            const r    = await apiFetch('api.php?action=automations_runs&rule_id=' + encodeURIComponent(rule.id));
            const data = await r.json();
            loading.remove();

            const runs = data.runs ?? [];
            if (runs.length === 0) {
                const empty = document.createElement('p');
                empty.textContent = 'No runs recorded for this automation yet.';
                cardBody.appendChild(empty);
                return;
            }

            const tbl = document.createElement('table');
            tbl.className   = 'adm-tbl';
            tbl.style.width = '100%';
            const thead = document.createElement('thead');
            thead.innerHTML = `<tr>
                <th class="adm-th">Time</th>
                <th class="adm-th">Table</th>
                <th class="adm-th">Record</th>
                <th class="adm-th">Event</th>
                <th class="adm-th">Status</th>
                <th class="adm-th">Error</th>
            </tr>`;
            tbl.appendChild(thead);

            const tbody = document.createElement('tbody');
            for (const run of runs) {
                const tr = document.createElement('tr');

                const cellDefs = [
                    run.executed_at ? new Date(run.executed_at).toLocaleString() : '—',
                    run.table_name,
                    String(run.record_id),
                    run.event,
                    null,
                    run.error_msg ?? '',
                ];

                cellDefs.forEach((text, ci) => {
                    const td = document.createElement('td');
                    td.className = 'adm-td';
                    if (ci === 4) {
                        const badge = document.createElement('span');
                        badge.className   = 'auto-run-badge '
                            + (AUTO_RUN_CLASS[run.status] ?? AUTO_RUN_CLASS.skipped);
                        badge.textContent = run.status;
                        td.appendChild(badge);
                    } else {
                        td.textContent = text;
                    }
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            }
            tbl.appendChild(tbody);
            cardBody.appendChild(tbl);
        } catch (_) {
            loading.textContent = 'Failed to load run history.';
        }
    }

    // ── Quick save helpers (toggle enable, duplicate) ────────────
    // Full rule entry for automations_save built from a list row; overrides
    // let callers flip enabled or clear the id to create a copy.
    function rulePayload(rule, overrides = {}) {
        return {
            id:            rule.id,
            name:          rule.name,
            enabled:       !!rule.enabled,
            trigger_table: rule.trigger_table,
            trigger_event: rule.trigger_event,
            conditions:    rule.conditions ?? { type: 'AND', rules: [] },
            actions:       rule.actions ?? [],
            ...overrides,
        };
    }

    async function saveRulePayload(payload, anchorEl) {
        try {
            const r    = await apiFetch('api.php?action=automations_save', {
                method: 'POST',
                body:   JSON.stringify(payload),
            });
            const data = await r.json();
            if (data.status === 'success') {
                await loadList();
            } else {
                autoStatusPill(anchorEl, data.error || 'Error', 'error');
            }
        } catch (_) {
            autoStatusPill(anchorEl, 'Request failed', 'error');
        }
    }

    // ── Delete ───────────────────────────────────────────────────
    async function deleteRule(id, btn) {
        if (!confirm('Delete this automation?')) return;
        try {
            const r    = await apiFetch('api.php?action=automations_delete', {
                method: 'POST',
                body:   JSON.stringify({ id }),
            });
            const data = await r.json();
            if (data.status === 'success') {
                autoStatusPill(btn, 'Deleted', 'success');
                await loadList();
            } else {
                autoStatusPill(btn, data.error || 'Error', 'error');
            }
        } catch (_) {
            autoStatusPill(btn, 'Request failed', 'error');
        }
    }

    // ── Form content builder ─────────────────────────────────────
    function buildFormContent(containerEl, rule, onSaved, onCancel) {
        const currentId = rule ? rule.id : null;

        const parsed = rule ? {
            name:          rule.name,
            enabled:       !!rule.enabled,
            trigger_table: rule.trigger_table,
            trigger_event: rule.trigger_event,
            conditions:    typeof rule.conditions === 'string'
                ? JSON.parse(rule.conditions)
                : (rule.conditions ?? { type: 'AND', rules: [] }),
            actions:       typeof rule.actions === 'string'
                ? JSON.parse(rule.actions)
                : (rule.actions ?? []),
        } : {
            name:          '',
            enabled:       true,
            trigger_table: tableOptions[0]?.value ?? '',
            trigger_event: 'create',
            conditions:    { type: 'AND', rules: [] },
            // A new n8n rule starts with the webhook call already in place — that is
            // the whole point of the tab.
            actions:       isN8n ? [autoDefaultAction('webhook', tableOptions)] : [],
        };

        const form = document.createElement('div');
        form.className = 'auto-form';

        // ── General ──────────────────────────────────────────────
        const general = autoSectionEl('General');

        general.appendChild(autoTextField(
            'Name',
            isN8n ? 'e.g. Push new leads to n8n' : 'e.g. Assign owner after lead creation',
            parsed.name,
            (v) => { parsed.name = v; }
        ).field);

        let condSectionRef = null;
        let actSectionRef  = null;

        const triggerRow = document.createElement('div');
        triggerRow.className = 'auto-form-row';
        triggerRow.appendChild(autoSelectField('Trigger table', tableOptions, parsed.trigger_table, (v) => {
            parsed.trigger_table = v;
            if (condSectionRef) condSectionRef.refresh();
            if (actSectionRef)  actSectionRef.refresh();
        }));
        triggerRow.appendChild(autoSelectField('Trigger event', AUTO_EVENTS, parsed.trigger_event, (v) => {
            parsed.trigger_event = v;
        }));
        general.appendChild(triggerRow);

        const statusLbl = document.createElement('label');
        statusLbl.className = 'auto-check';
        const statusCb = document.createElement('input');
        statusCb.type    = 'checkbox';
        statusCb.checked = parsed.enabled;
        statusCb.addEventListener('change', () => { parsed.enabled = statusCb.checked; });
        const statusTxt = document.createElement('span');
        statusTxt.textContent = 'Enabled';
        statusLbl.appendChild(statusCb);
        statusLbl.appendChild(statusTxt);
        general.appendChild(autoField('Status', statusLbl));

        form.appendChild(general);

        // ── Conditions ───────────────────────────────────────────
        condSectionRef = buildConditionsSection(parsed, getColumns);
        form.appendChild(condSectionRef.el);

        // ── Actions ──────────────────────────────────────────────
        actSectionRef = buildActionsSection(parsed, tableOptions, getColumns, users, mode);
        form.appendChild(actSectionRef.el);

        // ── Buttons ──────────────────────────────────────────────
        const btnRow = document.createElement('div');
        btnRow.className = 'auto-row-actions';

        const btnSave = document.createElement('button');
        btnSave.type        = 'button';
        btnSave.className   = 'btn btn-primary';
        btnSave.textContent = currentId ? 'Save Changes' : 'Create Automation';

        const btnCancel = document.createElement('button');
        btnCancel.type        = 'button';
        btnCancel.className   = 'btn';
        btnCancel.textContent = 'Cancel';
        btnCancel.addEventListener('click', onCancel);

        btnSave.addEventListener('click', async () => {
            if (!parsed.name.trim()) { autoStatusPill(btnSave, 'Name is required', 'error'); return; }
            if (!parsed.trigger_table) { autoStatusPill(btnSave, 'Select a trigger table', 'error'); return; }
            const payload = {
                id:            currentId ?? null,
                name:          parsed.name.trim(),
                enabled:       parsed.enabled,
                trigger_table: parsed.trigger_table,
                trigger_event: parsed.trigger_event,
                conditions:    parsed.conditions,
                actions:       parsed.actions,
            };
            try {
                const r    = await apiFetch('api.php?action=automations_save', {
                    method: 'POST',
                    body:   JSON.stringify(payload),
                });
                const data = await r.json();
                if (data.status === 'success') { await onSaved(); }
                else { autoStatusPill(btnSave, data.error || 'Save failed', 'error'); }
            } catch (_) { autoStatusPill(btnSave, 'Request failed', 'error'); }
        });

        btnRow.appendChild(btnSave);
        btnRow.appendChild(btnCancel);
        form.appendChild(btnRow);

        containerEl.appendChild(form);
    }

    // ── Form panel (New Automation) ──────────────────────────────
    function openForm(rule = null) {
        formWrap.style.display = '';
        formWrap.innerHTML     = '';
        listWrap.style.display = 'none';
        buildFormContent(formWrap, rule, async () => {
            closeForm();
            await loadList();
        }, closeForm);
    }

    function closeForm() {
        formWrap.style.display = 'none';
        formWrap.innerHTML     = '';
        listWrap.style.display = '';
    }

    return loadList;
}
