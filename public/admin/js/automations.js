// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { createPageHeader } from './ui.js';
import { getGlobalSchema } from './app.js';

function autoStatusPill(anchor, message, type = 'success') {
    const previous = anchor.parentNode?.querySelector('.auto-status-pill');
    if (previous) previous.remove();
    const pill = document.createElement('span');
    pill.className = 'auto-status-pill ' + type;
    pill.textContent = message;
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

const AUTO_OPS_NO_VALUE = ['is_empty', 'is_not_empty', 'changed', 'not_changed'];

const AUTO_ACTION_LABELS = {
    update:        'Update fields on this record',
    notify:        'Send notification',
    create_record: 'Create record in another table',
    webhook:       'Send webhook (HTTP request)',
    email:         'Send email (via cron)',
};

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

const AUTO_RESERVED_HEADERS = ['content-type', 'content-length', 'user-agent', 'host', 'x-sparrow-signature'];

const AUTO_RUN_CLASS = {
    ok:      'auto-run-ok',
    error:   'auto-run-error',
    skipped: 'auto-run-skipped',
};

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

function autoIsWebhookRule(rule) {
    const actions = typeof rule.actions === 'string' ? safeParse(rule.actions, []) : (rule.actions ?? []);
    return Array.isArray(actions) && actions.some(automationAction => automationAction && automationAction.type === 'webhook');
}

function safeParse(text, fallback) {
    try { return JSON.parse(text); } catch (_) { return fallback; }
}

function makeSelect(options, current, onChange, className = '') {
    const selectElement = document.createElement('select');
    if (className) selectElement.className = className;
    options.forEach(option => {
        const optionElement   = document.createElement('option');
        o.value   = option.value;
        o.text    = option.label;
        if (option.value === current) o.selected = true;
        selectElement.appendChild(o);
    });
    selectElement.addEventListener('change', () => onChange(selectElement.value));
    return selectElement;
}

function autoField(label, control, help) {
    const wrap = document.createElement('div');
    wrap.className = 'form-group';
    const labelElement = document.createElement('label');
    labelElement.textContent = label;
    wrap.appendChild(labelElement);
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
    const inputElement = document.createElement('input');
    inputElement.type        = type;
    inputElement.placeholder = placeholder;
    inputElement.value       = value || '';
    inputElement.addEventListener('input', () => onInput(inputElement.value));
    const field = autoField(label, inputElement, help);
    return { field, input: inputElement };
}

function autoSelectField(label, options, current, onChange, help) {
    return autoField(label, makeSelect(options, current, onChange), help);
}

function autoSectionElement(title) {
    const element = document.createElement('div');
    element.className = 'auto-section';
    const labelElement = document.createElement('div');
    labelElement.className   = 'auto-section-title';
    labelElement.textContent = title;
    element.appendChild(labelElement);
    return element;
}

function autoSubTitle(text) {
    const element = document.createElement('div');
    element.className   = 'auto-section-sub';
    element.textContent = text;
    return element;
}

function autoHintText(text) {
    const hint = document.createElement('div');
    hint.className   = 'auto-hint';
    hint.textContent = text;
    return hint;
}

function autoRemoveButton(onClick, label = '×') {
    const button = document.createElement('button');
    button.type        = 'button';
    button.className   = 'btn btn-sm btn-danger';
    button.textContent = label;
    button.addEventListener('click', onClick);
    return button;
}

function autoAddButton(label, onClick) {
    const button = document.createElement('button');
    button.type        = 'button';
    button.className   = 'btn btn-sm';
    button.textContent = label;
    button.addEventListener('click', onClick);
    return button;
}

function buildConditionsSection(parsed, getColumns) {
    const element = autoSectionElement('Conditions');
    element.appendChild(autoHintText(
        'The rule runs only when these conditions match the saved record. Leave empty to run on every change.'
    ));

    const groupContainer = document.createElement('div');
    element.appendChild(groupContainer);

    function renderGroup(group, container, depth, onRemove) {
        container.innerHTML = '';

        const groupHdr = document.createElement('div');
        groupHdr.className = 'auto-group-header';

        const matchLabel = document.createElement('span');
        matchLabel.textContent = 'Match';

        const typeToggle = makeSelect(
            [{ value: 'AND', label: 'AND' }, { value: 'OR', label: 'OR' }],
            group.type || 'AND',
            (fieldValue) => { group.type = fieldValue; }
        );

        groupHdr.appendChild(matchLabel);
        groupHdr.appendChild(typeToggle);

        if (depth > 0 && onRemove) {
            const buttonRmGroup = autoRemoveButton(onRemove, '× Group');
            buttonRmGroup.classList.add('auto-group-remove');
            groupHdr.appendChild(buttonRmGroup);
        }

        container.appendChild(groupHdr);

        const rowsElement = document.createElement('div');
        container.appendChild(rowsElement);

        function rerenderRows() {
            rowsElement.innerHTML = '';
            group.rules.forEach((item, i) => {
                if (item.type !== undefined && item.rules !== undefined) {
                    const subWrap = document.createElement('div');
                    subWrap.className = 'auto-group-nested';
                    renderGroup(item, subWrap, depth + 1, () => {
                        group.rules.splice(i, 1);
                        rerenderRows();
                    });
                    rowsElement.appendChild(subWrap);
                } else {
                    const row = document.createElement('div');
                    row.className = 'auto-row';

                    const cols   = getColumns(parsed.trigger_table);
                    const fieldSelect = makeSelect(cols, item.field, (fieldValue) => { item.field = fieldValue; });

                    const valueInput = document.createElement('input');
                    valueInput.type        = 'text';
                    valueInput.placeholder = 'value';
                    valueInput.value       = item.value || '';
                    valueInput.addEventListener('input', () => { item.value = valueInput.value; });

                    const syncValueInput = () => {
                        valueInput.disabled = AUTO_OPS_NO_VALUE.includes(item.operator);
                    };

                    const opSelect = makeSelect(AUTO_OPS, item.operator, (fieldValue) => {
                        item.operator = fieldValue;
                        syncValueInput();
                    }, 'auto-row-op');
                    syncValueInput();

                    row.appendChild(fieldSelect);
                    row.appendChild(opSelect);
                    row.appendChild(valueInput);
                    row.appendChild(autoRemoveButton(() => {
                        group.rules.splice(i, 1);
                        rerenderRows();
                    }));
                    rowsElement.appendChild(row);
                }
            });
        }

        rerenderRows();

        const addBtns = document.createElement('div');
        addBtns.className = 'auto-row-actions';
        addBtns.appendChild(autoAddButton('+ Condition', () => {
            const firstField = getColumns(parsed.trigger_table)[0]?.value || '';
            group.rules.push({ field: firstField, operator: '=', value: '' });
            rerenderRows();
        }));
        addBtns.appendChild(autoAddButton('+ Group', () => {
            group.rules.push({ type: 'AND', rules: [] });
            rerenderRows();
        }));
        container.appendChild(addBtns);
    }

    renderGroup(parsed.conditions, groupContainer, 0, null);

    return {
        el: element,
        refresh: () => renderGroup(parsed.conditions, groupContainer, 0, null),
    };
}

function buildActionsSection(parsed, tableOptions, getColumns, users, mode) {
    const allowed  = AUTO_TYPES_BY_MODE[mode] ?? AUTO_TYPES_BY_MODE.record;
    const defType  = allowed[0];
    const element = autoSectionElement(mode === 'n8n' ? 'Webhook calls' : 'Actions');

    if (mode === 'n8n') {
        element.appendChild(autoHintText(
            'Each call posts to one HTTP endpoint — point it at an n8n Webhook node, Make, or any receiver.'
        ));
    }

    const rows = document.createElement('div');
    element.appendChild(rows);

    element.appendChild(autoAddButton(mode === 'n8n' ? '+ Add Webhook Call' : '+ Add Action', () => {
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

            if (allowed.length > 1 || !allowed.includes(aType)) {
                const options = allowed.map(actionType => ({ value: actionType, label: AUTO_ACTION_LABELS[actionType] }));
                if (!allowed.includes(aType)) {
                    options.unshift({ value: aType, label: AUTO_ACTION_LABELS[aType] ?? aType });
                }
                actHdr.appendChild(makeSelect(options, aType, (fieldValue) => {
                    parsed.actions[i] = autoDefaultAction(fieldValue, tableOptions);
                    renderActRows();
                }));
            } else {
                const title = document.createElement('span');
                title.className   = 'auto-action-name';
                title.textContent = AUTO_ACTION_LABELS[aType] ?? aType;
                actHdr.appendChild(title);
            }

            actHdr.appendChild(autoRemoveButton(() => {
                parsed.actions.splice(i, 1);
                renderActRows();
            }, '× Remove'));
            actWrap.appendChild(actHdr);

            const bodyElement = document.createElement('div');
            actWrap.appendChild(bodyElement);

            if (aType === 'update') {
                renderUpdateBody(bodyElement, action, parsed.trigger_table, getColumns);
            } else if (aType === 'notify') {
                renderNotifyBody(bodyElement, action, users);
            } else if (aType === 'create_record') {
                renderCreateRecordBody(bodyElement, action, tableOptions, getColumns);
            } else if (aType === 'webhook') {
                renderWebhookBody(bodyElement, action);
            } else if (aType === 'email') {
                renderEmailBody(bodyElement, action);
            }

            rows.appendChild(actWrap);
        });
    }

    renderActRows();
    return { el: element, refresh: renderActRows };
}

function renderSetMap(bodyElement, action, getTable, getColumns, valuePlaceholder) {
    action.set = autoAsMap(action.set);

    bodyElement.appendChild(autoSubTitle('Field values'));

    const setRows = document.createElement('div');
    bodyElement.appendChild(setRows);

    bodyElement.appendChild(autoAddButton('+ Add Field', () => {
        const firstColumn = getColumns(getTable())[0]?.value || '';
        if (firstColumn && action.set[firstColumn] === undefined) {
            action.set[firstColumn] = '';
        }
        renderSetRows();
    }));

    function renderSetRows() {
        setRows.innerHTML = '';
        Object.entries(action.set ?? {}).forEach(([column, val]) => {
            const row = document.createElement('div');
            row.className = 'auto-row';

            const fieldSelect = makeSelect(getColumns(getTable()), column, (newColumn) => {
                const oldValue = action.set[column];
                delete action.set[column];
                action.set[newColumn] = oldValue;
                renderSetRows();
            });

            const equals = document.createElement('span');
            equals.className   = 'auto-row-eq';
            equals.textContent = '=';

            const valueInput = document.createElement('input');
            valueInput.type        = 'text';
            valueInput.className   = 'auto-row-wide';
            valueInput.placeholder = valuePlaceholder;
            valueInput.value       = val || '';
            valueInput.addEventListener('input', () => { action.set[column] = valueInput.value; });

            row.appendChild(fieldSelect);
            row.appendChild(equals);
            row.appendChild(valueInput);
            row.appendChild(autoRemoveButton(() => {
                delete action.set[column];
                renderSetRows();
            }));
            setRows.appendChild(row);
        });
    }

    renderSetRows();
    return renderSetRows;
}

function renderUpdateBody(bodyElement, action, triggerTable, getColumns) {
    renderSetMap(
        bodyElement,
        action,
        () => triggerTable,
        getColumns,
        'value or {{ current_user.id }} / {{ record.field }}'
    );
}

function renderNotifyBody(bodyElement, action, users) {
    if (!Array.isArray(action.user_ids)) {
        action.user_ids = action.user_id !== undefined
            ? [action.user_id]
            : ['{{ current_user.id }}'];
        delete action.user_id;
    }

    const allOptions = [
        { id: '{{ current_user.id }}', label: 'Current user ({{ current_user.id }})' },
        ...users.map(user => ({
            id:    String(u.id),
            label: u.username + (u.is_active === false || u.is_active === 'f' ? ' [inactive]' : ''),
        })),
    ];

    bodyElement.appendChild(autoSubTitle('Recipients'));

    const chipsElement = document.createElement('div');
    chipsElement.className = 'auto-chips';
    bodyElement.appendChild(chipsElement);

    const listElement = document.createElement('div');
    listElement.className = 'auto-picker';
    bodyElement.appendChild(listElement);

    function renderChips() {
        chipsElement.innerHTML = '';
        if (action.user_ids.length === 0) {
            const empty = document.createElement('span');
            empty.textContent = 'No recipients selected';
            chipsElement.appendChild(empty);
            return;
        }
        action.user_ids.forEach((userId, i) => {
            const option = allOptions.find(candidate => candidate.id === String(userId)) ?? { label: String(userId) };
            const chip = document.createElement('span');
            chip.className = 'auto-chip';
            const textSpan = document.createElement('span');
            textSpan.textContent = option.label;
            const removeButton = document.createElement('button');
            removeButton.type        = 'button';
            removeButton.className   = 'auto-chip-remove';
            removeButton.textContent = '×';
            removeButton.addEventListener('click', () => {
                action.user_ids.splice(i, 1);
                renderChips();
                renderList();
            });
            chip.appendChild(textSpan);
            chip.appendChild(removeButton);
            chipsElement.appendChild(chip);
        });
    }

    function renderList() {
        listElement.innerHTML = '';
        allOptions.forEach(option => {
            const isSelected = action.user_ids.some(existingId => String(existingId) === option.id);
            const row = document.createElement('label');
            row.className = 'auto-picker-row' + (isSelected ? ' selected' : '');

            const callback = document.createElement('input');
            callback.type    = 'checkbox';
            callback.checked = isSelected;
            callback.addEventListener('change', () => {
                if (callback.checked) {
                    if (!action.user_ids.some(existingId => String(existingId) === option.id)) {
                        action.user_ids.push(
                            option.id === '{{ current_user.id }}' ? option.id : parseInt(option.id, 10)
                        );
                    }
                } else {
                    action.user_ids = action.user_ids.filter(existingId => String(existingId) !== option.id);
                }
                renderChips();
                renderList();
            });

            const labelElement = document.createElement('span');
            labelElement.textContent = option.label;

            row.appendChild(callback);
            row.appendChild(labelElement);
            listElement.appendChild(row);
        });
    }

    renderChips();
    renderList();

    bodyElement.appendChild(autoTextField(
        'Title',
        'e.g. New lead: {{ record.name }}',
        action.title,
        (fieldValue) => { action.title = fieldValue; }
    ).field);

    bodyElement.appendChild(autoTextField(
        'Link',
        'e.g. /edit.php?table=leads&id={{ record.id }}',
        action.link,
        (fieldValue) => { action.link = fieldValue; }
    ).field);
}

function renderCreateRecordBody(bodyElement, action, tableOptions, getColumns) {
    if (!action.target_table && tableOptions.length > 0) {
        action.target_table = tableOptions[0].value;
    }

    let refreshRows = () => {};
    bodyElement.appendChild(autoSelectField('Into table', tableOptions, action.target_table ?? '', (fieldValue) => {
        action.target_table = fieldValue;
        refreshRows();
    }));

    refreshRows = renderSetMap(
        bodyElement,
        action,
        () => action.target_table,
        getColumns,
        'value or {{ record.field }} / {{ current_user.id }}'
    );
}

function autoMapEditor(bodyElement, map, opts) {
    bodyElement.appendChild(autoSubTitle(opts.label));

    const mapRows = document.createElement('div');
    bodyElement.appendChild(mapRows);

    bodyElement.appendChild(autoAddButton(opts.addLabel, () => {
        let key = opts.newKey;
        let suffixCounter = 1;
        while (map[key] !== undefined) { key = opts.newKey + '_' + (++suffixCounter); }
        map[key] = '';
        renderRows();
    }));

    function renderRows() {
        mapRows.innerHTML = '';
        Object.entries(map).forEach(([key, val]) => {
            const row = document.createElement('div');
            row.className = 'auto-row';

            const keyInput = document.createElement('input');
            keyInput.type        = 'text';
            keyInput.placeholder = opts.keyPlaceholder;
            keyInput.value       = key;
            keyInput.addEventListener('change', () => {
                const newKey = keyInput.value.trim();
                if (newKey === key) return;

                let reason = '';
                if (!newKey) {
                    reason = 'Name cannot be empty.';
                } else if (map[newKey] !== undefined) {
                    reason = `"${newKey}" is already used.`;
                } else if (opts.validateKey && !opts.validateKey(newKey)) {
                    reason = opts.invalidKeyHint ?? `"${newKey}" is not a valid name.`;
                }
                if (reason) {
                    keyInput.value = key;
                    autoStatusPill(keyInput, reason, 'error');
                    return;
                }
                const oldValue = map[key];
                delete map[key];
                map[newKey] = oldValue;

                if (opts.configuredValues) delete opts.configuredValues[key];
                renderRows();
            });

            const equals = document.createElement('span');
            equals.className   = 'auto-row-eq';
            equals.textContent = '=';

            const isStored = Boolean(opts.configuredValues?.[key]);
            const valueInput = document.createElement('input');
            valueInput.type        = 'text';
            valueInput.className   = 'auto-row-wide';
            valueInput.placeholder = isStored ? 'saved — type a new value to replace it' : opts.valuePlaceholder;
            valueInput.value       = val || '';
            valueInput.addEventListener('input', () => { map[key] = valueInput.value; });

            row.appendChild(keyInput);
            row.appendChild(equals);
            row.appendChild(valueInput);
            row.appendChild(autoRemoveButton(() => {
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

function renderWebhookBody(bodyElement, action) {
    action.payload            = autoAsMap(action.payload);
    action.headers            = autoAsMap(action.headers);
    action.headers_configured = autoAsMap(action.headers_configured);
    if (!action.method) action.method = 'POST';

    const requestRow = document.createElement('div');
    requestRow.className = 'auto-form-row';
    const methodField = autoSelectField('Method', AUTO_WEBHOOK_METHODS, action.method, (fieldValue) => {
        action.method = fieldValue;
    });
    methodField.classList.add('auto-form-narrow');
    requestRow.appendChild(methodField);
    const url = autoTextField(
        'Endpoint URL',
        'https://n8n.example.com/webhook/opensparrow',
        action.url,
        (fieldValue) => { action.url = fieldValue; },
        null,
        'url'
    );
    requestRow.appendChild(url.field);
    bodyElement.appendChild(requestRow);

    const secret = autoTextField(
        'Secret',
        action.secret_configured
            ? 'a secret is saved — type a new one to replace it'
            : 'optional',
        '',
        (fieldValue) => { action.secret = fieldValue; if (fieldValue) action.secret_clear = false; },
        'Adds an X-Sparrow-Signature header (HMAC SHA-256 of the JSON body).'
    );
    if (action.secret_configured) {
        const buttonClear = autoAddButton('Clear secret', () => {
            action.secret            = '';
            action.secret_clear      = true;
            secret.input.value       = '';
            secret.input.placeholder = 'will be cleared on save';
        });
        secret.field.appendChild(buttonClear);
    }
    bodyElement.appendChild(secret.field);

    bodyElement.appendChild(autoSelectField(
        'On failure',
        AUTO_WEBHOOK_RETRIES,
        String(action.retries ?? 0),
        (fieldValue) => { action.retries = parseInt(fieldValue, 10); },
        'Retries apply only to timeouts and 5xx/429 responses. A 4xx is never repeated.'
    ));

    autoMapEditor(bodyElement, action.payload, {
        label:            'Payload fields',
        addLabel:         '+ Add Field',
        newKey:           'field',
        keyPlaceholder:   'json_key',
        valuePlaceholder: 'value or {{ record.field }} / {{ current_user.id }}',
    });
    bodyElement.appendChild(autoHintText(
        'Payload fields map JSON keys to values (templates allowed). Leave the mapping empty to send the full record.'
    ));

    autoMapEditor(bodyElement, action.headers, {
        label:            'Headers',
        addLabel:         '+ Add Header',
        newKey:           'X-Custom-Header',
        keyPlaceholder:   'Header-Name',
        valuePlaceholder: 'value or {{ record.field }}',
        validateKey:      autoIsValidHeaderName,
        invalidKeyHint:   'Invalid or reserved header name — letters, digits and - _ only, no spaces or colons.',
        configuredValues: action.headers_configured,
    });
    bodyElement.appendChild(autoHintText(
        'Use headers for the receiver’s auth, e.g. an n8n Header Auth credential. '
        + 'Values are stored encrypted and never sent back to this page: leave one blank to keep '
        + 'the saved value, or remove the row to delete it. Renaming a header clears its value. '
        + 'Content-Type, User-Agent and X-Sparrow-Signature are set by OpenSparrow and cannot be overridden.'
    ));
}

function renderEmailBody(bodyElement, action) {
    if (!Array.isArray(action.recipients)) {
        action.recipients = typeof action.recipients === 'string' && action.recipients !== ''
            ? action.recipients.split(',').map(recipient => recipient.trim()).filter(Boolean)
            : [];
    }

    bodyElement.appendChild(autoTextField(
        'Recipients',
        'e.g. sales@example.com, {{ record.email }}',
        action.recipients.join(', '),
        (fieldValue) => { action.recipients = fieldValue.split(',').map(recipient => recipient.trim()).filter(Boolean); },
        'Comma-separated. Literal addresses or templates like {{ record.email }}.'
    ).field);

    bodyElement.appendChild(autoTextField(
        'Subject',
        'e.g. New lead: {{ record.name }}',
        action.subject,
        (fieldValue) => { action.subject = fieldValue; }
    ).field);

    const bodyTa = document.createElement('textarea');
    bodyTa.rows        = 4;
    bodyTa.placeholder = 'Plain-text message. Templates allowed, e.g. Status changed to {{ record.status }}.';
    bodyTa.value       = action.body || '';
    bodyTa.addEventListener('input', () => { action.body = bodyTa.value; });
    bodyElement.appendChild(autoField(
        'Message',
        bodyTa,
        'Delivered by the notification cron (cron_notifications.php).'
    ));
}

export async function renderAutomationsPage(context, mode = 'record') {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    workspaceElement.appendChild(wrap);

    wrap.appendChild(createPageHeader(
        mode === 'n8n' ? 'n8n Automations' : 'Record Automations',
        mode === 'n8n'
            ? 'Send record changes to n8n, Make or any HTTP receiver. Configure the endpoint, payload '
              + 'mapping, authentication headers and retry behaviour, then review the delivery history.'
            : 'React to record changes inside OpenSparrow — update fields, notify users, create a linked '
              + 'record or queue an email. Configure conditions and actions, then review run history.'
    ));

    let schemaObject = {};
    try {
        schemaObject = (await getGlobalSchema())?.tables ?? {};
    } catch (_) {}

    let users = [];
    try {
        const usersResponse = await apiFetch('api.php?action=users_list');
        const usersData = await usersResponse.json();
        users = usersData.users ?? [];
    } catch (_) {}

    const tableOptions = Object.keys(schemaObject).map(schemaTableName => ({
        value: schemaTableName,
        label: schemaObject[schemaTableName].display_name || schemaTableName,
    }));

    function getColumns(tableName) {
        const table = schemaObject[tableName];
        if (!table || !table.columns) return [];
        return Object.entries(table.columns)
            .filter(([, config]) => (config.type ?? '') !== 'virtual')
            .map(([column, config]) => ({ value: column, label: config.display_name || column }));
    }

    const loadList = buildAutomationsTab(wrap, mode, {
        schemaObj: schemaObject, tableOptions, getColumns, users,
    });
    await loadList();
}

function buildAutomationsTab(panel, mode, shared) {
    const { schemaObj: schemaObject, tableOptions, getColumns, users } = shared;
    const isN8n = mode === 'n8n';

    const listWrap = document.createElement('div');
    panel.appendChild(listWrap);

    const formWrap = document.createElement('div');
    formWrap.style.display = 'none';
    panel.appendChild(formWrap);

    const histWrap = document.createElement('div');
    histWrap.style.display = 'none';
    panel.appendChild(histWrap);

    async function loadList() {
        listWrap.innerHTML = '';

        const bar = document.createElement('div');
        bar.className = 'auto-bar';
        const buttonNew = document.createElement('button');
        buttonNew.type        = 'button';
        buttonNew.className   = 'btn btn-success';
        buttonNew.textContent = isN8n ? '+ New n8n Automation' : '+ New Automation';
        buttonNew.onclick     = () => openForm(null);
        bar.appendChild(buttonNew);
        listWrap.appendChild(bar);

        let rules = [];
        try {
            const response    = await apiFetch('api.php?action=automations_list');
            const data = await response.json();
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

            const header = document.createElement('div');
            header.className = 'block-header';

            const chevron = document.createElement('span');
            chevron.className   = 'block-chevron';
            chevron.textContent = '▶';

            const nameSpan = document.createElement('strong');
            nameSpan.className   = 'block-title';
            nameSpan.textContent = rule.name;

            const tableMeta = document.createElement('span');
            tableMeta.className   = 'auto-meta';
            tableMeta.textContent = (schemaObject[rule.trigger_table]?.display_name || rule.trigger_table)
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

            const buttonDup = document.createElement('button');
            buttonDup.type        = 'button';
            buttonDup.className   = 'btn btn-sm auto-shrink';
            buttonDup.textContent = 'Duplicate';
            buttonDup.title       = 'Create a disabled copy of this rule';
            buttonDup.addEventListener('click', e => {
                e.stopPropagation();
                saveRulePayload(
                    rulePayload(rule, { id: null, name: rule.name + ' (copy)', enabled: false }),
                    buttonDup
                );
            });

            const buttonHist = document.createElement('button');
            buttonHist.type        = 'button';
            buttonHist.className   = 'btn btn-sm auto-shrink';
            buttonHist.textContent = 'History';
            buttonHist.addEventListener('click', e => { e.stopPropagation(); showRunHistory(rule); });

            const buttonDel = document.createElement('button');
            buttonDel.type        = 'button';
            buttonDel.title       = 'Delete';
            buttonDel.textContent = '✕';
            buttonDel.className   = 'icon-btn icon-btn-danger';
            buttonDel.addEventListener('click', e => { e.stopPropagation(); deleteRule(rule.id, buttonDel); });

            header.appendChild(chevron);
            header.appendChild(nameSpan);
            header.appendChild(tableMeta);
            header.appendChild(badge);
            header.appendChild(buttonDup);
            header.appendChild(buttonHist);
            header.appendChild(buttonDel);
            card.appendChild(header);

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

            header.addEventListener('click', e => {
                if (e.target.closest('button, input, label')) return;
                if (card.classList.contains('collapsed')) openCard();
                else card.classList.add('collapsed');
            });

            cardList.appendChild(card);
        }

        listWrap.appendChild(cardList);
    }

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
        const buttonBack = document.createElement('button');
        buttonBack.type        = 'button';
        buttonBack.className   = 'btn btn-sm';
        buttonBack.textContent = '← Back';
        buttonBack.addEventListener('click', () => {
            histWrap.style.display = 'none';
            histWrap.innerHTML = '';
            listWrap.style.display = '';
        });
        cardHdr.appendChild(cardTitle);
        cardHdr.appendChild(buttonBack);
        card.appendChild(cardHdr);

        const cardBody = document.createElement('div');
        cardBody.className = 'adm-sec-body';
        card.appendChild(cardBody);
        histWrap.appendChild(card);

        const loading = document.createElement('p');
        loading.textContent = 'Loading...';
        cardBody.appendChild(loading);

        try {
            const response    = await apiFetch('api.php?action=automations_runs&rule_id=' + encodeURIComponent(rule.id));
            const data = await response.json();
            loading.remove();

            const runs = data.runs ?? [];
            if (runs.length === 0) {
                const empty = document.createElement('p');
                empty.textContent = 'No runs recorded for this automation yet.';
                cardBody.appendChild(empty);
                return;
            }

            const table = document.createElement('table');
            table.className   = 'adm-tbl';
            table.style.width = '100%';
            const thead = document.createElement('thead');
            thead.innerHTML = `<tr>
                <th class="adm-th">Time</th>
                <th class="adm-th">Table</th>
                <th class="adm-th">Record</th>
                <th class="adm-th">Event</th>
                <th class="adm-th">Status</th>
                <th class="adm-th">Error</th>
            </tr>`;
            table.appendChild(thead);

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
            table.appendChild(tbody);
            cardBody.appendChild(table);
        } catch (_) {
            loading.textContent = 'Failed to load run history.';
        }
    }

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

    async function saveRulePayload(payload, anchorElement) {
        try {
            const response    = await apiFetch('api.php?action=automations_save', {
                method: 'POST',
                body:   JSON.stringify(payload),
            });
            const data = await response.json();
            if (data.status === 'success') {
                await loadList();
            } else {
                autoStatusPill(anchorElement, data.error || 'Error', 'error');
            }
        } catch (_) {
            autoStatusPill(anchorElement, 'Request failed', 'error');
        }
    }

    async function deleteRule(id, button) {
        if (!confirm('Delete this automation?')) return;
        try {
            const response    = await apiFetch('api.php?action=automations_delete', {
                method: 'POST',
                body:   JSON.stringify({ id }),
            });
            const data = await response.json();
            if (data.status === 'success') {
                autoStatusPill(button, 'Deleted', 'success');
                await loadList();
            } else {
                autoStatusPill(button, data.error || 'Error', 'error');
            }
        } catch (_) {
            autoStatusPill(button, 'Request failed', 'error');
        }
    }

    function buildFormContent(containerElement, rule, onSaved, onCancel) {
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

            actions:       isN8n ? [autoDefaultAction('webhook', tableOptions)] : [],
        };

        const form = document.createElement('div');
        form.className = 'auto-form';

        const general = autoSectionElement('General');

        general.appendChild(autoTextField(
            'Name',
            isN8n ? 'e.g. Push new leads to n8n' : 'e.g. Assign owner after lead creation',
            parsed.name,
            (fieldValue) => { parsed.name = fieldValue; }
        ).field);

        let conditionSectionReference = null;
        let actSectionReference  = null;

        const triggerRow = document.createElement('div');
        triggerRow.className = 'auto-form-row';
        triggerRow.appendChild(autoSelectField('Trigger table', tableOptions, parsed.trigger_table, (fieldValue) => {
            parsed.trigger_table = fieldValue;
            if (conditionSectionReference) conditionSectionReference.refresh();
            if (actSectionReference)  actSectionReference.refresh();
        }));
        triggerRow.appendChild(autoSelectField('Trigger event', AUTO_EVENTS, parsed.trigger_event, (fieldValue) => {
            parsed.trigger_event = fieldValue;
        }));
        general.appendChild(triggerRow);

        const statusLabel = document.createElement('label');
        statusLabel.className = 'auto-check';
        const statusCallback = document.createElement('input');
        statusCallback.type    = 'checkbox';
        statusCallback.checked = parsed.enabled;
        statusCallback.addEventListener('change', () => { parsed.enabled = statusCallback.checked; });
        const statusText = document.createElement('span');
        statusText.textContent = 'Enabled';
        statusLabel.appendChild(statusCallback);
        statusLabel.appendChild(statusText);
        general.appendChild(autoField('Status', statusLabel));

        form.appendChild(general);

        conditionSectionReference = buildConditionsSection(parsed, getColumns);
        form.appendChild(conditionSectionReference.el);

        actSectionReference = buildActionsSection(parsed, tableOptions, getColumns, users, mode);
        form.appendChild(actSectionReference.el);

        const buttonRow = document.createElement('div');
        buttonRow.className = 'auto-row-actions';

        const buttonSave = document.createElement('button');
        buttonSave.type        = 'button';
        buttonSave.className   = 'btn btn-primary';
        buttonSave.textContent = currentId ? 'Save Changes' : 'Create Automation';

        const buttonCancel = document.createElement('button');
        buttonCancel.type        = 'button';
        buttonCancel.className   = 'btn';
        buttonCancel.textContent = 'Cancel';
        buttonCancel.addEventListener('click', onCancel);

        buttonSave.addEventListener('click', async () => {
            if (!parsed.name.trim()) { autoStatusPill(buttonSave, 'Name is required', 'error'); return; }
            if (!parsed.trigger_table) { autoStatusPill(buttonSave, 'Select a trigger table', 'error'); return; }
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
                const response    = await apiFetch('api.php?action=automations_save', {
                    method: 'POST',
                    body:   JSON.stringify(payload),
                });
                const data = await response.json();
                if (data.status === 'success') { await onSaved(); }
                else { autoStatusPill(buttonSave, data.error || 'Save failed', 'error'); }
            } catch (_) { autoStatusPill(buttonSave, 'Request failed', 'error'); }
        });

        buttonRow.appendChild(buttonSave);
        buttonRow.appendChild(buttonCancel);
        form.appendChild(buttonRow);

        containerElement.appendChild(form);
    }

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
