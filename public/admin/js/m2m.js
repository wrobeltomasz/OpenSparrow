// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { showStatusPill } from './app.js';

let _renderGen = 0;

export async function renderM2mPage(context) {
    const myGen = ++_renderGen;
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.textContent = '';

    let tables = [];
    let relationships = [];
    try {
        const res = await apiFetch('api.php?action=list_m2m', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (myGen !== _renderGen) return;
        tables        = data.tables        || [];
        relationships = data.relationships || [];
    } catch {
        const err = document.createElement('p');
        err.style.color = 'red';
        err.textContent = 'Failed to load schema data.';
        workspaceElement.appendChild(err);
        return;
    }

    const h2 = document.createElement('h2');
    h2.style.cssText = 'margin:0 0 6px;';
    h2.textContent = 'Many-to-Many Relationship Builder';

    const sub = document.createElement('p');
    sub.style.cssText = '  margin:0 0 32px;';
    sub.textContent = 'Select two tables — the wizard creates the junction table in PostgreSQL and updates the schema configuration automatically.';

    workspaceElement.append(h2, sub);

    const card = document.createElement('div');
    card.style.cssText = 'background:var(--panel); border:1px solid var(--border); border-radius:var(--radius-lg); padding:28px; max-width:680px; margin-bottom:44px;';

    const cardHeader = document.createElement('div');
    cardHeader.style.cssText = 'display:flex; align-items:center; gap:10px; margin-bottom:22px;';
    const cardBadge = document.createElement('div');
    cardBadge.style.cssText = 'width:32px; height:32px; border-radius:50%; background:var(--accent); color:#fff; display:flex; align-items:center; justify-content:center;  flex-shrink:0; line-height:1;';
    cardBadge.textContent = '↔';
    const cardH3 = document.createElement('h3');
    cardH3.style.cssText = 'margin:0; ';
    cardH3.textContent = 'Create New Relationship';
    cardHeader.append(cardBadge, cardH3);
    card.appendChild(cardHeader);

    const selectRow = document.createElement('div');
    selectRow.style.cssText = 'display:flex; align-items:flex-end; gap:10px; margin-bottom:22px;';

    function makeTableSelect(labelText) {
        const wrap = document.createElement('div');
        wrap.className = 'flex-1';
        const lbl = document.createElement('label');
        lbl.className = 'adm-field-label';
        lbl.textContent = labelText;
        const sel = document.createElement('select');
        sel.className = 'adm-input w-full';
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '— select table —';
        sel.appendChild(blank);
        tables.forEach(t => {
            const option = document.createElement('option');
            option.value = t.name;
            option.textContent = t.display_name ? `${t.display_name} (${t.name})` : t.name;
            sel.appendChild(option);
        });
        wrap.append(lbl, sel);
        return { wrap, sel };
    }

    const { wrap: wrapA, sel: selectA } = makeTableSelect('Table A — parent (has many)');
    const arrowElement = document.createElement('div');
    arrowElement.style.cssText = '  padding-bottom:9px; flex-shrink:0;';
    arrowElement.textContent = '↔';
    const { wrap: wrapB, sel: selectB } = makeTableSelect('Table B — related entity');
    selectRow.append(wrapA, arrowElement, wrapB);
    card.appendChild(selectRow);

    const optionGrid = document.createElement('div');
    optionGrid.style.cssText = 'display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:10px;';

    function makeField(labelText, placeholder, hint) {
        const wrap = document.createElement('div');
        const lbl = document.createElement('label');
        lbl.className = 'adm-field-label';
        lbl.textContent = labelText;
        const input = document.createElement('input');
        input.type = 'text';
        input.placeholder = placeholder;
        input.className = 'adm-input w-full';
        if (hint) {
            const h = document.createElement('div');
            h.style.cssText = '  margin-top:4px;';
            h.textContent = hint;
            wrap.append(lbl, input, h);
        } else {
            wrap.append(lbl, input);
        }
        return { wrap, inp: input };
    }

    const { wrap: wJunction, inp: inputJunction } = makeField('Junction Table Name', 'e.g. employee_company', 'Created in PostgreSQL');
    const { wrap: wLabel,    inp: inputLabel    } = makeField('Label in Form',        'e.g. Companies',      'Shown above checkboxes');
    const { wrap: wSelfFk,  inp: inputSelfFk   } = makeField('Self FK Column',        'e.g. employee_id',    'Column pointing to Table A');
    const { wrap: wOtherFk, inp: inputOtherFk  } = makeField('Other FK Column',       'e.g. company_id',     'Column pointing to Table B');
    const { wrap: wDisp,    inp: inputDisp     } = makeField('Display Column',         'e.g. name',           'Column from Table B shown as label');

    optionGrid.append(wJunction, wLabel, wSelfFk, wOtherFk, wDisp);
    card.appendChild(optionGrid);

    const GUESSES = ['name', 'title', 'label', 'code', 'description'];

    function autoFill() {
        const a = selectA.value;
        const b = selectB.value;
        if (!a || !b) return;
        const tB = tables.find(t => t.name === b);
        const bColumns = Array.isArray(tB?.columns) ? tB.columns : [];
        const dispGuess = GUESSES.find(g => bColumns.includes(g)) || bColumns.find(c => c !== 'id') || 'name';

        if (inputJunction.dataset.auto !== '0') { inputJunction.value = `${a}_${b}`; inputJunction.dataset.auto = '1'; }
        if (inputSelfFk.dataset.auto  !== '0') { inputSelfFk.value   = `${a}_id`;   inputSelfFk.dataset.auto   = '1'; }
        if (inputOtherFk.dataset.auto !== '0') { inputOtherFk.value  = `${b}_id`;   inputOtherFk.dataset.auto  = '1'; }
        if (inputLabel.dataset.auto   !== '0') { inputLabel.value    = tB?.display_name || b; inputLabel.dataset.auto = '1'; }
        if (inputDisp.dataset.auto    !== '0') { inputDisp.value     = dispGuess;   inputDisp.dataset.auto     = '1'; }
    }

    [inputJunction, inputLabel, inputSelfFk, inputOtherFk, inputDisp].forEach(input => {
        input.addEventListener('input', () => { input.dataset.auto = '0'; });
    });
    selectA.addEventListener('change', autoFill);
    selectB.addEventListener('change', autoFill);

    const preview = document.createElement('div');
    preview.style.cssText = '  margin:6px 0 20px; min-height:18px;';

    function updatePreview() {
        const a = selectA.value; const b = selectB.value;
        if (!a || !b) { preview.textContent = ''; return; }
        preview.textContent = `Will execute: CREATE TABLE app.${inputJunction.value || a + '_' + b} (id SERIAL PK, ${inputSelfFk.value || a + '_id'} → ${a}, ${inputOtherFk.value || b + '_id'} → ${b}, UNIQUE)`;
    }

    [selectA, selectB, inputJunction, inputSelfFk, inputOtherFk].forEach(element => element.addEventListener('input', updatePreview));
    [selectA, selectB].forEach(element => element.addEventListener('change', updatePreview));
    card.appendChild(preview);

    const buttonCreate = document.createElement('button');
    buttonCreate.type = 'button';
    buttonCreate.className = 'btn btn-primary';
    buttonCreate.innerHTML = '<span style="font-weight:300;line-height:1;">+</span> Create Relationship';
    card.appendChild(buttonCreate);

    buttonCreate.addEventListener('click', async () => {
        const tableA        = selectA.value.trim();
        const tableB        = selectB.value.trim();
        const junctionTable = inputJunction.value.trim();
        const selfFk        = inputSelfFk.value.trim();
        const otherFk       = inputOtherFk.value.trim();
        const label         = inputLabel.value.trim();
        const displayColumn    = inputDisp.value.trim();

        if (!tableA || !tableB)  { showStatusPill(buttonCreate, 'Select both tables.', 'error'); return; }
        if (tableA === tableB)   { showStatusPill(buttonCreate, 'Tables must be different.', 'error'); return; }
        if (!junctionTable)      { showStatusPill(buttonCreate, 'Junction table name required.', 'error'); return; }
        if (!selfFk || !otherFk) { showStatusPill(buttonCreate, 'Both FK column names required.', 'error'); return; }

        buttonCreate.disabled = true;
        buttonCreate.textContent = 'Creating…';

        try {
            const res = await apiFetch('api.php?action=create_m2m', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ table_a: tableA, table_b: tableB, junction_table: junctionTable, self_fk: selfFk, other_fk: otherFk, label, display_column: displayColumn })
            });
            const result = await res.json();
            if (result.status === 'success') {
                showStatusPill(buttonCreate, 'Relationship created!', 'success');
                setTimeout(() => renderM2mPage(context), 900);
            } else {
                showStatusPill(buttonCreate, result.error || 'Failed.', 'error');
                buttonCreate.disabled = false;
                buttonCreate.innerHTML = '<span style="font-weight:300;line-height:1;">+</span> Create Relationship';
            }
        } catch {
            showStatusPill(buttonCreate, 'Network error.', 'error');
            buttonCreate.disabled = false;
            buttonCreate.textContent = 'Create Relationship';
        }
    });

    workspaceElement.appendChild(card);

    const listH3 = document.createElement('h3');
    listH3.style.cssText = 'margin:0 0 14px;';
    listH3.textContent = 'Existing Many-to-Many Relationships';
    workspaceElement.appendChild(listH3);

    if (relationships.length === 0) {
        const empty = document.createElement('p');
        empty.style.cssText = ' ';
        empty.textContent = 'No many-to-many relationships configured yet.';
        workspaceElement.appendChild(empty);
        return;
    }

    const list = document.createElement('div');
    list.style.cssText = 'max-width:680px;';

    relationships.forEach(rel => {
        const card = document.createElement('div');
        card.className = 'column-block collapsed';

        const header = document.createElement('div');
        header.className = 'block-header';
        header.addEventListener('click', (e) => {
            if (e.target.closest('button, input, label')) return;
            card.classList.toggle('collapsed');
        });

        const chevron = document.createElement('span');
        chevron.className = 'block-chevron';
        chevron.textContent = '▶';

        const title = document.createElement('strong');
        title.className = 'block-title';
        title.textContent = `${rel.table_a_display} ↔ ${rel.table_b_display}`;

        const buttonDel = document.createElement('button');
        buttonDel.type = 'button';
        buttonDel.title = 'Remove';
        buttonDel.textContent = '✕';
        buttonDel.className = 'icon-btn icon-btn-danger';

        buttonDel.addEventListener('click', async (e) => {
            e.stopPropagation();
            if (!confirm(`Remove relationship "${rel.table_a_display} ↔ ${rel.table_b_display}"?\n\nThis removes the configuration entry. The junction table "${rel.junction_table}" stays in the database unless you choose to drop it next.`)) return;

            const alsoDropTable = confirm(`Also DROP TABLE "${rel.junction_table}" from PostgreSQL?\n\nWARNING: This permanently deletes all relationship data.`);

            buttonDel.disabled = true;

            try {
                const res = await apiFetch('api.php?action=delete_m2m', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ table_a: rel.table_a, m2m_index: rel.m2m_index, junction_table: rel.junction_table, drop_table: alsoDropTable })
                });
                const result = await res.json();
                if (result.status === 'success') {
                    card.style.opacity = '0.4';
                    setTimeout(() => card.remove(), 300);
                } else {
                    showStatusPill(buttonDel, result.error || 'Failed.', 'error');
                    buttonDel.disabled = false;
                }
            } catch {
                showStatusPill(buttonDel, 'Network error.', 'error');
                buttonDel.disabled = false;
            }
        });

        header.append(chevron, title, buttonDel);
        card.appendChild(header);

        const body = document.createElement('div');
        body.className = 'block-body';
        [
            ['Junction table', rel.junction_table],
            ['Label in form', rel.label],
            ['Display column', rel.display_column],
            ['Table A', rel.table_a_display],
            ['Table B', rel.table_b_display],
        ].forEach(([k, v]) => {
            const detail = document.createElement('div');
            detail.style.cssText = ' margin-bottom:6px;';
            const kElement = document.createElement('span');
            kElement.style.cssText = ' display:inline-block; min-width:130px;';
            kElement.textContent = k;
            const vElement = document.createElement('span');
            vElement.style.color = 'var(--text)';
            vElement.textContent = (v === undefined || v === null || v === '') ? '—' : v;
            detail.append(kElement, vElement);
            body.appendChild(detail);
        });
        card.appendChild(body);

        list.appendChild(card);
    });

    workspaceElement.appendChild(list);
}
