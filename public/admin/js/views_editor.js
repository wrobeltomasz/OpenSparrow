// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { markDirty } from './app.js';
import { createIconPicker, createTextInput, createCheckbox } from './ui.js';
import { apiFetch } from '../../assets/js/util/api.js';

export function renderViewsEditor(context) {
    const { workspaceEl: workspaceElement, currentConfig } = context;
    workspaceElement.innerHTML = '';

    if (!currentConfig.views || typeof currentConfig.views !== 'object' || Array.isArray(currentConfig.views)) {
        currentConfig.views = {};
    }
    const views = currentConfig.views;

    if (!Array.isArray(currentConfig.schemas)) {
        currentConfig.schemas = [];
    }

    Object.keys(views).forEach(viewName => {
        if (!views[viewName].source) views[viewName].source = 'postgres';
    });

    let currentSource = 'postgres';
    let dbColumns     = {};

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';

    const tabBar = document.createElement('div');
    tabBar.className = 'item-panel-items';

    const pgTab       = document.createElement('button');
    const schemasTab  = document.createElement('button');
    const settingsTab = document.createElement('button');
    [pgTab, schemasTab, settingsTab].forEach(tabElement => {
        tabElement.type = 'button';
        tabElement.className = 'item-btn';
    });
    function tabIcon(name) {
        const image = document.createElement('img');
        image.src = '../assets/icons/' + name;
        image.alt = '';
        image.style.cssText = 'width:15px;height:15px;opacity:.6;';
        return image;
    }
    pgTab.append(tabIcon('table_chart_view.png'), document.createTextNode('PostgreSQL Views'));
    schemasTab.append(tabIcon('database.png'), document.createTextNode('Schemas'));
    settingsTab.append(tabIcon('car_gear.png'), document.createTextNode('Global Settings'));
    tabBar.appendChild(pgTab);
    tabBar.appendChild(schemasTab);
    tabBar.appendChild(settingsTab);
    wrap.appendChild(tabBar);

    const headerElement = document.createElement('div');
    headerElement.innerHTML = `
        <h2 class="admin-page-title">Views Configuration</h2>
        <p class="admin-page-desc">Sync to discover PostgreSQL views, configure display names, column colors, and drill-down. Use "Save config" in the top bar to persist.</p>
    `;
    wrap.appendChild(headerElement);

    function updateTabUi() {
        pgTab.classList.toggle('active', currentSource === 'postgres');
        schemasTab.classList.toggle('active', currentSource === 'schemas');
        settingsTab.classList.toggle('active', currentSource === 'settings');
        syncButton.style.display = currentSource === 'postgres' ? '' : 'none';
        syncButton.textContent   = '↻ Sync PostgreSQL Views';
    }

    function switchSource(sourceType) {
        if (currentSource === sourceType) return;
        currentSource = sourceType;
        updateTabUi();
        renderList();
    }
    pgTab.addEventListener('click', () => switchSource('postgres'));
    schemasTab.addEventListener('click', () => switchSource('schemas'));
    settingsTab.addEventListener('click', () => switchSource('settings'));

    const statusElement = document.createElement('div');
    statusElement.style.cssText = 'display:none; padding:8px 14px; border-radius:var(--radius);  margin-bottom:16px;';
    wrap.appendChild(statusElement);

    const bar = document.createElement('div');
    bar.style.marginBottom = '12px';
    const syncButton = document.createElement('button');
    syncButton.className = 'btn btn-success';
    bar.appendChild(syncButton);
    wrap.appendChild(bar);

    const listElement = document.createElement('div');
    wrap.appendChild(listElement);

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

    async function syncFromDb() {
        const label = 'PostgreSQL';
        setStatus(`Syncing ${label} views…`, 'info');
        try {
            const result  = await apiFetch('../api/views.php?action=sync');
            const data = await result.json();
            if (data.status !== 'ok') { setStatus('Sync failed: ' + (data.error ?? 'unknown'), 'error'); return; }
            const synced      = data.db_views ?? [];
            const viewSchemas = data.view_schemas ?? {};
            const viewKinds   = data.view_kinds ?? {};
            Object.assign(dbColumns, data.columns ?? {});

            synced.forEach(vName => {
                const vSchema = viewSchemas[vName];
                if (!views[vName]) {
                    const cols = {};
                    Object.keys(dbColumns[vName] ?? {}).forEach(columnKey => { cols[columnKey] = { display_name: columnKey, color_rules: [] }; });
                    views[vName] = { display_name: vName, menu_name: vName, description: '', icon: 'assets/icons/table_chart_view.png', hidden: false, source: currentSource, columns: cols, drill_down: { enabled: false, levels: [] } };
                } else {
                    views[vName].source = currentSource;
                    Object.keys(dbColumns[vName] ?? {}).forEach(columnKey => {
                        if (!views[vName].columns) views[vName].columns = {};
                        if (!views[vName].columns[columnKey]) views[vName].columns[columnKey] = { display_name: columnKey, color_rules: [] };
                    });
                }
                if (currentSource === 'postgres' && vSchema) {
                    views[vName].schema = vSchema;
                }

                views[vName].materialized = viewKinds[vName] === 'materialized';
            });

            markDirty();
            setStatus(`Found ${synced.length} ${label} view(s). Edit below, then click "Save config".`, 'ok');
            renderList();
        } catch (_) {
            setStatus('Network error during sync.', 'error');
        }
    }

    function viewNamesForSource(sourceType) {
        return Object.keys(views).filter(viewName => (views[viewName].source || 'postgres') === sourceType);
    }

    function renderList() {
        listElement.innerHTML = '';
        if (currentSource === 'schemas') {
            renderSchemasPanel();
            return;
        }
        if (currentSource === 'settings') {
            renderSettingsPanel();
            return;
        }
        const names = viewNamesForSource(currentSource);
        if (names.length === 0) {
            listElement.innerHTML = '';
            const empty = document.createElement('p');
            empty.style.cssText = 'text-align:center; padding:32px;';
            empty.textContent = `No PostgreSQL views found. Click "${syncButton.textContent}" to discover views.`;
            listElement.appendChild(empty);
            return;
        }
        names.forEach(vName => listElement.appendChild(buildViewCard(vName, views[vName] ?? {})));
    }

    async function renderSchemasPanel() {
        listElement.innerHTML = '<p style=" padding:16px;">Loading schemas…</p>';
        try {
            const result  = await apiFetch('../api/views.php?action=schemas');
            const data = await result.json();
            if (data.status !== 'ok') {
                listElement.innerHTML = '';
                const errorParagraph = document.createElement('p');
                errorParagraph.style.cssText = 'color:var(--error); padding:16px;';
                errorParagraph.textContent = 'Failed to load schemas: ' + (data.error ?? 'unknown');
                listElement.appendChild(errorParagraph);
                return;
            }

            if (currentConfig.schemas.length === 0) {
                currentConfig.schemas = [...(data.selected ?? [])];
            }

            listElement.innerHTML = '';
            const intro = document.createElement('p');
            intro.style.cssText = '  margin:0 0 14px;';
            intro.textContent = 'Select which PostgreSQL schemas "↻ Sync PostgreSQL Views" searches for views. Unchecked schemas are skipped.';
            listElement.appendChild(intro);

            const list = document.createElement('div');
            list.style.cssText = 'display:flex; flex-direction:column; gap:8px;';
            (data.schemas ?? []).forEach(schemaName => {
                const row = document.createElement('label');
                row.style.cssText = 'display:flex; align-items:center; gap:10px; padding:8px 12px; border:1px solid var(--border-light); border-radius:var(--radius); cursor:pointer;';

                const callback = document.createElement('input');
                callback.type    = 'checkbox';
                callback.checked = currentConfig.schemas.includes(schemaName);
                callback.addEventListener('change', () => {
                    if (callback.checked) {
                        if (!currentConfig.schemas.includes(schemaName)) currentConfig.schemas.push(schemaName);
                    } else {
                        currentConfig.schemas = currentConfig.schemas.filter(existingSchema => existingSchema !== schemaName);
                    }
                    markDirty();
                });

                const nameSpan = document.createElement('span');
                nameSpan.textContent = schemaName;

                row.appendChild(callback);
                row.appendChild(nameSpan);
                list.appendChild(row);
            });
            listElement.appendChild(list);
        } catch (_) {
            listElement.innerHTML = '<p style="color:var(--error); padding:16px;">Network error while loading schemas.</p>';
        }
    }

    function renderSettingsPanel() {
        const heading = document.createElement('h3');
        heading.textContent = 'Views Global Settings';
        listElement.appendChild(heading);

        listElement.appendChild(createTextInput('menu_name', 'Menu Display Name',
            currentConfig.menu_name || 'Views', viewName => { currentConfig.menu_name = viewName; }));

        listElement.appendChild(createIconPicker('menu_icon', 'Menu Icon',
            currentConfig.menu_icon || '', viewName => {
                if (viewName && viewName.trim() !== '') currentConfig.menu_icon = viewName;
                else delete currentConfig.menu_icon;
            }));

        listElement.appendChild(createCheckbox('hidden', 'Hide from Sidebar Menu',
            currentConfig.hidden, viewName => {
                if (viewName) currentConfig.hidden = true;
                else delete currentConfig.hidden;
            }, false));

        const dangerGroup = document.createElement('div');
        dangerGroup.className = 'form-group';
        dangerGroup.style.cssText = 'margin-top:28px; border-top:1px solid var(--border); padding-top:20px;';

        const clearButton = document.createElement('button');
        clearButton.type = 'button';
        clearButton.className = 'btn btn-danger';
        clearButton.textContent = 'Clear Entire Config';
        clearButton.addEventListener('click', () => {
            if (!confirm('Are you sure you want to completely clear the Views configuration?')) return;
            currentConfig.views = {};
            currentConfig.schemas = [];
            delete currentConfig.menu_icon;
            delete currentConfig.hidden;
            currentConfig.menu_name = 'Views';
            markDirty();
            renderList();
        });
        dangerGroup.appendChild(clearButton);

        const clearHelp = document.createElement('span');
        clearHelp.className = 'help-text';
        clearHelp.textContent = 'Removes all views and resets the menu name, icon and visibility. Press "Save config" in the top bar to apply.';
        dangerGroup.appendChild(clearHelp);

        listElement.appendChild(dangerGroup);
    }

    function buildViewCard(vName, config) {
        const card = document.createElement('div');
        card.className = 'column-block collapsed';
        card.dataset.view = vName;
        if (config.hidden) card.style.opacity = '0.6';

        const cardHdr = document.createElement('div');
        cardHdr.className = 'block-header';

        const chevron = document.createElement('span');
        chevron.className = 'block-chevron';
        chevron.textContent = '▶';

        const nameSpan = document.createElement('strong');
        nameSpan.className = 'block-title';
        nameSpan.textContent = config.display_name ?? vName;
        const dbSpan = document.createElement('span');
        dbSpan.className = 'block-key';
        dbSpan.textContent = ` (${vName})`;
        nameSpan.appendChild(dbSpan);

        if (config.materialized) {
            const matBadge = document.createElement('span');
            matBadge.className = 'adm-badge adm-badge-muted';
            matBadge.style.marginLeft = '8px';
            matBadge.title = 'Materialized view — shows data from its last REFRESH MATERIALIZED VIEW, not live rows.';
            matBadge.textContent = 'Materialized';
            nameSpan.appendChild(matBadge);
        }

        const visibleLabel = document.createElement('label');
        visibleLabel.className = 'block-vis';
        const visibleCheckbox = document.createElement('input');
        visibleCheckbox.type    = 'checkbox';
        visibleCheckbox.checked = !config.hidden;
        visibleCheckbox.className = 'adm-check';
        visibleCheckbox.addEventListener('change', event => {
            views[vName].hidden = !event.target.checked;
            card.style.opacity = views[vName].hidden ? '0.6' : '1';
        });
        visibleLabel.appendChild(visibleCheckbox);
        visibleLabel.appendChild(document.createTextNode('Visible'));

        const delButton = document.createElement('button');
        delButton.type = 'button';
        delButton.title = 'Delete';
        delButton.textContent = '✕';
        delButton.className = 'icon-btn icon-btn-danger';
        delButton.addEventListener('click', (event) => {
            event.stopPropagation();
            if (!confirm(`Remove view "${config.display_name ?? vName}" from the configuration? It reappears on the next sync if it still exists in the database.`)) return;
            delete views[vName];
            markDirty();
            renderList();
        });

        cardHdr.appendChild(chevron);
        cardHdr.appendChild(nameSpan);
        cardHdr.appendChild(visibleLabel);
        cardHdr.appendChild(delButton);
        card.appendChild(cardHdr);

        const body = document.createElement('div');
        body.className = 'block-body';
        body.appendChild(buildCardBody(vName, config));
        card.appendChild(body);

        cardHdr.addEventListener('click', (event) => {
            if (event.target.closest('button, input, label')) return;
            card.classList.toggle('collapsed');
        });

        return card;
    }

    function buildCardBody(vName, config) {
        const frag = document.createDocumentFragment();

        const genHdr = document.createElement('h4');
        genHdr.textContent = 'General';
        frag.appendChild(genHdr);

        frag.appendChild(fg('Display name', 'text', config.display_name ?? vName, viewName => { views[vName].display_name = viewName; }));
        frag.appendChild(fg('Menu name',    'text', config.menu_name    ?? vName, viewName => { views[vName].menu_name    = viewName; }));
        frag.appendChild(fgArea('Description', config.description ?? '', viewName => { views[vName].description = viewName; }));
        frag.appendChild(createIconPicker('icon', 'Icon', config.icon ?? 'assets/icons/table_chart_view.png', viewName => { views[vName].icon = viewName; markDirty(); }));

        const divider1 = document.createElement('hr');
        divider1.style.cssText = 'border:none; border-top:1px solid var(--border-light); margin:20px 0;';
        frag.appendChild(divider1);

        const columnHdr = document.createElement('h4');
        columnHdr.textContent = 'Columns';
        frag.appendChild(columnHdr);
        frag.appendChild(buildColumnsEditor(vName, config.columns ?? {}));

        const divider2 = document.createElement('hr');
        divider2.style.cssText = 'border:none; border-top:1px solid var(--border-light); margin:20px 0;';
        frag.appendChild(divider2);

        const drillHdr = document.createElement('h4');
        drillHdr.textContent = 'Drill-down';
        frag.appendChild(drillHdr);
        frag.appendChild(buildDrillEditor(vName, config));

        return frag;
    }

    function fg(label, type, value, onChange) {
        const group = document.createElement('div');
        group.className = 'form-group';
        const labelElement = document.createElement('label');
        labelElement.textContent = label;
        group.appendChild(labelElement);
        const input = document.createElement('input');
        input.type = type; input.value = value ?? '';
        input.addEventListener('input', () => onChange(input.value));
        group.appendChild(input);
        return group;
    }

    function fgArea(label, value, onChange) {
        const group = document.createElement('div');
        group.className = 'form-group';
        const labelElement = document.createElement('label');
        labelElement.textContent = label;
        group.appendChild(labelElement);
        const textarea = document.createElement('textarea');
        textarea.rows = 3; textarea.style.resize = 'vertical';
        textarea.value = value ?? '';
        textarea.addEventListener('input', () => onChange(textarea.value));
        group.appendChild(textarea);
        return group;
    }

    function buildColumnsEditor(vName, columnsConfig) {
        const wrap = document.createElement('div');

        const dbCols  = Object.keys(dbColumns[vName] ?? {});
        const allColumns = dbCols.length > 0 ? dbCols : Object.keys(columnsConfig);

        if (allColumns.length === 0) {
            wrap.innerHTML = '<p style=" ">Sync from DB to see columns.</p>';
            return wrap;
        }

        allColumns.forEach(columnName => {
            const columnConfig = columnsConfig[columnName] ?? { display_name: columnName, color_rules: [] };
            if (!views[vName].columns) views[vName].columns = {};
            if (!views[vName].columns[columnName]) views[vName].columns[columnName] = { display_name: columnName, color_rules: [] };

            const columnBlock = document.createElement('div');
            columnBlock.className = 'subtable-block';

            const columnHdr = document.createElement('h4');
            columnHdr.style.cssText = 'display:flex; align-items:center; gap:8px;';
            const columnNameSpan = document.createElement('span');
            columnNameSpan.textContent = columnName;
            columnHdr.appendChild(columnNameSpan);
            const dtype = dbColumns[vName]?.[columnName]?.data_type ?? '';
            if (dtype) {
                const badge = document.createElement('span');
                badge.textContent = dtype;
                badge.style.cssText = ' font-weight:400;  background:var(--border-light); padding:1px 6px; border-radius:10px;';
                columnHdr.appendChild(badge);
            }
            columnBlock.appendChild(columnHdr);

            columnBlock.appendChild(fg('Display name', 'text', columnConfig.display_name ?? columnName, viewName => {
                views[vName].columns[columnName].display_name = viewName;
            }));

            const summaryGroup = document.createElement('div');
            summaryGroup.className = 'form-group';
            const summaryLabel = document.createElement('label');
            summaryLabel.textContent = 'Summary';
            summaryGroup.appendChild(summaryLabel);
            const summarySelect = document.createElement('select');
            ['none', 'sum', 'avg', 'count', 'min', 'max'].forEach(handler => {
                const option = document.createElement('option');
                option.value = handler;
                option.textContent = handler === 'none' ? 'None' : handler.toUpperCase();
                if ((columnConfig.summary ?? 'none') === handler) option.selected = true;
                summarySelect.appendChild(option);
            });
            summarySelect.addEventListener('change', () => {
                const viewName = summarySelect.value;
                if (viewName === 'none') {
                    delete views[vName].columns[columnName].summary;
                    delete views[vName].columns[columnName].summary_if;
                    syncConditionUi();
                } else {
                    views[vName].columns[columnName].summary = viewName;
                }
                conditionGroup.style.display = viewName === 'none' ? 'none' : 'block';
                markDirty();
            });
            summaryGroup.appendChild(summarySelect);
            columnBlock.appendChild(summaryGroup);

            const conditionGroup = document.createElement('div');
            conditionGroup.className = 'form-group';
            conditionGroup.style.display = (columnConfig.summary ?? 'none') === 'none' ? 'none' : 'block';
            const conditionLabel = document.createElement('label');
            conditionLabel.textContent = 'Summary condition (SUMIF / COUNTIF)';
            conditionGroup.appendChild(conditionLabel);

            const conditionRow = document.createElement('div');
            conditionRow.style.cssText = 'display:flex; align-items:center; gap:8px;';

            const conditionColumnSelect = document.createElement('select');
            conditionColumnSelect.className = 'adm-input';
            conditionColumnSelect.style.flex = '1';
            const conditionNone = document.createElement('option');
            conditionNone.value = '';
            conditionNone.textContent = '— no condition —';
            conditionColumnSelect.appendChild(conditionNone);
            allColumns.forEach(columnKey => {
                const optionElement = document.createElement('option');
                optionElement.value = columnKey;
                optionElement.textContent = columnKey;
                if (columnConfig.summary_if?.column === columnKey) optionElement.selected = true;
                conditionColumnSelect.appendChild(optionElement);
            });

            const conditionOpSelect = document.createElement('select');
            conditionOpSelect.className = 'adm-input w-110';
            ['==', '!=', '>', '>=', '<', '<=', 'contains'].forEach(operator => {
                const optionElement = document.createElement('option');
                optionElement.value = operator;
                optionElement.textContent = operator;
                if ((columnConfig.summary_if?.op ?? '==') === operator) optionElement.selected = true;
                conditionOpSelect.appendChild(optionElement);
            });

            const conditionValueInput = document.createElement('input');
            conditionValueInput.type        = 'text';
            conditionValueInput.className   = 'adm-input';
            conditionValueInput.style.flex  = '1';
            conditionValueInput.placeholder = 'Value';
            conditionValueInput.value       = columnConfig.summary_if?.value ?? '';

            function syncConditionUi() {
                const active = conditionColumnSelect.value !== '';
                conditionOpSelect.disabled  = !active;
                conditionValueInput.disabled = !active;
                if (!views[vName].columns[columnName].summary_if) {
                    conditionColumnSelect.value = '';
                    conditionOpSelect.disabled  = true;
                    conditionValueInput.disabled = true;
                }
            }

            function updateCondition() {
                if (conditionColumnSelect.value === '') {
                    delete views[vName].columns[columnName].summary_if;
                } else {
                    views[vName].columns[columnName].summary_if = {
                        column: conditionColumnSelect.value,
                        op:     conditionOpSelect.value,
                        value:  conditionValueInput.value,
                    };
                }
                syncConditionUi();
                markDirty();
            }
            conditionColumnSelect.addEventListener('change', updateCondition);
            conditionOpSelect.addEventListener('change', updateCondition);
            conditionValueInput.addEventListener('input', updateCondition);
            syncConditionUi();

            conditionRow.appendChild(conditionColumnSelect);
            conditionRow.appendChild(conditionOpSelect);
            conditionRow.appendChild(conditionValueInput);
            conditionGroup.appendChild(conditionRow);
            columnBlock.appendChild(conditionGroup);

            const rulesLabel = document.createElement('label');
            rulesLabel.textContent = 'Color rules';
            rulesLabel.style.cssText = 'display:block; margin-bottom:8px; font-weight:600;  color:var(--text);';
            columnBlock.appendChild(rulesLabel);

            const rulesList = document.createElement('div');
            rulesList.style.cssText = 'display:flex; flex-direction:column; gap:6px; margin-bottom:10px;';
            columnBlock.appendChild(rulesList);

            const rules = Array.isArray(columnConfig.color_rules) ? columnConfig.color_rules : [];
            views[vName].columns[columnName].color_rules = rules;

            function renderRules() {
                rulesList.innerHTML = '';
                rules.forEach((rule, index) => rulesList.appendChild(buildRuleRow(rule, index, rules, renderRules)));
            }
            renderRules();

            const addRuleButton = document.createElement('button');
            addRuleButton.className   = 'btn btn-success btn-sm';
            addRuleButton.textContent = '+ Add color rule';
            addRuleButton.addEventListener('click', () => {
                rules.push({ op: '>', value: 0, color: '#AB0000' });
                renderRules();
                markDirty();
            });
            columnBlock.appendChild(addRuleButton);

            wrap.appendChild(columnBlock);
        });

        return wrap;
    }

    function buildRuleRow(rule, index, rules, onUpdate) {
        const row = document.createElement('div');
        row.style.cssText = 'display:flex; align-items:center; gap:8px;';

        const opSelect = document.createElement('select');
        opSelect.className = 'adm-input w-64';
        ['>', '>=', '<', '<=', '=='].forEach(operator => {
            const optionElement = document.createElement('option');
            optionElement.value = operator; optionElement.textContent = operator;
            if (rule.op === operator) optionElement.selected = true;
            opSelect.appendChild(optionElement);
        });
        opSelect.addEventListener('change', () => { rules[index].op = opSelect.value; });

        const valueInput = document.createElement('input');
        valueInput.type  = 'number';
        valueInput.className = 'adm-input w-100';
        valueInput.value = rule.value ?? 0;
        valueInput.addEventListener('input', () => { rules[index].value = parseFloat(valueInput.value) || 0; });

        const colorInput = document.createElement('input');
        colorInput.type  = 'color';
        colorInput.className = 'adm-color';
        colorInput.value = rule.color ?? '#AB0000';
        colorInput.addEventListener('input', () => { rules[index].color = colorInput.value; });

        const delButton = document.createElement('button');
        delButton.className   = 'btn btn-danger btn-xs';
        delButton.textContent = '✕ Remove';
        delButton.addEventListener('click', () => { rules.splice(index, 1); onUpdate(); markDirty(); });

        row.appendChild(opSelect); row.appendChild(valueInput); row.appendChild(colorInput); row.appendChild(delButton);
        return row;
    }

    function buildDrillEditor(vName, config) {
        const wrap = document.createElement('div');
        const dd   = config.drill_down ?? { enabled: false, levels: [] };
        views[vName].drill_down = dd;

        const enableGroup = document.createElement('div');
        enableGroup.className = 'form-group';
        const enableLabel = document.createElement('label');
        enableLabel.textContent = 'Enable drill-down';
        enableGroup.appendChild(enableLabel);
        const enableCheckbox = document.createElement('input');
        enableCheckbox.type    = 'checkbox';
        enableCheckbox.checked = !!dd.enabled;
        enableCheckbox.addEventListener('change', () => { views[vName].drill_down.enabled = enableCheckbox.checked; });
        enableGroup.appendChild(enableCheckbox);
        wrap.appendChild(enableGroup);

        const levelsLabel = document.createElement('label');
        levelsLabel.textContent = 'Levels (ordered)';
        levelsLabel.style.cssText = 'display:block; margin-bottom:8px; font-weight:600;  color:var(--text);';
        wrap.appendChild(levelsLabel);

        const levelsList = document.createElement('div');
        levelsList.style.cssText = 'display:flex; flex-direction:column; gap:8px; margin-bottom:12px;';
        wrap.appendChild(levelsList);

        const dbCols  = Object.keys(dbColumns[vName] ?? {});
        const allColumns = dbCols.length > 0 ? dbCols : Object.keys(views[vName].columns ?? {});

        function renderLevels() {
            levelsList.innerHTML = '';
            (dd.levels ?? []).forEach((lvl, index) => {
                const levelRow = document.createElement('div');
                levelRow.style.cssText = 'display:flex; align-items:center; gap:8px; padding:8px 12px; background:var(--bg); border:1px solid var(--border-light); border-radius:var(--radius);';

                const indexSpan = document.createElement('span');
                indexSpan.style.cssText = '  min-width:52px;';
                indexSpan.textContent = `Level ${index}:`;

                const gbSelect = document.createElement('select');
                gbSelect.className = 'adm-input';
                gbSelect.style.flex = '1';
                allColumns.forEach(columnKey => {
                    const optionElement = document.createElement('option');
                    optionElement.value = columnKey; optionElement.textContent = columnKey;
                    if (lvl.group_by === columnKey) optionElement.selected = true;
                    gbSelect.appendChild(optionElement);
                });
                gbSelect.addEventListener('change', () => { dd.levels[index].group_by = gbSelect.value; });

                const labelInput = document.createElement('input');
                labelInput.type        = 'text';
                labelInput.placeholder = 'Label (optional)';
                labelInput.value       = lvl.label ?? '';
                labelInput.className = 'adm-input';
                labelInput.style.flex = '1';
                labelInput.addEventListener('input', () => { dd.levels[index].label = labelInput.value; });

                const delButton = document.createElement('button');
                delButton.className   = 'btn btn-danger btn-xs';
                delButton.textContent = '✕';
                delButton.addEventListener('click', () => { dd.levels.splice(index, 1); renderLevels(); markDirty(); });

                levelRow.appendChild(indexSpan); levelRow.appendChild(gbSelect); levelRow.appendChild(labelInput); levelRow.appendChild(delButton);
                levelsList.appendChild(levelRow);
            });
        }
        renderLevels();

        const addLevelButton = document.createElement('button');
        addLevelButton.className   = 'btn btn-success btn-sm';
        addLevelButton.textContent = '+ Add level';
        addLevelButton.addEventListener('click', () => {
            if (!dd.levels) dd.levels = [];
            dd.levels.push({ group_by: allColumns[0] ?? '', label: '' });
            renderLevels();
            markDirty();
        });
        wrap.appendChild(addLevelButton);
        return wrap;
    }

    syncButton.addEventListener('click', syncFromDb);

    Object.keys(views).forEach(viewName => {
        dbColumns[viewName] = {};
        Object.keys(views[viewName].columns ?? {}).forEach(columnKey => { dbColumns[viewName][columnKey] = { data_type: '' }; });
    });

    updateTabUi();

    if (Object.keys(views).length > 0) {
        renderList();
        setStatus('Config loaded. Sync to refresh column metadata from DB.', 'info');
    } else {
        renderList();
    }
}
