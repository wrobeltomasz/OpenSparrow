// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { buildInnerTabs, createPageHeader, mkTable, mkThead, td, tdStatus, buildSectionCard } from './ui.js';

let anonymizationConfig  = null;
let anonymizationVersion = 0;
let schemaCache = null;

import { escHtml } from '../../assets/js/util/esc.js';
import { getGlobalSchema } from './app.js';

const mkSection = (title, description) => buildSectionCard(title, description);

function mkStatusElement() {
    const element = document.createElement('p');
    element.style.cssText = 'margin-top:10px;  display:none;';
    return element;
}

function showStatus(element, messageElement, ok) {
    element.textContent = messageElement;
    element.style.color = ok ? 'var(--ok)' : 'var(--error)';
    element.style.display = '';
}

async function saveConfig(partial, statusElement) {
    Object.assign(anonymizationConfig, partial);
    try {
        const result  = await apiFetch('api.php?action=anonymization_save', {
            method:  'POST',
            body:    JSON.stringify({ ...anonConfig, version: anonymizationVersion }),
        });
        const data = await result.json();
        if (data.status === 'success') {
            anonymizationVersion = data.version ?? anonymizationVersion + 1;
            if (statusElement) showStatus(statusElement, 'Configuration saved.', true);
        } else {
            if (statusElement) showStatus(statusElement, 'Error: ' + (data.error || 'unknown'), false);
        }
    } catch (error) {
        if (statusElement) showStatus(statusElement, 'Request failed: ' + error.message, false);
    }
}

function isDateType(type) {
    const tableName = (type || '').toLowerCase();
    return tableName === 'date' || tableName.includes('timestamp') || tableName === 'datetime';
}

async function getSchema() {
    schemaCache = await getGlobalSchema();
    return schemaCache ?? {};
}

function buildRulesTab(context) {
    const { card, body } = mkSection(
        'Anonymization Rules',
        'Each rule anonymizes a PII column for records older than the configured number of days.'
    );

    const tableOptions = context.getTableOptions ? context.getTableOptions() : [];

    function renderRulesTable() {
        body.innerHTML = '';

        const rules = anonymizationConfig.rules || [];

        if (rules.length > 0) {
            const tableElement = document.createElement('table');
            tableElement.className = 'adm-tbl';
            tableElement.style.marginBottom = '20px';

            const thead = tableElement.createTHead();
            const headerRow    = thead.insertRow();
            ['Table', 'Date Column', 'Older Than', 'PII Column', 'Replacement', ''].forEach(headerLabel => {
                const th = document.createElement('th');
                th.className  = 'adm-th';
                th.textContent = headerLabel;
                headerRow.appendChild(th);
            });

            const tbody = tableElement.createTBody();
            rules.forEach((rule, index) => {
                const tr = tbody.insertRow();

                const tdTable = document.createElement('td');
                tdTable.className   = 'adm-td';
                tdTable.textContent = rule.table;
                tr.appendChild(tdTable);

                const tdDateColumn = document.createElement('td');
                tdDateColumn.className        = 'adm-td';
                tdDateColumn.style.fontFamily = 'monospace';
                tdDateColumn.textContent      = rule.date_column || '—';
                tr.appendChild(tdDateColumn);

                const tdDays = document.createElement('td');
                tdDays.className   = 'adm-td';
                tdDays.textContent = rule.days ? rule.days + ' days' : '—';
                tr.appendChild(tdDays);

                const tdColumn = document.createElement('td');
                tdColumn.className        = 'adm-td';
                tdColumn.style.fontFamily = 'monospace';
                tdColumn.textContent      = rule.column;
                tr.appendChild(tdColumn);

                const tdRepl = document.createElement('td');
                tdRepl.className        = 'adm-td';
                tdRepl.style.fontFamily = 'monospace';
                tdRepl.textContent      = rule.replacement;
                tr.appendChild(tdRepl);

                const tdAct = document.createElement('td');
                tdAct.className = 'adm-td';
                const delButton = document.createElement('button');
                delButton.className = 'btn btn-danger btn-xs';
                delButton.textContent = '✕ Remove';
                delButton.addEventListener('click', async () => {
                    if (!confirm('Remove rule for ' + rule.table + '.' + rule.column + '?')) return;
                    anonymizationConfig.rules.splice(index, 1);
                    const statusPill = mkStatusElement();
                    await saveConfig({}, statusPill);
                    renderRulesTable();
                });
                tdAct.appendChild(delButton);
                tr.appendChild(tdAct);
            });

            body.appendChild(tableElement);
            buildPreviewBlock(body);
        } else {
            const empty = document.createElement('p');
            empty.textContent = 'No rules configured yet. Use the form below to add one.';
            empty.style.cssText = ' margin-bottom:16px;';
            body.appendChild(empty);
        }

        buildAddForm(body, tableOptions, renderRulesTable);
    }

    renderRulesTable();
    return card;
}

