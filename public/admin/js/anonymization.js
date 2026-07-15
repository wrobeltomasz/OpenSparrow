// admin/js/anonymization.js — Data Anonymization admin module
// 4 tabs: Rules, Schedule, Suggestions, Dictionary.
// Persists config to config/anonymization.json via anonymization_save.
// Cron worker: cron/cron_anonymization.php.
import { apiFetch } from '../../assets/js/util/api.js';
import { buildInnerTabs } from './ui.js';

let anonConfig  = null;
let schemaCache = null;

import { escHtml as anonEsc } from '../../assets/js/util/esc.js';

function mkSection(title, desc) {
    const card = document.createElement('div');
    card.className = 'adm-sec-card';

    const hdr = document.createElement('div');
    hdr.className = 'adm-sec-hdr';
    hdr.style.display = 'block';

    const h3 = document.createElement('h3');
    h3.textContent = title;
    h3.style.cssText = 'margin:0 0 4px; font-size:15px;';

    const p = document.createElement('p');
    p.textContent = desc;
    p.style.cssText = 'margin:0; font-size:13px;';
    p.className = 'c-muted';

    hdr.append(h3, p);
    card.appendChild(hdr);

    const body = document.createElement('div');
    body.className = 'adm-sec-body';
    card.appendChild(body);

    return { card, body };
}

function mkStatusEl() {
    const el = document.createElement('p');
    el.style.cssText = 'margin-top:10px; font-size:13px; display:none;';
    return el;
}

function showStatus(el, msg, ok) {
    el.textContent = msg;
    el.style.color = ok ? 'var(--ok)' : '#a80000';
    el.style.display = '';
}

async function saveConfig(partial, statusEl) {
    Object.assign(anonConfig, partial);
    try {
        const res  = await apiFetch('api.php?action=anonymization_save', {
            method:  'POST',
            body:    JSON.stringify(anonConfig),
        });
        const data = await res.json();
        if (data.status === 'success') {
            if (statusEl) showStatus(statusEl, 'Saved.', true);
        } else {
            if (statusEl) showStatus(statusEl, 'Error: ' + (data.error || 'unknown'), false);
        }
    } catch (e) {
        if (statusEl) showStatus(statusEl, 'Request failed: ' + e.message, false);
    }
}

function isDateType(type) {
    const t = (type || '').toLowerCase();
    return t === 'date' || t.includes('timestamp') || t === 'datetime';
}

async function getSchema() {
    if (schemaCache) return schemaCache;
    const res = await apiFetch('api.php?action=get&file=schema');
    schemaCache = await res.json();
    return schemaCache;
}

// ─── Tab 1: Rules ─────────────────────────────────────────────────────────────

