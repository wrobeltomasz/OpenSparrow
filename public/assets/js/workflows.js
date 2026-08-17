// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { showToast } from './toast.js';
import { I18n } from './i18n.js';
import { getCsrfToken } from './util/csrf.js';
import { apiFetch } from './util/api.js';

async function fetchWorkflowsConfig() {
    try {
        const csrfToken = getCsrfToken();
        const result = await fetch('api.php?api=workflows', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            }
        });
        if (!result.ok) throw new Error('Network response was not ok');
        return await result.json();
    } catch (e) {
        console.warn('Could not load workflows config', e);
        return null;
    }
}

function createIconElement(iconPath, fallbackColor = 'var(--accent)') {
    if (!iconPath) {
        const div = document.createElement('div');
        div.style.cssText = `width:20px; height:20px; background:${fallbackColor}; border-radius:50%; margin-right:8px; display:inline-block; vertical-align:middle;`;
        return div;
    }
    const image = document.createElement('img');
    image.src = iconPath;
    image.alt = '';
    image.style.cssText = 'width:20px; height:20px; vertical-align:middle; margin-right:8px; object-fit:contain;';
    return image;
}

export async function initWorkflows(menuListElement, containerElement, titleElement, appSchema) {
    const config = await fetchWorkflowsConfig();

    if (!config || !config.workflows || config.workflows.length === 0) {
        return false;
    }

    if (config.hidden === true) {
        return false;
    }

    document.addEventListener("tableLoaded", () => {
        const bar = document.getElementById('wf-step-bar');
        if (bar) bar.remove();

        const gridUI = document.querySelectorAll('.actions, #filterBar, #globalSearch, #columnFilter, #addRow');
        gridUI.forEach(element => element.style.display = '');
    });

    const menuName = config.menu_name || 'Workflows';

    const menuRoot = menuListElement.closest('#menu') ?? menuListElement;
    const workflowLink = menuRoot.querySelector('a[data-page="workflows"]');

    const hideGridUi = () => {
        const uiToHide = document.querySelectorAll('.actions, #filterBar, #globalSearch, #columnFilter, #clearFilters, #addRow');
        uiToHide.forEach(element => element.style.display = 'none');
    };

    const activateLink = (link) => {
        menuRoot.querySelectorAll('a').forEach(l => l.classList.remove('active'));
        if (link) link.classList.add('active');
    };

    if (workflowLink) {
        workflowLink.addEventListener('click', (e) => {
            e.preventDefault();
            activateLink(workflowLink);
            hideGridUi();
            renderWorkflowsList(config.workflows, containerElement, titleElement, menuName, appSchema);
        });
    }

    const workflowChildLinks = menuRoot.querySelectorAll('a[data-workflow-id]');
    workflowChildLinks.forEach((link) => {
        link.addEventListener('click', (e) => {
            const workflow = config.workflows.find(w => w.id === link.dataset.workflowId);
            if (!workflow) return;
            e.preventDefault();
            activateLink(link);
            hideGridUi();
            startWorkflow(workflow, containerElement, titleElement, appSchema, config.workflows, menuName);
        });
    });

    const urlParameters = new URLSearchParameters(window.location.search);
    if (urlParameters.has('workflows')) {
        const workflowId = urlParameters.get('workflow') || '';
        const workflow = workflowId ? config.workflows.find(w => w.id === workflowId) : null;
        const matchingChildLink = workflow
            ? menuRoot.querySelector(`a[data-workflow-id="${CSS.escape(workflow.id)}"]`)
            : null;

        activateLink(matchingChildLink || workflowLink);
        hideGridUi();

        if (workflow) {
            startWorkflow(workflow, containerElement, titleElement, appSchema, config.workflows, menuName);
        } else {
            renderWorkflowsList(config.workflows, containerElement, titleElement, menuName, appSchema);
        }
        return true;
    }

    return false;
}

