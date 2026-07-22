// admin/js/dashboard.js — Dashboard layout + widget editor
// Imports the shared widget modules (self-register into WidgetRegistry) to live-preview widgets; edits the "dashboard" config widgets, queries and conditions.
import { createTextInput, createSelectInput, createColorInput, createCheckbox, renderGlobalSettings } from './ui.js';
import { WidgetRegistry } from '../../assets/js/dashboard/registry.js';
import { apiFetch } from '../../assets/js/util/api.js';

// Import widgets so they self-register into WidgetRegistry
import '../../assets/js/dashboard/widgets/stat-card.js';
import '../../assets/js/dashboard/widgets/bar-chart.js';
import '../../assets/js/dashboard/widgets/vertical-bar-chart.js';
import '../../assets/js/dashboard/widgets/pie-chart.js';
import '../../assets/js/dashboard/widgets/line-chart.js';
import '../../assets/js/dashboard/widgets/list.js';

const CONDITION_OPS = [
    { value: '=',           label: '= (equals)' },
    { value: '!=',          label: '!= (not equal)' },
    { value: '<',           label: '< (less than)' },
    { value: '>',           label: '> (greater than)' },
    { value: '<=',          label: '<= (less or equal)' },
    { value: '>=',          label: '>= (greater or equal)' },
    { value: 'LIKE',        label: 'LIKE (matches pattern)' },
    { value: 'ILIKE',       label: 'ILIKE (case-insensitive match)' },
    { value: 'IS NULL',     label: 'IS NULL (empty)' },
    { value: 'IS NOT NULL', label: 'IS NOT NULL (not empty)' },
];

function renderConditionsBuilder(q, colOptions) {
    if (!Array.isArray(q.conditions)) q.conditions = [];

    const wrap = document.createElement('div');
    wrap.className = 'form-group';

    const lbl = document.createElement('label');
    lbl.textContent = 'Filter Conditions (WHERE)';
    wrap.appendChild(lbl);

    const list = document.createElement('div');
    list.style.cssText = 'display:flex;flex-direction:column;gap:6px;margin-bottom:8px;';

    function rebuildList() {
        list.innerHTML = '';
        q.conditions.forEach((cond, idx) => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:6px;align-items:center;flex-wrap:wrap;';

            // AND/OR logic selector (hidden for first condition)
            if (idx > 0) {
                const logicSel = document.createElement('select');
                logicSel.className = 'adm-input w-70';
                ['AND', 'OR'].forEach(l => {
                    const o = document.createElement('option');
                    o.value = l; o.textContent = l;
                    if ((cond.logic || 'AND') === l) o.selected = true;
                    logicSel.appendChild(o);
                });
                logicSel.addEventListener('change', e => { cond.logic = e.target.value; });
                row.appendChild(logicSel);
            } else {
                const spacer = document.createElement('span');
                spacer.style.cssText = 'width:70px;text-align:center;';
                spacer.textContent = 'WHERE';
                row.appendChild(spacer);
            }

            // Column select
            const colSel = document.createElement('select');
            colSel.className = 'adm-input flex-1';
            colOptions.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt.value; o.textContent = opt.label;
                if (opt.value === cond.col) o.selected = true;
                colSel.appendChild(o);
            });
            colSel.addEventListener('change', e => { cond.col = e.target.value; rebuildList(); });
            row.appendChild(colSel);

            // Operator select
            const opSel = document.createElement('select');
            opSel.className = 'adm-input flex-1';
            CONDITION_OPS.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt.value; o.textContent = opt.label;
                if (opt.value === (cond.op || '=')) o.selected = true;
                opSel.appendChild(o);
            });
            opSel.addEventListener('change', e => { cond.op = e.target.value; rebuildList(); });
            row.appendChild(opSel);

            // Value input (hidden for IS NULL / IS NOT NULL)
            const noVal = ['IS NULL', 'IS NOT NULL'].includes(cond.op || '=');
            if (!noVal) {
                const valIn = document.createElement('input');
                valIn.type = 'text';
                valIn.placeholder = 'value';
                valIn.value = cond.val || '';
                valIn.className = 'adm-input flex-1';
                valIn.addEventListener('input', e => { cond.val = e.target.value; });
                row.appendChild(valIn);
            }

            // Remove button
            const rmBtn = document.createElement('button');
            rmBtn.type = 'button';
            rmBtn.textContent = '✕';
            rmBtn.className = 'btn btn-danger btn-xs';
            rmBtn.addEventListener('click', () => {
                q.conditions.splice(idx, 1);
                rebuildList();
            });
            row.appendChild(rmBtn);

            list.appendChild(row);
        });
    }

    rebuildList();
    wrap.appendChild(list);

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.textContent = '+ Add condition';
    addBtn.className = 'btn btn-secondary btn-sm';
    addBtn.addEventListener('click', () => {
        const firstCol = colOptions[0]?.value || '';
        q.conditions.push({ col: firstCol, op: '=', val: '' });
        rebuildList();
    });
    wrap.appendChild(addBtn);

    return wrap;
}