function buildPreviewBlock(container) {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'margin-bottom:20px;';

    const button = document.createElement('button');
    button.className   = 'btn btn-primary';
    button.textContent = 'Preview (dry run)';

    const hint = document.createElement('span');
    hint.textContent = 'Counts how many rows each rule would anonymize — no data is modified.';
    hint.style.cssText = 'margin-left:12px;  ';

    const outputElement = document.createElement('pre');
    outputElement.style.cssText = 'margin-top:12px; padding:12px; background:var(--bg); border:1px solid var(--border); border-radius:4px;  line-height:1.6; max-height:300px; overflow-y:auto; white-space:pre-wrap; display:none;';

    button.addEventListener('click', async () => {
        button.disabled    = true;
        button.textContent = 'Previewing…';
        outputElement.style.display = '';
        outputElement.textContent   = 'Please wait…';
        outputElement.style.color   = '';
        try {
            const result  = await apiFetch('api.php?action=preview_anonymization', {
                method:  'POST',
            });
            const data = await result.json();
            if (data.status === 'success') {
                outputElement.textContent = data.output || '(no output)';
            } else {
                outputElement.textContent = 'Error: ' + (data.error || 'unknown');
                outputElement.style.color = 'var(--error)';
            }
        } catch (error) {
            outputElement.textContent = 'Request failed: ' + error.message;
            outputElement.style.color = 'var(--error)';
        }
        button.disabled    = false;
        button.textContent = 'Preview (dry run)';
    });

    wrap.append(button, hint, outputElement);
    container.appendChild(wrap);
}

