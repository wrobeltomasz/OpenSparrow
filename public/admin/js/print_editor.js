/* admin/js/print_editor.js — Printouts module admin editor (renderPrintEditor):
   edits the "print" config templates via api/print.php (config / columns / save).
   Each template is bound to a PostgreSQL view from the Views module; the available
   variables (columns) are fetched live from the database so the user never types them. */

import { apiFetch } from '../../assets/js/util/api.js';
import { createIconPicker } from './ui.js';

export function renderPrintEditor(ctx) {
    const { workspaceEl, setSaveHandler } = ctx;
    workspaceEl.innerHTML = '';

    /* ---------- state ---------- */
    let prints      = {};     // "prints" object from the config store (working copy)
    let cfgVersion  = 0;      // optimistic-lock version echoed back on save
    let dbViews     = [];     // selectable PostgreSQL view names (from the "views" config)
    let viewColumns = {};     // view -> [{name, data_type}] (lazy, from action=columns)

    /* ---------- root layout ---------- */
    const wrap = document.createElement('div');
    wrap.style.cssText = 'padding: 20px 24px; max-width: 900px;';

    const hdr = document.createElement('div');
    hdr.style.cssText = 'display:flex; align-items:flex-start; justify-content:space-between; gap:20px; margin-bottom:20px; flex-wrap:wrap;';
    const hdrText = document.createElement('div');
    const hdrTitle = document.createElement('h2');
    hdrTitle.style.cssText = 'margin:0 0 4px; font-size:1.2rem; font-weight:700;';
    hdrTitle.textContent = 'Printouts Configuration';
    const hdrDesc = document.createElement('p');
    hdrDesc.style.cssText = 'margin:0; font-size:13px; color:var(--muted);';
    hdrDesc.textContent = 'Build printable report templates from simple blocks (header, text, table). Each template is bound to a PostgreSQL view from the Views module; its columns become the available {variables}. Optional parameters let users filter the report (e.g. by employee) before printing.';
    hdrText.appendChild(hdrTitle);
    hdrText.appendChild(hdrDesc);
    hdr.appendChild(hdrText);

    const hdrActions = document.createElement('div');
    hdrActions.style.cssText = 'display:flex; gap:8px; flex-shrink:0;';
    const addBtn = document.createElement('button');
    addBtn.className = 'btn btn-success';
    addBtn.textContent = '+ Add printout';
    hdrActions.appendChild(addBtn);
    hdr.appendChild(hdrActions);
    wrap.appendChild(hdr);

    setSaveHandler(async () => {
        const res = await apiFetch('../api/print.php?action=save', {
            method: 'POST',
            body: JSON.stringify({ prints, version: cfgVersion }),
        });
        const data = await res.json();
        if (data.status === 'ok') {
            cfgVersion = data.version ?? cfgVersion + 1;
            setStatus('Printouts saved.', 'ok');
            return { status: 'success', message: 'Printouts saved' };
        }
        if (res.status === 409) {
            setStatus('Save rejected: configuration was changed by someone else. Reload the page and re-apply your edits.', 'error');
        }
        return { status: 'error', error: data.error ?? 'unknown' };
    });

    const statusEl = document.createElement('div');
    statusEl.style.cssText = 'display:none;';
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

    /* ---------- columns of a view (lazy fetch, cached) ---------- */
    async function fetchColumns(viewName) {
        if (!viewName) return [];
        if (viewColumns[viewName]) return viewColumns[viewName];
        try {
            const res  = await apiFetch('../api/print.php?action=columns&view=' + encodeURIComponent(viewName));
            const data = await res.json();
            if (data.status !== 'ok') return [];
            viewColumns[viewName] = data.columns ?? [];
            return viewColumns[viewName];
        } catch {
            return [];
        }
    }

    /* ---------- form-group helpers ---------- */
    function fg(label, value, onChange) {
        const grp = document.createElement('div');
        grp.className = 'form-group';
        const lbl = document.createElement('label');
        lbl.textContent = label;
        grp.appendChild(lbl);
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.value = value ?? '';
        inp.addEventListener('input', () => onChange(inp.value));
        grp.appendChild(inp);
        return grp;
    }

    function fgArea(label, value, onChange, rows = 3) {
        const grp = document.createElement('div');
        grp.className = 'form-group';
        const lbl = document.createElement('label');
        lbl.textContent = label;
        grp.appendChild(lbl);
        const ta = document.createElement('textarea');
        ta.rows = rows;
        ta.style.resize = 'vertical';
        ta.value = value ?? '';
        ta.addEventListener('input', () => onChange(ta.value));
        grp.appendChild(ta);
        return grp;
    }

    /* ---------- variables badge row (auto-fetched from the view) ---------- */
    function buildVariablesRow(cols) {
        const box = document.createElement('div');
        box.style.cssText = 'display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px;';
        if (cols.length === 0) {
            const none = document.createElement('span');
            none.style.cssText = 'font-size:12px; color:var(--muted);';
            none.textContent = 'Select a view to load its variables.';
            box.appendChild(none);
            return box;
        }
        cols.forEach(col => {
            const badge = document.createElement('span');
            badge.style.cssText = 'font-size:11px; font-family:monospace; color:var(--accent-dark); background:var(--accent-light); padding:2px 8px; border-radius:10px;';
            badge.textContent = `{${col.name}}`;
            badge.title = col.data_type || '';
            box.appendChild(badge);
        });
        return box;
    }

    /* ---------- single template card ---------- */
    function buildPrintCard(pName, cfg) {
        const card = document.createElement('div');
        card.className = 'column-block';
        card.dataset.print = pName;
        if (cfg.hidden) card.style.opacity = '0.6';

        const cardHdr = document.createElement('div');
        cardHdr.style.cssText = 'display:flex; align-items:center; gap:10px; padding-bottom:12px; margin-bottom:16px; border-bottom:1px solid var(--border-light);';

        const toggleBtn = document.createElement('button');
        toggleBtn.textContent = '▶';
        toggleBtn.className = 'chevron-btn';

        const nameSpan = document.createElement('strong');
        nameSpan.style.cssText = 'font-size:15px; color:var(--text);';
        nameSpan.textContent = cfg.display_name || pName;

        const keySpan = document.createElement('span');
        keySpan.style.cssText = 'font-size:12px; color:var(--muted); font-family:monospace;';
        keySpan.textContent = `(${pName})`;

        const visibleLabel = document.createElement('label');
        visibleLabel.style.cssText = 'display:flex; align-items:center; gap:6px; margin-left:auto; font-size:13px; color:var(--muted); cursor:pointer; font-weight:normal;';
        const visibleChk = document.createElement('input');
        visibleChk.type = 'checkbox';
        visibleChk.checked = !cfg.hidden;
        visibleChk.style.cssText = 'width:15px; height:15px; accent-color:var(--accent); cursor:pointer;';
        visibleChk.addEventListener('change', e => {
            prints[pName].hidden = !e.target.checked;
            card.style.opacity = prints[pName].hidden ? '0.6' : '1';
        });
        visibleLabel.appendChild(visibleChk);
        visibleLabel.appendChild(document.createTextNode('Visible'));

        const delBtn = document.createElement('button');
        delBtn.className = 'btn btn-danger btn-xs';
        delBtn.textContent = '✕ Delete';
        delBtn.addEventListener('click', () => {
            if (!confirm(`Delete printout "${pName}"?`)) return;
            delete prints[pName];
            renderList();
        });

        cardHdr.appendChild(toggleBtn);
        cardHdr.appendChild(nameSpan);
        cardHdr.appendChild(keySpan);
        cardHdr.appendChild(visibleLabel);
        cardHdr.appendChild(delBtn);
        card.appendChild(cardHdr);

        const body = document.createElement('div');
        body.style.display = 'none';
        card.appendChild(body);

        let rendered = false;
        toggleBtn.addEventListener('click', async () => {
            const open = body.style.display === 'block';
            body.style.display = open ? 'none' : 'block';
            toggleBtn.textContent = open ? '▶' : '▼';
            if (!open && !rendered) {
                rendered = true;
                await buildCardBody(pName, cfg, body, nameSpan);
            }
        });

        return card;
    }

    /* ---------- card body ---------- */
    async function buildCardBody(pName, cfg, body, nameSpan) {
        body.innerHTML = '';

        /* General */
        const genHdr = document.createElement('h4');
        genHdr.textContent = 'General';
        body.appendChild(genHdr);

        body.appendChild(fg('Display name', cfg.display_name ?? pName, v => {
            prints[pName].display_name = v;
            nameSpan.textContent = v || pName;
        }));
        body.appendChild(fg('Menu name', cfg.menu_name ?? pName, v => { prints[pName].menu_name = v; }));
        body.appendChild(fgArea('Description', cfg.description ?? '', v => { prints[pName].description = v; }));
        body.appendChild(createIconPicker('icon', 'Icon', cfg.icon || 'assets/icons/picture_as_pdf.png', v => { prints[pName].icon = v; }));

        /* Data source */
        const srcHdr = document.createElement('h4');
        srcHdr.textContent = 'Data source (PostgreSQL view)';
        body.appendChild(srcHdr);

        const viewGrp = document.createElement('div');
        viewGrp.className = 'form-group';
        const viewLbl = document.createElement('label');
        viewLbl.textContent = 'SQL view (from the Views module)';
        viewGrp.appendChild(viewLbl);
        const viewSel = document.createElement('select');
        const optNone = document.createElement('option');
        optNone.value = '';
        optNone.textContent = '— select view —';
        viewSel.appendChild(optNone);
        dbViews.forEach(v => {
            const o = document.createElement('option');
            o.value = v;
            o.textContent = v;
            if ((cfg.view ?? '') === v) o.selected = true;
            viewSel.appendChild(o);
        });
        viewGrp.appendChild(viewSel);
        body.appendChild(viewGrp);

        const varsLbl = document.createElement('label');
        varsLbl.textContent = 'Available variables (columns of the view)';
        varsLbl.style.cssText = 'display:block; margin-bottom:8px; font-weight:600; font-size:14px; color:var(--text);';
        body.appendChild(varsLbl);

        let varsRow = buildVariablesRow([]);
        body.appendChild(varsRow);

        /* Parameters — optional filters shown to the user before the report is generated,
           e.g. "pick an employee". Each filters the report view by one column; the dropdown
           options come either from a separate lookup view or from distinct values of that
           column itself. */
        const paramsHdr = document.createElement('h4');
        paramsHdr.textContent = 'Report parameters';
        body.appendChild(paramsHdr);

        const paramsHint = document.createElement('p');
        paramsHint.style.cssText = 'margin:0 0 10px; font-size:12px; color:var(--muted);';
        paramsHint.textContent = 'Optional filters shown above the report before it is generated '
            + '(e.g. "pick an employee"). Leave the lookup view empty to offer distinct values of '
            + 'the filter column itself.';
        body.appendChild(paramsHint);

        const paramsList = document.createElement('div');
        paramsList.style.cssText = 'display:flex; flex-direction:column; gap:10px; margin-bottom:12px;';
        body.appendChild(paramsList);

        if (!Array.isArray(prints[pName].params)) prints[pName].params = [];
        const params = prints[pName].params;

        /* Blocks */
        const blkHdr = document.createElement('h4');
        blkHdr.textContent = 'Template blocks';
        body.appendChild(blkHdr);

        const blocksList = document.createElement('div');
        blocksList.style.cssText = 'display:flex; flex-direction:column; gap:10px; margin-bottom:12px;';
        body.appendChild(blocksList);

        if (!Array.isArray(prints[pName].blocks)) prints[pName].blocks = [];
        const blocks = prints[pName].blocks;
        let currentCols = [];

        async function refreshVariables() {
            currentCols = await fetchColumns(viewSel.value);
            const fresh = buildVariablesRow(currentCols);
            varsRow.replaceWith(fresh);
            varsRow = fresh;
            renderBlocks();
            renderParams();
        }

        viewSel.addEventListener('change', () => {
            prints[pName].view = viewSel.value;
            refreshVariables();
        });

        function renderBlocks() {
            blocksList.innerHTML = '';
            if (blocks.length === 0) {
                const empty = document.createElement('p');
                empty.style.cssText = 'color:var(--muted); font-size:13px; margin:0;';
                empty.textContent = 'No blocks yet. Add a header, text or table block below.';
                blocksList.appendChild(empty);
                return;
            }
            blocks.forEach((block, idx) => blocksList.appendChild(buildBlockRow(block, idx)));
        }

        function buildBlockRow(block, idx) {
            const row = document.createElement('div');
            row.className = 'subtable-block';

            const rowHdr = document.createElement('div');
            rowHdr.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:8px;';

            const typeSpan = document.createElement('strong');
            typeSpan.style.cssText = 'font-size:13px; color:var(--text); text-transform:capitalize;';
            typeSpan.textContent = `${idx + 1}. ${block.type}`;
            rowHdr.appendChild(typeSpan);

            const spacer = document.createElement('span');
            spacer.style.flex = '1';
            rowHdr.appendChild(spacer);

            const upBtn = document.createElement('button');
            upBtn.className = 'item-order-btn';
            upBtn.textContent = '^';
            upBtn.disabled = idx === 0;
            upBtn.addEventListener('click', () => {
                [blocks[idx - 1], blocks[idx]] = [blocks[idx], blocks[idx - 1]];
                renderBlocks();
            });
            const downBtn = document.createElement('button');
            downBtn.className = 'item-order-btn';
            downBtn.textContent = 'v';
            downBtn.disabled = idx === blocks.length - 1;
            downBtn.addEventListener('click', () => {
                [blocks[idx + 1], blocks[idx]] = [blocks[idx], blocks[idx + 1]];
                renderBlocks();
            });
            const rmBtn = document.createElement('button');
            rmBtn.className = 'btn btn-danger btn-xs';
            rmBtn.textContent = '✕';
            rmBtn.addEventListener('click', () => {
                blocks.splice(idx, 1);
                renderBlocks();
            });
            rowHdr.appendChild(upBtn);
            rowHdr.appendChild(downBtn);
            rowHdr.appendChild(rmBtn);
            row.appendChild(rowHdr);

            if (block.type === 'header') {
                const lvlGrp = document.createElement('div');
                lvlGrp.className = 'form-group';
                const lvlLbl = document.createElement('label');
                lvlLbl.textContent = 'Level';
                lvlGrp.appendChild(lvlLbl);
                const lvlSel = document.createElement('select');
                [1, 2, 3].forEach(l => {
                    const o = document.createElement('option');
                    o.value = String(l);
                    o.textContent = `H${l}`;
                    if ((block.level ?? 1) === l) o.selected = true;
                    lvlSel.appendChild(o);
                });
                lvlSel.addEventListener('change', () => { block.level = parseInt(lvlSel.value, 10); });
                lvlGrp.appendChild(lvlSel);
                row.appendChild(lvlGrp);
                row.appendChild(fg('Text (supports {variables})', block.text ?? '', v => { block.text = v; }));
            } else if (block.type === 'text') {
                row.appendChild(fgArea('Text (supports {variables}, values come from the first row)', block.text ?? '', v => { block.text = v; }, 4));
            } else if (block.type === 'table') {
                const colsLbl = document.createElement('label');
                colsLbl.textContent = 'Columns (all rows of the view are printed)';
                colsLbl.style.cssText = 'display:block; margin-bottom:6px; font-weight:600; font-size:13px; color:var(--text);';
                row.appendChild(colsLbl);

                const colsHint = document.createElement('p');
                colsHint.style.cssText = 'margin:0 0 8px; font-size:12px; color:var(--muted);';
                colsHint.textContent = 'Width is a percentage of the table; leave blank to auto-size. Widths do not need to add up to 100. Alignment applies to data cells only — column headers are always centered.';
                row.appendChild(colsHint);

                if (!Array.isArray(block.columns)) block.columns = [];
                // Normalize legacy bare-string entries to {name, align} objects.
                block.columns = block.columns.map(c => (typeof c === 'string' ? { name: c, align: 'left' } : c));

                const colsBox = document.createElement('div');
                colsBox.style.cssText = 'display:flex; flex-direction:column; gap:6px;';
                if (currentCols.length === 0) {
                    const none = document.createElement('span');
                    none.style.cssText = 'font-size:12px; color:var(--muted);';
                    none.textContent = 'Select a view first to choose columns (empty = all columns).';
                    colsBox.appendChild(none);
                }
                currentCols.forEach(col => {
                    let entry = block.columns.find(c => c.name === col.name);

                    const rowWrap = document.createElement('div');
                    rowWrap.style.cssText = 'display:flex; align-items:center; gap:10px;';

                    const lab = document.createElement('label');
                    lab.style.cssText = 'display:flex; align-items:center; gap:5px; font-size:13px; color:var(--text); cursor:pointer; font-weight:normal; min-width:160px;';
                    const chk = document.createElement('input');
                    chk.type = 'checkbox';
                    chk.checked = !!entry;
                    chk.style.cssText = 'width:14px; height:14px; accent-color:var(--accent); cursor:pointer;';
                    lab.appendChild(chk);
                    lab.appendChild(document.createTextNode(col.name));
                    rowWrap.appendChild(lab);

                    const widthInp = document.createElement('input');
                    widthInp.type = 'number';
                    widthInp.min = '1';
                    widthInp.max = '100';
                    widthInp.placeholder = 'auto %';
                    widthInp.className = 'adm-input w-80';
                    widthInp.value = entry?.width ?? '';
                    widthInp.disabled = !entry;
                    rowWrap.appendChild(widthInp);

                    const alignSel = document.createElement('select');
                    alignSel.className = 'adm-input w-110';
                    [['left', 'Left'], ['center', 'Center'], ['right', 'Right']].forEach(([v, l]) => {
                        const o = document.createElement('option');
                        o.value = v;
                        o.textContent = l;
                        if ((entry?.align ?? 'left') === v) o.selected = true;
                        alignSel.appendChild(o);
                    });
                    alignSel.disabled = !entry;
                    rowWrap.appendChild(alignSel);

                    chk.addEventListener('change', () => {
                        if (chk.checked) {
                            entry = { name: col.name, align: alignSel.value };
                            const w = parseInt(widthInp.value, 10);
                            if (w >= 1 && w <= 100) entry.width = w;
                            block.columns.push(entry);
                        } else {
                            block.columns = block.columns.filter(c => c.name !== col.name);
                            entry = null;
                        }
                        widthInp.disabled = !entry;
                        alignSel.disabled = !entry;
                    });
                    widthInp.addEventListener('input', () => {
                        if (!entry) return;
                        const w = parseInt(widthInp.value, 10);
                        if (widthInp.value === '') {
                            delete entry.width;
                        } else if (w >= 1 && w <= 100) {
                            entry.width = w;
                        }
                    });
                    alignSel.addEventListener('change', () => {
                        if (!entry) return;
                        entry.align = alignSel.value;
                    });

                    colsBox.appendChild(rowWrap);
                });
                row.appendChild(colsBox);
            }

            return row;
        }

        function renderParams() {
            paramsList.innerHTML = '';
            if (params.length === 0) {
                const empty = document.createElement('p');
                empty.style.cssText = 'color:var(--muted); font-size:13px; margin:0;';
                empty.textContent = 'No parameters. Add one below to let users filter this report before printing.';
                paramsList.appendChild(empty);
                return;
            }
            params.forEach((param, idx) => paramsList.appendChild(buildParamRow(param, idx)));
        }

        function buildParamRow(param, idx) {
            const row = document.createElement('div');
            row.className = 'subtable-block';

            const rowHdr = document.createElement('div');
            rowHdr.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:8px;';

            const titleSpan = document.createElement('strong');
            titleSpan.style.cssText = 'font-size:13px; color:var(--text);';
            titleSpan.textContent = `${idx + 1}. ${param.label || param.key || 'parameter'}`;
            rowHdr.appendChild(titleSpan);

            const spacer = document.createElement('span');
            spacer.style.flex = '1';
            rowHdr.appendChild(spacer);

            const upBtn = document.createElement('button');
            upBtn.className = 'item-order-btn';
            upBtn.textContent = '^';
            upBtn.disabled = idx === 0;
            upBtn.addEventListener('click', () => {
                [params[idx - 1], params[idx]] = [params[idx], params[idx - 1]];
                renderParams();
            });
            const downBtn = document.createElement('button');
            downBtn.className = 'item-order-btn';
            downBtn.textContent = 'v';
            downBtn.disabled = idx === params.length - 1;
            downBtn.addEventListener('click', () => {
                [params[idx + 1], params[idx]] = [params[idx], params[idx + 1]];
                renderParams();
            });
            const rmBtn = document.createElement('button');
            rmBtn.className = 'btn btn-danger btn-xs';
            rmBtn.textContent = '✕';
            rmBtn.addEventListener('click', () => {
                params.splice(idx, 1);
                renderParams();
            });
            rowHdr.appendChild(upBtn);
            rowHdr.appendChild(downBtn);
            rowHdr.appendChild(rmBtn);
            row.appendChild(rowHdr);

            row.appendChild(fg('Key (used as p_<key> in the report URL)', param.key ?? '', v => {
                param.key = v.trim();
                titleSpan.textContent = `${idx + 1}. ${param.label || param.key || 'parameter'}`;
            }));
            row.appendChild(fg('Label (shown to the user)', param.label ?? '', v => {
                param.label = v;
                titleSpan.textContent = `${idx + 1}. ${param.label || param.key || 'parameter'}`;
            }));

            /* Filter column — must belong to this template's own report view */
            const colGrp = document.createElement('div');
            colGrp.className = 'form-group';
            const colLbl = document.createElement('label');
            colLbl.textContent = 'Filter column (in the report view above)';
            colGrp.appendChild(colLbl);
            const colSel = document.createElement('select');
            const colNone = document.createElement('option');
            colNone.value = '';
            colNone.textContent = '— select column —';
            colSel.appendChild(colNone);
            currentCols.forEach(c => {
                const o = document.createElement('option');
                o.value = c.name;
                o.textContent = c.name;
                if ((param.column ?? '') === c.name) o.selected = true;
                colSel.appendChild(o);
            });
            colSel.addEventListener('change', () => { param.column = colSel.value; });
            colGrp.appendChild(colSel);
            row.appendChild(colGrp);

            const reqLabel = document.createElement('label');
            reqLabel.style.cssText = 'display:flex; align-items:center; gap:6px; font-size:13px; '
                + 'color:var(--text); cursor:pointer; font-weight:normal; margin-bottom:12px;';
            const reqChk = document.createElement('input');
            reqChk.type = 'checkbox';
            reqChk.checked = !!param.required;
            reqChk.style.cssText = 'width:14px; height:14px; accent-color:var(--accent); cursor:pointer;';
            reqChk.addEventListener('change', () => { param.required = reqChk.checked; });
            reqLabel.appendChild(reqChk);
            reqLabel.appendChild(document.createTextNode('Required (hides the "— all —" option; user must pick a value)'));
            row.appendChild(reqLabel);

            /* Lookup view (optional) — a separate view supplying nicer value/label pairs
               for the dropdown, e.g. v_employees.id / v_employees.full_name */
            const srcGrp = document.createElement('div');
            srcGrp.className = 'form-group';
            const srcLbl = document.createElement('label');
            srcLbl.textContent = 'Lookup view for dropdown options (optional)';
            srcGrp.appendChild(srcLbl);
            const srcSel = document.createElement('select');
            const srcNone = document.createElement('option');
            srcNone.value = '';
            srcNone.textContent = '— use filter column values —';
            srcSel.appendChild(srcNone);
            dbViews.forEach(v => {
                const o = document.createElement('option');
                o.value = v;
                o.textContent = v;
                if ((param.source_view ?? '') === v) o.selected = true;
                srcSel.appendChild(o);
            });
            srcGrp.appendChild(srcSel);
            row.appendChild(srcGrp);

            const valGrp = document.createElement('div');
            valGrp.className = 'form-group';
            const valLbl = document.createElement('label');
            valLbl.textContent = 'Value column (filtered on)';
            valGrp.appendChild(valLbl);
            const valSel = document.createElement('select');
            valGrp.appendChild(valSel);
            row.appendChild(valGrp);

            const labGrp = document.createElement('div');
            labGrp.className = 'form-group';
            const labLbl = document.createElement('label');
            labLbl.textContent = 'Label column (shown in the dropdown)';
            labGrp.appendChild(labLbl);
            const labSel = document.createElement('select');
            labGrp.appendChild(labSel);
            row.appendChild(labGrp);

            async function refreshSourceColumns() {
                valSel.innerHTML = '';
                labSel.innerHTML = '';
                if (!srcSel.value) {
                    valGrp.style.display = 'none';
                    labGrp.style.display = 'none';
                    return;
                }
                valGrp.style.display = '';
                labGrp.style.display = '';
                const cols = await fetchColumns(srcSel.value);
                cols.forEach(c => {
                    const ov = document.createElement('option');
                    ov.value = c.name;
                    ov.textContent = c.name;
                    if ((param.value_column ?? '') === c.name) ov.selected = true;
                    valSel.appendChild(ov);

                    const ol = document.createElement('option');
                    ol.value = c.name;
                    ol.textContent = c.name;
                    if ((param.label_column ?? '') === c.name) ol.selected = true;
                    labSel.appendChild(ol);
                });
            }

            srcSel.addEventListener('change', () => {
                param.source_view = srcSel.value;
                if (!srcSel.value) {
                    delete param.value_column;
                    delete param.label_column;
                }
                refreshSourceColumns();
            });
            valSel.addEventListener('change', () => { param.value_column = valSel.value; });
            labSel.addEventListener('change', () => { param.label_column = labSel.value; });

            refreshSourceColumns();

            return row;
        }

        const addParamBtn = document.createElement('button');
        addParamBtn.className = 'btn btn-success btn-sm';
        addParamBtn.style.marginBottom = '20px';
        addParamBtn.textContent = '+ Add parameter';
        addParamBtn.addEventListener('click', () => {
            params.push({ key: `param${params.length + 1}`, label: '', column: '', required: false });
            renderParams();
        });
        paramsList.after(addParamBtn);

        /* add-block buttons */
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

    /* ---------- list ---------- */
    function renderList() {
        listEl.innerHTML = '';
        const names = Object.keys(prints);
        if (names.length === 0) {
            const empty = document.createElement('p');
            empty.style.cssText = 'color:var(--muted); text-align:center; padding:32px;';
            empty.textContent = 'No printouts yet. Click "+ Add printout" to create the first template.';
            listEl.appendChild(empty);
            return;
        }
        names.forEach(pName => listEl.appendChild(buildPrintCard(pName, prints[pName] ?? {})));
    }

    /* ---------- add printout ---------- */
    addBtn.addEventListener('click', () => {
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

    /* ---------- init ---------- */
    (async () => {
        try {
            const res  = await apiFetch('../api/print.php?action=config');
            const data = await res.json();
            if (data.status !== 'ok') {
                setStatus('Failed to load configuration: ' + (data.error ?? 'unknown'), 'error');
                return;
            }
            prints     = data.config?.prints ?? {};
            cfgVersion = data.version ?? 0;
            /* PHP serializes an empty map as [] — normalize to a plain object, otherwise
               JSON.stringify drops named properties added onto the array on save */
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