// Runs the widget's current (unsaved) query/table/conditions against real data via
// includes/admin/dashboard.php (action=dashboard_calculate), so the operator can verify
// the WHERE conditions and aggregation before saving — the preview panel only ever shows
// mock data.
function renderCalculateButton(itemData) {
    const wrap = document.createElement('div');
    wrap.className = 'form-group';
    wrap.style.marginTop = '10px';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = 'Calculate (real data)';
    btn.className = 'btn btn-secondary btn-sm';

    const out = document.createElement('pre');
    out.style.cssText = 'margin-top:8px; padding:8px 10px; background:var(--bg); border:1px solid var(--border); border-radius:4px; font-size:13px; max-height:220px; overflow:auto; white-space:pre-wrap; display:none;';

    btn.addEventListener('click', async () => {
        if (!itemData.table) {
            out.style.display = '';
            out.style.color = 'var(--danger)';
            out.textContent = 'Select a source table first.';
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Calculating…';
        out.style.display = '';
        out.style.color = '';
        out.textContent = 'Please wait…';

        try {
            const res = await apiFetch('api.php?action=dashboard_calculate', {
                method: 'POST',
                body: {
                    table: itemData.table,
                    query: itemData.query || {},
                    display_columns: itemData.display_columns || [],
                },
            });
            const result = await res.json();
            if (result.status === 'success') {
                out.style.color = '';
                out.textContent = JSON.stringify(result.data, null, 2);
            } else {
                out.style.color = 'var(--danger)';
                out.textContent = 'Error: ' + (result.error || 'unknown');
            }
        } catch (e) {
            out.style.color = 'var(--danger)';
            out.textContent = 'Request failed: ' + e.message;
        }

        btn.disabled = false;
        btn.textContent = 'Calculate (real data)';
    });

    wrap.append(btn, out);
    return wrap;
}

// ── Widget Preview ───────────────────────────────────────────────────────────

function getMockData(type, displayColumns) {
    if (type === 'stat_card') return 1337;
    if (['bar_chart', 'vertical_bar_chart', 'pie_chart'].includes(type)) {
        return [
            { label: 'Category A', value: 42 },
            { label: 'Category B', value: 28 },
            { label: 'Category C', value: 15 },
            { label: 'Other',      value: 8  },
        ];
    }
    if (type === 'line_chart') {
        return [
            { label: '2026-01-01', value: 12 },
            { label: '2026-02-01', value: 19 },
            { label: '2026-03-01', value: 14 },
            { label: '2026-04-01', value: 27 },
            { label: '2026-05-01', value: 22 },
            { label: '2026-06-01', value: 31 },
        ];
    }
    if (type === 'list') {
        const cols = Array.isArray(displayColumns) && displayColumns.length
            ? displayColumns
            : ['name', 'status', 'created_at'];
        const row = Object.fromEntries(cols.map(c => [c, 'Example']));
        return [{ ...row }, { ...row }, { ...row }];
    }
    return null;
}

function renderPreviewInto(container, widget) {
    container.replaceChildren();

    const hdr = document.createElement('div');
    hdr.style.cssText = 'font-weight:600;border-bottom:1px solid var(--border-light);padding-bottom:6px;margin-bottom:12px;';
    hdr.textContent = 'Live Preview';
    container.appendChild(hdr);

    if (!widget.type) {
        const ph = document.createElement('p');
        ph.textContent = 'Select a widget type to preview.';
        container.appendChild(ph);
        return;
    }

    const mockWidget = {
        ...widget,
        data: getMockData(widget.type, widget.display_columns),
    };

    const widgetEl = document.createElement('div');
    widgetEl.className = 'dash-widget';
    widgetEl.dataset.w = widget.width || 1;
    widgetEl.dataset.h = widget.height || 1;
    widgetEl.style.pointerEvents = 'none';

    if (widget.type !== 'stat_card') {
        const title = document.createElement('h3');
        title.className = 'dash-title';
        title.textContent = widget.title || 'Widget Title';
        widgetEl.appendChild(title);
    }

    widgetEl.appendChild(WidgetRegistry.render(mockWidget));
    container.appendChild(widgetEl);
}

// ── Exported editors ─────────────────────────────────────────────────────────

export const WIDGET_TYPES = [
    { value: 'stat_card',         label: 'Stat Card' },
    { value: 'bar_chart',         label: 'Bar Chart (Horizontal)' },
    { value: 'vertical_bar_chart',label: 'Bar Chart (Vertical)' },
    { value: 'pie_chart',         label: 'Pie Chart' },
    { value: 'line_chart',        label: 'Line Chart (Time Series)' },
    { value: 'list',              label: 'Data List' },
];

export function renderDashboardLayout(ctx) {
    renderGlobalSettings(ctx, {
        title: 'Dashboard Global Settings',
        defaultMenuName: 'Dashboard',
        includeHidden: true,
        onAfter: ({ workspaceEl, currentConfig }) => {
            const layoutTitle = document.createElement('h4');
            layoutTitle.textContent = 'Grid Layout';
            layoutTitle.style.marginTop = '20px';
            workspaceEl.appendChild(layoutTitle);

            workspaceEl.appendChild(createTextInput('layout_gap', 'Grid Gap (CSS)', currentConfig.layout.gap || '20px', v => currentConfig.layout.gap = v));
        },
    });
}

export function renderDashboardEditor(key, itemData, isArray, ctx) {
    // Shadow workspaceEl: build a split layout — form on left, preview on right
    const { workspaceEl: containerEl, getTableOptions, getColumnOptionsForTable, renderEditor, renderSidebar } = ctx;

    const split = document.createElement('div');
    split.style.cssText = 'display:flex;gap:24px;align-items:flex-start;';
    containerEl.appendChild(split);

    // Form panel — all inputs go here (shadows outer workspaceEl)
    const workspaceEl = document.createElement('div');
    workspaceEl.style.cssText = 'flex:1 1 0;min-width:0;';

    // Preview panel — sticky alongside form
    const previewWrap = document.createElement('div');
    previewWrap.style.cssText = 'flex:0 0 280px;position:sticky;top:28px;';

    split.append(workspaceEl, previewWrap);

    function refreshPreview() {
        renderPreviewInto(previewWrap, itemData);
    }

    // Refresh preview on any input/select change inside the form panel
    workspaceEl.addEventListener('input',  refreshPreview);
    workspaceEl.addEventListener('change', refreshPreview);

    // ── Form fields ───────────────────────────────────────────────────────────

    workspaceEl.appendChild(createTextInput('id', 'Widget ID (Unique)', itemData.id, v => itemData.id = v));

    workspaceEl.appendChild(createSelectInput('type', 'Widget Type', WIDGET_TYPES, itemData.type || '', v => {
        itemData.type = v; itemData.query = {}; renderEditor(key, itemData, isArray);
    }));

    workspaceEl.appendChild(createTextInput('title', 'Widget Title', itemData.title, v => { itemData.title = v; renderSidebar(); }));
    workspaceEl.appendChild(createSelectInput('table', 'Source Table', getTableOptions(), itemData.table, v => {
        itemData.table = v; renderEditor(key, itemData, isArray);
    }));

    const queryBlock = document.createElement('div');
    queryBlock.style.borderLeft = '2px solid var(--accent)';
    queryBlock.style.paddingLeft = '15px';
    queryBlock.style.marginLeft = '15px';
    queryBlock.style.marginBottom = '20px';
    queryBlock.innerHTML = '<h4>Database Query Configuration</h4>';

    if (typeof itemData.query !== 'object' || itemData.query === null) itemData.query = {};
    const q = itemData.query;
    const colOptions = getColumnOptionsForTable(itemData.table);

    if (itemData.type === 'stat_card') {
        q.type = q.type || 'count'; q.column = q.column || 'id';
        queryBlock.appendChild(createSelectInput('q_type', 'Aggregation Function', [{ value: 'count', label: 'Count' }, { value: 'sum', label: 'Sum' }, { value: 'avg', label: 'Average' }], q.type, v => q.type = v));
        queryBlock.appendChild(createSelectInput('q_col', 'Target Column', colOptions, q.column, v => q.column = v));
    } else if (['bar_chart', 'vertical_bar_chart', 'pie_chart'].includes(itemData.type)) {
        q.type = 'group_by';
        queryBlock.appendChild(createSelectInput('q_group', 'Group By Column', colOptions, q.group_column || '', v => q.group_column = v));
        queryBlock.appendChild(createSelectInput('q_agg_col', 'Aggregation Column', colOptions, q.agg_column || 'id', v => q.agg_column = v));
        queryBlock.appendChild(createSelectInput('q_agg_type', 'Aggregation Function', [{ value: 'count', label: 'Count' }, { value: 'sum', label: 'Sum' }], q.agg_type || 'count', v => q.agg_type = v));
    } else if (itemData.type === 'line_chart') {
        q.type = 'time_series';
        queryBlock.appendChild(createSelectInput('q_x_col', 'Time Axis Column (X)', colOptions, q.x_column || '', v => q.x_column = v));
        queryBlock.appendChild(createSelectInput('q_granularity', 'Time Granularity', [
            { value: 'day',   label: 'Day' },
            { value: 'week',  label: 'Week' },
            { value: 'month', label: 'Month' },
            { value: 'year',  label: 'Year' },
        ], q.granularity || 'month', v => q.granularity = v));
        queryBlock.appendChild(createSelectInput('q_agg_col', 'Aggregation Column (Y)', colOptions, q.agg_column || 'id', v => q.agg_column = v));
        queryBlock.appendChild(createSelectInput('q_agg_type', 'Aggregation Function', [{ value: 'count', label: 'Count' }, { value: 'sum', label: 'Sum' }, { value: 'avg', label: 'Average' }], q.agg_type || 'count', v => q.agg_type = v));
        queryBlock.appendChild(createCheckbox('q_area', 'Fill area under line', q.area, v => q.area = v, false));
    } else if (itemData.type === 'list') {
        queryBlock.appendChild(createTextInput('q_limit', 'Limit Rows', q.limit || 5, v => q.limit = parseInt(v) || 5));
        queryBlock.appendChild(createSelectInput('q_order', 'Order By Column', colOptions, q.order_by || 'id', v => q.order_by = v));
        queryBlock.appendChild(createSelectInput('q_dir', 'Order Direction', [{ value: 'DESC', label: 'Descending' }, { value: 'ASC', label: 'Ascending' }], q.dir || 'DESC', v => q.dir = v));
    }

    queryBlock.appendChild(renderConditionsBuilder(q, colOptions));
    queryBlock.appendChild(renderCalculateButton(itemData));
    workspaceEl.appendChild(queryBlock);

    // Widget dimensions
    const sizeBlock = document.createElement('div');
    sizeBlock.style.cssText = 'display:flex;gap:16px;margin-bottom:16px;';
    sizeBlock.appendChild(createSelectInput('width', 'Width', [
        { value: 1, label: '1/3' },
        { value: 2, label: '2/3' },
        { value: 3, label: '3/3 (full)' },
    ], itemData.width || 1, v => { itemData.width = parseInt(v); }));
    sizeBlock.appendChild(createSelectInput('height', 'Height', [
        { value: 1, label: 'Small' },
        { value: 2, label: 'Medium' },
        { value: 3, label: 'Large' },
    ], itemData.height || 1, v => { itemData.height = parseInt(v); }));
    workspaceEl.appendChild(sizeBlock);

    // Color also drives the header visibility-filter chip dot for every widget type
    // (public/assets/js/dashboard/index.js), even though pie-chart.js ignores it for
    // slice colors — so this control stays for all types.
    workspaceEl.appendChild(createColorInput('color', 'Accent Color', itemData.color, v => { itemData.color = v; refreshPreview(); }));

    if (itemData.type === 'list') {
        const colsStr = Array.isArray(itemData.display_columns) ? itemData.display_columns.join(', ') : '';
        workspaceEl.appendChild(createTextInput('display_columns', 'Columns to Display (Comma separated)', colsStr, v => {
            itemData.display_columns = v.split(',').map(s => s.trim()).filter(s => s);
        }));
    }

    // Initial preview render
    refreshPreview();
}
