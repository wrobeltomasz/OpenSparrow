// admin/js/etl_common.js — shared building blocks for the ETL admin modules
// (etl.js and etl_flow.js). Factors out the form-field helpers, collapsible-card
// scaffold, run-history table and config persist/run-cron calls those two modules
// used to each copy verbatim. Inline styles here are layout-only (margin/flex/
// display); colors use CSS variables per the admin UI standard.
import { apiFetch } from '../../assets/js/util/api.js';

/** A hidden status line, shown by showStatus(). */
export function mkStatus() {
    const el = document.createElement('p');
    el.style.cssText = 'margin-top:10px; display:none;';
    return el;
}

/** Show a status message in an element created by mkStatus(), green on ok / red on error. */
export function showStatus(el, msg, ok) {
    el.textContent = msg;
    el.style.color = ok ? 'var(--ok)' : 'var(--danger)';
    el.style.display = '';
}

/** A labelled form-group wrapping a control node. */
export function fg(label, node) {
    const g = document.createElement('div');
    g.className = 'form-group';
    const l = document.createElement('label');
    l.textContent = label;
    g.append(l, node);
    return g;
}

/** A styled input of the given type. */
export function input(value, type = 'text') {
    const i = document.createElement('input');
    i.type = type;
    i.className = 'adm-input';
    i.value = value ?? '';
    return i;
}

/**
 * A checkbox paired with an inline label. onChange receives the new checked state.
 * Returns { input, label } so callers can place the label and reference the box.
 */
export function checkbox(labelText, checked, onChange) {
    const box = input('', 'checkbox');
    box.className = 'adm-check';
    box.checked = checked;
    box.onchange = () => onChange(box.checked);
    const lbl = document.createElement('label');
    lbl.style.cssText = 'display:flex; align-items:center; gap:8px;';
    lbl.append(box, document.createTextNode(labelText));
    return { input: box, label: lbl };
}

/**
 * A collapsible column-block card with a chevron header, editable title and a red
 * delete button. Returns { card, body, title }; append fields to body and update
 * title.textContent when the name changes. onDelete runs after an optional confirm.
 */
export function buildCollapsibleCard({ titleText, placeholder = '(unnamed)', onDelete, confirmMsg }) {
    const card = document.createElement('div');
    card.className = 'column-block collapsed';

    const hdr = document.createElement('div');
    hdr.className = 'block-header';
    const chevron = document.createElement('span');
    chevron.className = 'block-chevron';
    chevron.textContent = '▶';
    const title = document.createElement('strong');
    title.className = 'block-title';
    title.textContent = titleText || placeholder;

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'icon-btn icon-btn-danger';
    del.title = 'Delete';
    del.textContent = '✕';
    del.onclick = (e) => {
        e.stopPropagation();
        if (confirmMsg && !confirm(confirmMsg)) return;
        onDelete();
    };
    hdr.append(chevron, title, del);
    hdr.onclick = (e) => { if (!e.target.closest('button')) card.classList.toggle('collapsed'); };

    const body = document.createElement('div');
    body.className = 'block-body';
    card.append(hdr, body);
    return { card, body, title };
}

/**
 * Build a run-history table. `headers` are the column labels; `rowFn(row, helpers)`
 * returns the <td> cells for one row, using helpers.td(text, css),
 * helpers.statusCell(status) (colored badge) and helpers.errorCell(text).
 */
export function buildHistoryTable(headers, rows, rowFn) {
    const tbl = document.createElement('table');
    tbl.className = 'adm-tbl';

    const hr = tbl.createTHead().insertRow();
    headers.forEach(h => {
        const th = document.createElement('th');
        th.className = 'adm-th';
        th.textContent = h;
        hr.appendChild(th);
    });

    const clsMap = { success: 'ok', error: 'danger', running: 'warn' };
    const td = (text, css) => {
        const el = document.createElement('td');
        el.className = 'adm-td';
        if (css) el.style.cssText = css;
        el.textContent = text ?? '—';
        return el;
    };
    const statusCell = (status) => {
        const cell = document.createElement('td');
        cell.className = 'adm-td';
        const badge = document.createElement('span');
        badge.className = 'adm-badge adm-badge-' + (clsMap[status] || 'muted');
        badge.textContent = status || '';
        cell.appendChild(badge);
        return cell;
    };
    const errorCell = (text) => td(
        text || '',
        'color:var(--danger); max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;'
    );

    const tbody = tbl.createTBody();
    rows.forEach(r => {
        const tr = tbody.insertRow();
        rowFn(r, { td, statusCell, errorCell }).forEach(cell => tr.appendChild(cell));
    });

    const wrap = document.createElement('div');
    wrap.style.overflowX = 'auto';
    wrap.appendChild(tbl);
    return wrap;
}

/**
 * POST a config payload to the given save action. Returns { ok, version } on success
 * or { ok:false, error } otherwise — callers surface it via showStatus().
 */
export async function persistConfig(action, payload) {
    try {
        const res  = await apiFetch('api.php?action=' + action, {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.status === 'success') {
            return { ok: true, version: data.version };
        }
        return { ok: false, error: data.error || 'Save failed.' };
    } catch (_) {
        return { ok: false, error: 'Network error while saving.' };
    }
}

/**
 * Trigger a cron run action and stream its output into `out`. The backend reports
 * status:'error' when the run exits non-zero, but the captured output is shown either
 * way so failures are visible.
 */
export async function runCronAction(action, body, out) {
    out.style.display = '';
    out.textContent = 'Running…';
    try {
        const res  = await apiFetch('api.php?action=' + action, {
            method: 'POST',
            body: JSON.stringify(body),
        });
        const data = await res.json();
        out.textContent = data.output || data.error || 'No output.';
    } catch (_) {
        out.textContent = 'Network error.';
    }
}