function buildAddForm(container, tableOptions, onAdded) {
    const formCard = document.createElement('div');
    formCard.style.cssText = 'background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:16px; max-width:900px;';

    const title = document.createElement('strong');
    title.textContent = 'Add Rule';
    title.style.cssText = 'display:block; margin-bottom:12px; ';
    formCard.appendChild(title);

    const row = document.createElement('div');
    row.style.cssText = 'display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;';

    function mkField(labelText, element, width) {
        const wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex; flex-direction:column; gap:4px;';
        const labelElement = document.createElement('label');
        labelElement.textContent = labelText;
        labelElement.style.cssText = ' ';
        element.className = 'adm-input';
        if (width) element.style.width = width;
        wrap.append(labelElement, element);
        return wrap;
    }

    const tableSelect  = document.createElement('select');
    const dateColumnSelect = document.createElement('select');
    const daysInput    = document.createElement('input');
    const columnSelect    = document.createElement('select');
    const replInput    = document.createElement('input');

    daysInput.type        = 'number';
    daysInput.min         = '1';
    daysInput.value       = '365';
    daysInput.placeholder = 'e.g. 365';

    replInput.type        = 'text';
    replInput.placeholder = 'e.g. ***ANONYMIZED***';

    tableOptions.forEach(option => {
        const optionElement = document.createElement('option');
        optionElement.value = option.value; optionElement.textContent = option.label;
        tableSelect.appendChild(optionElement);
    });

    async function refreshColumns() {
        columnSelect.innerHTML     = '';
        dateColumnSelect.innerHTML = '';

        const tableElement = tableSelect.value;
        if (!tableElement) {
            [columnSelect, dateColumnSelect].forEach(selectElement => {
                const optionElement = document.createElement('option');
                optionElement.value = ''; optionElement.textContent = '-- Select --';
                selectElement.appendChild(optionElement);
            });
            return;
        }

        const allOptions = window._anonColOptions ? window._anonColOptions(tableElement) : [];
        if (allOptions.length === 0) {
            const optionElement = document.createElement('option');
            optionElement.value = ''; optionElement.textContent = '-- No columns --';
            columnSelect.appendChild(optionElement);
        } else {
            allOptions.forEach(option => {
                const optionElement = document.createElement('option');
                optionElement.value = option.value; optionElement.textContent = option.label;
                columnSelect.appendChild(optionElement);
            });
        }

        try {
            const schema   = await getSchema();
            const tDef     = (schema.tables || {})[tableElement] || {};
            const dateOptions = Object.entries(tDef.columns || {})
                .filter(([, def]) => isDateType(def.type))
                .map(([name, def]) => ({ value: name, label: def.display_name || name }));

            if (dateOptions.length === 0) {
                const optionElement = document.createElement('option');
                optionElement.value = ''; optionElement.textContent = '-- No date/timestamp columns --';
                dateColumnSelect.appendChild(optionElement);
            } else {
                dateOptions.forEach(({ value, label }) => {
                    const optionElement = document.createElement('option');
                    optionElement.value = value; optionElement.textContent = label;
                    dateColumnSelect.appendChild(optionElement);
                });
            }
        } catch (error) {
            const optionElement = document.createElement('option');
            optionElement.value = ''; optionElement.textContent = '-- Error loading --';
            dateColumnSelect.appendChild(optionElement);
        }
    }

    tableSelect.addEventListener('change', () => refreshColumns());
    refreshColumns();

    const addButton = document.createElement('button');
    addButton.className   = 'btn btn-primary';
    addButton.textContent = '+ Add Rule';
    addButton.style.alignSelf = 'flex-end';

    const statusPill = mkStatusElement();

    addButton.addEventListener('click', async () => {
        const tableName    = tableSelect.value.trim();
        const dateColumnName   = dateColumnSelect.value.trim();
        const days = parseInt(daysInput.value, 10);
        const columnName    = columnSelect.value.trim();
        const replacementValue    = replInput.value;

        if (!tableName || !dateColumnName) {
            showStatus(statusPill, 'Select a table and a date/timestamp column.', false);
            return;
        }
        if (!columnName) {
            showStatus(statusPill, 'Select a PII column to anonymize.', false);
            return;
        }
        if (isNaN(days) || days < 1) {
            showStatus(statusPill, 'Enter a valid number of days (minimum 1).', false);
            return;
        }
        const duplicate = (anonymizationConfig.rules || []).some(existingRule => existingRule.table === tableName && existingRule.column === columnName && existingRule.date_column === dateColumnName);
        if (duplicate) {
            showStatus(statusPill, 'A rule for ' + tableName + '.' + columnName + ' with that date column already exists.', false);
            return;
        }
        anonymizationConfig.rules = anonymizationConfig.rules || [];
        anonymizationConfig.rules.push({ table: tableName, date_column: dateColumnName, days, column: columnName, replacement: replacementValue });
        addButton.disabled = true;
        await saveConfig({}, statusPill);
        addButton.disabled = false;
        if (statusPill.style.color === 'rgb(43, 147, 72)') {
            replInput.value   = '';
            daysInput.value   = '365';
            tableSelect.value = tableOptions.length > 0 ? tableOptions[0].value : '';
            refreshColumns();
            onAdded();
        }
    });

    const tableWrap   = mkField('Table', tableSelect, '180px');
    const dateColumnWrap = mkField('Date Column', dateColumnSelect, '190px');
    const daysWrap    = mkField('Older Than (days)', daysInput, '110px');
    const columnWrap     = mkField('PII Column', columnSelect, '180px');
    const replWrap    = mkField('Replacement Value', replInput, '');
    replWrap.style.cssText += 'flex:1; min-width:140px;';

    row.append(tableWrap, dateColumnWrap, daysWrap, columnWrap, replWrap, addButton);
    formCard.append(row, statusPill);
    container.appendChild(formCard);
}

