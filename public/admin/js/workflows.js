// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { createTextInput, createSelectInput, createIconPicker } from './ui.js';
import { apiFetch } from '../../assets/js/util/api.js';

let procedureCache = null;

async function loadProcedures() {
    if (procedureCache !== null) return procedureCache;
    try {
        const result = await apiFetch('api.php?action=list_procedures');
        const data = await result.json();
        procedureCache = Array.isArray(data.procedures) ? data.procedures : [];
    } catch (error) {
        console.warn('Could not load stored procedures', error);
        procedureCache = [];
    }
    return procedureCache;
}

function procedureKey(proc) {
    return `${proc.schema}.${proc.name}`;
}

function procedureLabel(proc) {
    const argumentList = (proc.params || []).map(p => `${p.name} ${p.type}`).join(', ');
    return `${procedureKey(proc)}(${argumentList})`;
}

export function renderWorkflowsEditor(key, itemData, isArray, context) {
    const { workspaceEl: workspaceElement, getTableOptions, getColumnOptionsForTable, renderEditor } = context;

    if (!itemData.steps) itemData.steps = [];

    workspaceElement.appendChild(createTextInput('title', 'Workflow Title', itemData.title, v => itemData.title = v));
    workspaceElement.appendChild(createTextInput('description', 'Short Description', itemData.description || '', v => itemData.description = v));
    workspaceElement.appendChild(createIconPicker('icon', 'Workflow Icon', itemData.icon || '', v => {
        if (v && v.trim() !== '') itemData.icon = v; else delete itemData.icon;
    }));

    const stepsContainer = document.createElement('div');
    stepsContainer.style.marginTop = '30px';
    workspaceElement.appendChild(stepsContainer);

    function renderSteps() {
        stepsContainer.innerHTML = '<h3>Workflow Steps</h3>';

        itemData.steps.forEach((step, index) => {
            const incomplete = !step.title || step.title.trim() === '' || !step.table || step.table.trim() === '';

            const block = document.createElement('div');
            block.className = 'column-block collapsed';

            const header = document.createElement('div');
            header.className = 'block-header';
            header.addEventListener('click', (e) => {
                if (e.target.closest('button')) return;
                block.classList.toggle('collapsed');
            });

            const chevron = document.createElement('span');
            chevron.className = 'block-chevron';
            chevron.textContent = '▶';

            const h4 = document.createElement('h4');
            h4.textContent = incomplete ? `Step ${index + 1} — incomplete` : `Step ${index + 1}`;
            if (incomplete) h4.style.color = 'var(--error)';

            const delButton = document.createElement('button');
            delButton.type = 'button';
            delButton.title = 'Delete Step';
            delButton.textContent = '✕';
            delButton.className = 'icon-btn icon-btn-danger';
            delButton.onclick = () => {
                itemData.steps.splice(index, 1);
                renderSteps();
            };

            header.appendChild(chevron);
            header.appendChild(h4);
            header.appendChild(delButton);
            block.appendChild(header);

            block.appendChild(createTextInput('step_title', 'Step Name', step.title, v => step.title = v));

            block.appendChild(createTextInput('step_description', 'Step Description', step.description || '', v => step.description = v));

            block.appendChild(createSelectInput('step_table', 'Target Table', getTableOptions(), step.table || '', v => {
                step.table = v;
                step.foreign_key = '';
                renderSteps();
            }));

            const multiOptions = [
                { value: 'false', label: 'No (Single record)' },
                { value: 'true', label: 'Yes (Multiple records)' }
            ];
            const currentMulti = step.allow_multiple ? 'true' : 'false';
            block.appendChild(createSelectInput('allow_multiple', 'Allow adding multiple records?', multiOptions, currentMulti, v => {
                step.allow_multiple = (v === 'true');
            }));

            if (index > 0 && step.table) {
                const columnOptions = getColumnOptionsForTable(step.table);
                block.appendChild(createSelectInput('step_fk', 'Foreign Key (link to previous step)', columnOptions, step.foreign_key || '', v => step.foreign_key = v));

                const previousSteps = [{value: '', label: '-- Select Previous Step --'}];
                for (let i = 0; i < index; i++) {
                    previousSteps.push({value: i.toString(), label: `Step ${i + 1}: ${itemData.steps[i].title || 'Unnamed'}`});
                }

                block.appendChild(createSelectInput('link_to_step', 'Link to ID from Step', previousSteps, (step.link_to_step !== undefined ? step.link_to_step.toString() : ''), v => step.link_to_step = parseInt(v)));
            }

            const proc = step.procedure || {};
            const procedureEnabledOptions = [
                { value: 'false', label: 'No' },
                { value: 'true', label: 'Yes' }
            ];
            block.appendChild(createSelectInput(
                'proc_enabled',
                'Call a PostgreSQL procedure on Next step?',
                procedureEnabledOptions,
                proc.enabled ? 'true' : 'false',
                v => {
                    if (v === 'true') {
                        step.procedure = { enabled: true, schema: '', name: '', params: [] };
                    } else {
                        delete step.procedure;
                    }
                    renderSteps();
                }
            ));

            if (proc.enabled) {
                const procedureOptions = [{ value: '', label: '-- Select Procedure --' }];
                (procedureCache || []).forEach(p => {
                    procedureOptions.push({ value: procedureKey(p), label: procedureLabel(p) });
                });

                const selectedKey = (proc.schema && proc.name) ? `${proc.schema}.${proc.name}` : '';
                block.appendChild(createSelectInput('proc_name', 'Procedure', procedureOptions, selectedKey, v => {
                    const picked = (procedureCache || []).find(p => procedureKey(p) === v);
                    if (picked) {
                        step.procedure.schema = picked.schema;
                        step.procedure.name = picked.name;

                        step.procedure.params = (picked.params || []).map(() => ({ source: 'field', step: index, field: '' }));
                    } else {
                        step.procedure.schema = '';
                        step.procedure.name = '';
                        step.procedure.params = [];
                    }
                    renderSteps();
                }));

                const selectedProcedure = (procedureCache || []).find(p => procedureKey(p) === selectedKey);
                if (selectedProcedure) {
                    (selectedProcedure.params || []).forEach((declared, pi) => {
                        if (!step.procedure.params[pi]) {
                            step.procedure.params[pi] = { source: 'field', step: index, field: '' };
                        }
                        const parameterConfig = step.procedure.params[pi];
                        const parameterLabel = `${declared.name || 'arg' + (pi + 1)} (${declared.type})`;

                        block.appendChild(createSelectInput(
                            `param_src_${pi}`,
                            `Parameter ${pi + 1}: ${parameterLabel}`,
                            [
                                { value: 'field', label: 'From a workflow field' },
                                { value: 'literal', label: 'Fixed value' }
                            ],
                            parameterConfig.source || 'field',
                            v => {
                                step.procedure.params[pi] = v === 'literal'
                                    ? { source: 'literal', value: '' }
                                    : { source: 'field', step: index, field: '' };
                                renderSteps();
                            }
                        ));

                        if (parameterConfig.source === 'literal') {
                            block.appendChild(createTextInput(
                                `param_val_${pi}`,
                                `— value for ${parameterLabel}`,
                                parameterConfig.value || '',
                                v => parameterConfig.value = v
                            ));
                        } else {
                            const stepOptions = [];
                            for (let i = 0; i <= index; i++) {
                                stepOptions.push({
                                    value: i.toString(),
                                    label: `Step ${i + 1}: ${itemData.steps[i].title || 'Unnamed'}`
                                });
                            }
                            const sourceStep = (parameterConfig.step !== undefined && parameterConfig.step !== '')
                                ? parameterConfig.step.toString() : index.toString();

                            block.appendChild(createSelectInput(
                                `param_step_${pi}`,
                                `— source step for ${parameterLabel}`,
                                stepOptions,
                                sourceStep,
                                v => {
                                    parameterConfig.step = parseInt(v, 10);
                                    parameterConfig.field = '';
                                    renderSteps();
                                }
                            ));

                            const sourceTable = itemData.steps[parseInt(sourceStep, 10)]?.table || '';
                            const fieldOptions = [{ value: '', label: '-- Select Field --' }]
                                .concat(sourceTable ? getColumnOptionsForTable(sourceTable) : []);
                            block.appendChild(createSelectInput(
                                `param_field_${pi}`,
                                `— source field for ${parameterLabel}`,
                                fieldOptions,
                                parameterConfig.field || '',
                                v => parameterConfig.field = v
                            ));
                        }
                    });
                }
            }

            const bodyDiv = document.createElement('div');
            bodyDiv.className = 'block-body';
            while (block.children.length > 1) bodyDiv.appendChild(block.children[1]);
            block.appendChild(bodyDiv);

            stepsContainer.appendChild(block);
        });

        const addButton = document.createElement('button');
        addButton.className = 'btn btn-sm';
        addButton.textContent = '+ Add Step';
        addButton.onclick = () => {
            itemData.steps.push({ title: '', description: '', table: '', foreign_key: '', link_to_step: itemData.steps.length > 0 ? itemData.steps.length - 1 : 0, allow_multiple: false });
            renderSteps();
        };
        stepsContainer.appendChild(addButton);
    }

    renderSteps();

    if (procedureCache === null) {
        loadProcedures().then(() => renderSteps());
    }
}
