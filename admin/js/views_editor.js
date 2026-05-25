/* admin/views_editor.js — Views module admin editor */

import { markDirty } from './app.js';
import { createIconPicker } from './ui.js';

export function renderViewsEditor(ctx) {
    const { workspaceEl, currentConfig } = ctx;
    workspaceEl.innerHTML = '';

    /* ensure views is a plain object */
    if (!currentConfig.views || typeof currentConfig.views !== 'object' || Array.isArray(currentConfig.views)) {
        currentConfig.views = {};
    }
    const views = currentConfig.views;

    /* ---------- state ---------- */
    let dbViews   = [];
    let dbColumns = {};

    /* ---------- root layout ---------- */
    const wrap = document.createElement('div');
    wrap.style.cssText = 'padding: 20px 24px; max-width: 900px;';

    const hdr = document.createElement('div');
    hdr.style.cssText = 'display:flex; align-items:flex-start; justify-content:space-between; gap:20px; margin-bottom:20px; flex-wrap:wrap;';
    hdr.innerHTML = `
        <div>
            <h2 style="margin:0 0 4px; font-size:1.2rem; font-weight:700;">Views Configuration</h2>
            <p style="margin:0; font-size:13px; color:var(--muted);">Sync to discover PostgreSQL views, configure display names, column colors, and drill-down. Use "Save config" in the top bar to persist.</p>
        </div>
    `;
    const syncBtn = document.createElement('button');
    syncBtn.className   = 'btn-add';
    syncBtn.style.cssText = 'margin:0; flex-shrink:0;';
    syncBtn.textContent = '↻ Sync from Database';
    hdr.appendChild(syncBtn);
    wrap.appendChild(hdr);

    const statusEl = document.createElement('div');
    statusEl.style.cssText = 'display:none; padding:8px 14px; border-radius:var(--radius); font-size:13px; margin-bottom:16px;';
    wrap.appendChild(statusEl);

    const listEl = document.createElement('div');
    listEl.style.cssText = 'display:flex; flex-direction:column; gap:16px;';
    wrap.appendChild(listEl);

    workspaceEl.appendChild(wrap);

    function setStatus(msg, type = 'info') {
        const styles = {
            info:  'background:var(--accent-light); color:var(--accent-dark);',
            ok:    'background:rgba(43,147,72,0.08); color:var(--ok);',
            error: 'background:rgba(208,0,0,0.08); color:var(--danger);',
        };
        statusEl.style.cssText = `display:block; padding:8px 14px; border-radius:var(--radius); font-size:13px; margin-bottom:16px; ${styles[type] ?? styles.info}`;
        statusEl.textContent = msg;
    }

    /* ---------- sync from DB ---------- */
    async function syncFromDb() {
        setStatus('Syncing from database…', 'info');
        try {
            const res  = await fetch('../api_views.php?action=sync');
            const data = await res.json();
            if (data.status !== 'ok') { setStatus('Sync failed: ' + (data.error ?? 'unknown'), 'error'); return; }
            dbViews   = data.db_views  ?? [];
            dbColumns = data.columns   ?? {};

            dbViews.forEach(vName => {
                if (!views[vName]) {
                    const cols = {};
                    Object.keys(dbColumns[vName] ?? {}).forEach(c => { cols[c] = { display_name: c, color_rules: [] }; });
                    views[vName] = { display_name: vName, menu_name: vName, description: '', icon: 'assets/icons/table_chart_view.png', hidden: false, columns: cols, drill_down: { enabled: false, levels: [] } };
                } else {
                    Object.keys(dbColumns[vName] ?? {}).forEach(c => {
                        if (!views[vName].columns) views[vName].columns = {};
                        if (!views[vName].columns[c]) views[vName].columns[c] = { display_name: c, color_rules: [] };
                    });
                }
            });

            markDirty();
            setStatus(`Found ${dbViews.length} view(s). Edit below, then click "Save config".`, 'ok');
            renderList();
        } catch (_) {
            setStatus('Network error during sync.', 'error');
        }
    }

    /* ---------- render list ---------- */
    function renderList() {
        listEl.innerHTML = '';
        if (dbViews.length === 0) {
            listEl.innerHTML = '<p style="color:var(--muted); text-align:center; padding:32px;">No views found. Click "↻ Sync from Database" to discover views.</p>';
            return;
        }
        dbViews.forEach(vName => listEl.appendChild(buildViewCard(vName, views[vName] ?? {})));
    }

    /* ---------- single view card (column-block style) ---------- */
    function buildViewCard(vName, cfg) {
        const card = document.createElement('div');
        card.className = 'column-block';
        card.dataset.view = vName;
        if (cfg.hidden) card.style.opacity = '0.6';

        /* header: view name + collapse + visible toggle */
        const cardHdr = document.createElement('div');
        cardHdr.style.cssText = 'display:flex; align-items:center; gap:10px; padding-bottom:12px; margin-bottom:16px; border-bottom:1px solid var(--border-light); cursor:default;';

        const toggleBtn = document.createElement('button');
        toggleBtn.textContent = '▶';
        toggleBtn.style.cssText = 'background:none; border:none; font-size:12px; cursor:pointer; color:var(--muted); padding:0 4px; box-shadow:none;';

        const nameSpan = document.createElement('strong');
        nameSpan.style.cssText = 'font-size:15px; color:var(--text);';
        nameSpan.textContent = cfg.display_name ?? vName;

        const dbSpan = document.createElement('span');
        dbSpan.style.cssText = 'font-size:12px; color:var(--muted);';
        dbSpan.textContent = `(${vName})`;

        const visibleLabel = document.createElement('label');
        visibleLabel.style.cssText = 'display:flex; align-items:center; gap:6px; margin-left:auto; font-size:13px; color:var(--muted); cursor:pointer; font-weight:normal;';
        const visibleChk = document.createElement('input');
        visibleChk.type    = 'checkbox';
        visibleChk.checked = !cfg.hidden;
        visibleChk.style.cssText = 'width:15px; height:15px; accent-color:var(--accent); cursor:pointer;';
        visibleChk.addEventListener('change', e => {
            views[vName].hidden = !e.target.checked;
            card.style.opacity = views[vName].hidden ? '0.6' : '1';
        });
        visibleLabel.appendChild(visibleChk);
        visibleLabel.appendChild(document.createTextNode('Visible'));

        cardHdr.appendChild(toggleBtn);
        cardHdr.appendChild(nameSpan);
        cardHdr.appendChild(dbSpan);
        cardHdr.appendChild(visibleLabel);
        card.appendChild(cardHdr);

        /* collapsible body */
        const body = document.createElement('div');
        body.style.display = 'none';
        body.appendChild(buildCardBody(vName, cfg));
        card.appendChild(body);

        toggleBtn.addEventListener('click', () => {
            const open = body.style.display === 'block';
            body.style.display = open ? 'none' : 'block';
            toggleBtn.textContent = open ? '▶' : '�L';
        });

        return card;
    }

    /* ---------- card body ---------- */
    function buildCardBody(vName, cfg) {
        const frag = document.createDocumentFragment();

        /* General section */
        const genHdr = document.createElement('h4');
        genHdr.textContent = 'General';
        frag.appendChild(genHdr);

        frag.appendChild(fg('Display name', 'text', cfg.display_name ?? vName, v => { views[vName].display_name = v; }));
        frag.appendChild(fg('Menu name',    'text', cfg.menu_name    ?? vName, v => { views[vName].menu_name    = v; }));
        frag.appendChild(fgArea('Description', cfg.description ?? '', v => { views[vName].description = v; }));
        frag.appendChild(createIconPicker('icon', 'Icon', cfg.icon ?? 'assets/icons/table_chart_view.png', v => { views[vName].icon = v; markDirty(); }));

        const divider1 = document.createElement('hr');
        divider1.style.cssText = 'border:none; border-top:1px solid var(--border-light); margin:20px 0;';
        frag.appendChild(divider1);

        /* Columns section */
        const colHdr = document.createElement('h4');
        colHdr.textContent = 'Columns';
        frag.appendChild(colHdr);
        frag.appendChild(buildColumnsEditor(vName, cfg.columns ?? {}));

        const divider2 = document.createElement('hr');
        divider2.style.cssText = 'border:none; border-top:1px solid var(--border-light); margin:20px 0;';
        frag.appendChild(divider2);

        /* Drill-down section */
        const drillHdr = document.createElement('h4');
        drillHdr.textContent = 'Drill-down';
        frag.appendChild(drillHdr);
        frag.appendChild(buildDrillEditor(vName, cfg));

        return frag;
    }

    /* ---------- .form-group field helper ---------- */
    function fg(label, type, value, onChange) {
        const grp = document.createElement('div');
        grp.className = 'form-group';
        const lbl = document.createElement('label');
        lbl.textContent = label;
        grp.appendChild(lbl);
        const inp = document.createElement('input');
        inp.type = type; inp.value = value ?? '';
        inp.addEventListener('input', () => onChange(inp.value));
        grp.appendChild(inp);
        return grp;
    }

    function fgArea(label, value, onChange) {
        const grp = document.createElement('div');
        grp.className = 'form-group';
        const lbl = document.createElement('label');
        lbl.textContent = label;
        grp.appendChild(lbl);
        const ta = document.createElement('textarea');
        ta.rows = 3; ta.style.resize = 'vertical';
        ta.value = value ?? '';
        ta.addEventListener('input', () => onChange(ta.value));
        grp.appendChild(ta);
        return grp;
    }

    /* ---------- columns editor ---------- */
    function buildColumnsEditor(vName, colsCfg) {
        const wrap = document.createElement('div');

        const dbCols  = Object.keys(dbColumns[vName] ?? {});
        const allCols = dbCols.length > 0 ? dbCols : Object.keys(colsCfg);

        if (allCols.length === 0) {
            wrap.innerHTML = '<p style="color:var(--muted); font-size:13px;">Sync from DB to see columns.</p>';
            return wrap;
        }

        allCols.forEach(colName => {
            const colCfg = colsCfg[colName] ?? { display_name: colName, color_rules: [] };
            if (!views[vName].columns) views[vName].columns = {};
            if (!views[vName].columns[colName]) views[vName].columns[colName] = { display_name: colName, color_rules: [] };

            const colBlock = document.createElement('div');
            colBlock.className = 'subtable-block';

            const colHdr = document.createElement('h4');
            colHdr.style.cssText = 'display:flex; align-items:center; gap:8px;';
            const colNameSpan = document.createElement('span');
            colNameSpan.textContent = colName;
            colHdr.appendChild(colNameSpan);
            const dtype = dbColumns[vName]?.[colName]?.data_type ?? '';
            if (dtype) {
                const badge = document.createElement('span');
                badge.textContent = dtype;
                badge.style.cssText = 'font-size:11px; font-weight:400; color:var(--muted); background:var(--border-light); padding:1px 6px; border-radius:10px;';
                colHdr.appendChild(badge);
            }
            colBlock.appendChild(colHdr);

            colBlock.appendChild(fg('Display name', 'text', colCfg.display_name ?? colName, v => {
                views[vName].columns[colName].display_name = v;
            }));

            /* summary function */
            const summaryGrp = document.createElement('div');
            summaryGrp.className = 'form-group';
            const summaryLbl = document.createElement('label');
            summaryLbl.textContent = 'Summary';
            summaryGrp.appendChild(summaryLbl);
            const summarySel = document.createElement('select');
            ['none', 'sum', 'avg', 'count', 'min', 'max'].forEach(fn => {
                const opt = document.createElement('option');
                opt.value = fn;
                opt.textContent = fn === 'none' ? 'None' : fn.toUpperCase();
                if ((colCfg.summary ?? 'none') === fn) opt.selected = true;
                summarySel.appendChild(opt);
            });
            summarySel.addEventListener('change', () => {
                const v = summarySel.value;
                if (v === 'none') delete views[vName].columns[colName].summary;
                else views[vName].columns[colName].summary = v;
                markDirty();
            });
            summaryGrp.appendChild(summarySel);
            colBlock.appendChild(summaryGrp);

            /* color rules */
            const rulesLabel = document.createElement('label');
            rulesLabel.textContent = 'Color rules';
            rulesLabel.style.cssText = 'display:block; margin-bottom:8px; font-weight:600; font-size:14px; color:var(--text);';
            colBlock.appendChild(rulesLabel);

            const rulesList = document.createElement('div');
            rulesList.style.cssText = 'display:flex; flex-direction:column; gap:6px; margin-bottom:10px;';
            colBlock.appendChild(rulesList);

            const rules = Array.isArray(colCfg.color_rules) ? colCfg.color_rules : [];
            views[vName].columns[colName].color_rules = rules;

            function renderRules() {
                rulesList.innerHTML = '';
                rules.forEach((rule, idx) => rulesList.appendChild(buildRuleRow(rule, idx, rules, renderRules)));
            }
            renderRules();

            const addRuleBtn = document.createElement('button');
            addRuleBtn.className   = 'btn-add';
            addRuleBtn.style.cssText = 'margin:0; padding:7px 12px; font-size:13px;';
            addRuleBtn.textContent = '+ Add color rule';
            addRuleBtn.addEventListener('click', () => {
                rules.push({ op: '>', value: 0, color: '#d00000' });
                renderRules();
                markDirty();
            });
            colBlock.appendChild(addRuleBtn);

            wrap.appendChild(colBlock);
        });

        return wrap;
    }

    /* ---------- single color rule row ---------- */
    function buildRuleRow(rule, idx, rules, onUpdate) {
        const row = document.createElement('div');
        row.style.cssText = 'display:flex; align-items:center; gap:8px;';

        const opSel = document.createElement('select');
        opSel.style.cssText = 'width:64px; padding:8px 6px; border:1px solid var(--border); border-radius:var(--radius); font-size:13px; background:var(--panel); color:var(--text);';
        ['>', '>=', '<', '<=', '=='].forEach(op => {
            const o = document.createElement('option');
            o.value = op; o.textContent = op;
            if (rule.op === op) o.selected = true;
            opSel.appendChild(o);
        });
        opSel.addEventListener('change', () => { rules[idx].op = opSel.value; });

        const valInp = document.createElement('input');
        valInp.type  = 'number';
        valInp.style.cssText = 'width:100px; padding:8px 10px; border:1px solid var(--border); border-radius:var(--radius); font-size:13px; background:var(--panel); color:var(--text);';
        valInp.value = rule.value ?? 0;
        valInp.addEventListener('input', () => { rules[idx].value = parseFloat(valInp.value) || 0; });

        const colorInp = document.createElement('input');
        colorInp.type  = 'color';
        colorInp.style.cssText = 'width:44px; height:36px; border:1px solid var(--border); border-radius:var(--radius); cursor:pointer; padding:1px;';
        colorInp.value = rule.color ?? '#d00000';
        colorInp.addEventListener('input', () => { rules[idx].color = colorInp.value; });

        const delBtn = document.createElement('button');
        delBtn.className   = 'btn-remove';
        delBtn.style.cssText = 'float:none; padding:6px 10px; font-size:12px;';
        delBtn.textContent = '�s� Remove';
        delBtn.addEventListener('click', () => { rules.splice(idx, 1); onUpdate(); markDirty(); });

        row.appendChild(opSel); row.appendChild(valInp); row.appendChild(colorInp); row.appendChild(delBtn);
        return row;
    }

    /* ---------- drill-down editor ---------- */
    function buildDrillEditor(vName, cfg) {
        const wrap = document.createElement('div');
        const dd   = cfg.drill_down ?? { enabled: false, levels: [] };
        views[vName].drill_down = dd;

        /* enable toggle as form-group */
        const enableGrp = document.createElement('div');
        enableGrp.className = 'form-group';
        const enableLbl = document.createElement('label');
        enableLbl.textContent = 'Enable drill-down';
        enableGrp.appendChild(enableLbl);
        const enableChk = document.createElement('input');
        enableChk.type    = 'checkbox';
        enableChk.checked = !!dd.enabled;
        enableChk.addEventListener('change', () => { views[vName].drill_down.enabled = enableChk.checked; });
        enableGrp.appendChild(enableChk);
        wrap.appendChild(enableGrp);

        const levelsLabel = document.createElement('label');
        levelsLabel.textContent = 'Levels (ordered)';
        levelsLabel.style.cssText = 'display:block; margin-bottom:8px; font-weight:600; font-size:14px; color:var(--text);';
        wrap.appendChild(levelsLabel);

        const levelsList = document.createElement('div');
        levelsList.style.cssText = 'display:flex; flex-direction:column; gap:8px; margin-bottom:12px;';
        wrap.appendChild(levelsList);

        const dbCols  = Object.keys(dbColumns[vName] ?? {});
        const allCols = dbCols.length > 0 ? dbCols : Object.keys(views[vName].columns ?? {});

        function renderLevels() {
            levelsList.innerHTML = '';
            (dd.levels ?? []).forEach((lvl, idx) => {
                const lvlRow = document.createElement('div');
                lvlRow.style.cssText = 'display:flex; align-items:center; gap:8px; padding:8px 12px; background:var(--bg); border:1px solid var(--border-light); border-radius:var(--radius);';

                const idxSpan = document.createElement('span');
                idxSpan.style.cssText = 'font-size:12px; color:var(--muted); min-width:52px;';
                idxSpan.textContent = `Level ${idx}:`;

                const gbSel = document.createElement('select');
                gbSel.style.cssText = 'flex:1; padding:8px 10px; border:1px solid var(--border); border-radius:var(--radius); font-size:13px; background:var(--panel); color:var(--text);';
                allCols.forEach(c => {
                    const o = document.createElement('option');
                    o.value = c; o.textContent = c;
                    if (lvl.group_by === c) o.selected = true;
                    gbSel.appendChild(o);
                });
                gbSel.addEventListener('change', () => { dd.levels[idx].group_by = gbSel.value; });

                const labelInp = document.createElement('input');
                labelInp.type        = 'text';
                labelInp.placeholder = 'Label (optional)';
                labelInp.value       = lvl.label ?? '';
                labelInp.style.cssText = 'flex:1; padding:8px 10px; border:1px solid var(--border); border-radius:var(--radius); font-size:13px; background:var(--panel); color:var(--text);';
                labelInp.addEventListener('input', () => { dd.levels[idx].label = labelInp.value; });

                const delBtn = document.createElement('button');
                delBtn.className   = 'btn-remove';
                delBtn.style.cssText = 'float:none; padding:6px 10px; font-size:12px;';
                delBtn.textContent = '�s�';
                delBtn.addEventListener('click', () => { dd.levels.splice(idx, 1); renderLevels(); markDirty(); });

                lvlRow.appendChild(idxSpan); lvlRow.appendChild(gbSel); lvlRow.appendChild(labelInp); lvlRow.appendChild(delBtn);
                levelsList.appendChild(lvlRow);
            });
        }
        renderLevels();

        const addLvlBtn = document.createElement('button');
        addLvlBtn.className   = 'btn-add';
        addLvlBtn.style.cssText = 'margin:0; padding:7px 12px; font-size:13px;';
        addLvlBtn.textContent = '+ Add level';
        addLvlBtn.addEventListener('click', () => {
            if (!dd.levels) dd.levels = [];
            dd.levels.push({ group_by: allCols[0] ?? '', label: '' });
            renderLevels();
            markDirty();
        });
        wrap.appendChild(addLvlBtn);
        return wrap;
    }

    /* ---------- init ---------- */
    syncBtn.addEventListener('click', syncFromDb);

    if (Object.keys(views).length > 0) {
        dbViews = Object.keys(views);
        dbViews.forEach(v => {
            dbColumns[v] = {};
            Object.keys(views[v].columns ?? {}).forEach(c => { dbColumns[v][c] = { data_type: '' }; });
        });
        renderList();
        setStatus('Config loaded. Sync to refresh column metadata from DB.', 'info');
    } else {
        listEl.innerHTML = '<p style="color:var(--muted); text-align:center; padding:32px;">No views configured yet. Click "↻ Sync from Database" to start.</p>';
    }
}
