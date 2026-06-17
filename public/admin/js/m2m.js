// admin/js/m2m.js — Many-to-Many relationship builder wizard
// Lists/creates/deletes junction tables via api.php (list_m2m / create_m2m / delete_m2m). CSRF from meta tag.
import { showStatusPill } from './app.js';

let _renderGen = 0;

export async function renderM2mPage(ctx) {
    const myGen = ++_renderGen;
    const { workspaceEl } = ctx;
    workspaceEl.textContent = '';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // ── Fetch current state ────────────────────────────────────────────────────
    let tables = [];
    let relationships = [];
    try {
        const res = await fetch('api.php?action=list_m2m', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf }
        });
        const data = await res.json();
        if (myGen !== _renderGen) return;
        tables        = data.tables        || [];
        relationships = data.relationships || [];
    } catch {
        const err = document.createElement('p');
        err.style.color = 'red';
        err.textContent = 'Failed to load schema data.';
        workspaceEl.appendChild(err);
        return;
    }

    // ── Page header ────────────────────────────────────────────────────────────
    const h2 = document.createElement('h2');
    h2.style.cssText = 'margin:0 0 6px;';
    h2.textContent = 'Many-to-Many Relationship Builder';

    const sub = document.createElement('p');
    sub.style.cssText = 'color:var(--muted); font-size:14px; margin:0 0 32px;';
    sub.textContent = 'Select two tables — the wizard creates the junction table in PostgreSQL and updates schema.json automatically.';

    workspaceEl.append(h2, sub);

    // ── Create card ────────────────────────────────────────────────────────────
    const card = document.createElement('div');
    card.style.cssText = 'background:var(--panel); border:1px solid var(--border); border-radius:var(--radius-lg); padding:28px; max-width:680px; margin-bottom:44px;';

    const cardHeader = document.createElement('div');
    cardHeader.style.cssText = 'display:flex; align-items:center; gap:10px; margin-bottom:22px;';
    const cardBadge = document.createElement('div');
    cardBadge.style.cssText = 'width:32px; height:32px; border-radius:50%; background:#005A9E; color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; line-height:1;';
    cardBadge.textContent = '↔';
    const cardH3 = document.createElement('h3');
    cardH3.style.cssText = 'margin:0; font-size:16px;';
    cardH3.textContent = 'Create New Relationship';
    cardHeader.append(cardBadge, cardH3);
    card.appendChild(cardHeader);

    // Table A / Table B selects ─────────────────────────────────────────────────
    const selectRow = document.createElement('div');
    selectRow.style.cssText = 'display:flex; align-items:flex-end; gap:10px; margin-bottom:22px;';

    function makeTableSelect(labelText) {
        const wrap = document.createElement('div');
        wrap.style.flex = '1';
        const lbl = document.createElement('label');
        lbl.style.cssText = 'display:block; font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.6px; margin-bottom:6px;';
        lbl.textContent = labelText;
        const sel = document.createElement('select');
        sel.style.cssText = 'width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:var(--radius); font-size:14px; background:#fff;';
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '— select table —';
        sel.appendChild(blank);
        tables.forEach(t => {
            const opt = document.createElement('option');
            opt.value = t.name;
            opt.textContent = t.display_name ? `${t.display_name} (${t.name})` : t.name;
            sel.appendChild(opt);
        });
        wrap.append(lbl, sel);
        return { wrap, sel };
    }

    const { wrap: wrapA, sel: selA } = makeTableSelect('Table A — parent (has many)');
    const arrowEl = document.createElement('div');
    arrowEl.style.cssText = 'font-size:20px; color:var(--muted); padding-bottom:9px; flex-shrink:0;';
    arrowEl.textContent = '↔';
    const { wrap: wrapB, sel: selB } = makeTableSelect('Table B — related entity');
    selectRow.append(wrapA, arrowEl, wrapB);
    card.appendChild(selectRow);

    // Options grid ─────────────────────────────────────────────────────────────
    const optGrid = document.createElement('div');
    optGrid.style.cssText = 'display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:10px;';

    function makeField(labelText, placeholder, hint) {
        const wrap = document.createElement('div');
        const lbl = document.createElement('label');
        lbl.style.cssText = 'display:block; font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.6px; margin-bottom:5px;';
        lbl.textContent = labelText;
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.placeholder = placeholder;
        inp.style.cssText = 'width:100%; padding:7px 10px; border:1px solid var(--border); border-radius:var(--radius); font-size:13px; box-sizing:border-box;';
        if (hint) {
            const h = document.createElement('div');
            h.style.cssText = 'font-size:11px; color:var(--muted); margin-top:4px;';
            h.textContent = hint;
            wrap.append(lbl, inp, h);
        } else {
            wrap.append(lbl, inp);
        }
        return { wrap, inp };
    }

    const { wrap: wJunction, inp: inpJunction } = makeField('Junction Table Name', 'e.g. employee_company', 'Created in PostgreSQL');
    const { wrap: wLabel,    inp: inpLabel    } = makeField('Label in Form',        'e.g. Companies',      'Shown above checkboxes');
    const { wrap: wSelfFk,  inp: inpSelfFk   } = makeField('Self FK Column',        'e.g. employee_id',    'Column pointing to Table A');
    const { wrap: wOtherFk, inp: inpOtherFk  } = makeField('Other FK Column',       'e.g. company_id',     'Column pointing to Table B');
    const { wrap: wDisp,    inp: inpDisp     } = makeField('Display Column',         'e.g. name',           'Column from Table B shown as label');

    optGrid.append(wJunction, wLabel, wSelfFk, wOtherFk, wDisp);
    card.appendChild(optGrid);

    // Auto-fill ─────────────────────────────────────────────────────────────────
    const GUESSES = ['name', 'title', 'label', 'code', 'description'];

    function autoFill() {
        const a = selA.value;
        const b = selB.value;
        if (!a || !b) return;
        const tB = tables.find(t => t.name === b);
        const bCols = Array.isArray(tB?.columns) ? tB.columns : [];
        const dispGuess = GUESSES.find(g => bCols.includes(g)) || bCols.find(c => c !== 'id') || 'name';

        if (inpJunction.dataset.auto !== '0') { inpJunction.value = `${a}_${b}`; inpJunction.dataset.auto = '1'; }
        if (inpSelfFk.dataset.auto  !== '0') { inpSelfFk.value   = `${a}_id`;   inpSelfFk.dataset.auto   = '1'; }
        if (inpOtherFk.dataset.auto !== '0') { inpOtherFk.value  = `${b}_id`;   inpOtherFk.dataset.auto  = '1'; }
        if (inpLabel.dataset.auto   !== '0') { inpLabel.value    = tB?.display_name || b; inpLabel.dataset.auto = '1'; }
        if (inpDisp.dataset.auto    !== '0') { inpDisp.value     = dispGuess;   inpDisp.dataset.auto     = '1'; }
    }

    [inpJunction, inpLabel, inpSelfFk, inpOtherFk, inpDisp].forEach(inp => {
        inp.addEventListener('input', () => { inp.dataset.auto = '0'; });
    });
    selA.addEventListener('change', autoFill);
    selB.addEventListener('change', autoFill);

    // Preview pill (shows what will be created) ────────────────────────────────
    const preview = document.createElement('div');
    preview.style.cssText = 'font-size:12px; color:var(--muted); margin:6px 0 20px; min-height:18px;';

    function updatePreview() {
        const a = selA.value; const b = selB.value;
        if (!a || !b) { preview.textContent = ''; return; }
        preview.textContent = `Will execute: CREATE TABLE app.${inpJunction.value || a + '_' + b} (id SERIAL PK, ${inpSelfFk.value || a + '_id'} → ${a}, ${inpOtherFk.value || b + '_id'} → ${b}, UNIQUE)`;
    }

    [selA, selB, inpJunction, inpSelfFk, inpOtherFk].forEach(el => el.addEventListener('input', updatePreview));
    [selA, selB].forEach(el => el.addEventListener('change', updatePreview));
    card.appendChild(preview);

    // Create button ─────────────────────────────────────────────────────────────
    const btnCreate = document.createElement('button');
    btnCreate.type = 'button';
    btnCreate.className = 'btn btn-primary';
    btnCreate.innerHTML = '<span style="font-size:18px;font-weight:300;line-height:1;">+</span> Create Relationship';
    card.appendChild(btnCreate);

    btnCreate.addEventListener('click', async () => {
        const tableA        = selA.value.trim();
        const tableB        = selB.value.trim();
        const junctionTable = inpJunction.value.trim();
        const selfFk        = inpSelfFk.value.trim();
        const otherFk       = inpOtherFk.value.trim();
        const label         = inpLabel.value.trim();
        const displayCol    = inpDisp.value.trim();

        if (!tableA || !tableB)  { showStatusPill(btnCreate, 'Select both tables.', 'error'); return; }
        if (tableA === tableB)   { showStatusPill(btnCreate, 'Tables must be different.', 'error'); return; }
        if (!junctionTable)      { showStatusPill(btnCreate, 'Junction table name required.', 'error'); return; }
        if (!selfFk || !otherFk) { showStatusPill(btnCreate, 'Both FK column names required.', 'error'); return; }

        btnCreate.disabled = true;
        btnCreate.textContent = 'Creating…';

        try {
            const res = await fetch('api.php?action=create_m2m', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ table_a: tableA, table_b: tableB, junction_table: junctionTable, self_fk: selfFk, other_fk: otherFk, label, display_column: displayCol })
            });
            const result = await res.json();
            if (result.status === 'success') {
                showStatusPill(btnCreate, 'Relationship created!', 'success');
                setTimeout(() => renderM2mPage(ctx), 900);
            } else {
                showStatusPill(btnCreate, result.error || 'Failed.', 'error');
                btnCreate.disabled = false;
                btnCreate.innerHTML = '<span style="font-size:18px;font-weight:300;line-height:1;">+</span> Create Relationship';
            }
        } catch {
            showStatusPill(btnCreate, 'Network error.', 'error');
            btnCreate.disabled = false;
            btnCreate.textContent = 'Create Relationship';
        }
    });

    workspaceEl.appendChild(card);

    // ── Existing relationships ─────────────────────────────────────────────────
    const listH3 = document.createElement('h3');
    listH3.style.cssText = 'margin:0 0 14px;';
    listH3.textContent = 'Existing Many-to-Many Relationships';
    workspaceEl.appendChild(listH3);

    if (relationships.length === 0) {
        const empty = document.createElement('p');
        empty.style.cssText = 'color:var(--muted); font-size:14px;';
        empty.textContent = 'No many-to-many relationships configured yet.';
        workspaceEl.appendChild(empty);
        return;
    }

    const list = document.createElement('div');
    list.style.cssText = 'display:flex; flex-direction:column; gap:10px; max-width:680px;';

    relationships.forEach(rel => {
        const row = document.createElement('div');
        row.style.cssText = 'display:flex; align-items:center; gap:14px; padding:14px 18px; background:var(--panel); border:1px solid var(--border); border-radius:var(--radius);';

        const dot = document.createElement('div');
        dot.style.cssText = 'width:10px; height:10px; border-radius:50%; background:#005A9E; flex-shrink:0;';

        const info = document.createElement('div');
        info.style.flex = '1';

        const title = document.createElement('div');
        title.style.cssText = 'font-weight:600; font-size:14px;';
        title.textContent = `${rel.table_a_display} ↔ ${rel.table_b_display}`;

        const meta = document.createElement('div');
        meta.style.cssText = 'font-size:12px; color:var(--muted); margin-top:3px;';
        meta.textContent = `via ${rel.junction_table}  ·  Label: "${rel.label}"  ·  Display: ${rel.display_column}`;

        info.append(title, meta);

        const btnDel = document.createElement('button');
        btnDel.type = 'button';
        btnDel.style.cssText = 'padding:5px 14px; background:none; border:1px solid #d00000; color:#d00000; border-radius:var(--radius); cursor:pointer; font-size:12px; font-weight:600; white-space:nowrap; flex-shrink:0;';
        btnDel.textContent = 'Remove';

        btnDel.addEventListener('click', async () => {
            if (!confirm(`Remove relationship "${rel.table_a_display} ↔ ${rel.table_b_display}"?\n\nThis removes the configuration entry. The junction table "${rel.junction_table}" stays in the database unless you choose to drop it next.`)) return;

            const alsoDropTable = confirm(`Also DROP TABLE "${rel.junction_table}" from PostgreSQL?\n\nWARNING: This permanently deletes all relationship data.`);

            btnDel.disabled = true;
            btnDel.textContent = 'Removing…';

            try {
                const res = await fetch('api.php?action=delete_m2m', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ table_a: rel.table_a, m2m_index: rel.m2m_index, junction_table: rel.junction_table, drop_table: alsoDropTable })
                });
                const result = await res.json();
                if (result.status === 'success') {
                    row.style.opacity = '0.4';
                    setTimeout(() => row.remove(), 300);
                } else {
                    showStatusPill(btnDel, result.error || 'Failed.', 'error');
                    btnDel.disabled = false;
                    btnDel.textContent = 'Remove';
                }
            } catch {
                showStatusPill(btnDel, 'Network error.', 'error');
                btnDel.disabled = false;
                btnDel.textContent = 'Remove';
            }
        });

        row.append(dot, info, btnDel);
        list.appendChild(row);
    });

    workspaceEl.appendChild(list);
}