function buildScheduleTab() {
    const { card, body } = mkSection(
        'Schedule',
        'Configure when anonymization runs. Frequency is enforced by the cron script itself — set your OS scheduler to run daily and let the module handle the window.'
    );

    const enabledRow = document.createElement('div');
    enabledRow.style.cssText = 'display:flex; align-items:center; gap:10px; margin-bottom:20px;';
    const enabledCheckbox = document.createElement('input');
    enabledCheckbox.type    = 'checkbox';
    enabledCheckbox.id      = 'anon-enabled';
    enabledCheckbox.checked = anonymizationConfig.enabled;
    enabledCheckbox.className = 'adm-check';
    const enabledLabel = document.createElement('label');
    enabledLabel.htmlFor     = 'anon-enabled';
    enabledLabel.textContent = 'Anonymization enabled';
    enabledLabel.style.cssText = ' cursor:pointer;';
    enabledRow.append(enabledCheckbox, enabledLabel);
    body.appendChild(enabledRow);

    const freqRow = document.createElement('div');
    freqRow.style.cssText = 'display:flex; align-items:center; gap:10px; margin-bottom:20px;';
    const freqLabel = document.createElement('label');
    freqLabel.htmlFor     = 'anon-frequency';
    freqLabel.textContent = 'Frequency:';
    freqLabel.style.cssText = '  white-space:nowrap;';
    const freqSelect = document.createElement('select');
    freqSelect.id        = 'anon-frequency';
    freqSelect.className = 'adm-input w-180';
    [
        { value: 'manual',  label: 'Manual only (admin panel)' },
        { value: 'daily',   label: 'Daily' },
        { value: 'weekly',  label: 'Weekly' },
        { value: 'monthly', label: 'Monthly' },
    ].forEach(({ value, label }) => {
        const optionElement = document.createElement('option');
        optionElement.value       = value;
        optionElement.textContent = label;
        if (value === anonymizationConfig.frequency) optionElement.selected = true;
        freqSelect.appendChild(optionElement);
    });
    freqRow.append(freqLabel, freqSelect);
    body.appendChild(freqRow);

    const saveSt = mkStatusElement();

    const saveButton = document.createElement('button');
    saveButton.className   = 'btn btn-primary';
    saveButton.textContent = 'Save Schedule Settings';
    saveButton.style.marginBottom = '24px';
    saveButton.addEventListener('click', async () => {
        saveButton.disabled = true;
        await saveConfig({ enabled: enabledCheckbox.checked, frequency: freqSelect.value }, saveSt);
        saveButton.disabled = false;
    });
    body.append(saveButton, saveSt);

    const { card: runCard, body: runBody } = mkSection(
        'Run Anonymization Now',
        'Trigger the anonymization cron immediately, bypassing the frequency check.'
    );

    const runButton = document.createElement('button');
    runButton.className   = 'btn btn-primary';
    runButton.textContent = 'Run Now';

    const output = document.createElement('pre');
    output.style.cssText = 'margin-top:14px; padding:12px; background:var(--bg); border:1px solid var(--border); border-radius:4px;  line-height:1.6; max-height:300px; overflow-y:auto; white-space:pre-wrap; display:none;';

    runButton.addEventListener('click', async () => {
        runButton.disabled    = true;
        runButton.textContent = 'Running…';
        output.style.display = '';
        output.textContent   = 'Please wait…';
        output.style.color   = '';
        try {
            const result  = await apiFetch('api.php?action=run_anonymization', {
                method:  'POST',
            });
            const data = await result.json();
            if (data.status === 'success') {
                output.textContent = data.output || '(no output)';
            } else {
                output.textContent = 'Error: ' + (data.error || 'unknown');
                output.style.color = 'var(--error)';
            }
        } catch (error) {
            output.textContent = 'Request failed: ' + error.message;
            output.style.color = 'var(--error)';
        }
        runButton.disabled    = false;
        runButton.textContent = 'Run Now';
    });

    runBody.append(runButton, output);
    body.appendChild(runCard);

    const { card: setupCard, body: setupBody } = mkSection(
        'Cron Setup Guide',
        'Configure your OS scheduler to invoke the anonymization script automatically.'
    );

    const cronPath = window.location.origin + '/cron/cron_anonymization.php';

    function guideBlock(heading, code, note) {
        const wrap = document.createElement('div');
        wrap.style.cssText = 'background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:14px; margin-bottom:12px;';
        const headerLabel = document.createElement('strong');
        headerLabel.textContent = heading;
        headerLabel.style.cssText = 'display:block; margin-bottom:8px; ';
        const preElement = document.createElement('pre');
        preElement.style.cssText = 'margin:0 0 6px;  background:var(--accent-dark); color:var(--accent-light); padding:10px 12px; border-radius:4px; overflow-x:auto; white-space:pre-wrap;';
        preElement.textContent = code;
        wrap.append(headerLabel, preElement);
        if (note) {
            const paragraphNote = document.createElement('p');
            paragraphNote.textContent = note;
            paragraphNote.style.cssText = 'margin:6px 0 0;  ';
            wrap.appendChild(paragraphNote);
        }
        return wrap;
    }

    setupBody.appendChild(guideBlock(
        'Linux / macOS — crontab (daily at 02:00)',
        `0 2 * * * php ${cronPath}`,
        'Run: crontab -e  then paste the line above. The script enforces its own frequency window.'
    ));
    setupBody.appendChild(guideBlock(
        'Windows — Task Scheduler (daily)',
        `schtasks /create /tn "OpenSparrow Anonymization" /tr "php ${cronPath}" /sc daily /st 02:00`,
        'Run as the same user Apache/PHP runs under.'
    ));
    setupBody.appendChild(guideBlock(
        'Docker — add to docker-compose.yml',
        `services:\n  anon-cron:\n    image: php:8.1-cli\n    volumes:\n      - .:/var/www/html\n    command: sh -c "while true; do php /var/www/html/cron/cron_anonymization.php; sleep 86400; done"`,
        'sleep 86400 = 24 hours. Adjust as needed; the script skips early runs per the configured frequency.'
    ));

    body.appendChild(setupCard);

    return card;
}

