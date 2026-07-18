// assets/js/workflows.js — Loads workflow/automation config from api.php?api=workflows (CSRF + X-Requested-With) and renders the workflows UI. Toasts on error.

import { showToast } from './toast.js';
import { I18n } from './i18n.js';
import { getCsrfToken } from './util/csrf.js';
import { apiFetch } from './util/api.js';

// Fetch workflows configuration from backend
async function fetchWorkflowsConfig() {
    try {
        // Add CSRF header to prevent cross-site request forgery
        const csrfToken = getCsrfToken();
        const res = await fetch('api.php?api=workflows', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            }
        });
        if (!res.ok) throw new Error('Network response was not ok');
        return await res.json();
    } catch (e) {
        console.warn('Could not load workflows config', e);
        return null;
    }
}

// Helper to safely render icons as DOM elements
function createIconElement(iconPath, fallbackColor = 'var(--accent)') {
    if (!iconPath) {
        const div = document.createElement('div');
        div.style.cssText = `width:20px; height:20px; background:${fallbackColor}; border-radius:50%; margin-right:8px; display:inline-block; vertical-align:middle;`;
        return div;
    }
    const img = document.createElement('img');
    img.src = iconPath;
    img.alt = '';
    img.style.cssText = 'width:20px; height:20px; vertical-align:middle; margin-right:8px; object-fit:contain;';
    return img;
}

// Main initialization function to be called from app.js
export async function initWorkflows(menuListEl, containerEl, titleEl, appSchema) {
    const config = await fetchWorkflowsConfig();

    if (!config || !config.workflows || config.workflows.length === 0) {
        return false;
    }

    // Respect the "Hide from Sidebar Menu" flag from admin Global Settings
    if (config.hidden === true) {
        return false;
    }

    // Restore grid UI elements when navigating back to standard tables
    document.addEventListener("tableLoaded", () => {
        const bar = document.getElementById('wf-step-bar');
        if (bar) bar.remove();

        const gridUI = document.querySelectorAll('.actions, #filterBar, #globalSearch, #columnFilter, #addRow');
        gridUI.forEach(el => el.style.display = '');
    });

    const menuName = config.menu_name || 'Workflows';

    // Wire the PHP-rendered link (menu.php already outputs it with data-page="workflows")
    const menuRoot = menuListEl.closest('#menu') ?? menuListEl;
    const wfLink = menuRoot.querySelector('a[data-page="workflows"]');

    const hideGridUi = () => {
        const uiToHide = document.querySelectorAll('.actions, #filterBar, #globalSearch, #columnFilter, #clearFilters, #addRow');
        uiToHide.forEach(el => el.style.display = 'none');
    };

    const activateLink = (link) => {
        menuRoot.querySelectorAll('a').forEach(l => l.classList.remove('active'));
        if (link) link.classList.add('active');
    };

    if (wfLink) {
        wfLink.addEventListener('click', (e) => {
            e.preventDefault();
            activateLink(wfLink);
            hideGridUi();
            renderWorkflowsList(config.workflows, containerEl, titleEl, menuName, appSchema);
        });
    }

    // Each submenu child (menu.php renders data-workflow-id) jumps straight into that workflow.
    const wfChildLinks = menuRoot.querySelectorAll('a[data-workflow-id]');
    wfChildLinks.forEach((link) => {
        link.addEventListener('click', (e) => {
            const wf = config.workflows.find(w => w.id === link.dataset.workflowId);
            if (!wf) return;
            e.preventDefault();
            activateLink(link);
            hideGridUi();
            startWorkflow(wf, containerEl, titleEl, appSchema, config.workflows, menuName);
        });
    });

    // Auto-show workflows view when page was loaded with ?workflows in URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('workflows')) {
        const workflowId = urlParams.get('workflow') || '';
        const wf = workflowId ? config.workflows.find(w => w.id === workflowId) : null;
        const matchingChildLink = wf
            ? menuRoot.querySelector(`a[data-workflow-id="${CSS.escape(wf.id)}"]`)
            : null;

        activateLink(matchingChildLink || wfLink);
        hideGridUi();

        if (wf) {
            startWorkflow(wf, containerEl, titleEl, appSchema, config.workflows, menuName);
        } else {
            renderWorkflowsList(config.workflows, containerEl, titleEl, menuName, appSchema);
        }
        return true; // signals app.js to skip loadTable
    }

    return false;
}