function buildRulesTab(ctx) {
    const { card, body } = mkSection(
        'Anonymization Rules',
        'Each rule anonymizes a PII column for records older than the configured number of days.'
    );

    const tableOptions = ctx.getTableOptions ? ctx.getTableOptions() : [];

    function renderRulesTable() {
        body.innerHTML = '';

        const rules = anonConfig.rules || [];

        if (rules.length > 0) {
            const tbl = document.createElement('table');
            tbl.className = 'adm-tbl';
            tbl.style.marginBottom = '20px';

            const thead = tbl.createTHead();
            const hr    = thead.insertRow();
            ['Table', 'Date Column', 'Older Than', 'PII Column', 'Replacement', ''].forEach(h => {
                const th = document.createElement('th');
                th.className  = 'adm-th';
                th.textContent = h;
                hr.appendChild(th);
            });

            const tbody = tbl.createTBody();
            rules.forEach((rule, idx) => {
                const tr = tbody.insertRow();

                const tdTable = document.createElement('td');
                tdTable.className   = 'adm-td';
                tdTable.textContent = rule.table;
                tr.appendChild(tdTable);

                const tdDateCol = document.createElement('td');
                tdDateCol.className        = 'adm-td';
                tdDateCol.style.fontFamily = 'monospace';
                tdDateCol.textContent      = rule.date_column || '—';
                tr.appendChild(tdDateCol);

                const tdDays = document.createElement('td');
                tdDays.className   = 'adm-td';
                tdDays.textContent = rule.days ? rule.days + ' days' : '—';
                tr.appendChild(tdDays);

                const tdCol = document.createElement('td');
                tdCol.className        = 'adm-td';
                tdCol.style.fontFamily = 'monospace';
                tdCol.textContent      = rule.column;
                tr.appendChild(tdCol);

                const tdRepl = document.createElement('td');
                tdRepl.className        = 'adm-td';
                tdRepl.style.fontFamily = 'monospace';
                tdRepl.textContent      = rule.replacement;
                tr.appendChild(tdRepl);

                const tdAct = document.createElement('td');
                tdAct.className = 'adm-td';
                const delBtn = document.createElement('button');
                delBtn.textContent = '✕ Remove';
                delBtn.style.cssText = 'background:none; border:1px solid var(--danger); color:var(--danger); border-radius:4px; padding:3px 10px; font-size:12px; cursor:pointer;';
                delBtn.addEventListener('click', async () => {
                    if (!confirm('Remove rule for ' + rule.table + '.' + rule.column + '?')) return;
                    anonConfig.rules.splice(idx, 1);
                    const st = mkStatusEl();
                    await saveConfig({}, st);
                    renderRulesTable();
                });
                tdAct.appendChild(delBtn);
                tr.appendChild(tdAct);
            });

            body.appendChild(tbl);
            buildPreviewBlock(body);
        } else {
            const empty = document.createElement('p');
            empty.textContent = 'No rules configured yet. Use the form below to add one.';
            empty.style.cssText = 'color:var(--muted); margin-bottom:16px;';
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

    const btn = document.createElement('button');
    btn.className   = 'btn-action';
    btn.textContent = 'Preview (dry run)';

    const hint = document.createElement('span');
    hint.textContent = 'Counts how many rows each rule would anonymize — no data is modified.';
    hint.style.cssText = 'margin-left:12px; font-size:12px; color:var(--muted);';

    const out = document.createElement('pre');
    out.style.cssText = 'margin-top:12px; padding:12px; background:var(--bg); border:1px solid var(--border); border-radius:4px; font-size:12px; line-height:1.6; max-height:300px; overflow-y:auto; white-space:pre-wrap; display:none;';

    btn.addEventListener('click', async () => {
        btn.disabled    = true;
        btn.textContent = 'Previewing…';
        out.style.display = '';
        out.textContent   = 'Please wait…';
        out.style.color   = '';
        try {
            const res  = await apiFetch('api.php?action=preview_anonymization', {
                method:  'POST',
            });
            const data = await res.json();
            if (data.status === 'success') {
                out.textContent = data.output || '(no output)';
            } else {
                out.textContent = 'Error: ' + (data.error || 'unknown');
                out.style.color = '#a80000';
            }
        } catch (e) {
            out.textContent = 'Request failed: ' + e.message;
            out.style.color = '#a80000';
        }
        btn.disabled    = false;
        btn.textContent = 'Preview (dry run)';
    });

    wrap.append(btn, hint, out);
    container.appendChild(wrap);
}

function buildAddForm(container, tableOptions, onAdded) {
    const formCard = document.createElement('div');
    formCard.style.cssText = 'background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:16px; max-width:900px;';

    const title = document.createElement('strong');
    title.textContent = 'Add Rule';
    title.style.cssText = 'display:block; margin-bottom:12px; font-size:13px;';
    formCard.appendChild(title);

    const row = document.createElement('div');
    row.style.cssText = 'display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;';

    function mkField(labelText, el, width) {
        const wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex; flex-direction:column; gap:4px;';
        const lbl = document.createElement('label');
        lbl.textContent = labelText;
        lbl.style.cssText = 'font-size:12px; color:var(--muted);';
        el.className = 'adm-input';
        if (width) el.style.width = width;
        wrap.append(lbl, el);
        return wrap;
    }

    const tableSelect  = document.createElement('select');
    const dateColSelect = document.createElement('select');
    const daysInput    = document.createElement('input');
    const colSelect    = document.createElement('select');
    const replInput    = document.createElement('input');

    daysInput.type        = 'number';
    daysInput.min         = '1';
    daysInput.value       = '365';
    daysInput.placeholder = 'e.g. 365';

    replInput.type        = 'text';
    replInput.placeholder = 'e.g. ***ANONYMIZED***';

    tableOptions.forEach(opt => {
        const o = document.createElement('option');
        o.value = opt.value; o.textContent = opt.label;
        tableSelect.appendChild(o);
    });

    async function refreshColumns() {
        colSelect.innerHTML     = '';
        dateColSelect.innerHTML = '';

        const tbl = tableSelect.value;
        if (!tbl) {
            [colSelect, dateColSelect].forEach(sel => {
                const o = document.createElement('option');
                o.value = ''; o.textContent = '-- Select --';
                sel.appendChild(o);
            });
            return;
        }

        // PII column — all columns via ctx helper
        const allOpts = window._anonColOptions ? window._anonColOptions(tbl) : [];
        if (allOpts.length === 0) {
            const o = document.createElement('option');
            o.value = ''; o.textContent = '-- No columns --';
            colSelect.appendChild(o);
        } else {
            allOpts.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt.value; o.textContent = opt.label;
                colSelect.appendChild(o);
            });
        }

        // Date column — only date / timestamp types
        try {
            const schema   = await getSchema();
            const tDef     = (schema.tables || {})[tbl] || {};
            const dateOpts = Object.entries(tDef.columns || {})
                .filter(([, def]) => isDateType(def.type))
                .map(([name, def]) => ({ value: name, label: def.display_name || name }));

            if (dateOpts.length === 0) {
                const o = document.createElement('option');
                o.value = ''; o.textContent = '-- No date/timestamp columns --';
                dateColSelect.appendChild(o);
            } else {
                dateOpts.forEach(({ value, label }) => {
                    const o = document.createElement('option');
                    o.value = value; o.textContent = label;
                    dateColSelect.appendChild(o);
                });
            }
        } catch (e) {
            const o = document.createElement('option');
            o.value = ''; o.textContent = '-- Error loading --';
            dateColSelect.appendChild(o);
        }
    }

    tableSelect.addEventListener('change', () => refreshColumns());
    refreshColumns();

    const addBtn = document.createElement('button');
    addBtn.className   = 'btn-action';
    addBtn.textContent = '+ Add Rule';
    addBtn.style.alignSelf = 'flex-end';

    const st = mkStatusEl();

    addBtn.addEventListener('click', async () => {
        const t    = tableSelect.value.trim();
        const dc   = dateColSelect.value.trim();
        const days = parseInt(daysInput.value, 10);
        const c    = colSelect.value.trim();
        const r    = replInput.value;

        if (!t || !dc) {
            showStatus(st, 'Select a table and a date/timestamp column.', false);
            return;
        }
        if (!c) {
            showStatus(st, 'Select a PII column to anonymize.', false);
            return;
        }
        if (isNaN(days) || days < 1) {
            showStatus(st, 'Enter a valid number of days (minimum 1).', false);
            return;
        }
        const duplicate = (anonConfig.rules || []).some(x => x.table === t && x.column === c && x.date_column === dc);
        if (duplicate) {
            showStatus(st, 'A rule for ' + t + '.' + c + ' with that date column already exists.', false);
            return;
        }
        anonConfig.rules = anonConfig.rules || [];
        anonConfig.rules.push({ table: t, date_column: dc, days, column: c, replacement: r });
        addBtn.disabled = true;
        await saveConfig({}, st);
        addBtn.disabled = false;
        if (st.style.color === 'rgb(43, 147, 72)') {
            replInput.value   = '';
            daysInput.value   = '365';
            tableSelect.value = tableOptions.length > 0 ? tableOptions[0].value : '';
            refreshColumns();
            onAdded();
        }
    });

    const tableWrap   = mkField('Table', tableSelect, '180px');
    const dateColWrap = mkField('Date Column', dateColSelect, '190px');
    const daysWrap    = mkField('Older Than (days)', daysInput, '110px');
    const colWrap     = mkField('PII Column', colSelect, '180px');
    const replWrap    = mkField('Replacement Value', replInput, '');
    replWrap.style.cssText += 'flex:1; min-width:140px;';

    row.append(tableWrap, dateColWrap, daysWrap, colWrap, replWrap, addBtn);
    formCard.append(row, st);
    container.appendChild(formCard);
}