function buildSuggestionsTab() {
    const { card, body } = mkSection(
        'PII Column Suggestions',
        'Scans your schema for column names matching the dictionary keywords. Uses the dictionary from the Dictionary tab.'
    );

    const scanButton = document.createElement('button');
    scanButton.className   = 'btn btn-primary';
    scanButton.textContent = 'Scan Schema';

    const container = document.createElement('div');
    container.style.marginTop = '16px';

    scanButton.addEventListener('click', async () => {
        scanButton.disabled    = true;
        scanButton.textContent = 'Scanning…';
        container.innerHTML = '';

        try {
            const schema  = await getGlobalSchema();
            const tables  = schema?.tables || {};
            const keywords = (anonymizationConfig.dictionary || []).map(word => word.toLowerCase().trim()).filter(Boolean);

            if (keywords.length === 0) {
                const messageElement = document.createElement('p');
                messageElement.textContent = 'Dictionary is empty. Add keywords in the Dictionary tab first.';
                container.appendChild(messageElement);
                return;
            }

            const matches = [];
            for (const tableName in tables) {
                const tDef   = tables[tableName];
                const cols   = tDef.columns || {};
                for (const columnName in cols) {
                    const haystack = columnName.toLowerCase();
                    const dispName = (cols[columnName].display_name || '').toLowerCase();
                    const matched  = keywords.filter(keyword => haystack.includes(keyword) || dispName.includes(keyword));
                    if (matched.length > 0) {
                        matches.push({ table: tableName, column: columnName, keywords: matched });
                    }
                }
            }

            if (matches.length === 0) {
                const messageElement = document.createElement('p');
                messageElement.textContent = 'No columns matched the current dictionary keywords.';
                container.appendChild(messageElement);
                return;
            }

            const count = document.createElement('p');
            count.textContent = matches.length + ' potential PII column(s) found:';
            count.style.cssText = '  margin-bottom:12px;';
            container.appendChild(count);

            const tableElement   = document.createElement('table');
            tableElement.className = 'adm-tbl';
            const thead = tableElement.createTHead();
            const headerRow    = thead.insertRow();
            ['Table', 'Column', 'Matched Keywords', ''].forEach(headerLabel => {
                const th = document.createElement('th');
                th.className   = 'adm-th';
                th.textContent = headerLabel;
                headerRow.appendChild(th);
            });
            const tbody = tableElement.createTBody();

            matches.forEach(({ table, column, keywords: kws }) => {
                const tr = tbody.insertRow();

                const tdT = document.createElement('td');
                tdT.className  = 'adm-td';
                tdT.textContent = table;
                tr.appendChild(tdT);

                const tdC = document.createElement('td');
                tdC.className  = 'adm-td';
                tdC.style.fontFamily = 'monospace';
                tdC.textContent = column;
                tr.appendChild(tdC);

                const keywordCell = document.createElement('td');
                keywordCell.className  = 'adm-td';
                keywordCell.textContent = kws.join(', ');
                tr.appendChild(keywordCell);

                const actionCell = document.createElement('td');
                actionCell.className = 'adm-td';

                const alreadyHas = (anonymizationConfig.rules || []).some(replacementValue => replacementValue.table === table && replacementValue.column === column);
                if (alreadyHas) {
                    const badge = document.createElement('span');
                    badge.textContent = '✓ Rule exists';
                    badge.style.cssText = ' color:var(--ok);';
                    actionCell.appendChild(badge);
                } else {
                    const addButton = document.createElement('button');
                    addButton.textContent = '+ Add Rule';
                    addButton.className = 'btn btn-secondary btn-xs';
                    addButton.addEventListener('click', async () => {
                        addButton.style.display = 'none';

                        const form = document.createElement('div');
                        form.style.cssText = 'display:flex; flex-direction:column; gap:5px; padding:4px 0;';

                        function fLabel(text) {
                            const labelElement = document.createElement('label');
                            labelElement.textContent = text;
                            labelElement.style.cssText = ' ';
                            return labelElement;
                        }

                        const dateColumnSelect = document.createElement('select');
                        dateColumnSelect.className  = 'adm-input';

                        try {
                            const schema   = await getSchema();
                            const tDef     = (schema.tables || {})[table] || {};
                            const dateOptions = Object.entries(tDef.columns || {})
                                .filter(([, def]) => isDateType(def.type))
                                .map(([name, def]) => ({ value: name, label: def.display_name || name }));
                            if (dateOptions.length === 0) {
                                const optionElement = document.createElement('option');
                                optionElement.value = ''; optionElement.textContent = '-- no date columns --';
                                dateColumnSelect.appendChild(optionElement);
                            } else {
                                dateOptions.forEach(({ value, label }) => {
                                    const optionElement = document.createElement('option');
                                    optionElement.value = value; optionElement.textContent = label;
                                    dateColumnSelect.appendChild(optionElement);
                                });
                            }
                        } catch (error) {
                            const optionElement = document.createElement('option');
                            optionElement.value = ''; optionElement.textContent = '-- error --';
                            dateColumnSelect.appendChild(optionElement);
                        }

                        const daysInp = document.createElement('input');
                        daysInp.type      = 'number';
                        daysInp.min       = '1';
                        daysInp.value     = '365';
                        daysInp.className = 'adm-input w-90';

                        const replInp = document.createElement('input');
                        replInp.type      = 'text';
                        replInp.value     = '***ANONYMIZED***';
                        replInp.className = 'adm-input';

                        const formSt = document.createElement('p');
                        formSt.style.cssText = 'margin:2px 0;  display:none;';

                        const buttonRow = document.createElement('div');
                        buttonRow.style.cssText = 'display:flex; gap:6px; margin-top:2px;';

                        const saveButton = document.createElement('button');
                        saveButton.textContent  = 'Save';
                        saveButton.className = 'btn btn-primary btn-xs';

                        const cancelButton = document.createElement('button');
                        cancelButton.textContent  = 'Cancel';
                        cancelButton.className = 'btn btn-secondary btn-xs';

                        cancelButton.addEventListener('click', () => {
                            form.remove();
                            addButton.style.display = '';
                        });

                        saveButton.addEventListener('click', async () => {
                            const dateColumnName   = dateColumnSelect.value.trim();
                            const days = parseInt(daysInp.value, 10);
                            const repl = replInp.value;
                            if (!dateColumnName) {
                                formSt.textContent = 'Select a date column.';
                                formSt.style.color = 'var(--error)';
                                formSt.style.display = '';
                                return;
                            }
                            if (isNaN(days) || days < 1) {
                                formSt.textContent = 'Enter a valid number of days.';
                                formSt.style.color = 'var(--error)';
                                formSt.style.display = '';
                                return;
                            }
                            anonymizationConfig.rules = anonymizationConfig.rules || [];
                            anonymizationConfig.rules.push({ table, date_column: dateColumnName, days, column, replacement: repl });
                            const statusPill = mkStatusElement();
                            await saveConfig({}, statusPill);
                            form.remove();
                            addButton.disabled = true;
                            addButton.style.display = '';
                            addButton.textContent = '✓ Added';
                            addButton.className = 'btn btn-success btn-xs';
                        });

                        buttonRow.append(saveButton, cancelButton);
                        form.append(
                            fLabel('Date column:'), dateColumnSelect,
                            fLabel('Older than (days):'), daysInp,
                            fLabel('Replacement:'), replInp,
                            formSt, buttonRow
                        );
                        actionCell.appendChild(form);
                    });
                    actionCell.appendChild(addButton);
                }
                tr.appendChild(actionCell);
            });

            container.appendChild(tableElement);
        } catch (error) {
            const messageElement = document.createElement('p');
            messageElement.textContent = 'Failed to load schema: ' + error.message;
            messageElement.style.color = 'var(--error)';
            container.appendChild(messageElement);
        } finally {
            scanButton.disabled    = false;
            scanButton.textContent = 'Scan Schema';
        }
    });

    body.append(scanButton, container);
    return card;
}

