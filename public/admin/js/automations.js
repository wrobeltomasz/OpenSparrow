// admin/js/automations.js — Automation rules management UI
// CRUD over the "automations" config via api.php (automations_list/save/delete) plus run history (automations_runs). HTML-escapes output; CSRF via apiFetch().

import { apiFetch } from '../../assets/js/util/api.js';
import { escHtml as autoEsc } from '../../assets/js/util/esc.js';

function autoStatusPill(anchor, msg, type = 'success') {
    const prev = anchor.parentNode?.querySelector('.auto-status-pill');
    if (prev) prev.remove();
    const colors = {
        success: { bg: 'rgba(43,147,72,0.12)', fg: '#2b9348', border: '#64748B' },
        error:   { bg: 'rgba(208,0,0,0.08)',   fg: '#a80000', border: '#d00000' },
        info:    { bg: '#DDEAF4',               fg: '#1E293B', border: '#DDEAF4' },
    }[type] ?? { bg: '#DDEAF4', fg: '#1E293B', border: '#DDEAF4' };
    const pill = document.createElement('span');
    pill.className = 'auto-status-pill';
    pill.textContent = msg;
    pill.style.cssText = `display:inline-flex;align-items:center;gap:6px;margin-left:10px;padding:4px 10px;`
        + `background:${colors.bg};color:${colors.fg};border:1px solid ${colors.border};`
        + `border-radius:999px;font-weight:600;transition:opacity .3s;`;
    anchor.insertAdjacentElement('afterend', pill);
    setTimeout(() => { pill.style.opacity = '0'; setTimeout(() => pill.remove(), 300); },
        type === 'error' ? 6000 : 3000);
}