// ─── Tab 2: Schedule ──────────────────────────────────────────────────────────

function buildScheduleTab() {
    const { card, body } = mkSection(
        'Schedule',
        'Configure when anonymization runs. Frequency is enforced by the cron script itself — set your OS scheduler to run daily and let the module handle the window.'
    );

    // Enabled toggle
    const enabledRow = document.createElement('div');
    enabledRow.style.cssText = 'display:flex; align-items:center; gap:10px; margin-bottom:20px;';
    const enabledChk = document.createElement('input');
    enabledChk.type    = 'checkbox';
    enabledChk.id      = 'anon-enabled';
    enabledChk.checked = anonConfig.enabled;
    enabledChk.style.width = '16px';
    const enabledLbl = document.createElement('label');
    enabledLbl.htmlFor     = 'anon-enabled';
    enabledLbl.textContent = 'Anonymization enabled';
    enabledLbl.style.cssText = 'font-size:14px; cursor:pointer;';
    enabledRow.append(enabledChk, enabledLbl);
    body.appendChild(enabledRow);

    // Frequency
    const freqRow = document.createElement('div');
    freqRow.style.cssText = 'display:flex; align-items:center; gap:10px; margin-bottom:20px;';
    const freqLabel = document.createElement('label');
    freqLabel.htmlFor     = 'anon-frequency';
    freqLabel.textContent = 'Frequency:';
    freqLabel.style.cssText = 'font-size:13px; color:var(--muted); white-space:nowrap;';
    const freqSelect = document.createElement('select');
    freqSelect.id        = 'anon-frequency';
    freqSelect.className = 'adm-input';
    freqSelect.style.width = '180px';
    [
        { value: 'manual',  label: 'Manual only (admin panel)' },
        { value: 'daily',   label: 'Daily' },
        { value: 'weekly',  label: 'Weekly' },
        { value: 'monthly', label: 'Monthly' },
    ].forEach(({ value, label }) => {
        const o = document.createElement('option');
        o.value       = value;
        o.textContent = label;
        if (value === anonConfig.frequency) o.selected = true;
        freqSelect.appendChild(o);
    });
    freqRow.append(freqLabel, freqSelect);
    body.appendChild(freqRow);

    const saveSt = mkStatusEl();

    const saveBtn = document.createElement('button');
    saveBtn.className   = 'btn-action';
    saveBtn.textContent = 'Save Schedule Settings';
    saveBtn.style.marginBottom = '24px';
    saveBtn.addEventListener('click', async () => {
        saveBtn.disabled = true;
        await saveConfig({ enabled: enabledChk.checked, frequency: freqSelect.value }, saveSt);
        saveBtn.disabled = false;
    });
    body.append(saveBtn, saveSt);

    // Run now section
    const { card: runCard, body: runBody } = mkSection(
        'Run Anonymization Now',
        'Trigger the anonymization cron immediately, bypassing the frequency check.'
    );

    const runBtn = document.createElement('button');
    runBtn.className   = 'btn-action';
    runBtn.textContent = 'Run Now';

    const output = document.createElement('pre');
    output.style.cssText = 'margin-top:14px; padding:12px; background:var(--bg); border:1px solid var(--border); border-radius:4px; font-size:12px; line-height:1.6; max-height:300px; overflow-y:auto; white-space:pre-wrap; display:none;';

    runBtn.addEventListener('click', async () => {
        runBtn.disabled    = true;
        runBtn.textContent = 'Running…';
        output.style.display = '';
        output.textContent   = 'Please wait…';
        output.style.color   = '';
        try {
            const res  = await apiFetch('api.php?action=run_anonymization', {
                method:  'POST',
            });
            const data = await res.json();
            if (data.status === 'success') {
                output.textContent = data.output || '(no output)';
            } else {
                output.textContent = 'Error: ' + (data.error || 'unknown');
                output.style.color = '#a80000';
            }
        } catch (e) {
            output.textContent = 'Request failed: ' + e.message;
            output.style.color = '#a80000';
        }
        runBtn.disabled    = false;
        runBtn.textContent = 'Run Now';
    });

    runBody.append(runBtn, output);
    body.appendChild(runCard);

    // Cron setup guide
    const { card: setupCard, body: setupBody } = mkSection(
        'Cron Setup Guide',
        'Configure your OS scheduler to invoke the anonymization script automatically.'
    );

    const cronPath = window.location.origin + '/cron/cron_anonymization.php';

    function guideBlock(heading, code, note) {
        const wrap = document.createElement('div');
        wrap.style.cssText = 'background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:14px; margin-bottom:12px;';
        const h = document.createElement('strong');
        h.textContent = heading;
        h.style.cssText = 'display:block; margin-bottom:8px; font-size:13px;';
        const pre = document.createElement('pre');
        pre.style.cssText = 'margin:0 0 6px; font-size:12px; background:var(--accent-dark); color:var(--accent-light); padding:10px 12px; border-radius:4px; overflow-x:auto; white-space:pre-wrap;';
        pre.textContent = code;
        wrap.append(h, pre);
        if (note) {
            const pn = document.createElement('p');
            pn.textContent = note;
            pn.style.cssText = 'margin:6px 0 0; font-size:12px; color:var(--muted);';
            wrap.appendChild(pn);
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

// ─── Tab 3: Suggestions ───────────────────────────────────────────────────────

function buildSuggestionsTab() {
    const { card, body } = mkSection(
        'PII Column Suggestions',
        'Scans your schema for column names matching the dictionary keywords. Uses the dictionary from the Dictionary tab.'
    );

    const scanBtn = document.createElement('button');
    scanBtn.className   = 'btn-action';
    scanBtn.textContent = 'Scan Schema';

    const container = document.createElement('div');
    container.style.marginTop = '16px';

    scanBtn.addEventListener('click', async () => {
        scanBtn.disabled    = true;
        scanBtn.textContent = 'Scanning…';
        container.innerHTML = '';

        try {
            const res     = await apiFetch('api.php?action=get&file=schema');
            const schema  = await res.json();
            const tables  = schema.tables || {};
            const keywords = (anonConfig.dictionary || []).map(w => w.toLowerCase().trim()).filter(Boolean);

            if (keywords.length === 0) {
                const msg = document.createElement('p');
                msg.textContent = 'Dictionary is empty. Add keywords in the Dictionary tab first.';
                msg.style.color = 'var(--muted)';
                container.appendChild(msg);
                return;
            }

            const matches = [];
            for (const tableName in tables) {
                const tDef   = tables[tableName];
                const cols   = tDef.columns || {};
                for (const colName in cols) {
                    const haystack = colName.toLowerCase();
                    const dispName = (cols[colName].display_name || '').toLowerCase();
                    const matched  = keywords.filter(kw => haystack.includes(kw) || dispName.includes(kw));
                    if (matched.length > 0) {
                        matches.push({ table: tableName, column: colName, keywords: matched });
                    }
                }
            }

            if (matches.length === 0) {
                const msg = document.createElement('p');
                msg.textContent = 'No columns matched the current dictionary keywords.';
                msg.style.color = 'var(--muted)';
                container.appendChild(msg);
                return;
            }

            const count = document.createElement('p');
            count.textContent = matches.length + ' potential PII column(s) found:';
            count.style.cssText = 'font-size:13px; color:var(--muted); margin-bottom:12px;';
            container.appendChild(count);

            const tbl   = document.createElement('table');
            tbl.className = 'adm-tbl';
            const thead = tbl.createTHead();
            const hr    = thead.insertRow();
            ['Table', 'Column', 'Matched Keywords', ''].forEach(h => {
                const th = document.createElement('th');
                th.className   = 'adm-th';
                th.textContent = h;
                hr.appendChild(th);
            });
            const tbody = tbl.createTBody();

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

                const tdK = document.createElement('td');
                tdK.className  = 'adm-td';
                tdK.textContent = kws.join(', ');
                tr.appendChild(tdK);

                const tdA = document.createElement('td');
                tdA.className = 'adm-td';

                const alreadyHas = (anonConfig.rules || []).some(r => r.table === table && r.column === column);
                if (alreadyHas) {
                    const badge = document.createElement('span');
                    badge.textContent = '✓ Rule exists';
                    badge.style.cssText = 'font-size:12px; color:var(--ok);';
                    tdA.appendChild(badge);
                } else {
                    const addBtn = document.createElement('button');
                    addBtn.textContent = '+ Add Rule';
                    addBtn.style.cssText = 'background:none; border:1px solid var(--accent); color:var(--accent); border-radius:4px; padding:3px 10px; font-size:12px; cursor:pointer;';
                    addBtn.addEventListener('click', async () => {
                        addBtn.style.display = 'none';

                        const form = document.createElement('div');
                        form.style.cssText = 'display:flex; flex-direction:column; gap:5px; padding:4px 0;';

                        function fLbl(text) {
                            const l = document.createElement('label');
                            l.textContent = text;
                            l.style.cssText = 'font-size:11px; color:var(--muted);';
                            return l;
                        }

                        const dateColSel = document.createElement('select');
                        dateColSel.className  = 'adm-input';
                        dateColSel.style.fontSize = '12px';

                        try {
                            const schema   = await getSchema();
                            const tDef     = (schema.tables || {})[table] || {};
                            const dateOpts = Object.entries(tDef.columns || {})
                                .filter(([, def]) => isDateType(def.type))
                                .map(([name, def]) => ({ value: name, label: def.display_name || name }));
                            if (dateOpts.length === 0) {
                                const o = document.createElement('option');
                                o.value = ''; o.textContent = '-- no date columns --';
                                dateColSel.appendChild(o);
                            } else {
                                dateOpts.forEach(({ value, label }) => {
                                    const o = document.createElement('option');
                                    o.value = value; o.textContent = label;
                                    dateColSel.appendChild(o);
                                });
                            }
                        } catch (e) {
                            const o = document.createElement('option');
                            o.value = ''; o.textContent = '-- error --';
                            dateColSel.appendChild(o);
                        }

                        const daysInp = document.createElement('input');
                        daysInp.type      = 'number';
                        daysInp.min       = '1';
                        daysInp.value     = '365';
                        daysInp.className = 'adm-input';
                        daysInp.style.cssText = 'font-size:12px; width:90px;';

                        const replInp = document.createElement('input');
                        replInp.type      = 'text';
                        replInp.value     = '***ANONYMIZED***';
                        replInp.className = 'adm-input';
                        replInp.style.fontSize = '12px';

                        const formSt = document.createElement('p');
                        formSt.style.cssText = 'margin:2px 0; font-size:11px; display:none;';

                        const btnRow = document.createElement('div');
                        btnRow.style.cssText = 'display:flex; gap:6px; margin-top:2px;';

                        const saveBtn = document.createElement('button');
                        saveBtn.textContent  = 'Save';
                        saveBtn.style.cssText = 'background:var(--accent); color:#fff; border:none; border-radius:4px; padding:3px 10px; font-size:12px; cursor:pointer;';

                        const cancelBtn = document.createElement('button');
                        cancelBtn.textContent  = 'Cancel';
                        cancelBtn.style.cssText = 'background:none; border:1px solid var(--border); border-radius:4px; padding:3px 10px; font-size:12px; cursor:pointer;';

                        cancelBtn.addEventListener('click', () => {
                            form.remove();
                            addBtn.style.display = '';
                        });

                        saveBtn.addEventListener('click', async () => {
                            const dc   = dateColSel.value.trim();
                            const days = parseInt(daysInp.value, 10);
                            const repl = replInp.value;
                            if (!dc) {
                                formSt.textContent = 'Select a date column.';
                                formSt.style.color = '#a80000';
                                formSt.style.display = '';
                                return;
                            }
                            if (isNaN(days) || days < 1) {
                                formSt.textContent = 'Enter a valid number of days.';
                                formSt.style.color = '#a80000';
                                formSt.style.display = '';
                                return;
                            }
                            anonConfig.rules = anonConfig.rules || [];
                            anonConfig.rules.push({ table, date_column: dc, days, column, replacement: repl });
                            const st = mkStatusEl();
                            await saveConfig({}, st);
                            form.remove();
                            addBtn.disabled = true;
                            addBtn.style.display = '';
                            addBtn.textContent = '✓ Added';
                            addBtn.style.borderColor = 'var(--ok)';
                            addBtn.style.color = 'var(--ok)';
                        });

                        btnRow.append(saveBtn, cancelBtn);
                        form.append(
                            fLbl('Date column:'), dateColSel,
                            fLbl('Older than (days):'), daysInp,
                            fLbl('Replacement:'), replInp,
                            formSt, btnRow
                        );
                        tdA.appendChild(form);
                    });
                    tdA.appendChild(addBtn);
                }
                tr.appendChild(tdA);
            });

            container.appendChild(tbl);
        } catch (e) {
            const msg = document.createElement('p');
            msg.textContent = 'Failed to load schema: ' + e.message;
            msg.style.color = '#a80000';
            container.appendChild(msg);
        } finally {
            scanBtn.disabled    = false;
            scanBtn.textContent = 'Scan Schema';
        }
    });

    body.append(scanBtn, container);
    return card;
}

// ─── Tab 4: Dictionary ────────────────────────────────────────────────────────

function buildDictionaryTab() {
    const { card, body } = mkSection(
        'PII Keyword Dictionary',
        'Comma-separated keywords used by the Suggestions tab to detect PII column names (case-insensitive, substring match).'
    );

    const textarea = document.createElement('textarea');
    textarea.className = 'adm-input';
    textarea.rows      = 6;
    textarea.style.cssText = 'width:100%; max-width:600px; font-size:13px; font-family:monospace; margin-bottom:12px; resize:vertical;';
    textarea.value = (anonConfig.dictionary || []).join(', ');

    const hint = document.createElement('p');
    hint.textContent = 'Example: PESEL, NIP, email, phone, address, imię, nazwisko, ID number';
    hint.style.cssText = 'font-size:12px; color:var(--muted); margin-bottom:16px;';

    const saveBtn = document.createElement('button');
    saveBtn.className   = 'btn-action';
    saveBtn.textContent = 'Save Dictionary';

    const st = mkStatusEl();

    saveBtn.addEventListener('click', async () => {
        const words = textarea.value
            .split(',')
            .map(w => w.trim())
            .filter(Boolean);
        saveBtn.disabled = true;
        await saveConfig({ dictionary: words }, st);
        saveBtn.disabled = false;
        anonConfig.dictionary = words;
    });

    body.append(textarea, hint, saveBtn, st);

    // Log cleanup section
    const { card: logCard, body: logBody } = mkSection(
        'Log Cleanup',
        'Delete old anonymization run entries from spw_anonymization_log.'
    );

    const logRow = document.createElement('div');
    logRow.style.cssText = 'display:flex; align-items:center; gap:12px; flex-wrap:wrap;';

    const logLabel = document.createElement('label');
    logLabel.textContent = 'Delete runs older than';
    logLabel.style.cssText = 'font-size:13px; color:var(--muted);';

    const logInput = document.createElement('input');
    logInput.type  = 'number';
    logInput.value = '90';
    logInput.min   = '1';
    logInput.max   = '3650';
    logInput.className  = 'adm-input';
    logInput.style.width = '80px';

    const logUnit = document.createElement('span');
    logUnit.textContent = 'days';
    logUnit.style.cssText = 'font-size:13px; color:var(--muted);';

    const purgeBtn = document.createElement('button');
    purgeBtn.className   = 'btn-remove';
    purgeBtn.style.cssText = 'padding:6px 16px; font-size:13px; float:none;';
    purgeBtn.textContent = 'Purge Old Logs';

    const purgeSt = mkStatusEl();

    purgeBtn.addEventListener('click', async () => {
        const days = parseInt(logInput.value, 10);
        if (!days || days < 1) {
            showStatus(purgeSt, 'Enter a valid number of days.', false);
            return;
        }
        if (!confirm('Delete anonymization log entries older than ' + days + ' day(s)?')) return;
        purgeBtn.disabled    = true;
        purgeBtn.textContent = 'Purging…';
        purgeSt.style.display = 'none';
        try {
            const res  = await apiFetch('api.php?action=anonymization_purge_log', {
                method:  'POST',
                body:    JSON.stringify({ days }),
            });
            const data = await res.json();
            if (data.status === 'success') {
                showStatus(purgeSt, 'Deleted ' + data.deleted + ' log row(s).', true);
            } else {
                showStatus(purgeSt, 'Error: ' + (data.error || 'unknown'), false);
            }
        } catch (e) {
            showStatus(purgeSt, 'Request failed: ' + e.message, false);
        }
        purgeBtn.disabled    = false;
        purgeBtn.textContent = 'Purge Old Logs';
    });

    logRow.append(logLabel, logInput, logUnit, purgeBtn);
    logBody.append(logRow, purgeSt);
    body.appendChild(logCard);

    return card;
}

// ─── Tab 2 supplement: Run History ───────────────────────────────────────────

// Renders the per-run "Report" cell: a View toggle that expands the structured
// GDPR/EDPB JSON report below the row, plus a Download button.
function buildReportCell(r, tbody, colspan) {
    const cell = document.createElement('td');
    cell.className = 'adm-td';

    let report = null;
    if (r.report) {
        try {
            report = typeof r.report === 'string' ? JSON.parse(r.report) : r.report;
        } catch (e) {
            report = null;
        }
    }

    if (!report) {
        cell.textContent = '—';
        cell.style.color = 'var(--muted)';
        return cell;
    }

    const viewBtn = document.createElement('button');
    viewBtn.textContent  = 'View';
    viewBtn.style.cssText = 'background:none; border:1px solid var(--accent); color:var(--accent); border-radius:4px; padding:3px 10px; font-size:12px; cursor:pointer;';

    let detailRow = null;

    viewBtn.addEventListener('click', () => {
        if (detailRow) {
            detailRow.remove();
            detailRow = null;
            viewBtn.textContent = 'View';
            return;
        }

        const parentTr = cell.closest('tr');
        detailRow = tbody.insertRow(parentTr.sectionRowIndex + 1);
        viewBtn.textContent = 'Hide';

        const dtd = detailRow.insertCell();
        dtd.className   = 'adm-td';
        dtd.colSpan     = colspan;
        dtd.style.cssText = 'background:var(--bg); padding:12px;';

        const bar = document.createElement('div');
        bar.style.cssText = 'display:flex; align-items:center; gap:12px; margin-bottom:8px; flex-wrap:wrap;';

        const idLbl = document.createElement('strong');
        idLbl.textContent = report.report_id || 'Report';
        idLbl.style.cssText = 'font-size:13px; font-family:monospace;';

        const dlBtn = document.createElement('button');
        dlBtn.textContent  = 'Download JSON';
        dlBtn.style.cssText = 'background:var(--accent); color:#fff; border:none; border-radius:4px; padding:3px 12px; font-size:12px; cursor:pointer;';
        dlBtn.addEventListener('click', () => {
            const blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;
            a.download = (report.report_id || 'anonymization-report') + '.json';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        });

        bar.append(idLbl, dlBtn);

        const pre = document.createElement('pre');
        pre.style.cssText = 'margin:0; padding:12px; background:#fff; border:1px solid var(--border); border-radius:4px; font-size:12px; line-height:1.5; max-height:360px; overflow:auto; white-space:pre-wrap;';
        pre.textContent = JSON.stringify(report, null, 2);

        dtd.append(bar, pre);
    });

    cell.appendChild(viewBtn);
    return cell;
}

function buildHistorySection() {
    const { card, body } = mkSection(
        'Run History',
        'Last 50 executions from spw_anonymization_log.'
    );

    const loadBtn = document.createElement('button');
    loadBtn.className   = 'btn-action';
    loadBtn.textContent = 'Load History';

    const container = document.createElement('div');
    container.style.marginTop = '14px';

    loadBtn.addEventListener('click', async () => {
        loadBtn.disabled    = true;
        loadBtn.textContent = 'Loading…';
        container.innerHTML = '';
        try {
            const res  = await apiFetch('api.php?action=anonymization_log');
            const data = await res.json();
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
            const tbl   = document.createElement('table');
            tbl.className = 'adm-tbl';
            const headers = ['#', 'Status', 'Triggered By', 'Started At', 'Duration', 'Rules', 'Rows Anonymized', 'Report', 'Error'];
            const thead = tbl.createTHead();
            const hr    = thead.insertRow();
            headers.forEach(h => {
                const th = document.createElement('th');
                th.className   = 'adm-th';
                th.textContent = h;
                hr.appendChild(th);
            });
            const tbody = tbl.createTBody();
            data.rows.forEach(r => {
                const tr = tbody.insertRow();
                function td(text, css) {
                    const el = document.createElement('td');
                    el.className  = 'adm-td';
                    if (css) el.style.cssText = css;
                    el.textContent = text ?? '—';
                    return el;
                }
                const clsMap = { success: 'ok', error: 'danger', running: 'warn' };
                const badge  = document.createElement('span');
                badge.className   = 'adm-badge adm-badge-' + (clsMap[r.status] || 'muted');
                badge.textContent = (r.status || '').toUpperCase();
                const tdSt = document.createElement('td');
                tdSt.className = 'adm-td';
                tdSt.appendChild(badge);

                tr.append(
                    td(r.id),
                    tdSt,
                    td(r.triggered_by),
                    td(r.started_at ? r.started_at.replace('T', ' ').substring(0, 19) : ''),
                    td(r.duration_sec !== null && r.duration_sec !== undefined ? Number(r.duration_sec).toFixed(2) + 's' : '—'),
                    td(r.rules_processed),
                    td(r.rows_anonymized),
                    buildReportCell(r, tbody, headers.length),
                    td(r.error_message, 'color:#a80000; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;')
                );
            });
            container.appendChild(tbl);
        } catch (e) {
            container.textContent = 'Request failed: ' + e.message;
        }
        loadBtn.disabled    = false;
        loadBtn.textContent = 'Refresh';
    });

    body.append(loadBtn, container);
    return card;
}

// ─── Main render ──────────────────────────────────────────────────────────────

export async function renderAnonymizationPage(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '<p style="color:var(--muted);padding:20px;">Loading configuration…</p>';

    // Expose column options for the add-rule form.
    if (ctx.getColumnOptionsForTable) {
        window._anonColOptions = ctx.getColumnOptionsForTable;
    }

    try {
        const res  = await apiFetch('api.php?action=anonymization_load');
        const data = await res.json();
        if (data.status !== 'success') {
            workspaceEl.innerHTML = '<p style="color:#a80000;padding:20px;">Failed to load config: ' + anonEsc(data.error || 'unknown') + '</p>';
            return;
        }
        anonConfig = data.config;
    } catch (e) {
        workspaceEl.innerHTML = '<p style="color:#a80000;padding:20px;">Request failed: ' + anonEsc(e.message) + '</p>';
        return;
    }

    workspaceEl.innerHTML = '';

    const wrap = document.createElement('div');
    workspaceEl.appendChild(wrap);

    const [p0, p1, p2, p3] = buildInnerTabs(wrap, [
        { label: 'Rules' },
        { label: 'Schedule' },
        { label: 'Suggestions' },
        { label: 'Dictionary' },
    ]);

    p0.appendChild(buildRulesTab(ctx));
    p0.appendChild(buildHistorySection());

    p1.appendChild(buildScheduleTab());

    p2.appendChild(buildSuggestionsTab());

    p3.appendChild(buildDictionaryTab());
}