function buildDictionaryTab() {
    const { card, body } = mkSection(
        'PII Keyword Dictionary',
        'Comma-separated keywords used by the Suggestions tab to detect PII column names (case-insensitive, substring match).'
    );

    const textarea = document.createElement('textarea');
    textarea.className = 'adm-input w-full mono';
    textarea.rows      = 6;
    textarea.style.maxWidth = '600px';
    textarea.style.marginBottom = '12px';
    textarea.style.resize = 'vertical';
    textarea.value = (anonymizationConfig.dictionary || []).join(', ');

    const hint = document.createElement('p');
    hint.textContent = 'Example: PESEL, NIP, email, phone, address, imię, nazwisko, ID number';
    hint.style.cssText = '  margin-bottom:16px;';

    const saveButton = document.createElement('button');
    saveButton.className   = 'btn btn-primary';
    saveButton.textContent = 'Save Dictionary';

    const statusPill = mkStatusElement();

    saveButton.addEventListener('click', async () => {
        const words = textarea.value
            .split(',')
            .map(word => word.trim())
            .filter(Boolean);
        saveButton.disabled = true;
        await saveConfig({ dictionary: words }, statusPill);
        saveButton.disabled = false;
        anonymizationConfig.dictionary = words;
    });

    body.append(textarea, hint, saveButton, statusPill);

    const { card: logCard, body: logBody } = mkSection(
        'Log Cleanup',
        'Delete old anonymization run entries from spw_anonymization_log.'
    );

    const logRow = document.createElement('div');
    logRow.style.cssText = 'display:flex; align-items:center; gap:12px; flex-wrap:wrap;';

    const logLabel = document.createElement('label');
    logLabel.textContent = 'Delete runs older than';
    logLabel.style.cssText = ' ';

    const logInput = document.createElement('input');
    logInput.type  = 'number';
    logInput.value = '90';
    logInput.min   = '1';
    logInput.max   = '3650';
    logInput.className  = 'adm-input w-80';

    const logUnit = document.createElement('span');
    logUnit.textContent = 'days';
    logUnit.style.cssText = ' ';

    const purgeButton = document.createElement('button');
    purgeButton.className   = 'btn btn-danger';
    purgeButton.textContent = 'Purge Old Logs';

    const purgeSt = mkStatusElement();

    purgeButton.addEventListener('click', async () => {
        const days = parseInt(logInput.value, 10);
        if (!days || days < 1) {
            showStatus(purgeSt, 'Enter a valid number of days.', false);
            return;
        }
        if (!confirm('Delete anonymization log entries older than ' + days + ' day(s)?')) return;
        purgeButton.disabled    = true;
        purgeButton.textContent = 'Purging…';
        purgeSt.style.display = 'none';
        try {
            const result  = await apiFetch('api.php?action=anonymization_purge_log', {
                method:  'POST',
                body:    JSON.stringify({ days }),
            });
            const data = await result.json();
            if (data.status === 'success') {
                showStatus(purgeSt, 'Deleted ' + data.deleted + ' log row(s).', true);
            } else {
                showStatus(purgeSt, 'Error: ' + (data.error || 'unknown'), false);
            }
        } catch (error) {
            showStatus(purgeSt, 'Request failed: ' + error.message, false);
        }
        purgeButton.disabled    = false;
        purgeButton.textContent = 'Purge Old Logs';
    });

    logRow.append(logLabel, logInput, logUnit, purgeButton);
    logBody.append(logRow, purgeSt);
    body.appendChild(logCard);

    return card;
}

