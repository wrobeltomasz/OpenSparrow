// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { createIconPicker, buildInnerTabs } from './ui.js';

export function renderPrintEditor(context) {
    const { workspaceEl: workspaceElement, setSaveHandler } = context;
    workspaceElement.innerHTML = '';

    let prints      = {};
    let configVersion  = 0;
    let dbViews     = [];
    let viewColumns = {};

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';

    const hdrTitle = document.createElement('h2');
    hdrTitle.className = 'admin-page-title';
    hdrTitle.textContent = 'Printouts';
    const hdrDescription = document.createElement('p');
    hdrDescription.className = 'admin-page-desc';
    hdrDescription.textContent = 'Build printable report templates from simple blocks (header, text, table). Each template is bound to a PostgreSQL view from the Views module; its columns become the available {variables}. Optional parameters let users filter the report (e.g. by employee) before printing.';
    wrap.appendChild(hdrTitle);
    wrap.appendChild(hdrDescription);

    const [listPanel, globalPanel] = buildInnerTabs(wrap, [
        { label: 'All Printouts', icon: 'picture_as_pdf.png' },
        { label: 'Global Settings', icon: 'car_gear.png' },
    ]);

    const globalHeading = document.createElement('h3');
    globalHeading.textContent = 'Global Settings';
    const globalDescription = document.createElement('p');
    globalDescription.style.cssText = '  margin:0;';
    globalDescription.textContent = 'Printouts have no module-wide settings yet — each template configures its own view, parameters and blocks below. Use "All Printouts" to add and edit templates.';
    globalPanel.appendChild(globalHeading);
    globalPanel.appendChild(globalDescription);

    const bar = document.createElement('div');
    bar.style.marginBottom = '12px';
    const addButton = document.createElement('button');
    addButton.type = 'button';
    addButton.className = 'btn btn-success';
    addButton.textContent = '+ Add printout';
    bar.appendChild(addButton);
    listPanel.appendChild(bar);

    setSaveHandler(async () => {
        const result = await apiFetch('../api/print.php?action=save', {
            method: 'POST',
            body: JSON.stringify({ prints, version: configVersion }),
        });
        const data = await result.json();
        if (data.status === 'ok') {
            cfgVersion: configVersion = data.version ?? configVersion + 1;
            setStatus('Printouts saved.', 'ok');
            return { status: 'success', message: 'Printouts saved' };
        }
        if (result.status === 409) {
            setStatus('Save rejected: configuration was changed by someone else. Reload the page and re-apply your edits.', 'error');
        }
        return { status: 'error', error: data.error ?? 'unknown' };
    });

    const statusElement = document.createElement('div');
    statusElement.style.cssText = 'display:none;';
    listPanel.appendChild(statusElement);

    const listElement = document.createElement('div');
    listElement.style.cssText = 'display:flex; flex-direction:column; gap:16px;';
    listPanel.appendChild(listElement);

    workspaceElement.appendChild(wrap);

    function setStatus(message, type = 'info') {
        const styles = {
            info:  'background:var(--accent-light); color:var(--accent-dark);',
            ok:    'background:var(--ok-light); color:var(--ok);',
            error: 'background:var(--error-light); color:var(--error);',
        };
        statusElement.style.cssText = `display:block; padding:8px 14px; border-radius:var(--radius);  margin-bottom:16px; ${styles[type] ?? styles.info}`;
        statusElement.textContent = message;
    }

    async function fetchColumns(viewName) {
        if (!viewName) return [];
        if (viewColumns[viewName]) return viewColumns[viewName];
        try {
            const result  = await apiFetch('../api/print.php?action=columns&view=' + encodeURIComponent(viewName));
            const data = await result.json();
            if (data.status !== 'ok') return [];
            viewColumns[viewName] = data.columns ?? [];
            return viewColumns[viewName];
        } catch {
            return [];
        }
    }

    function fg(label, value, onChange) {
        const grp = document.createElement('div');
        grp.className = 'form-group';
        const label = document.createElement('label');
        label.textContent = label;
        grp.appendChild(label);
        const input = document.createElement('input');
        input.type = 'text';
        input.value = value ?? '';
        input.addEventListener('input', () => onChange(input.value));
        grp.appendChild(input);
        return grp;
    }

    function fgArea(label, value, onChange, rows = 3) {
        const grp = document.createElement('div');
        grp.className = 'form-group';
        const label = document.createElement('label');
        label.textContent = label;
        grp.appendChild(label);
        const ta = document.createElement('textarea');
        ta.rows = rows;
        ta.style.resize = 'vertical';
        ta.value = value ?? '';
        ta.addEventListener('input', () => onChange(ta.value));
        grp.appendChild(ta);
        return grp;
    }

    function buildVariablesRow(columns) {
        const box = document.createElement('div');
        box.style.cssText = 'display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px;';
        if (columns.length === 0) {
            const none = document.createElement('span');
            none.style.cssText = ' ';
            none.textContent = 'Select a view to load its variables.';
            box.appendChild(none);
            return box;
        }
        columns.forEach(column => {
            const badge = document.createElement('span');
            badge.style.cssText = ' font-family:var(--font-mono); color:var(--accent-dark); background:var(--accent-light); padding:2px 8px; border-radius:10px;';
            badge.textContent = `{${column.name}}`;
            badge.title = column.data_type || '';
            box.appendChild(badge);
        });
        return box;
    }

    function buildPrintCard(pName, config) {
        const card = document.createElement('div');
        card.className = 'column-block collapsed';
        card.dataset.print = pName;
        if (config.hidden) card.style.opacity = '0.6';

        const cardHdr = document.createElement('div');
        cardHdr.className = 'block-header';

        const chevron = document.createElement('span');
        chevron.className = 'block-chevron';
        chevron.textContent = '▶';

        const nameSpan = document.createElement('strong');
        nameSpan.className = 'block-title';
        nameSpan.textContent = config.display_name || pName;
        const keySpan = document.createElement('span');
        keySpan.className = 'block-key';
        keySpan.textContent = ` (${pName})`;
        nameSpan.appendChild(keySpan);

        const visibleLabel = document.createElement('label');
        visibleLabel.className = 'block-vis';
        const visibleCheckbox = document.createElement('input');
        visibleCheckbox.type = 'checkbox';
        visibleCheckbox.checked = !config.hidden;
        visibleCheckbox.className = 'adm-check';
        visibleCheckbox.addEventListener('change', e => {
            prints[pName].hidden = !e.target.checked;
            card.style.opacity = prints[pName].hidden ? '0.6' : '1';
        });
        visibleLabel.appendChild(visibleCheckbox);
        visibleLabel.appendChild(document.createTextNode('Visible'));

        const delButton = document.createElement('button');
        delButton.type = 'button';
        delButton.className = 'icon-btn icon-btn-danger';
        delButton.title = 'Delete';
        delButton.textContent = '✕';
        delButton.addEventListener('click', (e) => {
            e.stopPropagation();
            if (!confirm(`Delete printout "${pName}"?`)) return;
            delete prints[pName];
            renderList();
        });

        cardHdr.appendChild(chevron);
        cardHdr.appendChild(nameSpan);
        cardHdr.appendChild(visibleLabel);
        cardHdr.appendChild(delButton);
        card.appendChild(cardHdr);

        const body = document.createElement('div');
        body.className = 'block-body';
        card.appendChild(body);

        let rendered = false;
        cardHdr.addEventListener('click', async (e) => {
            if (e.target.closest('button, input, label')) return;
            const willOpen = card.classList.contains('collapsed');
            card.classList.toggle('collapsed');
            if (willOpen && !rendered) {
                rendered = true;
                await buildCardBody(pName, config, body, nameSpan);
            }
        });

        return card;
    }

    async function buildCardBody(pName, config, body, nameSpan) {
        body.innerHTML = '';

        const genHdr = document.createElement('h4');
        genHdr.textContent = 'General';
        body.appendChild(genHdr);

        body.appendChild(fg('Display name', config.display_name ?? pName, v => {
            prints[pName].display_name = v;

            nameSpan.firstChild.nodeValue = v || pName;
        }));
        body.appendChild(fg('Menu name', config.menu_name ?? pName, v => { prints[pName].menu_name = v; }));
        body.appendChild(fgArea('Description', config.description ?? '', v => { prints[pName].description = v; }));
        body.appendChild(createIconPicker('icon', 'Icon', config.icon || 'assets/icons/picture_as_pdf.png', v => { prints[pName].icon = v; }));

        const sourceHdr = document.createElement('h4');
        sourceHdr.textContent = 'Data source (PostgreSQL view)';
        body.appendChild(sourceHdr);

        const viewGroup = document.createElement('div');
        viewGroup.className = 'form-group';
        const viewLabel = document.createElement('label');
        viewLabel.textContent = 'SQL view (from the Views module)';
        viewGroup.appendChild(viewLabel);
        const viewSelect = document.createElement('select');
        const optionNone = document.createElement('option');
        optionNone.value = '';
        optionNone.textContent = '— select view —';
        viewSelect.appendChild(optionNone);
        dbViews.forEach(v => {
            const o = document.createElement('option');
            o.value = v;
            o.textContent = v;
            if ((config.view ?? '') === v) o.selected = true;
            viewSelect.appendChild(o);
        });
        viewGroup.appendChild(viewSelect);
        body.appendChild(viewGroup);

        const variablesLabel = document.createElement('label');
        variablesLabel.textContent = 'Available variables (columns of the view)';
        variablesLabel.style.cssText = 'display:block; margin-bottom:8px; font-weight:600;  color:var(--text);';
        body.appendChild(variablesLabel);

        let variablesRow = buildVariablesRow([]);
        body.appendChild(variablesRow);

        const parametersHdr = document.createElement('h4');
        parametersHdr.textContent = 'Report parameters';
        body.appendChild(parametersHdr);

        const parametersHint = document.createElement('p');
        parametersHint.style.cssText = 'margin:0 0 10px;  ';
        parametersHint.textContent = 'Optional filters shown above the report before it is generated '
            + '(e.g. "pick an employee"). Leave the lookup view empty to offer distinct values of '
            + 'the filter column itself.';
        body.appendChild(parametersHint);

        const parametersList = document.createElement('div');
        parametersList.style.cssText = 'display:flex; flex-direction:column; gap:10px; margin-bottom:12px;';
        body.appendChild(parametersList);

        if (!Array.isArray(prints[pName].params)) prints[pName].params = [];
        const parameters = prints[pName].params;

        const blkHdr = document.createElement('h4');
        blkHdr.textContent = 'Template blocks';
        body.appendChild(blkHdr);

        const blocksList = document.createElement('div');
        blocksList.style.cssText = 'display:flex; flex-direction:column; gap:10px; margin-bottom:12px;';
        body.appendChild(blocksList);

        if (!Array.isArray(prints[pName].blocks)) prints[pName].blocks = [];
        const blocks = prints[pName].blocks;
        let currentColumns = [];

        async function refreshVariables() {
            currentCols: currentColumns = await fetchColumns(viewSelect.value);
            const fresh = buildVariablesRow(currentColumns);
            variablesRow.replaceWith(fresh);
            variablesRow = fresh;
            renderBlocks();
            renderParameters();
        }

        viewSelect.addEventListener('change', () => {
            prints[pName].view = viewSelect.value;
            refreshVariables();
        });

        function renderBlocks() {
            blocksList.innerHTML = '';
            if (blocks.length === 0) {
                const empty = document.createElement('p');
                empty.style.cssText = '  margin:0;';
                empty.textContent = 'No blocks yet. Add a header, text or table block below.';
                blocksList.appendChild(empty);
                return;
            }
            blocks.forEach((block, index) => blocksList.appendChild(buildBlockRow(block, index)));
        }

        function buildBlockRow(block, index) {
            const row = document.createElement('div');
            row.className = 'subtable-block';

            const rowHdr = document.createElement('div');
            rowHdr.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:8px;';

            const typeSpan = document.createElement('strong');
            typeSpan.style.cssText = ' color:var(--text); text-transform:capitalize;';
            typeSpan.textContent = `${index + 1}. ${block.type}`;
            rowHdr.appendChild(typeSpan);

            const spacer = document.createElement('span');
            spacer.style.flex = '1';
            rowHdr.appendChild(spacer);

            const upButton = document.createElement('button');
            upButton.className = 'item-order-btn';
            upButton.textContent = '^';
            upButton.disabled = index === 0;
            upButton.addEventListener('click', () => {
                [blocks[index - 1], blocks[index]] = [blocks[index], blocks[index - 1]];
                renderBlocks();
            });
            const downButton = document.createElement('button');
            downButton.className = 'item-order-btn';
            downButton.textContent = 'v';
            downButton.disabled = index === blocks.length - 1;
            downButton.addEventListener('click', () => {
                [blocks[index + 1], blocks[index]] = [blocks[index], blocks[index + 1]];
                renderBlocks();
            });
            const rmButton = document.createElement('button');
            rmButton.className = 'btn btn-danger btn-xs';
            rmButton.textContent = '✕';
            rmButton.addEventListener('click', () => {
                blocks.splice(index, 1);
                renderBlocks();
            });
            rowHdr.appendChild(upButton);
            rowHdr.appendChild(downButton);
            rowHdr.appendChild(rmButton);
            row.appendChild(rowHdr);

            if (block.type === 'header') {
                const levelGroup = document.createElement('div');
                levelGroup.className = 'form-group';
                const levelLabel = document.createElement('label');
                levelLabel.textContent = 'Level';
                levelGroup.appendChild(levelLabel);
                const levelSelect = document.createElement('select');
                [1, 2, 3].forEach(l => {
                    const o = document.createElement('option');
                    o.value = String(l);
                    o.textContent = `H${l}`;
                    if ((block.level ?? 1) === l) o.selected = true;
                    levelSelect.appendChild(o);
                });
                levelSelect.addEventListener('change', () => { block.level = parseInt(levelSelect.value, 10); });
                levelGroup.appendChild(levelSelect);
                row.appendChild(levelGroup);
                row.appendChild(fg('Text (supports {variables})', block.text ?? '', v => { block.text = v; }));
            } else if (block.type === 'text') {
                row.appendChild(fgArea('Text (supports {variables}, values come from the first row)', block.text ?? '', v => { block.text = v; }, 4));
            } else if (block.type === 'table') {
                const columnsLabel = document.createElement('label');
                columnsLabel.textContent = 'Columns (all rows of the view are printed)';
                columnsLabel.style.cssText = 'display:block; margin-bottom:6px; font-weight:600;  color:var(--text);';
                row.appendChild(columnsLabel);

                const columnsHint = document.createElement('p');
                columnsHint.style.cssText = 'margin:0 0 8px;  ';
                columnsHint.textContent = 'Width is a percentage of the table; leave blank to auto-size. Widths do not need to add up to 100. Alignment applies to data cells only — column headers are always centered.';
                row.appendChild(columnsHint);

                if (!Array.isArray(block.columns)) block.columns = [];

                block.columns = block.columns.map(c => (typeof c === 'string' ? { name: c, align: 'left' } : c));

                const columnsBox = document.createElement('div');
                columnsBox.style.cssText = 'display:flex; flex-direction:column; gap:6px;';
                if (currentColumns.length === 0) {
                    const none = document.createElement('span');
                    none.style.cssText = ' ';
                    none.textContent = 'Select a view first to choose columns (empty = all columns).';
                    columnsBox.appendChild(none);
                }
                currentColumns.forEach(column => {
                    let entry = block.columns.find(c => c.name === column.name);

                    const rowWrap = document.createElement('div');
                    rowWrap.style.cssText = 'display:flex; align-items:center; gap:10px;';

                    const lab = document.createElement('label');
                    lab.style.cssText = 'display:flex; align-items:center; gap:5px;  color:var(--text); cursor:pointer; font-weight:normal; min-width:160px;';
                    const chk = document.createElement('input');
                    chk.type = 'checkbox';
                    chk.checked = !!entry;
                    chk.style.cssText = 'width:14px; height:14px; accent- cursor:pointer;';
                    lab.appendChild(chk);
                    lab.appendChild(document.createTextNode(column.name));
                    rowWrap.appendChild(lab);

                    const widthInput = document.createElement('input');
                    widthInput.type = 'number';
                    widthInput.min = '1';
                    widthInput.max = '100';
                    widthInput.placeholder = 'auto %';
                    widthInput.className = 'adm-input w-80';
                    widthInput.value = entry?.width ?? '';
                    widthInput.disabled = !entry;
                    rowWrap.appendChild(widthInput);

                    const alignSelect = document.createElement('select');
                    alignSelect.className = 'adm-input w-110';
                    [['left', 'Left'], ['center', 'Center'], ['right', 'Right']].forEach(([v, l]) => {
                        const o = document.createElement('option');
                        o.value = v;
                        o.textContent = l;
                        if ((entry?.align ?? 'left') === v) o.selected = true;
                        alignSelect.appendChild(o);
                    });
                    alignSelect.disabled = !entry;
                    rowWrap.appendChild(alignSelect);

                    chk.addEventListener('change', () => {
                        if (chk.checked) {
                            entry = { name: column.name, align: alignSelect.value };
                            const w = parseInt(widthInput.value, 10);
                            if (w >= 1 && w <= 100) entry.width = w;
                            block.columns.push(entry);
                        } else {
                            block.columns = block.columns.filter(c => c.name !== column.name);
                            entry = null;
                        }
                        widthInput.disabled = !entry;
                        alignSelect.disabled = !entry;
                    });
                    widthInput.addEventListener('input', () => {
                        if (!entry) return;
                        const w = parseInt(widthInput.value, 10);
                        if (widthInput.value === '') {
                            delete entry.width;
                        } else if (w >= 1 && w <= 100) {
                            entry.width = w;
                        }
                    });
                    alignSelect.addEventListener('change', () => {
                        if (!entry) return;
                        entry.align = alignSelect.value;
                    });

                    columnsBox.appendChild(rowWrap);
                });
                row.appendChild(columnsBox);
            }

            return row;
        }

        function renderParameters() {
            parametersList.innerHTML = '';
            if (parameters.length === 0) {
                const empty = document.createElement('p');
                empty.style.cssText = '  margin:0;';
                empty.textContent = 'No parameters. Add one below to let users filter this report before printing.';
                parametersList.appendChild(empty);
                return;
            }
            parameters.forEach((parameter, index) => parametersList.appendChild(buildParameterRow(parameter, index)));
        }

        function buildParameterRow(parameter, index) {
            const row = document.createElement('div');
            row.className = 'subtable-block';

            const rowHdr = document.createElement('div');
            rowHdr.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:8px;';

            const titleSpan = document.createElement('strong');
            titleSpan.style.cssText = ' color:var(--text);';
            titleSpan.textContent = `${index + 1}. ${parameter.label || parameter.key || 'parameter'}`;
            rowHdr.appendChild(titleSpan);

            const spacer = document.createElement('span');
            spacer.style.flex = '1';
            rowHdr.appendChild(spacer);

            const upButton = document.createElement('button');
            upButton.className = 'item-order-btn';
            upButton.textContent = '^';
            upButton.disabled = index === 0;
            upButton.addEventListener('click', () => {
                [parameters[index - 1], parameters[index]] = [parameters[index], parameters[index - 1]];
                renderParameters();
            });
            const downButton = document.createElement('button');
            downButton.className = 'item-order-btn';
            downButton.textContent = 'v';
            downButton.disabled = index === parameters.length - 1;
            downButton.addEventListener('click', () => {
                [parameters[index + 1], parameters[index]] = [parameters[index], parameters[index + 1]];
                renderParameters();
            });
            const rmButton = document.createElement('button');
            rmButton.className = 'btn btn-danger btn-xs';
            rmButton.textContent = '✕';
            rmButton.addEventListener('click', () => {
                parameters.splice(index, 1);
                renderParameters();
            });
            rowHdr.appendChild(upButton);
            rowHdr.appendChild(downButton);
            rowHdr.appendChild(rmButton);
            row.appendChild(rowHdr);

            row.appendChild(fg('Key (used as p_<key> in the report URL)', parameter.key ?? '', v => {
                parameter.key = v.trim();
                titleSpan.textContent = `${index + 1}. ${parameter.label || parameter.key || 'parameter'}`;
            }));
            row.appendChild(fg('Label (shown to the user)', parameter.label ?? '', v => {
                parameter.label = v;
                titleSpan.textContent = `${index + 1}. ${parameter.label || parameter.key || 'parameter'}`;
            }));

            const columnGroup = document.createElement('div');
            columnGroup.className = 'form-group';
            const columnLabel = document.createElement('label');
            columnLabel.textContent = 'Filter column (in the report view above)';
            columnGroup.appendChild(columnLabel);
            const columnSelect = document.createElement('select');
            const columnNone = document.createElement('option');
            columnNone.value = '';
            columnNone.textContent = '— select column —';
            columnSelect.appendChild(columnNone);
            currentColumns.forEach(c => {
                const o = document.createElement('option');
                o.value = c.name;
                o.textContent = c.name;
                if ((parameter.column ?? '') === c.name) o.selected = true;
                columnSelect.appendChild(o);
            });
            columnSelect.addEventListener('change', () => { parameter.column = columnSelect.value; });
            columnGroup.appendChild(columnSelect);
            row.appendChild(columnGroup);

            const requestLabel = document.createElement('label');
            requestLabel.style.cssText = 'display:flex; align-items:center; gap:6px;  '
                + 'color:var(--text); cursor:pointer; font-weight:normal; margin-bottom:12px;';
            const requestCheckbox = document.createElement('input');
            requestCheckbox.type = 'checkbox';
            requestCheckbox.checked = !!parameter.required;
            requestCheckbox.style.cssText = 'width:14px; height:14px; accent- cursor:pointer;';
            requestCheckbox.addEventListener('change', () => { parameter.required = requestCheckbox.checked; });
            requestLabel.appendChild(requestCheckbox);
            requestLabel.appendChild(document.createTextNode('Required (hides the "— all —" option; user must pick a value)'));
            row.appendChild(requestLabel);

            const sourceGroup = document.createElement('div');
            sourceGroup.className = 'form-group';
            const sourceLabel = document.createElement('label');
            sourceLabel.textContent = 'Lookup view for dropdown options (optional)';
            sourceGroup.appendChild(sourceLabel);
            const sourceSelect = document.createElement('select');
            const sourceNone = document.createElement('option');
            sourceNone.value = '';
            sourceNone.textContent = '— use filter column values —';
            sourceSelect.appendChild(sourceNone);
            dbViews.forEach(v => {
                const o = document.createElement('option');
                o.value = v;
                o.textContent = v;
                if ((parameter.source_view ?? '') === v) o.selected = true;
                sourceSelect.appendChild(o);
            });
            sourceGroup.appendChild(sourceSelect);
            row.appendChild(sourceGroup);

            const valueGroup = document.createElement('div');
            valueGroup.className = 'form-group';
            const valueLabel = document.createElement('label');
            valueLabel.textContent = 'Value column (filtered on)';
            valueGroup.appendChild(valueLabel);
            const valueSelect = document.createElement('select');
            valueGroup.appendChild(valueSelect);
            row.appendChild(valueGroup);

            const labGroup = document.createElement('div');
            labGroup.className = 'form-group';
            const labLabel = document.createElement('label');
            labLabel.textContent = 'Label column (shown in the dropdown)';
            labGroup.appendChild(labLabel);
            const labSelect = document.createElement('select');
            labGroup.appendChild(labSelect);
            row.appendChild(labGroup);

            async function refreshSourceColumns() {
                valueSelect.innerHTML = '';
                labSelect.innerHTML = '';
                if (!sourceSelect.value) {
                    valueGroup.style.display = 'none';
                    labGroup.style.display = 'none';
                    return;
                }
                valueGroup.style.display = '';
                labGroup.style.display = '';
                const columns = await fetchColumns(sourceSelect.value);
                columns.forEach(c => {
                    const ov = document.createElement('option');
                    ov.value = c.name;
                    ov.textContent = c.name;
                    if ((parameter.value_column ?? '') === c.name) ov.selected = true;
                    valueSelect.appendChild(ov);

                    const ol = document.createElement('option');
                    ol.value = c.name;
                    ol.textContent = c.name;
                    if ((parameter.label_column ?? '') === c.name) ol.selected = true;
                    labSelect.appendChild(ol);
                });
            }

            sourceSelect.addEventListener('change', () => {
                parameter.source_view = sourceSelect.value;
                if (!sourceSelect.value) {
                    delete parameter.value_column;
                    delete parameter.label_column;
                }
                refreshSourceColumns();
            });
            valueSelect.addEventListener('change', () => { parameter.value_column = valueSelect.value; });
            labSelect.addEventListener('change', () => { parameter.label_column = labSelect.value; });

            refreshSourceColumns();

            return row;
        }

        const addParameterButton = document.createElement('button');
        addParameterButton.className = 'btn btn-success btn-sm';
        addParameterButton.style.marginBottom = '20px';
        addParameterButton.textContent = '+ Add parameter';
        addParameterButton.addEventListener('click', () => {
            parameters.push({ key: `param${parameters.length + 1}`, label: '', column: '', required: false });
            renderParameters();
        });
        parametersList.after(addParameterButton);

        const addRow = document.createElement('div');
        addRow.style.cssText = 'display:flex; gap:8px; flex-wrap:wrap;';
        [
            { label: '+ Header block', make: () => ({ type: 'header', text: '', level: 1 }) },
            { label: '+ Text block',   make: () => ({ type: 'text', text: '' }) },
            { label: '+ Table block',  make: () => ({ type: 'table', columns: [] }) },
        ].forEach(def => {
            const b = document.createElement('button');
            b.className = 'btn btn-success btn-sm';
            b.textContent = def.label;
            b.addEventListener('click', () => {
                blocks.push(def.make());
                renderBlocks();
            });
            addRow.appendChild(b);
        });
        body.appendChild(addRow);

        await refreshVariables();
    }

    function renderList() {
        listElement.innerHTML = '';
        const names = Object.keys(prints);
        if (names.length === 0) {
            const empty = document.createElement('p');
            empty.style.cssText = ' text-align:center; padding:32px;';
            empty.textContent = 'No printouts yet. Click "+ Add printout" to create the first template.';
            listElement.appendChild(empty);
            return;
        }
        names.forEach(pName => listElement.appendChild(buildPrintCard(pName, prints[pName] ?? {})));
    }

    addButton.addEventListener('click', () => {
        const raw = prompt('Internal key of the new printout (letters, digits, _ or -):', '');
        if (raw === null) return;
        const key = raw.trim();
        if (!/^[a-zA-Z0-9_-]{1,64}$/.test(key)) {
            setStatus('Invalid key — use 1-64 letters, digits, underscores or dashes.', 'error');
            return;
        }
        if (prints[key]) {
            setStatus(`Printout "${key}" already exists.`, 'error');
            return;
        }
        prints[key] = {
            display_name: key,
            menu_name: key,
            description: '',
            icon: 'assets/icons/picture_as_pdf.png',
            hidden: false,
            view: '',
            blocks: [],
            params: [],
        };
        renderList();
        setStatus(`Printout "${key}" added. Configure it below, then click Save config in the top right.`, 'ok');
    });

    (async () => {
        try {
            const result  = await apiFetch('../api/print.php?action=config');
            const data = await result.json();
            if (data.status !== 'ok') {
                setStatus('Failed to load configuration: ' + (data.error ?? 'unknown'), 'error');
                return;
            }
            prints     = data.config?.prints ?? {};
            configVersion = data.version ?? 0;

            if (!prints || typeof prints !== 'object' || Array.isArray(prints)) {
                prints = {};
            }
            dbViews = data.views ?? [];
            renderList();
            if (dbViews.length === 0) {
                setStatus('No PostgreSQL views registered. Sync views in the Views tab first.', 'info');
            }
        } catch {
            setStatus('Network error while loading configuration.', 'error');
        }
    })();
}
