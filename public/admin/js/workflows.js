// admin/js/workflows.js — Workflows multi-step wizard editor (renderWorkflowsEditor): edits workflow steps; global settings (menu_name/icon/hidden) handled centrally in app.js.
import { createTextInput, createSelectInput, createIconPicker } from './ui.js';
import { apiFetch } from '../../assets/js/util/api.js';

// Stored procedures available for the "call on Next step" hook, fetched once per
// page load from the admin introspection endpoint and shared by every step editor.
let procedureCache = null;

async function loadProcedures() {
    if (procedureCache !== null) return procedureCache;
    try {
        const res = await apiFetch('api.php?action=list_procedures');
        const data = await res.json();
        procedureCache = Array.isArray(data.procedures) ? data.procedures : [];
    } catch (err) {
        console.warn('Could not load stored procedures', err);
        procedureCache = [];
    }
    return procedureCache;
}

// Stable key identifying one procedure signature in the select control.
function procedureKey(proc) {
    return `${proc.schema}.${proc.name}`;
}

// "app.validate_customer(p_email text, p_note text)"
function procedureLabel(proc) {
    const args = (proc.params || []).map(p => `${p.name} ${p.type}`).join(', ');
    return `${procedureKey(proc)}(${args})`;
}

// Render the multi-step wizard configuration interface. Global Workflow
// settings (menu_name/menu_icon/hidden) are handled centrally in app.js via
// the shared renderGlobalSettings helper.
export function renderWorkflowsEditor(key, itemData, isArray, ctx) {
    const { workspaceEl, getTableOptions, getColumnOptionsForTable, renderEditor } = ctx;

    // Ensure array structure for workflow steps
    if (!itemData.steps) itemData.steps = [];

    workspaceEl.appendChild(createTextInput('title', 'Workflow Title', itemData.title, v => itemData.title = v));
    workspaceEl.appendChild(createTextInput('description', 'Short Description', itemData.description || '', v => itemData.description = v));
    workspaceEl.appendChild(createIconPicker('icon', 'Workflow Icon', itemData.icon || '', v => {
        if (v && v.trim() !== '') itemData.icon = v; else delete itemData.icon;
    }));

    const stepsContainer = document.createElement('div');
    stepsContainer.style.marginTop = '30px';
    workspaceEl.appendChild(stepsContainer);

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
            if (incomplete) h4.style.color = 'var(--danger)';

            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.title = 'Delete Step';
            delBtn.textContent = '✕';
            delBtn.className = 'icon-btn icon-btn-danger';
            delBtn.onclick = () => {
                itemData.steps.splice(index, 1);
                renderSteps();
            };

            header.appendChild(chevron);
            header.appendChild(h4);
            header.appendChild(delBtn);
            block.appendChild(header);

            block.appendChild(createTextInput('step_title', 'Step Name', step.title, v => step.title = v));
            
            // Add step description field
            block.appendChild(createTextInput('step_description', 'Step Description', step.description || '', v => step.description = v));
            
            block.appendChild(createSelectInput('step_table', 'Target Table', getTableOptions(), step.table || '', v => {
                step.table = v;
                step.foreign_key = ''; 
                renderSteps();
            }));

            // Multiple records option
            const multiOptions = [
                { value: 'false', label: 'No (Single record)' },
                { value: 'true', label: 'Yes (Multiple records)' }
            ];
            const currentMulti = step.allow_multiple ? 'true' : 'false';
            block.appendChild(createSelectInput('allow_multiple', 'Allow adding multiple records?', multiOptions, currentMulti, v => {
                step.allow_multiple = (v === 'true');
            }));

            // Map foreign key to previous steps
            if (index > 0 && step.table) {
                const colOptions = getColumnOptionsForTable(step.table);
                block.appendChild(createSelectInput('step_fk', 'Foreign Key (link to previous step)', colOptions, step.foreign_key || '', v => step.foreign_key = v));
                
                const prevSteps = [{value: '', label: '-- Select Previous Step --'}];
                for (let i = 0; i < index; i++) {
                    prevSteps.push({value: i.toString(), label: `Step ${i + 1}: ${itemData.steps[i].title || 'Unnamed'}`});
                }
                
                block.appendChild(createSelectInput('link_to_step', 'Link to ID from Step', prevSteps, (step.link_to_step !== undefined ? step.link_to_step.toString() : ''), v => step.link_to_step = parseInt(v)));
            }

            // ---- Stored procedure called when the user clicks "Next step" ----
            // Only form values and literals can be passed: the wizard defers all
            // database writes to the final review screen, so no record id exists yet.
            const proc = step.procedure || {};
            const procEnabledOptions = [
                { value: 'false', label: 'No' },
                { value: 'true', label: 'Yes' }
            ];
            block.appendChild(createSelectInput(
                'proc_enabled',
                'Call a PostgreSQL procedure on Next step?',
                procEnabledOptions,
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
                const procOptions = [{ value: '', label: '-- Select Procedure --' }];
                (procedureCache || []).forEach(p => {
                    procOptions.push({ value: procedureKey(p), label: procedureLabel(p) });
                });

                const selectedKey = (proc.schema && proc.name) ? `${proc.schema}.${proc.name}` : '';
                block.appendChild(createSelectInput('proc_name', 'Procedure', procOptions, selectedKey, v => {
                    const picked = (procedureCache || []).find(p => procedureKey(p) === v);
                    if (picked) {
                        step.procedure.schema = picked.schema;
                        step.procedure.name = picked.name;
                        // One positional entry per declared IN parameter — fixed arity
                        // prevents an argument-count mismatch at call time.
                        step.procedure.params = (picked.params || []).map(() => ({ source: 'field', step: index, field: '' }));
                    } else {
                        step.procedure.schema = '';
                        step.procedure.name = '';
                        step.procedure.params = [];
                    }
                    renderSteps();
                }));

                const selectedProc = (procedureCache || []).find(p => procedureKey(p) === selectedKey);
                if (selectedProc) {
                    (selectedProc.params || []).forEach((declared, pi) => {
                        if (!step.procedure.params[pi]) {
                            step.procedure.params[pi] = { source: 'field', step: index, field: '' };
                        }
                        const paramCfg = step.procedure.params[pi];
                        const paramLabel = `${declared.name || 'arg' + (pi + 1)} (${declared.type})`;

                        block.appendChild(createSelectInput(
                            `param_src_${pi}`,
                            `Parameter ${pi + 1}: ${paramLabel}`,
                            [
                                { value: 'field', label: 'From a workflow field' },
                                { value: 'literal', label: 'Fixed value' }
                            ],
                            paramCfg.source || 'field',
                            v => {
                                step.procedure.params[pi] = v === 'literal'
                                    ? { source: 'literal', value: '' }
                                    : { source: 'field', step: index, field: '' };
                                renderSteps();
                            }
                        ));

                        if (paramCfg.source === 'literal') {
                            block.appendChild(createTextInput(
                                `param_val_${pi}`,
                                `— value for ${paramLabel}`,
                                paramCfg.value || '',
                                v => paramCfg.value = v
                            ));
                        } else {
                            // Fields of the current step and every earlier step are
                            // available — earlier steps are already buffered by then.
                            const stepOptions = [];
                            for (let i = 0; i <= index; i++) {
                                stepOptions.push({
                                    value: i.toString(),
                                    label: `Step ${i + 1}: ${itemData.steps[i].title || 'Unnamed'}`
                                });
                            }
                            const srcStep = (paramCfg.step !== undefined && paramCfg.step !== '')
                                ? paramCfg.step.toString() : index.toString();

                            block.appendChild(createSelectInput(
                                `param_step_${pi}`,
                                `— source step for ${paramLabel}`,
                                stepOptions,
                                srcStep,
                                v => {
                                    paramCfg.step = parseInt(v, 10);
                                    paramCfg.field = '';
                                    renderSteps();
                                }
                            ));

                            const srcTable = itemData.steps[parseInt(srcStep, 10)]?.table || '';
                            const fieldOptions = [{ value: '', label: '-- Select Field --' }]
                                .concat(srcTable ? getColumnOptionsForTable(srcTable) : []);
                            block.appendChild(createSelectInput(
                                `param_field_${pi}`,
                                `— source field for ${paramLabel}`,
                                fieldOptions,
                                paramCfg.field || '',
                                v => paramCfg.field = v
                            ));
                        }
                    });
                }
            }

            // Wrap everything after the header into the collapsible body.
            const bodyDiv = document.createElement('div');
            bodyDiv.className = 'block-body';
            while (block.children.length > 1) bodyDiv.appendChild(block.children[1]);
            block.appendChild(bodyDiv);

            stepsContainer.appendChild(block);
        });

        const addBtn = document.createElement('button');
        addBtn.className = 'btn btn-sm';
        addBtn.textContent = '+ Add Step';
        addBtn.onclick = () => {
            itemData.steps.push({ title: '', description: '', table: '', foreign_key: '', link_to_step: itemData.steps.length > 0 ? itemData.steps.length - 1 : 0, allow_multiple: false });
            renderSteps();
        };
        stepsContainer.appendChild(addBtn);
    }

    renderSteps();

    // The procedure list arrives asynchronously; re-render once it lands so the
    // dropdowns populate without blocking the initial paint of the editor.
    if (procedureCache === null) {
        loadProcedures().then(() => renderSteps());
    }
}