function buildReportCell(replacementValue, tbody, colspan) {
    const cell = document.createElement('td');
    cell.className = 'adm-td';

    let report = null;
    if (replacementValue.report) {
        try {
            report = typeof replacementValue.report === 'string' ? JSON.parse(replacementValue.report) : replacementValue.report;
        } catch (error) {
            report = null;
        }
    }

    if (!report) {
        cell.textContent = '—';
        return cell;
    }

    const viewButton = document.createElement('button');
    viewButton.textContent  = 'View';
    viewButton.className = 'btn btn-secondary btn-xs';

    let detailRow = null;

    viewButton.addEventListener('click', () => {
        if (detailRow) {
            detailRow.remove();
            detailRow = null;
            viewButton.textContent = 'View';
            return;
        }

        const parentTr = cell.closest('tr');
        detailRow = tbody.insertRow(parentTr.sectionRowIndex + 1);
        viewButton.textContent = 'Hide';

        const detailCell = detailRow.insertCell();
        detailCell.className   = 'adm-td';
        detailCell.colSpan     = colspan;
        detailCell.style.cssText = 'background:var(--bg); padding:12px;';

        const bar = document.createElement('div');
        bar.style.cssText = 'display:flex; align-items:center; gap:12px; margin-bottom:8px; flex-wrap:wrap;';

        const idLabel = document.createElement('strong');
        idLabel.textContent = report.report_id || 'Report';
        idLabel.style.cssText = ' font-family:var(--font-mono);';

        const dlButton = document.createElement('button');
        dlButton.textContent  = 'Download JSON';
        dlButton.className = 'btn btn-primary btn-xs';
        dlButton.addEventListener('click', () => {
            const blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
            const url  = URL.createObjectURL(blob);
            const downloadLink    = document.createElement('a');
            downloadLink.href     = url;
            downloadLink.download = (report.report_id || 'anonymization-report') + '.json';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            downloadLink.remove();
            URL.revokeObjectURL(url);
        });

        bar.append(idLabel, dlButton);

        const preElement = document.createElement('pre');
        preElement.style.cssText = 'margin:0; padding:12px; background:#fff; border:1px solid var(--border); border-radius:4px;  line-height:1.5; max-height:360px; overflow:auto; white-space:pre-wrap;';
        preElement.textContent = JSON.stringify(report, null, 2);

        detailCell.append(bar, preElement);
    });

    cell.appendChild(viewButton);
    return cell;
}

