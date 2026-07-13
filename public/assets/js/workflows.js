// assets/js/workflows.js — Loads workflow/automation config from api.php?api=workflows (CSRF + X-Requested-With) and renders the workflows UI. Toasts on error.

import { showToast } from './toast.js';
import { I18n } from './i18n.js';

// Fetch workflows configuration from backend
async function fetchWorkflowsConfig() {
    try {
        // Add CSRF header to prevent cross-site request forgery
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
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
        cardDesc.textContent = wf.description || 'No description provided for this workflow.';

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
    const stepResults = {};

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
            const bar = document.getElementById('wf-step-bar');
            if (bar) bar.remove();
            renderSuccessScreen();
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
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
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
            errorMsg.style.cssText = 'color: red; text-align: center; margin-top: 40px;';
            errorMsg.textContent = `Error: Schema for table '${step.table}' not found.`;
            containerEl.appendChild(errorMsg);
            return;
        }

        // Pre-fetch FK options for all non-injected FK columns in parallel
        const fkOptionMap = {}; // { colName: [{value, label}] }
        const fkCfgMap = tableSchema.foreign_keys || {};
        const csrfForFk = document.querySelector('meta[name="csrf-token"]')?.content || '';

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
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
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

        const form = document.createElement('form');
        // Center the form and remove boxy styling to eliminate "white space" nesting
        form.style.maxWidth = '500px';
        form.style.margin = '8px auto 40px';
        form.style.width = '100%';
        form.style.padding = '0 20px 40px 20px';

        // Render step title prominently inside the form
        if (step.title && step.title.trim() !== '') {
            const stepTitleEl = document.createElement('h2');
            stepTitleEl.style.marginTop = '0';
            stepTitleEl.style.marginBottom = '8px';
            stepTitleEl.style.color = 'var(--accent-dark)';
            stepTitleEl.style.fontSize = '22px';
            stepTitleEl.textContent = step.title;
            form.appendChild(stepTitleEl);
        }

        // Render step description if provided in admin
        if (step.description && step.description.trim() !== '') {
            const descEl = document.createElement('p');
            descEl.style.color = 'var(--muted)';
            descEl.style.fontSize = '14px';
            descEl.style.marginTop = '0';
            descEl.style.marginBottom = '24px';
            descEl.style.lineHeight = '1.5';
            descEl.textContent = step.description;
            form.appendChild(descEl);
        } else {
            // Add some spacing if there is no description but there is a title
            const spacer = document.createElement('div');
            spacer.style.height = '16px';
            form.appendChild(spacer);
        }

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
            formGroup.style.marginBottom = '18px';

            const label = document.createElement('label');
            label.textContent = colDef.display_name || colName;
            label.style.display = 'block';
            label.style.marginBottom = '6px';
            label.style.fontWeight = '500';
            label.style.color = 'var(--text)';
            label.style.fontSize = '13.5px';

            let input;
            let isVirtual = false;
            const type = (colDef.type || '').toLowerCase();

            // Render virtual column as live-calculated readonly display
            if (type === 'virtual') {
                isVirtual = true;
                input = document.createElement('input');
                input.type = 'text';
                input.readOnly = true;
                input.tabIndex = -1;
                input.dataset.virtual = colName;
                input.style.background = '#f1f5f9';
                input.style.color = 'var(--muted)';
                input.style.cursor = 'default';
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
            } else if (type.includes('date')) {
                input = document.createElement('input');
                input.type = 'date';
            } else {
                input = document.createElement('input');
                input.type = 'text';
            }

            input.name = colName;
            input.style.width = '100%';
            input.style.padding = '10px 12px';
            input.style.boxSizing = 'border-box';
            input.style.border = '1px solid var(--border)';
            input.style.borderRadius = 'var(--radius)';
            input.style.fontSize = '14px';
            if (!isVirtual) {
                input.style.color = 'var(--text)';
                input.style.transition = 'border-color var(--transition), box-shadow var(--transition)';
                input.style.background = '#fff';
                input.addEventListener('focus', () => {
                    input.style.borderColor = 'var(--accent)';
                    input.style.outline = 'none';
                    input.style.boxShadow = '0 0 0 2px var(--accent-light)';
                });
                input.addEventListener('blur', () => {
                    input.style.borderColor = 'var(--border)';
                    input.style.boxShadow = 'none';
                });
            }

            if (type.includes('bool')) {
                input.style.width = 'auto';
            }

            formGroup.appendChild(label);
            formGroup.appendChild(input);
            form.appendChild(formGroup);
        }

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
            filterRow.style.cssText = 'display:flex; align-items:center; gap:6px; margin-top:6px; font-size:12px; color:var(--muted); cursor:pointer; user-select:none;';
            const filterCb = document.createElement('input');
            filterCb.type = 'checkbox';
            filterCb.style.cssText = 'cursor:pointer; margin:0;';
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

        // Add action buttons
        const btnContainer = document.createElement('div');
        btnContainer.style.marginTop = '24px';
        btnContainer.style.display = 'flex';
        btnContainer.style.gap = '12px';

        const submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.dataset.action = step.allow_multiple ? 'add' : 'continue';
        submitBtn.textContent = step.allow_multiple ? I18n.t('form.save_add_another') : I18n.t('form.next_step');
        submitBtn.style.cssText = 'padding: 10px 20px; background: var(--accent); color: white; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; box-shadow: var(--shadow-sm); transition: background var(--transition);';
        submitBtn.addEventListener('mouseenter', () => submitBtn.style.background = 'var(--accent-dark)');
        submitBtn.addEventListener('mouseleave', () => submitBtn.style.background = 'var(--accent)');
        btnContainer.appendChild(submitBtn);

        // For multi-record steps: "Save & Exit" saves current entry (or skips if empty) then advances
        let continueBtn = null;
        if (step.allow_multiple) {
            continueBtn = document.createElement('button');
            continueBtn.type = 'submit';
            continueBtn.dataset.action = 'continue';
            continueBtn.textContent = I18n.t('form.save_exit');
            continueBtn.style.cssText = 'padding: 10px 20px; background: transparent; color: var(--muted); border: 1px solid var(--border); border-radius: var(--radius); cursor: pointer; font-weight: 600; transition: all var(--transition);';
            continueBtn.addEventListener('mouseenter', () => { continueBtn.style.color = 'var(--text)'; continueBtn.style.borderColor = 'var(--muted)'; continueBtn.style.background = '#f8fafc'; });
            continueBtn.addEventListener('mouseleave', () => { continueBtn.style.color = 'var(--muted)'; continueBtn.style.borderColor = 'var(--border)'; continueBtn.style.background = 'transparent'; });
            btnContainer.appendChild(continueBtn);
        }
        const finishBtn = continueBtn; // alias kept for error-reset reference below

        form.appendChild(btnContainer);

        const msgBox = document.createElement('div');
        msgBox.style.marginTop = '15px';
        msgBox.style.fontSize = '14px';
        msgBox.style.fontWeight = '500';
        form.appendChild(msgBox);

        // Handle form submission and data saving
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const action = e.submitter?.dataset?.action || 'continue';
            submitBtn.disabled = true;
            if (continueBtn) continueBtn.disabled = true;
            e.submitter.textContent = I18n.t('workflow.saving');
            msgBox.textContent = '';

            const payload = {};
            
            // Extract values from the form inputs securely
            for (const [colName, colDef] of Object.entries(tableSchema.columns)) {
                if (colName === 'id' || colDef.readonly || (colDef.type || '').toLowerCase() === 'virtual') continue;

                if (step.foreign_key === colName && step.link_to_step !== undefined && step.link_to_step !== "") {
                    continue;
                }
                
                const inputEl = form.querySelector(`[name="${colName}"]`);
                if (!inputEl) continue;

                if (inputEl.type === 'checkbox') {
                    payload[colName] = inputEl.checked;
                } else {
                    if (inputEl.value !== "") {
                        payload[colName] = inputEl.value;
                    }
                }
            }

            // Automatically inject the foreign key from a previous step if required
            if (step.foreign_key && step.link_to_step !== undefined && step.link_to_step !== "") {
                const linkIndex = parseInt(step.link_to_step, 10);
                if (stepResults[linkIndex]) {
                    payload[step.foreign_key] = stepResults[linkIndex];
                }
            }

            try {
                // Add CSRF header to API request
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        table: step.table,
                        data: payload
                    })
                });

                // Fetch raw text first to intercept server-side HTML errors
                const rawText = await response.text();
                let result;

                try {
                    result = JSON.parse(rawText);
                } catch (parseError) {
                    console.error("RAW SERVER RESPONSE:", rawText);
                    const cleanError = rawText.replace(/<\/?[^>]+(>|$)/g, "").trim();
                    throw new Error(`Server Error (PHP/SQL): \n\n${cleanError.substring(0, 150)}... \n\n(Check F12 console for full log)`);
                }
                
                const isSuccess = result.ok === true || result.status === 'success' || result.success === true;

                if (isSuccess && result.id) {
                    if (!stepResults[currentStepIndex]) {
                        stepResults[currentStepIndex] = result.id;
                    }

                    if (action === 'add') {
                        form.reset();
                        refreshVirtuals();
                        const successSpan = document.createElement('span');
                        successSpan.style.color = 'var(--ok)';
                        successSpan.textContent = I18n.t('workflow.record_saved_next');
                        msgBox.appendChild(successSpan);
                        submitBtn.disabled = false;
                        if (continueBtn) continueBtn.disabled = false;
                        submitBtn.textContent = I18n.t('form.save_add_another');
                    } else {
                        currentStepIndex++;
                        renderCurrentStep();
                    }
                } else {
                    throw new Error(result.error || result.message || 'Unknown error occurred while saving.');
                }
            } catch (err) {
                console.error(err);
                showToast(`Error saving data: ${err.message}`, 'error');
                submitBtn.disabled = false;
                if (continueBtn) continueBtn.disabled = false;
                submitBtn.textContent = step.allow_multiple ? I18n.t('form.save_add_another') : I18n.t('form.next_step');
                if (e.submitter) e.submitter.textContent = e.submitter.dataset.action === 'add' ? I18n.t('form.save_add_another') : (step.allow_multiple ? I18n.t('form.save_exit') : I18n.t('form.next_step'));
            }
        });

        containerEl.appendChild(form);
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