function renderWorkflowsList(workflows, containerElement, titleElement, menuName, appSchema) {
    const staleBar = document.getElementById('wf-step-bar');
    if (staleBar) staleBar.remove();

    titleElement.textContent = menuName;
    containerElement.textContent = '';

    const listContainer = document.createElement('div');
    listContainer.style.display = 'grid';
    listContainer.style.gridTemplateColumns = 'repeat(auto-fill, minmax(320px, 1fr))';
    listContainer.style.gap = '24px';
    listContainer.style.padding = '24px';

    workflows.forEach(workflow => {
        const card = document.createElement('div');

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

        if (workflow.icon) {
            const image = document.createElement('img');
            image.src = workflow.icon;
            image.alt = '';
            image.style.cssText = 'width:22px; height:22px; object-fit:contain;';
            iconWrapper.appendChild(image);
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
        cardTitle.textContent = workflow.title;

        header.appendChild(iconWrapper);
        header.appendChild(cardTitle);

        const cardDescription = document.createElement('p');
        cardDescription.style.color = 'var(--muted)';
        cardDescription.style.fontSize = '14px';
        cardDescription.style.margin = '0 0 20px 0';
        cardDescription.style.lineHeight = '1.5';
        cardDescription.style.flexGrow = '1';
        cardDescription.textContent = workflow.description || I18n.t('workflow.no_description');

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
        const validStepCount = (workflow.steps || []).filter(s => s && s.table).length;
        stepCount.textContent = I18n.t('workflow.steps', { count: validStepCount }, validStepCount);

        const startButton = document.createElement('span');
        startButton.style.fontSize = '13.5px';
        startButton.style.color = 'var(--accent)';
        startButton.style.fontWeight = '600';
        startButton.textContent = I18n.t('workflow.start');

        footer.appendChild(stepCount);
        footer.appendChild(startButton);

        card.appendChild(header);
        card.appendChild(cardDescription);
        card.appendChild(footer);

        card.addEventListener('click', () => startWorkflow(workflow, containerElement, titleElement, appSchema, workflows, menuName));

        listContainer.appendChild(card);
    });

    containerElement.appendChild(listContainer);
}

function startWorkflow(workflow, containerElement, titleElement, appSchema, allWorkflows, menuName) {
    workflow = { ...workflow, steps: (workflow.steps || []).filter(s => s && s.table) };

    let currentStepIndex = 0;

    const stepData = [];
    const stepMeta = [];
    const stepResults = {};
    const savedRecords = new Set();

    let returnToReview = false;

    function goToStep(i) {
        currentStepIndex = i;
        if (currentStepIndex >= workflow.steps.length) {
            renderReview();
        } else {
            renderCurrentStep();
        }
    }

    function readForm(form) {
        const snap = {};
        form.querySelectorAll('[name]').forEach((element) => {
            snap[element.name] = element.type === 'checkbox' ? element.checked : element.value;
        });
        return snap;
    }

    function writeForm(form, snap) {
        if (!snap) return;
        Object.entries(snap).forEach(([name, value]) => {
            const element = form.querySelector(`[name="${CSS.escape(name)}"]`);
            if (!element) return;
            if (element.type === 'checkbox') element.checked = !!value;
            else element.value = value ?? '';
        });
    }

    function buildPayload(tableSchema, step, snap) {
        const payload = {};
        for (const [columnName, columnDef] of Object.entries(tableSchema.columns)) {
            const type = (columnDef.type || '').toLowerCase();
            if (columnName === 'id' || columnDef.readonly || type === 'virtual') continue;
            if (step.foreign_key === columnName && step.link_to_step !== undefined && step.link_to_step !== '') continue;
            const raw = snap[columnName];
            if (type.includes('bool')) {
                payload[columnName] = !!raw;
            } else if (raw !== undefined && raw !== '') {
                payload[columnName] = (type.includes('timestamp') || type.includes('datetime'))
                    ? String(raw).replace('T', ' ')
                    : raw;
            }
        }
        return payload;
    }

    function renderStepBar() {
        let bar = document.getElementById('wf-step-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'wf-step-bar';
            bar.style.cssText = 'display:flex; align-items:center; justify-content:center; flex-wrap:wrap; gap:0; margin:32px auto 28px; max-width:700px; padding:0 20px;';
            containerElement.parentNode.insertBefore(bar, containerElement);
        }
        bar.textContent = '';

        workflow.steps.forEach((s, i) => {
            const done      = i < currentStepIndex;
            const current   = i === currentStepIndex;
            const labelText = s.title || `Step ${i + 1}`;

            const pill = document.createElement('div');
            pill.style.cssText = [
                'display:flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; font-size:13px; font-weight:600; white-space:nowrap; transition:all .2s;',
                done    ? 'background:var(--ok-light); color:var(--ok);'  :
                current ? 'background:var(--accent); color:#fff;' :
                          'background:var(--border-light); color:var(--muted);'
            ].join('');

            const dot = document.createElement('span');
            dot.style.cssText = `width:8px; height:8px; border-radius:50%; background:${done ? 'var(--ok)' : current ? '#fff' : 'var(--border)'};`;
            const label = document.createElement('span');
            label.textContent = labelText;

            pill.append(dot, label);
            bar.appendChild(pill);

            if (i < workflow.steps.length - 1) {
                const arrow = document.createElement('span');
                arrow.textContent = '→';
                arrow.style.cssText = 'color:var(--border); font-size:14px; padding:0 2px; flex-shrink:0;';
                bar.appendChild(arrow);
            }
        });
    }

    async function renderCurrentStep() {
        if (currentStepIndex >= workflow.steps.length) {
            renderReview();
            return;
        }

        const step = workflow.steps[currentStepIndex];

        renderStepBar();

        titleElement.textContent = I18n.t('workflow.step_of', { title: workflow.title, current: currentStepIndex + 1, total: workflow.steps.length });
        containerElement.textContent = '';

        let activeSchema = appSchema;
        if (!activeSchema && typeof window !== 'undefined' && window.schema) {
            activeSchema = window.schema;
        }

        if (!activeSchema) {
            try {
                const csrfToken = getCsrfToken();
                const result = await fetch('api.php?api=schema', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    }
                });
                if (result.ok) activeSchema = await result.json();
            } catch (error) {
                console.warn('Could not fetch schema dynamically', error);
            }
        }

        let tableSchema = activeSchema?.tables?.[step.table];
        if (!tableSchema && activeSchema?.tables) {
            const key = Object.keys(activeSchema.tables).find(k => k.toLowerCase() === step.table.toLowerCase());
            if (key) tableSchema = activeSchema.tables[key];
        }

        let fullSchema = activeSchema;

        if (!tableSchema) {
            try {
                const result = await fetch('api/schema.php?include_hidden=1', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const full = await result.json();
                fullSchema = full;
                tableSchema = full.tables?.[step.table];
                if (!tableSchema && full.tables) {
                    const key = Object.keys(full.tables).find(k => k.toLowerCase() === step.table.toLowerCase());
                    if (key) tableSchema = full.tables[key];
                }
            } catch {  }
        }

        if (!tableSchema) {
            const errorMessage = document.createElement('p');
            errorMessage.style.cssText = 'color: var(--error); text-align: center; margin-top: 40px;';
            errorMessage.textContent = I18n.t('workflow.schema_not_found', { table: step.table });
            containerElement.appendChild(errorMessage);
            return;
        }

        stepMeta[currentStepIndex] = { tableSchema };

        const imagesConfig = tableSchema.images && tableSchema.images.enabled ? tableSchema.images : null;
        let pendingImages = [];

        const fkOptionMap = {};
        const fkConfigMap = tableSchema.foreign_keys || {};
        const csrfForFk = getCsrfToken();

        await Promise.all(
            Object.entries(fkConfigMap).map(async ([columnName, fkDef]) => {
                if (step.foreign_key === columnName && step.link_to_step !== undefined && step.link_to_step !== '') {
                    return;
                }
                const referenceColumn = fkDef.reference_column || 'id';

                const rawDisp = (Array.isArray(fkDef.display_columns) && fkDef.display_columns.length > 0)
                    ? fkDef.display_columns : (fkDef.display_column ?? '');
                const dispColumns = Array.isArray(rawDisp)
                    ? rawDisp
                    : String(rawDisp).split(',').map(s => s.trim()).filter(Boolean);

                try {
                    const result = await fetch(
                        `api/fk.php?table=${encodeURIComponent(step.table)}&col=${encodeURIComponent(columnName)}`,
                        { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfForFk } }
                    );
                    const data = await result.json();
                    const rows = data.rows || [];
                    fkOptionMap[columnName] = rows.map(row => {
                        const label = dispColumns.length
                            ? dispColumns.map(c => row[c] ?? '').filter(Boolean).join(' ')
                            : String(row[referenceColumn] ?? row.id ?? '');
                        return { value: row[referenceColumn] ?? row.id, label: label || String(row[referenceColumn] ?? row.id) };
                    });
                } catch {
                    fkOptionMap[columnName] = [];
                }
            })
        );

        async function fetchFkOptions(columnName, filterColumn = '', filterValue = '') {
            const csrf = getCsrfToken();
            let url = `api/fk.php?table=${encodeURIComponent(step.table)}&col=${encodeURIComponent(columnName)}`;
            if (filterColumn && filterValue !== '') {
                url += `&filter_col=${encodeURIComponent(filterColumn)}&filter_val=${encodeURIComponent(filterValue)}`;
            }
            try {
                const result = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf } });
                const data = await result.json();
                const rows = data.rows || [];
                const fkDef = fkConfigMap[columnName] || {};
                const referenceColumn = fkDef.reference_column || 'id';
                const rawDisp = (Array.isArray(fkDef.display_columns) && fkDef.display_columns.length > 0)
                    ? fkDef.display_columns : (fkDef.display_column ?? '');
                const dispColumns = Array.isArray(rawDisp) ? rawDisp : String(rawDisp).split(',').map(s => s.trim()).filter(Boolean);
                return rows.map(row => {
                    const label = dispColumns.length
                        ? dispColumns.map(c => row[c] ?? '').filter(Boolean).join(' ')
                        : String(row[referenceColumn] ?? row.id ?? '');
                    return { value: row[referenceColumn] ?? row.id, label: label || String(row[referenceColumn] ?? row.id) };
                });
            } catch {
                return [];
            }
        }

        function rebuildSelect(selectElement, options) {
            const previous = selectElement.value;
            selectElement.textContent = '';
            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = I18n.t('workflow.select_blank');
            selectElement.appendChild(blank);
            options.forEach(({ value, label }) => {
                const option = document.createElement('option');
                option.value = String(value);
                option.textContent = label;
                selectElement.appendChild(option);
            });
            selectElement.value = options.some(o => String(o.value) === previous) ? previous : '';
        }

        const page = document.createElement('div');
        page.className = 'form-page wf-form-page';

        if (step.title && step.title.trim() !== '') {
            const stepTitleElement = document.createElement('h2');
            stepTitleElement.className = 'wf-step-title';
            const tableIcon = tableSchema.icon;
            if (tableIcon) {
                if (tableIcon.includes('/') || tableIcon.includes('.')) {
                    const iconImage = document.createElement('img');
                    iconImage.src = tableIcon;
                    iconImage.alt = '';
                    iconImage.className = 'wf-step-title-icon';
                    iconImage.onerror = () => iconImage.remove();
                    stepTitleElement.appendChild(iconImage);
                } else {
                    const iconSpan = document.createElement('span');
                    iconSpan.className = 'wf-step-title-icon';
                    iconSpan.textContent = tableIcon;
                    stepTitleElement.appendChild(iconSpan);
                }
            }
            stepTitleElement.appendChild(document.createTextNode(step.title));
            page.appendChild(stepTitleElement);
        }

        if (step.description && step.description.trim() !== '') {
            const descriptionElement = document.createElement('p');
            descriptionElement.className = 'wf-step-desc';
            descriptionElement.textContent = step.description;
            page.appendChild(descriptionElement);
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'form-wrapper';

        const form = document.createElement('form');
        form.className = 'editor-form';

        const grid = document.createElement('div');
        grid.className = 'form-grid';

        const virtualFields = {};

        function calcVirtualValue(formula) {
            const ops = formula.cols || [];
            const values = ops.map(c => {
                const element = form.querySelector(`[name="${c}"]`);
                return parseFloat(element?.value ?? 0) || 0;
            });
            switch (formula.op) {
                case 'multiply':  return values.reduce((a, b) => a * b, 1);
                case 'add':       return values.reduce((a, b) => a + b, 0);
                case 'subtract':  return values.length >= 2 ? values[0] - values.slice(1).reduce((a, b) => a + b, 0) : 0;
                case 'divide':    return values.length >= 2 && values[1] !== 0 ? values[0] / values[1] : 0;
                default:          return 0;
            }
        }

        function refreshVirtuals() {
            for (const [, vf] of Object.entries(virtualFields)) {
                const result = calcVirtualValue(vf.formula);
                vf.el.value = Number.isInteger(result) ? result : parseFloat(result.toFixed(4));
            }
        }

        for (const [columnName, columnDef] of Object.entries(tableSchema.columns)) {
            if (columnName === 'id' || columnDef.readonly || columnDef.show_in_edit === false) continue;

            if (step.foreign_key === columnName && step.link_to_step !== undefined && step.link_to_step !== "") {
                continue;
            }

            const formGroup = document.createElement('div');
            formGroup.className = 'form-group';

            const label = document.createElement('label');
            label.textContent = columnDef.display_name || columnName;

            let input;
            const type = (columnDef.type || '').toLowerCase();

            if (columnDef.not_null && type !== 'virtual' && !columnDef.readonly) {
                const request = document.createElement('span');
                request.className = 'required';
                request.textContent = ' *';
                label.appendChild(request);
            }

            if (type === 'virtual') {
                input = document.createElement('input');
                input.type = 'text';
                input.readOnly = true;
                input.tabIndex = -1;
                input.dataset.virtual = columnName;
                virtualFields[columnName] = { el: input, formula: columnDef.formula || {} };
            } else if (Object.prototype.hasOwnProperty.call(fkOptionMap, columnName)) {
                input = document.createElement('select');
                const blankOption = document.createElement('option');
                blankOption.value = '';
                blankOption.textContent = I18n.t('workflow.select_blank');
                input.appendChild(blankOption);
                (fkOptionMap[columnName] || []).forEach(({ value, label }) => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = label;
                    input.appendChild(option);
                });
            } else if (type === 'enum' && Array.isArray(columnDef.options)) {
                input = document.createElement('select');
                const defaultOption = document.createElement('option');
                defaultOption.value = '';
                defaultOption.textContent = I18n.t('workflow.select_blank');
                input.appendChild(defaultOption);

                columnDef.options.forEach(optionValue => {
                    const option = document.createElement('option');
                    option.value = optionValue;
                    option.textContent = optionValue;
                    input.appendChild(option);
                });
            } else if (type.includes('bool')) {
                input = document.createElement('input');
                input.type = 'checkbox';
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

            input.name = columnName;

            if (type.includes('bool')) {
                input.classList.add('wf-checkbox');
            }

            if (columnDef.not_null && type !== 'virtual' && !columnDef.readonly && !type.includes('bool')) {
                input.required = true;
            }

            formGroup.appendChild(label);
            formGroup.appendChild(input);
            grid.appendChild(formGroup);
        }

        form.appendChild(grid);

        let imageStatusElement = null;
        if (imagesConfig) {
            const imageGroup = document.createElement('div');
            imageGroup.className = 'form-group';

            const imageLabel = document.createElement('label');
            imageLabel.textContent = imagesConfig.label || I18n.t('images.label');

            const imageInput = document.createElement('input');
            imageInput.type = 'file';
            imageInput.accept = 'image/*';
            imageInput.multiple = imagesConfig.max_per_record > 1;
            imageInput.className = 'ef-upload-input';

            imageStatusElement = document.createElement('span');
            imageStatusElement.className = 'ef-upload-status';
            imageStatusElement.textContent = I18n.t('workflow.image_limit', { max: imagesConfig.max_per_record });

            imageInput.addEventListener('change', () => {
                pendingImages = Array.from(imageInput.files || []).slice(0, imagesConfig.max_per_record);
                imageStatusElement.textContent = pendingImages.length
                    ? I18n.t('workflow.images_selected', { n: pendingImages.length }, pendingImages.length)
                    : I18n.t('workflow.image_limit', { max: imagesConfig.max_per_record });
            });

            imageGroup.appendChild(imageLabel);
            imageGroup.appendChild(imageInput);
            imageGroup.appendChild(imageStatusElement);
            form.appendChild(imageGroup);
        }

        function snapshotWithImages() {
            const snap = readForm(form);
            if (imagesConfig) snap.__images = pendingImages;
            return snap;
        }

        const fkLinkMap = {};
        for (const [columnName, fkDef] of Object.entries(fkConfigMap)) {
            const referenceTableName = fkDef.reference_table;
            if (!referenceTableName) continue;
            let referenceSchema = fullSchema?.tables?.[referenceTableName];
            if (!referenceSchema && fullSchema?.tables) {
                const k = Object.keys(fullSchema.tables).find(t => t.toLowerCase() === referenceTableName.toLowerCase());
                if (k) referenceSchema = fullSchema.tables[k];
            }
            if (!referenceSchema?.foreign_keys) continue;
            for (const referenceFkColumn of Object.keys(referenceSchema.foreign_keys)) {
                if (referenceFkColumn !== columnName && fkConfigMap[referenceFkColumn]) {
                    fkLinkMap[columnName] = referenceFkColumn;
                    break;
                }
            }
        }

        for (const [depColumn, masterColumn] of Object.entries(fkLinkMap)) {
            const selectElement = form.querySelector(`[name="${depColumn}"]`);
            const masterElement = form.querySelector(`[name="${masterColumn}"]`);
            if (!selectElement || !masterElement) continue;

            const filterRow = document.createElement('label');
            filterRow.className = 'wf-related-toggle';
            const filterCallback = document.createElement('input');
            filterCallback.type = 'checkbox';
            filterCallback.className = 'wf-checkbox';
            const filterLabel = document.createElement('span');
            filterLabel.textContent = I18n.t('workflow.show_related');
            filterRow.appendChild(filterCallback);
            filterRow.appendChild(filterLabel);
            selectElement.parentElement.appendChild(filterRow);

            const applyFilter = async () => {
                const masterValue = masterElement.value;
                const options = (filterCallback.checked && masterValue)
                    ? await fetchFkOptions(depColumn, masterColumn, masterValue)
                    : await fetchFkOptions(depColumn);
                rebuildSelect(selectElement, options);
            };

            filterCallback.addEventListener('change', applyFilter);
            masterElement.addEventListener('change', () => { if (filterCallback.checked) applyFilter(); });
        }

        if (Object.keys(virtualFields).length > 0) {
            form.addEventListener('input', refreshVirtuals);
            form.addEventListener('change', refreshVirtuals);
            refreshVirtuals();
        }

        const bufferedRecords = stepData[currentStepIndex] || [];
        let multiListElement = null;

        let editingIndex = null;
        let addButton = null;
        let cancelEditButton = null;

        function labelForRecord(snap) {
            const parts = [];
            for (const [columnName, columnDef] of Object.entries(tableSchema.columns)) {
                const t = (columnDef.type || '').toLowerCase();
                if (columnName === 'id' || t === 'virtual' || t.includes('bool')) continue;
                const v = snap[columnName];
                if (v !== undefined && String(v).trim() !== '') parts.push(String(v));
                if (parts.length >= 2) break;
            }
            return parts.join(' — ') || I18n.t('workflow.select_blank');
        }

        function renderMultiList() {
            if (!multiListElement) return;
            multiListElement.textContent = '';
            const records = stepData[currentStepIndex] || [];
            records.forEach((snap, ri) => {
                const row = document.createElement('div');
                row.className = 'wf-buffered-row' + (ri === editingIndex ? ' active' : '');

                const text = document.createElement('span');
                text.className = 'wf-buffered-label';
                text.textContent = `${ri + 1}. ${labelForRecord(snap)}`;
                text.title = I18n.t('common.edit');
                text.addEventListener('click', () => enterEditMode(ri));
                const rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'icon-btn icon-btn-danger';
                rm.textContent = '✕';
                rm.title = I18n.t('common.delete');
                rm.addEventListener('click', () => {
                    stepData[currentStepIndex].splice(ri, 1);

                    if (editingIndex !== null) exitEditMode();
                    else renderMultiList();
                });
                row.appendChild(text);
                row.appendChild(rm);
                multiListElement.appendChild(row);
            });
        }

        function enterEditMode(ri) {
            editingIndex = ri;
            form.reset();
            const snap = (stepData[currentStepIndex] || [])[ri];
            writeForm(form, snap);
            refreshVirtuals();
            if (imagesConfig) {
                pendingImages = snap?.__images || [];
                if (imageStatusElement) {
                    imageStatusElement.textContent = pendingImages.length
                        ? I18n.t('workflow.images_selected', { n: pendingImages.length }, pendingImages.length)
                        : I18n.t('workflow.image_limit', { max: imagesConfig.max_per_record });
                }
            }
            if (addButton) addButton.textContent = I18n.t('form.update_record');
            if (cancelEditButton) cancelEditButton.hidden = false;
            renderMultiList();
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function exitEditMode() {
            editingIndex = null;
            form.reset();
            refreshVirtuals();
            if (imagesConfig) {
                pendingImages = [];
                if (imageStatusElement) imageStatusElement.textContent = I18n.t('workflow.image_limit', { max: imagesConfig.max_per_record });
            }
            if (addButton) addButton.textContent = I18n.t('form.add_record');
            if (cancelEditButton) cancelEditButton.hidden = true;
            renderMultiList();
        }

        if (step.allow_multiple) {
            multiListEl: multiListElement = document.createElement('div');
            multiListElement.className = 'wf-buffered-list';
            form.appendChild(multiListElement);
            renderMultiList();
        } else if (bufferedRecords.length > 0) {
            writeForm(form, bufferedRecords[0]);
            refreshVirtuals();
            if (imagesConfig) {
                pendingImages = bufferedRecords[0].__images || [];
                if (imageStatusElement && pendingImages.length) {
                    imageStatusElement.textContent = I18n.t('workflow.images_selected', { n: pendingImages.length }, pendingImages.length);
                }
            }
        }

        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'form-actions';

        if (currentStepIndex > 0) {
            const backButton = document.createElement('button');
            backButton.type = 'button';
            backButton.className = 'btn-cancel';
            backButton.textContent = I18n.t('pagination.prev');
            backButton.addEventListener('click', () => {
                if (!step.allow_multiple) {
                    stepData[currentStepIndex] = [snapshotWithImages()];
                }
                goToStep(currentStepIndex - 1);
            });
            buttonContainer.appendChild(backButton);
        }

        if (step.allow_multiple) {
            addBtn: addButton = document.createElement('button');
            addButton.type = 'button';
            addButton.className = 'btn-cancel';
            addButton.textContent = I18n.t('form.add_record');
            addButton.addEventListener('click', () => {
                if (!form.reportValidity()) return;
                if (!stepData[currentStepIndex]) stepData[currentStepIndex] = [];
                if (editingIndex !== null) {
                    stepData[currentStepIndex][editingIndex] = snapshotWithImages();
                } else {
                    stepData[currentStepIndex].push(snapshotWithImages());
                }

                exitEditMode();
            });

            cancelEditButton = document.createElement('button');
            cancelEditButton.type = 'button';
            cancelEditButton.className = 'btn-cancel';
            cancelEditButton.textContent = I18n.t('common.cancel');
            cancelEditButton.hidden = true;
            cancelEditButton.addEventListener('click', () => exitEditMode());

            buttonContainer.appendChild(addButton);
            buttonContainer.appendChild(cancelEditButton);
        }

        const nextButton = document.createElement('button');
        nextButton.type = 'submit';
        nextButton.className = 'btn-save';

        nextButton.textContent = returnToReview ? I18n.t('form.save') : I18n.t('form.next_step');
        buttonContainer.appendChild(nextButton);

        form.appendChild(buttonContainer);

        async function callStepProcedure() {
            const stepValues = {};
            stepData.forEach((records, index) => {
                if (!records || !records[0]) return;

                const { __images, ...values } = records[0];
                stepValues[String(index)] = values;
            });

            const result = await apiFetch('api.php?api=workflow_procedure', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: {
                    workflow_id: workflow.id,
                    step_index: currentStepIndex,
                    step_values: stepValues
                }
            });

            const rawText = await result.text();
            let result;
            try {
                result = JSON.parse(rawText);
            } catch {
                console.error('RAW SERVER RESPONSE:', rawText);
                const cleanError = rawText.replace(/<\/?[^>]+(>|$)/g, '').trim();
                throw new Error(I18n.t('workflow.server_error', { msg: cleanError.substring(0, 150) }));
            }

            if (!result.ok || result.success !== true) {
                throw new Error(result.error || I18n.t('workflow.unknown_save_error'));
            }
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (step.allow_multiple) {
                const hasAnyValue = Array.from(form.querySelectorAll('[name]')).some((element) =>
                    element.type === 'checkbox' ? element.checked : String(element.value ?? '').trim() !== '');
                if (hasAnyValue && form.checkValidity()) {
                    if (!stepData[currentStepIndex]) stepData[currentStepIndex] = [];
                    if (editingIndex !== null) {
                        stepData[currentStepIndex][editingIndex] = snapshotWithImages();
                    } else {
                        stepData[currentStepIndex].push(snapshotWithImages());
                    }
                }
            } else {
                if (!form.reportValidity()) return;
                stepData[currentStepIndex] = [snapshotWithImages()];
            }

            if (step.procedure && step.procedure.enabled && !returnToReview) {
                const previousLabel = nextButton.textContent;
                nextButton.disabled = true;
                nextButton.textContent = I18n.t('workflow.procedure_running');
                try {
                    await callStepProcedure();
                } catch (error) {
                    console.error(error);
                    showToast(I18n.t('workflow.procedure_error', { msg: error.message }), 'error');
                    nextButton.disabled = false;
                    nextButton.textContent = previousLabel;
                    return;
                }
                nextButton.disabled = false;
                nextButton.textContent = previousLabel;
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
        containerElement.appendChild(page);
    }

    function renderReview() {
        renderStepBar();
        titleElement.textContent = workflow.title;
        containerElement.textContent = '';

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
            const editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.className = 'btn btn-sm';
            editButton.textContent = I18n.t('common.edit');
            editButton.addEventListener('click', () => {
                returnToReview = true;
                goToStep(i);
            });
            head.appendChild(h3);
            head.appendChild(editButton);
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
                    const columns = meta?.tableSchema?.columns || {};
                    for (const [columnName, columnDef] of Object.entries(columns)) {
                        const type = (columnDef.type || '').toLowerCase();
                        if (columnName === 'id' || columnDef.readonly || type === 'virtual') continue;
                        if (step.foreign_key === columnName && step.link_to_step !== undefined && step.link_to_step !== '') continue;
                        let value = snap[columnName];
                        if (type.includes('bool')) {
                            val: value = value ? '✓' : '✗';
                        } else if (value === undefined || value === '') {
                            continue;
                        }
                        const dt = document.createElement('dt');
                        dt.textContent = columnDef.display_name || columnName;
                        const dd = document.createElement('dd');
                        dd.textContent = String(value);
                        dl.appendChild(dt);
                        dl.appendChild(dd);
                    }
                    if (Array.isArray(snap.__images) && snap.__images.length > 0) {
                        const dt = document.createElement('dt');
                        dt.textContent = meta?.tableSchema?.images?.label || I18n.t('images.label');
                        const dd = document.createElement('dd');
                        dd.textContent = I18n.t('workflow.images_selected', { n: snap.__images.length }, snap.__images.length);
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

        const backButton = document.createElement('button');
        backButton.type = 'button';
        backButton.className = 'btn-cancel';
        backButton.textContent = I18n.t('pagination.prev');
        backButton.addEventListener('click', () => goToStep(workflow.steps.length - 1));

        const saveButton = document.createElement('button');
        saveButton.type = 'button';
        saveButton.className = 'btn-save';
        saveButton.textContent = I18n.t('form.save');

        const message = document.createElement('div');
        message.className = 'wf-form-msg';

        saveButton.addEventListener('click', () => saveAll(saveButton, backButton, message));

        actions.appendChild(backButton);
        actions.appendChild(saveButton);
        page.appendChild(actions);
        page.appendChild(message);
        containerElement.appendChild(page);
    }

    async function saveAll(saveButton, backButton, messageElement) {
        saveButton.disabled = true;
        if (backButton) backButton.disabled = true;
        saveButton.textContent = I18n.t('workflow.saving');
        messageElement.textContent = '';

        try {
            for (let i = 0; i < workflow.steps.length; i++) {
                const step = workflow.steps[i];
                const meta = stepMeta[i];
                const records = stepData[i] || [];
                if (!meta || records.length === 0) continue;

                for (const snap of records) {
                    if (savedRecords.has(snap)) continue;

                    const payload = buildPayload(meta.tableSchema, step, snap);

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

                    if (Array.isArray(snap.__images) && snap.__images.length > 0) {
                        for (const file of snap.__images) {
                            const formData = new FormData();
                            formData.append('action', 'upload');
                            formData.append('csrf_token', getCsrfToken());
                            formData.append('file', file);
                            formData.append('related_table', step.table);
                            formData.append('related_id', String(result.id));
                            formData.append('related_field', '__image');

                            const imageResult = await fetch('api/files.php', { method: 'POST', body: formData });
                            const imageData = await imageResult.json();
                            if (!imageData.success) {
                                throw new Error(I18n.t('workflow.image_upload_error', { msg: imageData.error || '' }));
                            }
                        }
                    }
                }
            }

            const bar = document.getElementById('wf-step-bar');
            if (bar) bar.remove();
            renderSuccessScreen();
        } catch (error) {
            console.error(error);
            showToast(I18n.t('workflow.save_error', { msg: error.message }), 'error');
            saveButton.disabled = false;
            if (backButton) backButton.disabled = false;
            saveButton.textContent = I18n.t('form.save');
        }
    }

    function renderSuccessScreen() {
        titleElement.textContent = I18n.t('workflow.completed_title');
        containerElement.textContent = '';

        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'margin: 60px auto; padding: 0 20px; text-align: center; max-width: 500px;';

        const heading = document.createElement('h2');
        heading.style.cssText = 'color: var(--ok); margin-top: 0; font-size: 28px;';
        heading.textContent = I18n.t('workflow.success');

        const paragraph = document.createElement('p');
        paragraph.style.cssText = 'color: var(--text); font-size: 15px; line-height: 1.6;';

        const textStart = document.createTextNode(I18n.t('workflow.success_before') + ' ');
        const boldTitle = document.createElement('b');
        boldTitle.textContent = workflow.title;
        const textEnd = document.createTextNode(' ' + I18n.t('workflow.success_after'));

        paragraph.appendChild(textStart);
        paragraph.appendChild(boldTitle);
        paragraph.appendChild(textEnd);

        const finishButton = document.createElement('button');
        finishButton.id = 'wf-finish-btn';
        finishButton.style.cssText = 'margin-top: 24px; padding: 10px 24px; background: var(--accent); color: white; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; box-shadow: var(--shadow-sm); transition: background var(--transition);';
        finishButton.textContent = I18n.t('workflow.finish_return');

        finishButton.addEventListener('mouseenter', () => finishButton.style.background = 'var(--accent-dark)');
        finishButton.addEventListener('mouseleave', () => finishButton.style.background = 'var(--accent)');
        finishButton.addEventListener('click', () => renderWorkflowsList(allWorkflows, containerElement, titleElement, menuName, appSchema));

        wrapper.appendChild(heading);
        wrapper.appendChild(paragraph);
        wrapper.appendChild(finishButton);

        containerElement.appendChild(wrapper);
    }

    renderCurrentStep();
}