function buildHistorySection() {
    const { card, body } = mkSection(
        'Run History',
        'Last 50 executions from spw_anonymization_log.'
    );

    const loadButton = document.createElement('button');
    loadButton.className   = 'btn btn-primary';
    loadButton.textContent = 'Load History';

    const container = document.createElement('div');
    container.style.marginTop = '14px';

    loadButton.addEventListener('click', async () => {
        loadButton.disabled    = true;
        loadButton.textContent = 'Loading…';
        container.innerHTML = '';
        try {
            const result  = await apiFetch('api.php?action=anonymization_log');
            const data = await result.json();
            if (data.status !== 'success') {
                container.textContent = 'Error: ' + (data.error || 'unknown');
                return;
            }
            if (data.note) {
                container.textContent = data.note;
                return;
            }
            if (!data.rows || data.rows.length === 0) {
                container.textContent = 'No runs recorded yet.';
                return;
            }
            const headers = ['#', 'Status', 'Triggered By', 'Started At', 'Duration', 'Rules', 'Rows Anonymized', 'Report', 'Error'];
            const tableElement = mkTable();
            mkThead(tableElement, headers);
            const tbody = tableElement.createTBody();
            data.rows.forEach(replacementValue => {
                const tr = tbody.insertRow();
                const tdSt = tdStatus(replacementValue.status);

                tr.append(
                    td(replacementValue.id),
                    tdSt,
                    td(replacementValue.triggered_by),
                    td(replacementValue.started_at ? replacementValue.started_at.replace('T', ' ').substring(0, 19) : ''),
                    td(replacementValue.duration_sec !== null && replacementValue.duration_sec !== undefined ? Number(replacementValue.duration_sec).toFixed(2) + 's' : '—'),
                    td(replacementValue.rules_processed),
                    td(replacementValue.rows_anonymized),
                    buildReportCell(replacementValue, tbody, headers.length),
                    td(replacementValue.error_message, 'color:var(--error); max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;')
                );
            });
            container.appendChild(tableElement);
        } catch (error) {
            container.textContent = 'Request failed: ' + error.message;
        }
        loadButton.disabled    = false;
        loadButton.textContent = 'Refresh';
    });

    body.append(loadButton, container);
    return card;
}

export async function renderAnonymizationPage(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '<p style="padding:20px;">Loading configuration…</p>';

    if (context.getColumnOptionsForTable) {
        window._anonColOptions = context.getColumnOptionsForTable;
    }

    try {
        const result  = await apiFetch('api.php?action=anonymization_load');
        const data = await result.json();
        if (data.status !== 'success') {
            workspaceElement.innerHTML = '<p style="color:var(--error);padding:20px;">Failed to load config: ' + escHtml(data.error || 'unknown') + '</p>';
            return;
        }
        anonymizationConfig  = data.config;
        anonymizationVersion = data.version ?? 0;
    } catch (error) {
        workspaceElement.innerHTML = '<p style="color:var(--error);padding:20px;">Request failed: ' + escHtml(error.message) + '</p>';
        return;
    }

    workspaceElement.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    workspaceElement.appendChild(wrap);

    wrap.appendChild(createPageHeader(
        'Data Anonymization',
        'Scrub or mask personal data in aging records on a schedule — configure rules, review PII suggestions, and manage the replacement dictionary.'
    ));

    const [p0, p1, p2, p3, p4] = buildInnerTabs(wrap, [
        { label: 'Rules', icon: 'checklist_rtl.png' },
        { label: 'Schedule', icon: 'calendar_check.png' },
        { label: 'Suggestions', icon: 'fact_check.png' },
        { label: 'Dictionary', icon: 'menu_book.png' },
        { label: 'History', icon: 'manage_history.png' },
    ]);

    p0.appendChild(buildRulesTab(context));

    p1.appendChild(buildScheduleTab());

    p2.appendChild(buildSuggestionsTab());

    p3.appendChild(buildDictionaryTab());

    p4.appendChild(buildHistorySection());
}