// Render the beautiful grid list of available workflows
function renderWorkflowsList(workflows, containerEl, titleEl, menuName, appSchema) {
    // The step bar is inserted as containerEl's sibling (not its child), so
    // clearing containerEl alone leaves it behind when navigating back to the
    // list mid-workflow (e.g. clicking the main Workflows link).
    const staleBar = document.getElementById('wf-step-bar');
    if (staleBar) staleBar.remove();

    titleEl.textContent = menuName;
    containerEl.textContent = ''; // Safely clear container

    const listContainer = document.createElement('div');
    listContainer.style.display = 'grid';
    listContainer.style.gridTemplateColumns = 'repeat(auto-fill, minmax(320px, 1fr))';
    listContainer.style.gap = '24px';
    listContainer.style.padding = '24px';

    workflows.forEach(wf => {
        const card = document.createElement('div');
        
        // Premium UI styling based on provided CSS variables
        card.style.cssText = `
            display: flex;
            flex-direction: column;
            padding: 24px;
            background: var(--panel);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            cursor: pointer;
            box-shadow: var(--shadow-sm);
            transition: all var(--transition);
            position: relative;
        `;
        
        // Hover effects
        card.addEventListener('mouseenter', () => {
            card.style.transform = 'translateY(-3px)';
            card.style.boxShadow = 'var(--shadow-md)';
            card.style.borderColor = 'var(--border)';
        });
        card.addEventListener('mouseleave', () => {
            card.style.transform = 'none';
            card.style.boxShadow = 'var(--shadow-sm)';
            card.style.borderColor = 'var(--border-light)';
        });
        
        const header = document.createElement('div');
        header.style.display = 'flex';
        header.style.alignItems = 'center';
        header.style.gap = '14px';
        header.style.marginBottom = '14px';
        
        const iconWrapper = document.createElement('div');
        iconWrapper.style.cssText = `
            display: flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            background: var(--accent-light);
            border-radius: 8px;
        `;
        
        // Safely append image or placeholder
        if (wf.icon) {
            const img = document.createElement('img');
            img.src = wf.icon;
            img.alt = '';
            img.style.cssText = 'width:22px; height:22px; object-fit:contain;';
            iconWrapper.appendChild(img);
        } else {
            const div = document.createElement('div');
            div.style.cssText = 'width:22px; height:22px; background:var(--accent); border-radius:50%;';
            iconWrapper.appendChild(div);
        }

        const cardTitle = document.createElement('h3');
        cardTitle.style.margin = '0';
        cardTitle.style.color = 'var(--accent-dark)';
        cardTitle.style.fontSize = '1.15rem';
        cardTitle.style.fontWeight = '600';
        cardTitle.textContent = wf.title;
        
        header.appendChild(iconWrapper);
        header.appendChild(cardTitle);
        
        const cardDesc = document.createElement('p');
        cardDesc.style.color = 'var(--muted)';
        cardDesc.style.fontSize = '14px';
        cardDesc.style.margin = '0 0 20px 0';
        cardDesc.style.lineHeight = '1.5';
        cardDesc.style.flexGrow = '1';
        cardDesc.textContent = wf.description || I18n.t('workflow.no_description');

        const footer = document.createElement('div');
        footer.style.display = 'flex';
        footer.style.alignItems = 'center';
        footer.style.justifyContent = 'space-between';
        footer.style.marginTop = 'auto';
        footer.style.paddingTop = '16px';
        footer.style.borderTop = '1px solid var(--border-light)';
        
        const stepCount = document.createElement('span');
        stepCount.style.fontSize = '12px';
        stepCount.style.color = 'var(--muted)';
        stepCount.style.fontWeight = '600';
        stepCount.style.textTransform = 'uppercase';
        stepCount.style.letterSpacing = '0.5px';
        const validStepCount = (wf.steps || []).filter(s => s && s.table).length;
        stepCount.textContent = I18n.t('workflow.steps', { count: validStepCount }, validStepCount);
        
        const startBtn = document.createElement('span');
        startBtn.style.fontSize = '13.5px';
        startBtn.style.color = 'var(--accent)';
        startBtn.style.fontWeight = '600';
        startBtn.textContent = I18n.t('workflow.start');

        footer.appendChild(stepCount);
        footer.appendChild(startBtn);

        card.appendChild(header);
        card.appendChild(cardDesc);
        card.appendChild(footer);
        
        card.addEventListener('click', () => startWorkflow(wf, containerEl, titleEl, appSchema, workflows, menuName));
        
        listContainer.appendChild(card);
    });

    containerEl.appendChild(listContainer);
}