const AUTO_EVENTS = [
    { value: 'create', label: 'After create' },
    { value: 'update', label: 'After update' },
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

const AUTO_ACTION_TYPES = [
    { value: 'update',        label: 'Update fields on this record' },
    { value: 'notify',        label: 'Send notification' },
    { value: 'create_record', label: 'Create record in another table' },
    { value: 'webhook',       label: 'Send webhook (HTTP request)' },
    { value: 'email',         label: 'Send email (via cron)' },
];

const AUTO_WEBHOOK_METHODS = [
    { value: 'POST', label: 'POST' },
    { value: 'PUT',  label: 'PUT' },
];

const AUTO_RUN_COLORS = {
    ok:      { bg: 'rgba(43,147,72,0.12)',  fg: '#2b9348' },
    error:   { bg: 'rgba(208,0,0,0.08)',    fg: '#a80000' },
    skipped: { bg: 'rgba(100,116,139,0.1)', fg: '#64748B' },
};

// ── Shared: bare select ───────────────────────────────────────────
function makeSelect(options, current, onChange) {
    const sel = document.createElement('select');
    sel.className = 'form-input';
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

// ── Conditions builder (recursive AND/OR groups) ──────────────────
function buildConditionsSection(parsed, getColumns) {
    const el = document.createElement('div');
    const lbl = document.createElement('div');
    lbl.style.cssText = 'font-weight:600;margin-bottom:8px;';
    lbl.textContent   = 'Conditions';
    el.appendChild(lbl);

    const groupContainer = document.createElement('div');
    el.appendChild(groupContainer);

    function renderGroup(group, container, depth, onRemove) {
        container.innerHTML = '';

        // Group header: [Match] [AND|OR] [x Group — only if depth > 0]
        const groupHdr = document.createElement('div');
        groupHdr.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px;';
        if (depth > 0) {
            groupHdr.style.paddingLeft = (depth * 16) + 'px';
        }

        const matchLbl = document.createElement('span');
        matchLbl.textContent = 'Match';
        matchLbl.style.cssText = '';

        const typeToggle = document.createElement('select');
        typeToggle.className = 'adm-input w-70';
        ['AND', 'OR'].forEach(t => {
            const o = document.createElement('option');
            o.value = t; o.text = t;
            if ((group.type || 'AND') === t) o.selected = true;
            typeToggle.appendChild(o);
        });
        typeToggle.addEventListener('change', () => { group.type = typeToggle.value; });

        groupHdr.appendChild(matchLbl);
        groupHdr.appendChild(typeToggle);

        if (depth > 0 && onRemove) {
            const btnRmGroup = document.createElement('button');
            btnRmGroup.className = 'btn btn-sm btn-danger';
            btnRmGroup.textContent = '× Group';
            btnRmGroup.style.marginLeft = 'auto';
            btnRmGroup.addEventListener('click', onRemove);
            groupHdr.appendChild(btnRmGroup);
        }

        container.appendChild(groupHdr);

        // Rows area
        const rowsEl = document.createElement('div');
        container.appendChild(rowsEl);

        function rerenderRows() {
            rowsEl.innerHTML = '';
            group.rules.forEach((item, i) => {
                if (item.type !== undefined && item.rules !== undefined) {
                    // Sub-group
                    const subWrap = document.createElement('div');
                    subWrap.style.cssText = `border-left:2px solid var(--border);`
                        + `margin-left:${depth * 16 + 8}px;padding-left:8px;margin-bottom:6px;`;
                    renderGroup(item, subWrap, depth + 1, () => {
                        group.rules.splice(i, 1);
                        rerenderRows();
                    });
                    rowsEl.appendChild(subWrap);
                } else {
                    // Leaf condition row
                    const row = document.createElement('div');
                    row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;flex-wrap:wrap;';
                    if (depth > 0) {
                        row.style.paddingLeft = (depth * 16 + 8) + 'px';
                    }

                    const cols = getColumns(parsed.trigger_table);
                    const fldSel = makeSelect(cols, item.field, (v) => { item.field = v; });
                    fldSel.style.flex = '1';

                    const valInp = document.createElement('input');
                    valInp.type        = 'text';
                    valInp.className   = 'form-input';
                    valInp.placeholder = 'value';
                    valInp.value       = item.value || '';
                    valInp.style.flex  = '1';
                    valInp.addEventListener('input', () => { item.value = valInp.value; });

                    const syncValInp = () => {
                        valInp.disabled = AUTO_OPS_NO_VALUE.includes(item.operator);
                        valInp.style.opacity = valInp.disabled ? '0.4' : '';
                    };

                    const opSel = makeSelect(AUTO_OPS, item.operator, (v) => {
                        item.operator = v;
                        syncValInp();
                    });
                    opSel.style.minWidth = '120px';
                    syncValInp();

                    const btnRm = document.createElement('button');
                    btnRm.className   = 'btn btn-sm btn-danger';
                    btnRm.textContent = '×';
                    btnRm.addEventListener('click', () => {
                        group.rules.splice(i, 1);
                        rerenderRows();
                    });

                    row.appendChild(fldSel);
                    row.appendChild(opSel);
                    row.appendChild(valInp);
                    row.appendChild(btnRm);
                    rowsEl.appendChild(row);
                }
            });
        }

        rerenderRows();

        // Add buttons
        const addBtns = document.createElement('div');
        addBtns.style.cssText = 'display:flex;gap:8px;margin-top:6px;';
        if (depth > 0) {
            addBtns.style.paddingLeft = (depth * 16 + 8) + 'px';
        }

        const btnAddCond = document.createElement('button');
        btnAddCond.className   = 'btn btn-sm';
        btnAddCond.textContent = '+ Condition';
        btnAddCond.addEventListener('click', () => {
            const firstField = getColumns(parsed.trigger_table)[0]?.value || '';
            group.rules.push({ field: firstField, operator: '=', value: '' });
            rerenderRows();
        });

        const btnAddGroup = document.createElement('button');
        btnAddGroup.className   = 'btn btn-sm';
        btnAddGroup.textContent = '+ Group';
        btnAddGroup.addEventListener('click', () => {
            group.rules.push({ type: 'AND', rules: [] });
            rerenderRows();
        });

        addBtns.appendChild(btnAddCond);
        addBtns.appendChild(btnAddGroup);
        container.appendChild(addBtns);
    }

    renderGroup(parsed.conditions, groupContainer, 0, null);

    return {
        el,
        refresh: () => renderGroup(parsed.conditions, groupContainer, 0, null),
    };
}

// ── Actions builder ───────────────────────────────────────────────
function buildActionsSection(parsed, tableOptions, getColumns, users) {
    const el = document.createElement('div');
    const lbl = document.createElement('div');
    lbl.style.cssText = 'font-weight:600;margin-bottom:8px;';
    lbl.textContent   = 'Actions';
    el.appendChild(lbl);

    const rows = document.createElement('div');
    el.appendChild(rows);

    const btnAdd = document.createElement('button');
    btnAdd.className   = 'btn btn-sm';
    btnAdd.textContent = '+ Add Action';
    btnAdd.style.marginTop = '6px';
    btnAdd.addEventListener('click', () => {
        parsed.actions.push({ type: 'update', set: {} });
        renderActRows();
    });
    el.appendChild(btnAdd);

    function renderActRows() {
        rows.innerHTML = '';
        parsed.actions.forEach((action, i) => {
            const actWrap = document.createElement('div');
            actWrap.style.cssText = 'border:1px solid var(--border);border-radius:6px;padding:10px;margin-bottom:8px;';

            // Action header: type selector + remove button
            const actHdr = document.createElement('div');
            actHdr.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:10px;';

            const typeSel = document.createElement('select');
            typeSel.className = 'form-input';
            typeSel.style.flex = '1';
            AUTO_ACTION_TYPES.forEach(at => {
                const o = document.createElement('option');
                o.value = at.value; o.text = at.label;
                if (at.value === (action.type || 'update')) o.selected = true;
                typeSel.appendChild(o);
            });
            typeSel.addEventListener('change', () => {
                const defaults = {
                    update:        { type: 'update', set: {} },
                    notify:        { type: 'notify', user_ids: ['{{ current_user.id }}'], title: '', link: '' },
                    create_record: { type: 'create_record', target_table: tableOptions[0]?.value ?? '', set: {} },
                    webhook:       { type: 'webhook', method: 'POST', url: '', payload: {} },
                    email:         { type: 'email', recipients: [], subject: '', body: '' },
                };
                parsed.actions[i] = defaults[typeSel.value] ?? defaults.update;
                renderActRows();
            });

            const btnRmAct = document.createElement('button');
            btnRmAct.className   = 'btn btn-sm btn-danger';
            btnRmAct.textContent = '× Remove';
            btnRmAct.addEventListener('click', () => {
                parsed.actions.splice(i, 1);
                renderActRows();
            });

            actHdr.appendChild(typeSel);
            actHdr.appendChild(btnRmAct);
            actWrap.appendChild(actHdr);

            const bodyEl = document.createElement('div');
            actWrap.appendChild(bodyEl);

            const aType = action.type || 'update';
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

function renderUpdateBody(bodyEl, action, triggerTable, getColumns) {
    if (!action.set) action.set = {};

    const setRows = document.createElement('div');
    bodyEl.appendChild(setRows);

    const btnAddField = document.createElement('button');
    btnAddField.className   = 'btn btn-sm';
    btnAddField.textContent = '+ Add Field';
    btnAddField.style.marginTop = '4px';
    btnAddField.addEventListener('click', () => {
        const firstCol = getColumns(triggerTable)[0]?.value || '';
        if (firstCol && action.set[firstCol] === undefined) {
            action.set[firstCol] = '';
        }
        renderSetRows();
    });
    bodyEl.appendChild(btnAddField);

    function renderSetRows() {
        setRows.innerHTML = '';
        Object.entries(action.set ?? {}).forEach(([col, val]) => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;flex-wrap:wrap;';

            const cols = getColumns(triggerTable);
            const fldSel = makeSelect(cols, col, (newCol) => {
                const oldVal = action.set[col];
                delete action.set[col];
                action.set[newCol] = oldVal;
                renderSetRows();
            });
            fldSel.style.flex = '1';

            const eq = document.createElement('span');
            eq.textContent   = '=';
            eq.style.cssText = 'font-weight:600;';

            const valInp = document.createElement('input');
            valInp.type        = 'text';
            valInp.className   = 'form-input';
            valInp.placeholder = 'value or {{ current_user.id }} / {{ record.field }}';
            valInp.value       = val || '';
            valInp.style.flex  = '2';
            valInp.addEventListener('input', () => { action.set[col] = valInp.value; });

            const btnRm = document.createElement('button');
            btnRm.className   = 'btn btn-sm btn-danger';
            btnRm.textContent = '×';
            btnRm.addEventListener('click', () => {
                delete action.set[col];
                renderSetRows();
            });

            row.appendChild(fldSel);
            row.appendChild(eq);
            row.appendChild(valInp);
            row.appendChild(btnRm);
            setRows.appendChild(row);
        });
    }

    renderSetRows();
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

    // ── Recipients label ─────────────────────────────────────────
    const recipientsLbl = document.createElement('div');
    recipientsLbl.textContent = 'Recipients';
    recipientsLbl.style.cssText = 'font-weight:600;margin-bottom:4px;';
    bodyEl.appendChild(recipientsLbl);

    // ── Selected chips ───────────────────────────────────────────
    const chipsEl = document.createElement('div');
    chipsEl.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px;min-height:26px;';
    bodyEl.appendChild(chipsEl);

    // ── Checkbox list ────────────────────────────────────────────
    const listEl = document.createElement('div');
    listEl.style.cssText = 'border:1px solid var(--border);border-radius:6px;'
        + 'max-height:150px;overflow-y:auto;margin-bottom:10px;';
    bodyEl.appendChild(listEl);

    function renderChips() {
        chipsEl.innerHTML = '';
        if (action.user_ids.length === 0) {
            const empty = document.createElement('span');
            empty.textContent = 'No recipients selected';
            empty.style.cssText = 'line-height:26px;';
            chipsEl.appendChild(empty);
            return;
        }
        action.user_ids.forEach((uid, i) => {
            const opt = allOptions.find(o => o.id === String(uid)) ?? { label: String(uid) };
            const chip = document.createElement('span');
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:2px 8px;'
                + 'background:var(--accent-subtle,#DDEAF4);border-radius:999px;';
            const txt = document.createElement('span');
            txt.textContent = opt.label;
            const rm = document.createElement('button');
            rm.type = 'button';
            rm.textContent = '×';
            rm.style.cssText = 'border:none;background:none;cursor:pointer;padding:0 2px;'
                + 'line-height:1;';
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
            row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:5px 10px;cursor:pointer;'
                + (isSelected ? 'background:rgba(0,0,0,0.04);' : '');

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

    // ── Title and Link text fields ────────────────────────────────
    [
        { key: 'title', label: 'Title', placeholder: 'e.g. New lead: {{ record.name }}' },
        { key: 'link',  label: 'Link',  placeholder: 'e.g. /edit.php?table=leads&id={{ record.id }}' },
    ].forEach(({ key, label, placeholder }) => {
        const wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;';
        const lbl = document.createElement('span');
        lbl.textContent = label;
        lbl.style.cssText = 'font-weight:600;min-width:60px;';
        const inp = document.createElement('input');
        inp.type        = 'text';
        inp.className   = 'form-input';
        inp.placeholder = placeholder;
        inp.value       = action[key] || '';
        inp.style.flex  = '1';
        inp.addEventListener('input', () => { action[key] = inp.value; });
        wrap.appendChild(lbl);
        wrap.appendChild(inp);
        bodyEl.appendChild(wrap);
    });
}

function renderCreateRecordBody(bodyEl, action, tableOptions, getColumns) {
    if (!action.set) action.set = {};
    if (!action.target_table && tableOptions.length > 0) {
        action.target_table = tableOptions[0].value;
    }

    // Target table selector
    const tblRow = document.createElement('div');
    tblRow.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px;';
    const tblLbl = document.createElement('span');
    tblLbl.textContent = 'Into table';
    tblLbl.style.cssText = 'font-weight:600;min-width:70px;';

    const tblSel = makeSelect(tableOptions, action.target_table ?? '', (v) => {
        action.target_table = v;
        renderSetRows();
    });
    tblSel.style.flex = '1';
    tblRow.appendChild(tblLbl);
    tblRow.appendChild(tblSel);
    bodyEl.appendChild(tblRow);

    const setRows = document.createElement('div');
    bodyEl.appendChild(setRows);

    const btnAddField = document.createElement('button');
    btnAddField.className   = 'btn btn-sm';
    btnAddField.textContent = '+ Add Field';
    btnAddField.style.marginTop = '4px';
    btnAddField.addEventListener('click', () => {
        const firstCol = getColumns(action.target_table)[0]?.value || '';
        if (firstCol && action.set[firstCol] === undefined) {
            action.set[firstCol] = '';
        }
        renderSetRows();
    });
    bodyEl.appendChild(btnAddField);

    function renderSetRows() {
        setRows.innerHTML = '';
        Object.entries(action.set ?? {}).forEach(([col, val]) => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;flex-wrap:wrap;';

            const cols = getColumns(action.target_table);
            const fldSel = makeSelect(cols, col, (newCol) => {
                const oldVal = action.set[col];
                delete action.set[col];
                action.set[newCol] = oldVal;
                renderSetRows();
            });
            fldSel.style.flex = '1';

            const eq = document.createElement('span');
            eq.textContent   = '=';
            eq.style.cssText = 'font-weight:600;';

            const valInp = document.createElement('input');
            valInp.type        = 'text';
            valInp.className   = 'form-input';
            valInp.placeholder = 'value or {{ record.field }} / {{ current_user.id }}';
            valInp.value       = val || '';
            valInp.style.flex  = '2';
            valInp.addEventListener('input', () => { action.set[col] = valInp.value; });

            const btnRm = document.createElement('button');
            btnRm.className   = 'btn btn-sm btn-danger';
            btnRm.textContent = '×';
            btnRm.addEventListener('click', () => {
                delete action.set[col];
                renderSetRows();
            });

            row.appendChild(fldSel);
            row.appendChild(eq);
            row.appendChild(valInp);
            row.appendChild(btnRm);
            setRows.appendChild(row);
        });
    }

    renderSetRows();
}

function autoHintText(text) {
    const hint = document.createElement('div');
    hint.textContent = text;
    hint.style.cssText = 'margin-bottom:8px;';
    return hint;
}

function autoLabeledInput(bodyEl, label, placeholder, value, onInput) {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;';
    const lbl = document.createElement('span');
    lbl.textContent = label;
    lbl.style.cssText = 'font-weight:600;min-width:80px;';
    const inp = document.createElement('input');
    inp.type        = 'text';
    inp.className   = 'form-input';
    inp.placeholder = placeholder;
    inp.value       = value || '';
    inp.style.flex  = '1';
    inp.addEventListener('input', () => onInput(inp.value));
    wrap.appendChild(lbl);
    wrap.appendChild(inp);
    bodyEl.appendChild(wrap);
    return inp;
}

function renderWebhookBody(bodyEl, action) {
    if (!action.payload) action.payload = {};
    if (!action.method) action.method = 'POST';

    bodyEl.appendChild(autoHintText(
        'Sends a JSON payload to the endpoint. Payload fields below map JSON keys to values '
        + '(templates allowed). Leave the mapping empty to send the full record.'
    ));

    // Method + URL row
    const reqRow = document.createElement('div');
    reqRow.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px;';
    const methodSel = makeSelect(AUTO_WEBHOOK_METHODS, action.method, (v) => { action.method = v; });
    methodSel.classList.add('w-90');
    const urlInp = document.createElement('input');
    urlInp.type        = 'url';
    urlInp.className   = 'form-input';
    urlInp.placeholder = 'https://example.com/hooks/opensparrow';
    urlInp.value       = action.url || '';
    urlInp.style.flex  = '1';
    urlInp.addEventListener('input', () => { action.url = urlInp.value; });
    reqRow.appendChild(methodSel);
    reqRow.appendChild(urlInp);
    bodyEl.appendChild(reqRow);

    autoLabeledInput(
        bodyEl,
        'Secret',
        'optional — adds X-Sparrow-Signature header (HMAC SHA-256 of the JSON body)',
        action.secret,
        (v) => { action.secret = v; }
    );

    // Payload mapping: free-text JSON key -> template value
    const mapLbl = document.createElement('div');
    mapLbl.textContent = 'Payload fields';
    mapLbl.style.cssText = 'font-weight:600;margin-bottom:4px;';
    bodyEl.appendChild(mapLbl);

    const mapRows = document.createElement('div');
    bodyEl.appendChild(mapRows);

    const btnAddField = document.createElement('button');
    btnAddField.className   = 'btn btn-sm';
    btnAddField.textContent = '+ Add Field';
    btnAddField.style.marginTop = '4px';
    btnAddField.addEventListener('click', () => {
        let key = 'field';
        let n = 1;
        while (action.payload[key] !== undefined) { key = 'field_' + (++n); }
        action.payload[key] = '';
        renderMapRows();
    });
    bodyEl.appendChild(btnAddField);

    function renderMapRows() {
        mapRows.innerHTML = '';
        Object.entries(action.payload ?? {}).forEach(([key, val]) => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;flex-wrap:wrap;';

            const keyInp = document.createElement('input');
            keyInp.type        = 'text';
            keyInp.className   = 'form-input';
            keyInp.placeholder = 'json_key';
            keyInp.value       = key;
            keyInp.style.flex  = '1';
            keyInp.addEventListener('change', () => {
                const newKey = keyInp.value.trim();
                if (!newKey || newKey === key || action.payload[newKey] !== undefined) {
                    keyInp.value = key;
                    return;
                }
                const oldVal = action.payload[key];
                delete action.payload[key];
                action.payload[newKey] = oldVal;
                renderMapRows();
            });

            const eq = document.createElement('span');
            eq.textContent   = '=';
            eq.style.cssText = 'font-weight:600;';

            const valInp = document.createElement('input');
            valInp.type        = 'text';
            valInp.className   = 'form-input';
            valInp.placeholder = 'value or {{ record.field }} / {{ current_user.id }}';
            valInp.value       = val || '';
            valInp.style.flex  = '2';
            valInp.addEventListener('input', () => { action.payload[key] = valInp.value; });

            const btnRm = document.createElement('button');
            btnRm.className   = 'btn btn-sm btn-danger';
            btnRm.textContent = '×';
            btnRm.addEventListener('click', () => {
                delete action.payload[key];
                renderMapRows();
            });

            row.appendChild(keyInp);
            row.appendChild(eq);
            row.appendChild(valInp);
            row.appendChild(btnRm);
            mapRows.appendChild(row);
        });
    }

    renderMapRows();
}

function renderEmailBody(bodyEl, action) {
    // Normalize recipients to an array (backend accepts array or comma string).
    if (!Array.isArray(action.recipients)) {
        action.recipients = typeof action.recipients === 'string' && action.recipients !== ''
            ? action.recipients.split(',').map(s => s.trim()).filter(Boolean)
            : [];
    }

    bodyEl.appendChild(autoHintText(
        'Queues an email delivered by the notification cron (cron_notifications.php). '
        + 'Recipients accept literal addresses or templates like {{ record.email }}.'
    ));

    autoLabeledInput(
        bodyEl,
        'Recipients',
        'e.g. sales@example.com, {{ record.email }}',
        action.recipients.join(', '),
        (v) => { action.recipients = v.split(',').map(s => s.trim()).filter(Boolean); }
    );

    autoLabeledInput(
        bodyEl,
        'Subject',
        'e.g. New lead: {{ record.name }}',
        action.subject,
        (v) => { action.subject = v; }
    );

    const bodyLblRow = document.createElement('div');
    bodyLblRow.style.cssText = 'display:flex;gap:8px;align-items:flex-start;margin-bottom:6px;';
    const bodyLbl = document.createElement('span');
    bodyLbl.textContent = 'Message';
    bodyLbl.style.cssText = 'font-weight:600;min-width:80px;padding-top:6px;';
    const bodyTa = document.createElement('textarea');
    bodyTa.className   = 'form-input';
    bodyTa.rows        = 4;
    bodyTa.placeholder = 'Plain-text message. Templates allowed, e.g. Status changed to {{ record.status }}.';
    bodyTa.value       = action.body || '';
    bodyTa.style.flex  = '1';
    bodyTa.addEventListener('input', () => { action.body = bodyTa.value; });
    bodyLblRow.appendChild(bodyLbl);
    bodyLblRow.appendChild(bodyTa);
    bodyEl.appendChild(bodyLblRow);
}

// ── Shared action handle (set by renderAutomationsPage, used by item panel) ────
export const autoActions = { openNew: null };

// ── Main page ─────────────────────────────────────────────────────
export async function renderAutomationsPage(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.style.cssText = 'max-width:900px;';
    workspaceEl.appendChild(wrap);

    // ── Header ──────────────────────────────────────────────────
    const hdr = document.createElement('div');
    hdr.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;';
    const h2 = document.createElement('h2');
    h2.textContent = 'Automations';
    h2.style.margin = '0';
    hdr.appendChild(h2);
    wrap.appendChild(hdr);

    // ── Panels ──────────────────────────────────────────────────
    const listWrap = document.createElement('div');
    listWrap.id = 'auto-list-wrap';
    wrap.appendChild(listWrap);

    const formWrap = document.createElement('div');
    formWrap.id = 'auto-form-wrap';
    formWrap.style.display = 'none';
    wrap.appendChild(formWrap);

    const histWrap = document.createElement('div');
    histWrap.id = 'auto-hist-wrap';
    histWrap.style.display = 'none';
    wrap.appendChild(histWrap);

    // ── Load schema ──────────────────────────────────────────────
    let schemaObj = {};
    try {
        const sr = await apiFetch('api.php?action=get&file=schema');
        const sd = await sr.json();
        schemaObj = sd.tables ?? {};
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

    let editingId = null;
    let rules     = [];

    // ── List ────────────────────────────────────────────────────
    async function loadList() {
        listWrap.innerHTML = '';
        try {
            const r    = await apiFetch('api.php?action=automations_list');
            const data = await r.json();
            rules = data.automations ?? [];
        } catch (_) {
            rules = [];
        }

        if (rules.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = 'No automations yet. Create one to get started.';
            listWrap.appendChild(empty);
            return;
        }

        const cardList = document.createElement('div');
        cardList.style.cssText = 'max-width:900px;';

        for (const rule of rules) {
            const card = document.createElement('div');
            card.className = 'column-block collapsed';

            // Header
            const hdr = document.createElement('div');
            hdr.className = 'block-header';

            const chevron = document.createElement('span');
            chevron.className = 'block-chevron';
            chevron.textContent = '▶';

            const nameSpan = document.createElement('strong');
            nameSpan.className = 'block-title';
            nameSpan.textContent = rule.name;

            const tableMeta = document.createElement('span');
            tableMeta.style.cssText = '  flex-shrink:0;';
            tableMeta.textContent = (schemaObj[rule.trigger_table]?.display_name || rule.trigger_table)
                + ' · ' + (AUTO_EVENTS.find(e => e.value === rule.trigger_event)?.label ?? rule.trigger_event);

            const badge = document.createElement('span');
            badge.className = rule.enabled ? 'adm-badge adm-badge-ok' : 'adm-badge adm-badge-muted';
            badge.textContent = rule.enabled ? 'Active' : 'Disabled';
            badge.style.flexShrink = '0';
            badge.style.cursor = 'pointer';
            badge.title = rule.enabled ? 'Click to disable' : 'Click to enable';
            badge.addEventListener('click', e => {
                e.stopPropagation();
                saveRulePayload(rulePayload(rule, { enabled: !rule.enabled }), badge);
            });

            const btnDup = document.createElement('button');
            btnDup.type = 'button';
            btnDup.className = 'btn btn-sm';
            btnDup.textContent = 'Duplicate';
            btnDup.style.flexShrink = '0';
            btnDup.title = 'Create a disabled copy of this rule';
            btnDup.addEventListener('click', e => {
                e.stopPropagation();
                saveRulePayload(
                    rulePayload(rule, { id: null, name: rule.name + ' (copy)', enabled: false }),
                    btnDup
                );
            });

            const btnHist = document.createElement('button');
            btnHist.type = 'button';
            btnHist.className = 'btn btn-sm';
            btnHist.textContent = 'History';
            btnHist.style.flexShrink = '0';
            btnHist.addEventListener('click', e => { e.stopPropagation(); showRunHistory(rule); });

            const btnDel = document.createElement('button');
            btnDel.type = 'button';
            btnDel.title = 'Delete';
            btnDel.textContent = '✕';
            btnDel.className = 'icon-btn icon-btn-danger';
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
        card.style.cssText = 'border:1px solid var(--border);border-radius:8px;overflow:hidden;';

        const cardHdr = document.createElement('div');
        cardHdr.style.cssText = 'padding:14px 18px;background:var(--bg);border-bottom:1px solid var(--border);'
            + 'display:flex;align-items:center;justify-content:space-between;';
        const cardTitle = document.createElement('h3');
        cardTitle.textContent = 'Run History: ' + rule.name;
        cardTitle.style.margin = '0';
        const btnBack = document.createElement('button');
        btnBack.className = 'btn btn-sm';
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
        cardBody.style.cssText = 'padding:18px;';
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
            tbl.className = 'adm-tbl';
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
                const clr = AUTO_RUN_COLORS[run.status] ?? AUTO_RUN_COLORS.skipped;

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
                        badge.textContent = run.status;
                        badge.style.cssText = `display:inline-block;padding:2px 8px;border-radius:999px;`
                            + `font-weight:600;background:${clr.bg};color:${clr.fg};`;
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
                method:  'POST',
                body:    JSON.stringify(payload),
            });
            const data = await r.json();
            if (data.ok) {
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
                method:  'POST',
                body: JSON.stringify({ id }),
            });
            const data = await r.json();
            if (data.ok) {
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
            actions:       [],
        };

        const cardBody = document.createElement('div');
        cardBody.style.cssText = 'display:flex;flex-direction:column;gap:16px;';

        // ── Name ─────────────────────────────────────────────────
        cardBody.appendChild(autoField('Name', () => {
            const inp = document.createElement('input');
            inp.type        = 'text';
            inp.className   = 'form-input';
            inp.placeholder = 'e.g. Assign owner after lead creation';
            inp.value       = parsed.name;
            inp.addEventListener('input', () => { parsed.name = inp.value; });
            return inp;
        }));

        // ── Trigger ──────────────────────────────────────────────
        let condSectionRef = null;
        let actSectionRef  = null;

        const triggerRow = document.createElement('div');
        triggerRow.style.cssText = 'display:flex;gap:12px;flex-wrap:wrap;';

        const tblSel = autoSelect('Trigger Table', tableOptions, parsed.trigger_table, (v) => {
            parsed.trigger_table = v;
            if (condSectionRef) condSectionRef.refresh();
            if (actSectionRef)  actSectionRef.refresh();
        });
        tblSel.style.flex = '1';
        triggerRow.appendChild(tblSel);

        const evtSel = autoSelect('Trigger Event', AUTO_EVENTS, parsed.trigger_event, (v) => {
            parsed.trigger_event = v;
        });
        evtSel.style.flex = '1';
        triggerRow.appendChild(evtSel);

        cardBody.appendChild(triggerRow);

        // ── Enabled toggle ───────────────────────────────────────
        cardBody.appendChild(autoField('Status', () => {
            const lbl = document.createElement('label');
            lbl.style.cssText = 'display:flex;align-items:center;gap:8px;cursor:pointer;';
            const cb = document.createElement('input');
            cb.type    = 'checkbox';
            cb.checked = parsed.enabled;
            cb.addEventListener('change', () => { parsed.enabled = cb.checked; });
            lbl.appendChild(cb);
            const span = document.createElement('span');
            span.textContent = 'Enabled';
            lbl.appendChild(span);
            return lbl;
        }));

        // ── Conditions ───────────────────────────────────────────
        condSectionRef = buildConditionsSection(parsed, getColumns);
        cardBody.appendChild(condSectionRef.el);

        // ── Actions ──────────────────────────────────────────────
        actSectionRef = buildActionsSection(parsed, tableOptions, getColumns, users);
        cardBody.appendChild(actSectionRef.el);

        // ── Buttons ──────────────────────────────────────────────
        const btnRow = document.createElement('div');
        btnRow.style.cssText = 'display:flex;gap:10px;';

        const btnSave = document.createElement('button');
        btnSave.className   = 'btn btn-primary';
        btnSave.textContent = currentId ? 'Save Changes' : 'Create Automation';

        const btnCancel = document.createElement('button');
        btnCancel.className   = 'btn';
        btnCancel.textContent = 'Cancel';
        btnCancel.addEventListener('click', onCancel);

        btnSave.addEventListener('click', async () => {
            if (!parsed.name.trim()) { autoStatusPill(btnSave, 'Name is required', 'error'); return; }
            if (!parsed.trigger_table) { autoStatusPill(btnSave, 'Select a trigger table', 'error'); return; }
            const payload = {
                id: currentId ?? null,
                name: parsed.name.trim(),
                enabled: parsed.enabled,
                trigger_table: parsed.trigger_table,
                trigger_event: parsed.trigger_event,
                conditions: parsed.conditions,
                actions: parsed.actions,
            };
            try {
                const r    = await apiFetch('api.php?action=automations_save', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                const data = await r.json();
                if (data.ok) { await onSaved(); }
                else { autoStatusPill(btnSave, data.error || 'Save failed', 'error'); }
            } catch (_) { autoStatusPill(btnSave, 'Request failed', 'error'); }
        });

        btnRow.appendChild(btnSave);
        btnRow.appendChild(btnCancel);
        cardBody.appendChild(btnRow);

        containerEl.appendChild(cardBody);
    }

    // ── Form panel (New Automation) ──────────────────────────────
    function openForm(rule = null) {
        editingId = rule ? rule.id : null;
        formWrap.style.display = '';
        formWrap.innerHTML = '';
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
        editingId              = null;
    }

    // ── Helpers ──────────────────────────────────────────────────
    function autoField(label, inputFactory) {
        const wrap = document.createElement('div');
        const lbl  = document.createElement('label');
        lbl.style.cssText = 'display:block;font-weight:600;margin-bottom:4px;';
        lbl.textContent   = label;
        wrap.appendChild(lbl);
        wrap.appendChild(inputFactory());
        return wrap;
    }

    function autoSelect(label, options, current, onChange) {
        const wrap = document.createElement('div');
        const lbl  = document.createElement('label');
        lbl.style.cssText = 'display:block;font-weight:600;margin-bottom:4px;';
        lbl.textContent   = label;
        wrap.appendChild(lbl);
        wrap.appendChild(makeSelect(options, current, onChange));
        return wrap;
    }

    autoActions.openNew = openForm;

    await loadList();
}
