/* admin/js/print_editor.js — Printouts module admin editor (renderPrintEditor):
   edits config/print.json print templates via api/print.php (config / columns / save).
   Each template is bound to a PostgreSQL view from the Views module; the available
   variables (columns) are fetched live from the database so the user never types them. */

import { showStatusPill } from './app.js';
import { createIconPicker } from './ui.js';
import { getCsrfToken } from '../../assets/js/util/csrf.js';

export function renderPrintEditor(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '';

    /* ---------- state ---------- */
    let prints      = {};     // config/print.json "prints" object (working copy)
    let dbViews     = [];     // selectable PostgreSQL view names (from config/views.json)
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
    hdrDesc.textContent = 'Build printable report templates from simple blocks (header, text, table). Each template is bound to a PostgreSQL view from the Views module; its columns become the available {variables}.';
    hdrText.appendChild(hdrTitle);
    hdrText.appendChild(hdrDesc);
    hdr.appendChild(hdrText);

    const hdrActions = document.createElement('div');
    hdrActions.style.cssText = 'display:flex; gap:8px; flex-shrink:0;';
    const addBtn = document.createElement('button');
    addBtn.className = 'btn-add';
    addBtn.style.cssText = 'margin:0;';
    addBtn.textContent = '+ Add printout';
    const saveBtn = document.createElement('button');
    saveBtn.className = 'btn-add';
    saveBtn.style.cssText = 'margin:0;';
    saveBtn.textContent = 'Save printouts';
    hdrActions.appendChild(addBtn);
    hdrActions.appendChild(saveBtn);
    hdr.appendChild(hdrActions);
    wrap.appendChild(hdr);

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
            const res  = await fetch('../api/print.php?action=columns&view=' + encodeURIComponent(viewName));
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
        toggleBtn.style.cssText = 'background:none; border:none; font-size:12px; cursor:pointer; color:var(--muted); padding:0 4px; box-shadow:none;';

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

                if (!Array.isArray(block.columns)) block.columns = [];
                const colsBox = document.createElement('div');
                colsBox.style.cssText = 'display:flex; flex-wrap:wrap; gap:10px;';
                if (currentCols.length === 0) {
                    const none = document.createElement('span');
                    none.style.cssText = 'font-size:12px; color:var(--muted);';
                    none.textContent = 'Select a view first to choose columns (empty = all columns).';
                    colsBox.appendChild(none);
                }
                currentCols.forEach(col => {
                    const lab = document.createElement('label');
                    lab.style.cssText = 'display:flex; align-items:center; gap:5px; font-size:13px; color:var(--text); cursor:pointer; font-weight:normal;';
                    const chk = document.createElement('input');
                    chk.type = 'checkbox';
                    chk.checked = block.columns.includes(col.name);
                    chk.style.cssText = 'width:14px; height:14px; accent-color:var(--accent); cursor:pointer;';
                    chk.addEventListener('change', () => {
                        if (chk.checked) {
                            if (!block.columns.includes(col.name)) block.columns.push(col.name);
                        } else {
                            block.columns = block.columns.filter(c => c !== col.name);
                        }
                    });
                    lab.appendChild(chk);
                    lab.appendChild(document.createTextNode(col.name));
                    colsBox.appendChild(lab);
                });
                row.appendChild(colsBox);
            }

            return row;
        }

        /* add-block buttons */
        const addRow = document.createElement('div');
        addRow.style.cssText = 'display:flex; gap:8px; flex-wrap:wrap;';
        [
            { label: '+ Header block', make: () => ({ type: 'header', text: '', level: 1 }) },
            { label: '+ Text block',   make: () => ({ type: 'text', text: '' }) },
            { label: '+ Table block',  make: () => ({ type: 'table', columns: [] }) },
        ].forEach(def => {
            const b = document.createElement('button');
            b.className = 'btn-add';
            b.style.cssText = 'margin:0; padding:7px 12px; font-size:13px;';
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

    /* ---------- add / save ---------- */
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
        };
        renderList();
        setStatus(`Printout "${key}" added. Configure it below, then click "Save printouts".`, 'ok');
    });

    saveBtn.addEventListener('click', async () => {
        try {
            const res = await fetch('../api/print.php?action=save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrfToken(),
                },
                body: JSON.stringify({ prints }),
            });
            const data = await res.json();
            if (data.status === 'ok') {
                showStatusPill(saveBtn, 'print.json saved', 'success');
                setStatus('Printouts saved to config/print.json.', 'ok');
            } else {
                setStatus('Save failed: ' + (data.error ?? 'unknown'), 'error');
            }
        } catch {
            setStatus('Network error during save.', 'error');
        }
    });

    /* ---------- init ---------- */
    (async () => {
        try {
            const res  = await fetch('../api/print.php?action=config');
            const data = await res.json();
            if (data.status !== 'ok') {
                setStatus('Failed to load configuration: ' + (data.error ?? 'unknown'), 'error');
                return;
            }
            prints = data.config?.prints ?? {};
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
