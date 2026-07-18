// assets/js/util/record-tooltip.js — shared floating record tooltip.
//
// A single body-appended container reused by the grid row tooltip, the calendar
// event tooltip and the board card tooltip so the three modules share one look,
// one positioning logic and one source of truth. Populated on mouseenter from a
// { title, rows } model and positioned below (or above, when space runs out) the
// hovered anchor element. Values are set via textContent — safe against XSS.

const TOOLTIP_ID = 'record-tooltip';
const TOOLTIP_STYLE = 'position:absolute;display:none;background:#fff;border:1px solid #ddd;'
    + 'padding:12px;border-radius:6px;box-shadow:0 5px 15px rgba(0,0,0,0.2);font-size:13px;'
    + 'z-index:10000;pointer-events:none;min-width:220px;max-width:340px;max-height:400px;'
    + 'overflow-y:auto;color:#333;';

// Lazily create (once) and return the shared tooltip container.
export function getRecordTooltip() {
    let el = document.getElementById(TOOLTIP_ID);
    if (!el) {
        el = document.createElement('div');
        el.id = TOOLTIP_ID;
        el.style.cssText = TOOLTIP_STYLE;
        document.body.appendChild(el);
    }
    return el;
}

// Build [{ label, value, color }] rows from a raw record map plus its column
// definitions. Iterates the schema column order when available (falling back to
// the record's own keys), skips `id`, the `*__display` helper keys and empty
// values, prefers the `key__display` label, and derives an enum swatch color
// from the column's `enum_colors`.
export function rowsFromRecord(rowData = {}, columns = {}) {
    const rows = [];
    // Schema column order first (nice, stable ordering + labels/colors), then any
    // remaining record keys not described by the schema so nothing is dropped.
    const seen = new Set();
    const keys = [];
    for (const k of Object.keys(columns)) { keys.push(k); seen.add(k); }
    for (const k of Object.keys(rowData)) {
        if (k.endsWith('__display')) continue;
        if (!seen.has(k)) keys.push(k);
    }
    for (const key of keys) {
        if (key === 'id') continue;
        if (key.endsWith('__display')) continue;
        const val = rowData[key + '__display'] ?? rowData[key];
        if (val === null || val === undefined || val === '') continue;
        const colCfg = columns[key] || {};
        const label = colCfg.display_name || key;
        const color = (colCfg.type || '').toLowerCase() === 'enum'
            ? (colCfg.enum_colors?.[String(val)] ?? null)
            : null;
        rows.push({ label, value: String(val), color });
    }
    return rows;
}

// Populate the shared tooltip with the model and position it against an anchor.
// model: { title: string, rows: [{ label, value, color }] }
export function showRecordTooltip(anchor, { title, rows } = {}) {
    const el = getRecordTooltip();
    el.innerHTML = '';

    if (title !== undefined && title !== null && title !== '') {
        const header = document.createElement('div');
        header.style.cssText = 'font-weight:bold;font-size:14px;margin-bottom:8px;'
            + 'border-bottom:1px solid #eee;padding-bottom:5px;';
        header.textContent = String(title);
        el.appendChild(header);
    }

    (rows || []).forEach(row => {
        const rowDiv = document.createElement('div');
        rowDiv.style.marginBottom = '4px';

        const strong = document.createElement('strong');
        strong.style.color = '#555';
        strong.textContent = row.label + ': ';
        rowDiv.appendChild(strong);

        if (row.color) {
            const swatch = document.createElement('span');
            swatch.style.cssText = 'display:inline-block;width:10px;height:10px;border-radius:2px;'
                + `background:${row.color};margin-right:4px;vertical-align:middle;`;
            rowDiv.appendChild(swatch);
        }

        const spanVal = document.createElement('span');
        spanVal.style.color = '#111';
        spanVal.textContent = String(row.value);
        rowDiv.appendChild(spanVal);

        el.appendChild(rowDiv);
    });

    el.style.display = 'block';

    const rect = anchor.getBoundingClientRect();
    let topPos = rect.bottom + window.scrollY + 5;
    if (topPos + el.offsetHeight > window.innerHeight + window.scrollY) {
        topPos = rect.top + window.scrollY - el.offsetHeight - 5;
    }
    el.style.left = (rect.left + window.scrollX) + 'px';
    el.style.top = topPos + 'px';
}

// Hide the shared tooltip (no-op if it was never created).
export function hideRecordTooltip() {
    const el = document.getElementById(TOOLTIP_ID);
    if (el) el.style.display = 'none';
}