// Start and manage the step-by-step wizard
function startWorkflow(workflow, containerEl, titleEl, appSchema, allWorkflows, menuName) {
    // Drop steps with no target table (e.g. a blank step left in the workflow
    // config). They cannot render and would otherwise abort the run after a
    // valid step with "Schema for table '' not found.". Use a local copy so the
    // saved config and the workflow list are untouched.
    workflow = { ...workflow, steps: (workflow.steps || []).filter(s => s && s.table) };

    let currentStepIndex = 0;
    // Deferred-save model: nothing is written to the database until the final
    // review screen. stepData[i] holds an array of raw form snapshots for step i
    // (length 1 for single-record steps, N for allow_multiple). stepMeta[i]
    // caches the resolved tableSchema so the final save can build payloads
    // without re-fetching. stepResults maps step index → the first saved record
    // id, used to inject foreign keys during the final save. savedRecords tracks
    // already-persisted snapshots so a retry after a mid-save failure does not
    // duplicate the records that already went in.
    const stepData = [];
    const stepMeta = [];
    const stepResults = {};
    const savedRecords = new Set();
    // When editing a single step from the review screen, jump straight back to
    // the review instead of walking forward through every later step again.
    let returnToReview = false;

    // Navigate to a step index; anything past the last step is the review screen.
    function goToStep(i) {
        currentStepIndex = i;
        if (currentStepIndex >= workflow.steps.length) {
            renderReview();
        } else {
            renderCurrentStep();
        }
    }

    // Snapshot every named input's raw value (checkboxes as booleans).
    function readForm(form) {
        const snap = {};
        form.querySelectorAll('[name]').forEach((el) => {
            snap[el.name] = el.type === 'checkbox' ? el.checked : el.value;
        });
        return snap;
    }

    // Restore a snapshot into a freshly rebuilt form.
    function writeForm(form, snap) {
        if (!snap) return;
        Object.entries(snap).forEach(([name, val]) => {
            const el = form.querySelector(`[name="${CSS.escape(name)}"]`);
            if (!el) return;
            if (el.type === 'checkbox') el.checked = !!val;
            else el.value = val ?? '';
        });
    }

    // Build the API payload for one buffered record, applying the same value
    // conversions as edit.php (checkbox → bool, datetime-local → PostgreSQL
    // timestamp, drop empty strings) and skipping id/readonly/virtual columns and
    // the foreign key that gets injected from a previous step.
    function buildPayload(tableSchema, step, snap) {
        const payload = {};
        for (const [colName, colDef] of Object.entries(tableSchema.columns)) {
            const type = (colDef.type || '').toLowerCase();
            if (colName === 'id' || colDef.readonly || type === 'virtual') continue;
            if (step.foreign_key === colName && step.link_to_step !== undefined && step.link_to_step !== '') continue;
            const raw = snap[colName];
            if (type.includes('bool')) {
                payload[colName] = !!raw;
            } else if (raw !== undefined && raw !== '') {
                payload[colName] = (type.includes('timestamp') || type.includes('datetime'))
                    ? String(raw).replace('T', ' ')
                    : raw;
            }
        }
        return payload;
    }

    // Step progress indicator above the form
    function renderStepBar() {
        let bar = document.getElementById('wf-step-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'wf-step-bar';
            bar.style.cssText = 'display:flex; align-items:center; justify-content:center; flex-wrap:wrap; gap:0; margin:32px auto 28px; max-width:700px; padding:0 20px;';
            containerEl.parentNode.insertBefore(bar, containerEl);
        }
        bar.textContent = '';

        workflow.steps.forEach((s, i) => {
            const done      = i < currentStepIndex;
            const current   = i === currentStepIndex;
            const labelText = s.title || `Step ${i + 1}`;

            const pill = document.createElement('div');
            pill.style.cssText = [
                'display:flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; font-size:13px; font-weight:600; white-space:nowrap; transition:all .2s;',
                done    ? 'background:#dcfce7; color:#166534;'  :
                current ? 'background:var(--accent); color:#fff;' :
                          'background:#f1f5f9; color:#94a3b8;'
            ].join('');

            const dot = document.createElement('span');
            dot.style.cssText = `width:8px; height:8px; border-radius:50%; background:${done ? '#16a34a' : current ? '#fff' : '#cbd5e1'};`;
            const lbl = document.createElement('span');
            lbl.textContent = labelText;

            pill.append(dot, lbl);
            bar.appendChild(pill);

            if (i < workflow.steps.length - 1) {
                const arrow = document.createElement('span');
                arrow.textContent = '→';
                arrow.style.cssText = 'color:#cbd5e1; font-size:14px; padding:0 2px; flex-shrink:0;';
                bar.appendChild(arrow);
            }
        });
    }

    // Render a single step of the workflow
    async function renderCurrentStep() {
        if (currentStepIndex >= workflow.steps.length) {
            renderReview();
            return;
        }

        const step = workflow.steps[currentStepIndex];

        renderStepBar();

        // Set main title to show progress
        titleEl.textContent = I18n.t('workflow.step_of', { title: workflow.title, current: currentStepIndex + 1, total: workflow.steps.length });
        containerEl.textContent = ''; // Safely clear container

        // Safely resolve schema with API fallback
        let activeSchema = appSchema;
        if (!activeSchema && typeof window !== 'undefined' && window.schema) {
            activeSchema = window.schema;
        }
        
        if (!activeSchema) {
            try {
                // Add CSRF header to schema fetch
                const csrfToken = getCsrfToken();
                const res = await fetch('api.php?api=schema', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    }
                });
                if (res.ok) activeSchema = await res.json();
            } catch (err) {
                console.warn('Could not fetch schema dynamically', err);
            }
        }

        // Case-insensitive table matching for robust schema loading
        let tableSchema = activeSchema?.tables?.[step.table];
        if (!tableSchema && activeSchema?.tables) {
            const key = Object.keys(activeSchema.tables).find(k => k.toLowerCase() === step.table.toLowerCase());
            if (key) tableSchema = activeSchema.tables[key];
        }

        // fullSchema is used for FK linkage detection (referenced tables' FK maps).
        // Default to activeSchema; upgraded below if hidden-table fetch is needed.
        let fullSchema = activeSchema;

        // Hidden tables (e.g. subtables used only in workflows) are excluded from
        // window.schema — fetch full schema including hidden when not found.
        if (!tableSchema) {
            try {
                const res = await fetch('api/schema.php?include_hidden=1', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const full = await res.json();
                fullSchema = full;
                tableSchema = full.tables?.[step.table];
                if (!tableSchema && full.tables) {
                    const key = Object.keys(full.tables).find(k => k.toLowerCase() === step.table.toLowerCase());
                    if (key) tableSchema = full.tables[key];
                }
            } catch { /* fall through to error below */ }
        }

        if (!tableSchema) {
            const errorMsg = document.createElement('p');
            errorMsg.style.cssText = 'color: var(--danger); text-align: center; margin-top: 40px;';
            errorMsg.textContent = I18n.t('workflow.schema_not_found', { table: step.table });
            containerEl.appendChild(errorMsg);
            return;
        }

        // Cache the resolved schema so the final save can build payloads without
        // re-fetching (the form is long gone by then).
        stepMeta[currentStepIndex] = { tableSchema };

        // Pre-fetch FK options for all non-injected FK columns in parallel
        const fkOptionMap = {}; // { colName: [{value, label}] }
        const fkCfgMap = tableSchema.foreign_keys || {};
        const csrfForFk = getCsrfToken();

        await Promise.all(
            Object.entries(fkCfgMap).map(async ([colName, fkDef]) => {
                // Skip if this FK will be auto-injected from a previous step
                if (step.foreign_key === colName && step.link_to_step !== undefined && step.link_to_step !== '') {
                    return;
                }
                const refCol = fkDef.reference_column || 'id';
                // display_columns may be array or comma-separated string
                const rawDisp = (Array.isArray(fkDef.display_columns) && fkDef.display_columns.length > 0)
                    ? fkDef.display_columns : (fkDef.display_column ?? '');
                const dispCols = Array.isArray(rawDisp)
                    ? rawDisp
                    : String(rawDisp).split(',').map(s => s.trim()).filter(Boolean);

                try {
                    const res = await fetch(
                        `api/fk.php?table=${encodeURIComponent(step.table)}&col=${encodeURIComponent(colName)}`,
                        { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfForFk } }
                    );
                    const data = await res.json();
                    const rows = data.rows || [];
                    fkOptionMap[colName] = rows.map(row => {
                        const label = dispCols.length
                            ? dispCols.map(c => row[c] ?? '').filter(Boolean).join(' ')
                            : String(row[refCol] ?? row.id ?? '');
                        return { value: row[refCol] ?? row.id, label: label || String(row[refCol] ?? row.id) };
                    });
                } catch {
                    fkOptionMap[colName] = [];
                }
            })
        );

        // Re-fetch FK options for a column, optionally filtered by a master column value
        async function fetchFkOptions(colName, filterCol = '', filterVal = '') {
            const csrf = getCsrfToken();
            let url = `api/fk.php?table=${encodeURIComponent(step.table)}&col=${encodeURIComponent(colName)}`;
            if (filterCol && filterVal !== '') {
                url += `&filter_col=${encodeURIComponent(filterCol)}&filter_val=${encodeURIComponent(filterVal)}`;
            }
            try {
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf } });
                const data = await res.json();
                const rows = data.rows || [];
                const fkDef = fkCfgMap[colName] || {};
                const refCol = fkDef.reference_column || 'id';
                const rawDisp = (Array.isArray(fkDef.display_columns) && fkDef.display_columns.length > 0)
                    ? fkDef.display_columns : (fkDef.display_column ?? '');
                const dispCols = Array.isArray(rawDisp) ? rawDisp : String(rawDisp).split(',').map(s => s.trim()).filter(Boolean);
                return rows.map(row => {
                    const label = dispCols.length
                        ? dispCols.map(c => row[c] ?? '').filter(Boolean).join(' ')
                        : String(row[refCol] ?? row.id ?? '');
                    return { value: row[refCol] ?? row.id, label: label || String(row[refCol] ?? row.id) };
                });
            } catch {
                return [];
            }
        }

        function rebuildSelect(selectEl, options) {
            const prev = selectEl.value;
            selectEl.textContent = '';
            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = I18n.t('workflow.select_blank');
            selectEl.appendChild(blank);
            options.forEach(({ value, label }) => {
                const opt = document.createElement('option');
                opt.value = String(value);
                opt.textContent = label;
                selectEl.appendChild(opt);
            });
            selectEl.value = options.some(o => String(o.value) === prev) ? prev : '';
        }

        // Mirror edit.php's record-form layout: a centered .form-page holding a
        // heading, an optional description, and a .form-wrapper card around the
        // .editor-form. Styling comes entirely from the shared classes in
        // styles.css so the workflow step looks identical to the edit screen.
        const page = document.createElement('div');
        page.className = 'form-page wf-form-page';

        // Render step title prominently, like edit.php's <h2> page heading.
        // Prefix it with the icon configured for this step's table (schema
        // `icon`), rendered as an <img> for a path/filename or inline text for
        // an emoji — mirroring the calendar/menu icon handling.
        if (step.title && step.title.trim() !== '') {
            const stepTitleEl = document.createElement('h2');
            stepTitleEl.className = 'wf-step-title';
            const tableIcon = tableSchema.icon;
            if (tableIcon) {
                if (tableIcon.includes('/') || tableIcon.includes('.')) {
                    const iconImg = document.createElement('img');
                    iconImg.src = tableIcon;
                    iconImg.alt = '';
                    iconImg.className = 'wf-step-title-icon';
                    iconImg.onerror = () => iconImg.remove();
                    stepTitleEl.appendChild(iconImg);
                } else {
                    const iconSpan = document.createElement('span');
                    iconSpan.className = 'wf-step-title-icon';
                    iconSpan.textContent = tableIcon;
                    stepTitleEl.appendChild(iconSpan);
                }
            }
            stepTitleEl.appendChild(document.createTextNode(step.title));
            page.appendChild(stepTitleEl);
        }

        // Render step description if provided in admin
        if (step.description && step.description.trim() !== '') {
            const descEl = document.createElement('p');
            descEl.className = 'wf-step-desc';
            descEl.textContent = step.description;
            page.appendChild(descEl);
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'form-wrapper';

        const form = document.createElement('form');
        form.className = 'editor-form';

        const grid = document.createElement('div');
        grid.className = 'form-grid';

        // Track virtual column display elements for live recalculation
        const virtualFields = {}; // { colName: { el, formula } }

        function calcVirtualValue(formula) {
            const ops = formula.cols || [];
            const vals = ops.map(c => {
                const el = form.querySelector(`[name="${c}"]`);
                return parseFloat(el?.value ?? 0) || 0;
            });
            switch (formula.op) {
                case 'multiply':  return vals.reduce((a, b) => a * b, 1);
                case 'add':       return vals.reduce((a, b) => a + b, 0);
                case 'subtract':  return vals.length >= 2 ? vals[0] - vals.slice(1).reduce((a, b) => a + b, 0) : 0;
                case 'divide':    return vals.length >= 2 && vals[1] !== 0 ? vals[0] / vals[1] : 0;
                default:          return 0;
            }
        }

        function refreshVirtuals() {
            for (const [, vf] of Object.entries(virtualFields)) {
                const result = calcVirtualValue(vf.formula);
                vf.el.value = Number.isInteger(result) ? result : parseFloat(result.toFixed(4));
            }
        }

        // Generate form fields dynamically based on schema
        for (const [colName, colDef] of Object.entries(tableSchema.columns)) {
            if (colName === 'id' || colDef.readonly || colDef.show_in_edit === false) continue;

            // Skip rendering the field if it will be automatically injected as a foreign key
            if (step.foreign_key === colName && step.link_to_step !== undefined && step.link_to_step !== "") {
                continue;
            }

            const formGroup = document.createElement('div');
            formGroup.className = 'form-group';

            const label = document.createElement('label');
            label.textContent = colDef.display_name || colName;

            let input;
            const type = (colDef.type || '').toLowerCase();

            // Required marker, mirroring edit.php (skipped for virtual/readonly fields)
            if (colDef.not_null && type !== 'virtual' && !colDef.readonly) {
                const req = document.createElement('span');
                req.className = 'required';
                req.textContent = ' *';
                label.appendChild(req);
            }

            // Render virtual column as live-calculated readonly display
            if (type === 'virtual') {
                input = document.createElement('input');
                input.type = 'text';
                input.readOnly = true;
                input.tabIndex = -1;
                input.dataset.virtual = colName;
                virtualFields[colName] = { el: input, formula: colDef.formula || {} };
            // Render FK column as searchable select using schema FK config
            } else if (Object.prototype.hasOwnProperty.call(fkOptionMap, colName)) {
                input = document.createElement('select');
                const blankOpt = document.createElement('option');
                blankOpt.value = '';
                blankOpt.textContent = I18n.t('workflow.select_blank');
                input.appendChild(blankOpt);
                (fkOptionMap[colName] || []).forEach(({ value, label }) => {
                    const opt = document.createElement('option');
                    opt.value = value;
                    opt.textContent = label;
                    input.appendChild(opt);
                });
            // Render select dropdown for ENUM types
            } else if (type === 'enum' && Array.isArray(colDef.options)) {
                input = document.createElement('select');
                const defaultOpt = document.createElement('option');
                defaultOpt.value = '';
                defaultOpt.textContent = I18n.t('workflow.select_blank');
                input.appendChild(defaultOpt);

                colDef.options.forEach(optVal => {
                    const opt = document.createElement('option');
                    opt.value = optVal;
                    opt.textContent = optVal;
                    input.appendChild(opt);
                });
            } else if (type.includes('bool')) {
                input = document.createElement('input');
                input.type = 'checkbox';
            // Timestamp (Date + Time) → native datetime-local picker, mirroring
            // edit.php's TimestampField. Checked before 'date' since a plain date
            // column has no time component. 'timestamp' has no 'date' substring,
            // so without this it fell through to a plain text input with no picker.
            } else if (type.includes('timestamp') || type.includes('datetime')) {
                input = document.createElement('input');
                input.type = 'datetime-local';
                input.step = '1';
            } else if (type.includes('date')) {
                input = document.createElement('input');
                input.type = 'date';
            } else {
                input = document.createElement('input');
                input.type = 'text';
            }

            input.name = colName;
            // Focus/hover/readonly styling is inherited from the shared
            // .editor-form input/select rules in styles.css. Checkboxes get the
            // dedicated class so they render inline instead of full-width.
            if (type.includes('bool')) {
                input.classList.add('wf-checkbox');
            }

            // Enforce required fields client-side, mirroring edit.php's field
            // registry (TextField/EnumField/ForeignKeyField/DateField all set the
            // native `required` attribute on not_null, non-locked columns). Without
            // this the ` *` marker was purely cosmetic and empty required fields
            // slipped through to the next step. Booleans are excluded — a not_null
            // checkbox defaults to false and is never "empty".
            if (colDef.not_null && type !== 'virtual' && !colDef.readonly && !type.includes('bool')) {
                input.required = true;
            }

            formGroup.appendChild(label);
            formGroup.appendChild(input);
            grid.appendChild(formGroup);
        }

        form.appendChild(grid);

        // Detect FK linkages: for each FK select, check if referenced table has a FK
        // column that also appears in this form → enable "Show related only" checkbox.
        const fkLinkMap = {}; // { dependentCol: masterCol }
        for (const [colName, fkDef] of Object.entries(fkCfgMap)) {
            const refTableName = fkDef.reference_table;
            if (!refTableName) continue;
            let refSchema = fullSchema?.tables?.[refTableName];
            if (!refSchema && fullSchema?.tables) {
                const k = Object.keys(fullSchema.tables).find(t => t.toLowerCase() === refTableName.toLowerCase());
                if (k) refSchema = fullSchema.tables[k];
            }
            if (!refSchema?.foreign_keys) continue;
            for (const refFkCol of Object.keys(refSchema.foreign_keys)) {
                if (refFkCol !== colName && fkCfgMap[refFkCol]) {
                    fkLinkMap[colName] = refFkCol;
                    break;
                }
            }
        }

        // Add "Show related only" checkbox for each linked FK select
        for (const [depCol, masterCol] of Object.entries(fkLinkMap)) {
            const selectEl = form.querySelector(`[name="${depCol}"]`);
            const masterEl = form.querySelector(`[name="${masterCol}"]`);
            if (!selectEl || !masterEl) continue;

            const filterRow = document.createElement('label');
            filterRow.className = 'wf-related-toggle';
            const filterCb = document.createElement('input');
            filterCb.type = 'checkbox';
            filterCb.className = 'wf-checkbox';
            const filterLbl = document.createElement('span');
            filterLbl.textContent = I18n.t('workflow.show_related');
            filterRow.appendChild(filterCb);
            filterRow.appendChild(filterLbl);
            selectEl.parentElement.appendChild(filterRow);

            const applyFilter = async () => {
                const masterVal = masterEl.value;
                const opts = (filterCb.checked && masterVal)
                    ? await fetchFkOptions(depCol, masterCol, masterVal)
                    : await fetchFkOptions(depCol);
                rebuildSelect(selectEl, opts);
            };

            filterCb.addEventListener('change', applyFilter);
            masterEl.addEventListener('change', () => { if (filterCb.checked) applyFilter(); });
        }

        // Wire live recalculation: any input change refreshes all virtual fields
        if (Object.keys(virtualFields).length > 0) {
            form.addEventListener('input', refreshVirtuals);
            form.addEventListener('change', refreshVirtuals);
            refreshVirtuals(); // initial render with empty values = 0
        }

        // ---- Deferred-save navigation (no database write until the review) ----

        // For allow_multiple steps, render the list of records already buffered
        // for this step, each with a remove button. Single-record steps instead
        // repopulate the form from the buffer when the step is revisited.
        const bufferedRecords = stepData[currentStepIndex] || [];
        let multiListEl = null;
        // Index of the buffered record currently loaded into the form for
        // in-place editing (allow_multiple only); null means the form adds a new
        // record. addBtn/cancelEditBtn are assigned when the buttons are built
        // below and toggled by enter/exitEditMode.
        let editingIndex = null;
        let addBtn = null;
        let cancelEditBtn = null;

        function labelForRecord(snap) {
            // Short human label from the first couple of non-empty text values.
            const parts = [];
            for (const [colName, colDef] of Object.entries(tableSchema.columns)) {
                const t = (colDef.type || '').toLowerCase();
                if (colName === 'id' || t === 'virtual' || t.includes('bool')) continue;
                const v = snap[colName];
                if (v !== undefined && String(v).trim() !== '') parts.push(String(v));
                if (parts.length >= 2) break;
            }
            return parts.join(' — ') || I18n.t('workflow.select_blank');
        }

        function renderMultiList() {
            if (!multiListEl) return;
            multiListEl.textContent = '';
            const records = stepData[currentStepIndex] || [];
            records.forEach((snap, ri) => {
                const row = document.createElement('div');
                row.className = 'wf-buffered-row' + (ri === editingIndex ? ' active' : '');
                // Clicking the label loads this record's values back into the
                // form for editing (update-in-place), instead of only adding new.
                const txt = document.createElement('span');
                txt.className = 'wf-buffered-label';
                txt.textContent = `${ri + 1}. ${labelForRecord(snap)}`;
                txt.title = I18n.t('common.edit');
                txt.addEventListener('click', () => enterEditMode(ri));
                const rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'icon-btn icon-btn-danger';
                rm.textContent = '✕';
                rm.title = I18n.t('common.delete');
                rm.addEventListener('click', () => {
                    stepData[currentStepIndex].splice(ri, 1);
                    // Removing a row shifts indices; drop any in-progress edit
                    // and re-render from a clean "add new" state.
                    if (editingIndex !== null) exitEditMode();
                    else renderMultiList();
                });
                row.appendChild(txt);
                row.appendChild(rm);
                multiListEl.appendChild(row);
            });
        }

        // Load a buffered record into the form for editing; the Add button
        // becomes "Update Record" and a Cancel button appears.
        function enterEditMode(ri) {
            editingIndex = ri;
            form.reset();
            writeForm(form, (stepData[currentStepIndex] || [])[ri]);
            refreshVirtuals();
            if (addBtn) addBtn.textContent = I18n.t('form.update_record');
            if (cancelEditBtn) cancelEditBtn.hidden = false;
            renderMultiList();
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        // Return to "add new" mode: clear the form and restore button labels.
        function exitEditMode() {
            editingIndex = null;
            form.reset();
            refreshVirtuals();
            if (addBtn) addBtn.textContent = I18n.t('form.add_record');
            if (cancelEditBtn) cancelEditBtn.hidden = true;
            renderMultiList();
        }

        if (step.allow_multiple) {
            multiListEl = document.createElement('div');
            multiListEl.className = 'wf-buffered-list';
            form.appendChild(multiListEl);
            renderMultiList();
        } else if (bufferedRecords.length > 0) {
            writeForm(form, bufferedRecords[0]);
            refreshVirtuals();
        }

        // Action buttons — Back / (Add to list) / Next. Nothing is saved here.
        const btnContainer = document.createElement('div');
        btnContainer.className = 'form-actions';

        if (currentStepIndex > 0) {
            const backBtn = document.createElement('button');
            backBtn.type = 'button';
            backBtn.className = 'btn-cancel';
            backBtn.textContent = I18n.t('pagination.prev');
            backBtn.addEventListener('click', () => {
                // Preserve whatever is currently entered before stepping back.
                if (!step.allow_multiple) {
                    stepData[currentStepIndex] = [readForm(form)];
                }
                goToStep(currentStepIndex - 1);
            });
            btnContainer.appendChild(backBtn);
        }

        if (step.allow_multiple) {
            addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'btn-cancel';
            addBtn.textContent = I18n.t('form.add_record');
            addBtn.addEventListener('click', () => {
                if (!form.reportValidity()) return;
                if (!stepData[currentStepIndex]) stepData[currentStepIndex] = [];
                if (editingIndex !== null) {
                    // Replace the record being edited instead of appending.
                    stepData[currentStepIndex][editingIndex] = readForm(form);
                } else {
                    stepData[currentStepIndex].push(readForm(form));
                }
                // exitEditMode clears the form, restores labels and re-renders.
                exitEditMode();
            });

            cancelEditBtn = document.createElement('button');
            cancelEditBtn.type = 'button';
            cancelEditBtn.className = 'btn-cancel';
            cancelEditBtn.textContent = I18n.t('common.cancel');
            cancelEditBtn.hidden = true;
            cancelEditBtn.addEventListener('click', () => exitEditMode());

            btnContainer.appendChild(addBtn);
            btnContainer.appendChild(cancelEditBtn);
        }

        const nextBtn = document.createElement('button');
        nextBtn.type = 'submit';
        nextBtn.className = 'btn-save';
        // "Save" when returning from the review after an edit; otherwise advance.
        nextBtn.textContent = returnToReview ? I18n.t('form.save') : I18n.t('form.next_step');
        btnContainer.appendChild(nextBtn);

        form.appendChild(btnContainer);

        // Advance to the next step (or the review), buffering the current form.
        form.addEventListener('submit', (e) => {
            e.preventDefault();

            if (step.allow_multiple) {
                // Records are committed via the "Add Record" button, which
                // validates each one. Next just advances — never block on a
                // required field here. As a convenience, if the form still holds
                // a *complete, valid* record, fold it into the buffer so it is
                // not silently lost; a half-entered (invalid) form is discarded
                // via checkValidity() (no blocking prompt) and advancing proceeds.
                const hasAnyValue = Array.from(form.querySelectorAll('[name]')).some((el) =>
                    el.type === 'checkbox' ? el.checked : String(el.value ?? '').trim() !== '');
                if (hasAnyValue && form.checkValidity()) {
                    if (!stepData[currentStepIndex]) stepData[currentStepIndex] = [];
                    if (editingIndex !== null) {
                        // Mid-edit: commit the change in place, not as a duplicate.
                        stepData[currentStepIndex][editingIndex] = readForm(form);
                    } else {
                        stepData[currentStepIndex].push(readForm(form));
                    }
                }
            } else {
                // Enforce required fields before buffering. reportValidity() shows
                // the browser's native (localized) prompt and focuses the first
                // offending field.
                if (!form.reportValidity()) return;
                stepData[currentStepIndex] = [readForm(form)];
            }

            if (returnToReview) {
                returnToReview = false;
                goToStep(workflow.steps.length);
            } else {
                goToStep(currentStepIndex + 1);
            }
        });

        wrapper.appendChild(form);
        page.appendChild(wrapper);
        containerEl.appendChild(page);
    }

    // Review screen: shows every buffered step and its records, lets the user
    // jump back to edit any step, and performs the actual save on confirm.
    function renderReview() {
        renderStepBar();
        titleEl.textContent = workflow.title;
        containerEl.textContent = '';

        const page = document.createElement('div');
        page.className = 'form-page wf-form-page';

        workflow.steps.forEach((step, i) => {
            const records = stepData[i] || [];
            const meta = stepMeta[i];

            const card = document.createElement('div');
            card.className = 'form-wrapper wf-review-card';

            const head = document.createElement('div');
            head.className = 'wf-review-head';
            const h3 = document.createElement('h3');
            h3.textContent = step.title || `Step ${i + 1}`;
            const editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'btn btn-sm';
            editBtn.textContent = I18n.t('common.edit');
            editBtn.addEventListener('click', () => {
                returnToReview = true;
                goToStep(i);
            });
            head.appendChild(h3);
            head.appendChild(editBtn);
            card.appendChild(head);

            if (records.length === 0) {
                const p = document.createElement('p');
                p.className = 'wf-review-empty';
                p.textContent = I18n.t('form.no_records');
                card.appendChild(p);
            } else {
                records.forEach((snap) => {
                    const dl = document.createElement('dl');
                    dl.className = 'wf-review-fields';
                    const cols = meta?.tableSchema?.columns || {};
                    for (const [colName, colDef] of Object.entries(cols)) {
                        const type = (colDef.type || '').toLowerCase();
                        if (colName === 'id' || colDef.readonly || type === 'virtual') continue;
                        if (step.foreign_key === colName && step.link_to_step !== undefined && step.link_to_step !== '') continue;
                        let val = snap[colName];
                        if (type.includes('bool')) {
                            val = val ? '✓' : '✗';
                        } else if (val === undefined || val === '') {
                            continue;
                        }
                        const dt = document.createElement('dt');
                        dt.textContent = colDef.display_name || colName;
                        const dd = document.createElement('dd');
                        dd.textContent = String(val);
                        dl.appendChild(dt);
                        dl.appendChild(dd);
                    }
                    card.appendChild(dl);
                });
            }
            page.appendChild(card);
        });

        const actions = document.createElement('div');
        actions.className = 'form-actions';

        const backBtn = document.createElement('button');
        backBtn.type = 'button';
        backBtn.className = 'btn-cancel';
        backBtn.textContent = I18n.t('pagination.prev');
        backBtn.addEventListener('click', () => goToStep(workflow.steps.length - 1));

        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'btn-save';
        saveBtn.textContent = I18n.t('form.save');

        const msg = document.createElement('div');
        msg.className = 'wf-form-msg';

        saveBtn.addEventListener('click', () => saveAll(saveBtn, backBtn, msg));

        actions.appendChild(backBtn);
        actions.appendChild(saveBtn);
        page.appendChild(actions);
        page.appendChild(msg);
        containerEl.appendChild(page);
    }

    // Persist every buffered record in step order. Foreign keys that link to a
    // previous step are injected from that step's first saved id. This is NOT
    // atomic — records saved before a mid-run failure remain (same as the old
    // per-step model). savedRecords guards against re-inserting them on retry.
    async function saveAll(saveBtn, backBtn, msgEl) {
        saveBtn.disabled = true;
        if (backBtn) backBtn.disabled = true;
        saveBtn.textContent = I18n.t('workflow.saving');
        msgEl.textContent = '';

        try {
            for (let i = 0; i < workflow.steps.length; i++) {
                const step = workflow.steps[i];
                const meta = stepMeta[i];
                const records = stepData[i] || [];
                if (!meta || records.length === 0) continue;

                for (const snap of records) {
                    if (savedRecords.has(snap)) continue;

                    const payload = buildPayload(meta.tableSchema, step, snap);

                    // Inject the foreign key from a previous step's saved id.
                    if (step.foreign_key && step.link_to_step !== undefined && step.link_to_step !== '') {
                        const linkIndex = parseInt(step.link_to_step, 10);
                        if (stepResults[linkIndex] !== undefined) {
                            payload[step.foreign_key] = stepResults[linkIndex];
                        }
                    }

                    const response = await apiFetch('api.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: { table: step.table, data: payload }
                    });

                    // Read raw text first to intercept server-side HTML errors.
                    const rawText = await response.text();
                    let result;
                    try {
                        result = JSON.parse(rawText);
                    } catch {
                        console.error('RAW SERVER RESPONSE:', rawText);
                        const cleanError = rawText.replace(/<\/?[^>]+(>|$)/g, '').trim();
                        throw new Error(I18n.t('workflow.server_error', { msg: cleanError.substring(0, 150) }));
                    }

                    const isSuccess = result.ok === true || result.status === 'success' || result.success === true;
                    if (!isSuccess || !result.id) {
                        throw new Error(result.error || result.message || I18n.t('workflow.unknown_save_error'));
                    }

                    savedRecords.add(snap);
                    if (stepResults[i] === undefined) stepResults[i] = result.id;
                }
            }

            const bar = document.getElementById('wf-step-bar');
            if (bar) bar.remove();
            renderSuccessScreen();
        } catch (err) {
            console.error(err);
            showToast(I18n.t('workflow.save_error', { msg: err.message }), 'error');
            saveBtn.disabled = false;
            if (backBtn) backBtn.disabled = false;
            saveBtn.textContent = I18n.t('form.save');
        }
    }

    // Render the final success screen centered using DOM methods
    function renderSuccessScreen() {
        titleEl.textContent = I18n.t('workflow.completed_title');
        containerEl.textContent = ''; // Safely clear container

        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'margin: 60px auto; padding: 0 20px; text-align: center; max-width: 500px;';

        const heading = document.createElement('h2');
        heading.style.cssText = 'color: var(--ok); margin-top: 0; font-size: 28px;';
        heading.textContent = I18n.t('workflow.success');

        const paragraph = document.createElement('p');
        paragraph.style.cssText = 'color: var(--text); font-size: 15px; line-height: 1.6;';
        
        // Safely build mixed text and HTML elements
        const textStart = document.createTextNode(I18n.t('workflow.success_before') + ' ');
        const boldTitle = document.createElement('b');
        boldTitle.textContent = workflow.title;
        const textEnd = document.createTextNode(' ' + I18n.t('workflow.success_after'));
        
        paragraph.appendChild(textStart);
        paragraph.appendChild(boldTitle);
        paragraph.appendChild(textEnd);

        const finishBtn = document.createElement('button');
        finishBtn.id = 'wf-finish-btn';
        finishBtn.style.cssText = 'margin-top: 24px; padding: 10px 24px; background: var(--accent); color: white; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; box-shadow: var(--shadow-sm); transition: background var(--transition);';
        finishBtn.textContent = I18n.t('workflow.finish_return');

        finishBtn.addEventListener('mouseenter', () => finishBtn.style.background = 'var(--accent-dark)');
        finishBtn.addEventListener('mouseleave', () => finishBtn.style.background = 'var(--accent)');
        finishBtn.addEventListener('click', () => renderWorkflowsList(allWorkflows, containerEl, titleEl, menuName, appSchema));

        wrapper.appendChild(heading);
        wrapper.appendChild(paragraph);
        wrapper.appendChild(finishBtn);
        
        containerEl.appendChild(wrapper);
    }

    // Start the first step
    renderCurrentStep();
}