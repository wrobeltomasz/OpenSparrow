// admin/js/ui.js — Shared admin UI toolkit
// Reusable form-field builders (createTextInput/SelectInput/ColorInput/IconPicker/Checkbox/MultiSelect), inner tabs, menu preview, array/object reorder helpers, and field helpTexts. Imported across admin modules.

import { apiFetch } from '../../assets/js/util/api.js';
export const helpTexts = {
    display_name: "The name that will be shown to users in the interface.",
    icon: "Path to the icon image (e.g., assets/icons/my_icon.png) or emoji.",
    hidden: "If checked, this table will not be displayed in the main application's sidebar menu.",
    type: "Database data type (e.g., String(255), integer, boolean, date).",
    fk_ref: "Select a related table. If selected, specify the Reference Column (usually 'id') and Display Column (what users see).",
    url_template: "Template for the link when an event is clicked (e.g., edit.php?table=tasks&id={id}).",
    display_columns: "For 'list' widget type only: A comma-separated list of database columns to display in each row.",
    notified_users: "Select specific active users who will receive notifications.", // Added help text for users
    validation_regexp: "Regular expression pattern for client and server side validation (e.g., ^[A-Z]{2}\\d{4}$).",
    validation_message: "Custom error message displayed when the input does not match the RegExp pattern."
};

export function moveArrayItem(arr, index, direction) {
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= arr.length) return false;
    const item = arr.splice(index, 1)[0];
    arr.splice(newIndex, 0, item);
    return true;
}

export function moveObjectKey(obj, key, direction) {
    const keys = Object.keys(obj);
    const index = keys.indexOf(key);
    if (index < 0) return obj;
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= keys.length) return obj;

    const temp = keys[newIndex];
    keys[newIndex] = keys[index];
    keys[index] = temp;

    const newObj = {};
    keys.forEach(k => newObj[k] = obj[k]);
    return newObj;
}

export function createTextInput(key, labelText, value, onChange) {
    const wrapper = document.createElement('div');
    wrapper.className = 'form-group';
    const label = document.createElement('label');
    label.textContent = labelText;
    wrapper.appendChild(label);
    const input = document.createElement('input');
    input.type = 'text';
    input.value = value || '';
    input.addEventListener('input', (e) => onChange(e.target.value));
    wrapper.appendChild(input);
    if (helpTexts[key]) {
        const help = document.createElement('span');
        help.className = 'help-text';
        help.textContent = helpTexts[key];
        wrapper.appendChild(help);
    }
    return wrapper;
}

export function createNumberInput(key, labelText, value, onChange) {
    const wrapper = document.createElement('div');
    wrapper.className = 'form-group';
    const label = document.createElement('label');
    label.textContent = labelText;
    wrapper.appendChild(label);
    const input = document.createElement('input');
    input.type = 'number';
    input.value = (value === undefined || value === null) ? '' : value;
    input.addEventListener('input', (e) => onChange(e.target.value));
    wrapper.appendChild(input);
    if (helpTexts[key]) {
        const help = document.createElement('span');
        help.className = 'help-text';
        help.textContent = helpTexts[key];
        wrapper.appendChild(help);
    }
    return wrapper;
}

export function createTextarea(key, labelText, value, onChange) {
    const wrapper = document.createElement('div');
    wrapper.className = 'form-group';
    const label = document.createElement('label');
    label.textContent = labelText;
    wrapper.appendChild(label);
    const ta = document.createElement('textarea');
    ta.value = value || '';
    ta.addEventListener('input', (e) => onChange(e.target.value));
    wrapper.appendChild(ta);
    if (helpTexts[key]) {
        const help = document.createElement('span');
        help.className = 'help-text';
        help.textContent = helpTexts[key];
        wrapper.appendChild(help);
    }
    return wrapper;
}

export function createDatalistInput(key, labelText, listId, value, onChange) {
    const wrapper = document.createElement('div');
    wrapper.className = 'form-group';
    const label = document.createElement('label');
    label.textContent = labelText;
    wrapper.appendChild(label);
    const input = document.createElement('input');
    input.type = 'text';
    input.setAttribute('list', listId); 
    input.value = value || '';
    input.addEventListener('input', (e) => onChange(e.target.value));
    wrapper.appendChild(input);
    return wrapper;
}

export function createIconPicker(key, labelText, value, onChange) {
    const wrapper = document.createElement('div');
    wrapper.className = 'form-group';
    
    const label = document.createElement('label');
    label.textContent = labelText;
    wrapper.appendChild(label);

    const inputGroup = document.createElement('div');
    inputGroup.className = 'field-inline-group';

    const input = document.createElement('input');
    input.type = 'text';
    input.value = value || '';
    input.classList.add('flex-1');
    input.addEventListener('input', (e) => onChange(e.target.value));

    const btn = document.createElement('button');
    btn.textContent = 'Browse';
    btn.type = 'button';
    btn.className = 'btn btn-secondary btn-sm';
    btn.onclick = async () => {
        const modal = document.createElement('div');
        modal.style.cssText = `position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); display:flex; justify-content:center; align-items:center; z-index:10000;`;
        
        const content = document.createElement('div');
        content.style.cssText = `background:#fff; padding:20px; border-radius:8px; width:90%; max-width:600px; max-height:80vh; overflow-y:auto; position:relative; box-shadow: 0 4px 15px rgba(0,0,0,0.2);`;
        
        const closeBtn = document.createElement('button');
        closeBtn.textContent = 'Close';
        closeBtn.className = 'btn btn-danger btn-xs';
        closeBtn.style.cssText = 'position:absolute; top:15px; right:15px;';
        closeBtn.onclick = () => modal.remove();
        content.appendChild(closeBtn);
        
        content.innerHTML += '<h3 style="margin-top:0;">Select Icon</h3><p style="color:#64748B; font-size:13px;">Icons are loaded from <code>assets/icons/</code> and <code>assets/img/</code>.</p>';
        
        const grid = document.createElement('div');
        grid.style.cssText = `display:grid; grid-template-columns:repeat(auto-fill, minmax(70px, 1fr)); gap:15px; margin-top:20px;`;
        
        try {
            const res = await apiFetch('api.php?action=list_icons');
            const data = await res.json();
            if (data.status === 'success' && data.icons.length > 0) {
                data.icons.forEach(iconPath => {
                    const imgBox = document.createElement('div');
                    imgBox.style.cssText = `cursor:pointer; text-align:center; padding:10px; border:1px solid #CBD5E1; border-radius:6px; transition:0.2s; display:flex; align-items:center; justify-content:center; height: 70px;`;
                    imgBox.onmouseover = () => { imgBox.style.borderColor = '#64748B'; imgBox.style.background = '#DDEAF4'; };
                    imgBox.onmouseout = () => { imgBox.style.borderColor = '#DDEAF4'; imgBox.style.background = 'transparent'; };
                    
                    const img = document.createElement('img');
                    img.src = '../' + iconPath; 
                    img.style.maxWidth = '100%';
                    img.style.maxHeight = '100%';
                    img.style.objectFit = 'contain';
                    
                    imgBox.appendChild(img);
                    imgBox.onclick = () => {
                        input.value = iconPath;
                        onChange(iconPath);
                        modal.remove();
                    };
                    grid.appendChild(imgBox);
                });
            } else {
                grid.innerHTML = '<p style="grid-column: 1 / -1; color:#64748B;">No icons found. Create an <code>assets/icons/</code> folder in the root directory and upload files (PNG, SVG, JPG) there.</p>';
            }
        } catch(e) {
            grid.innerHTML = '<p style="color:var(--danger); grid-column: 1 / -1;">An error occurred while loading icons.</p>';
        }
        
        content.appendChild(grid);
        modal.appendChild(content);
        document.body.appendChild(modal);
    };

    inputGroup.appendChild(input);
    inputGroup.appendChild(btn);
    wrapper.appendChild(inputGroup);

    if (helpTexts[key]) {
        const help = document.createElement('span');
        help.className = 'help-text';
        help.textContent = helpTexts[key];
        wrapper.appendChild(help);
    }
    return wrapper;
}

export function createSelectInput(key, labelText, options, value, onChange) {
    const wrapper = document.createElement('div');
    wrapper.className = 'form-group';
    const label = document.createElement('label');
    label.textContent = labelText;
    wrapper.appendChild(label);
    const select = document.createElement('select');
    options.forEach(opt => {
        const o = document.createElement('option');
        o.value = opt.value;
        o.textContent = opt.label;
        if (opt.value === value) o.selected = true;
        select.appendChild(o);
    });
    select.addEventListener('change', (e) => onChange(e.target.value));
    wrapper.appendChild(select);
    return wrapper;
}

export function createColorInput(key, labelText, value, onChange) {
    const wrapper = document.createElement('div');
    wrapper.className = 'form-group';
    const label = document.createElement('label');
    label.textContent = labelText;
    wrapper.appendChild(label);
    const input = document.createElement('input');
    input.type = 'color';
    input.value = value || '#64748B'; 
    input.addEventListener('input', (e) => onChange(e.target.value));
    wrapper.appendChild(input);
    return wrapper;
}

export function createCheckbox(key, labelText, value, onChange, defaultValue = true) {
    const wrapper = document.createElement('div');
    wrapper.className = 'form-group checkbox-row';

    const input = document.createElement('input');
    input.type = 'checkbox';
    input.checked = (value !== undefined && value !== null) ? value : defaultValue;
    if (value === undefined || value === null) onChange(defaultValue);
    input.addEventListener('change', (e) => onChange(e.target.checked));

    const label = document.createElement('label');
    label.textContent = labelText;
    label.onclick = () => input.click();

    wrapper.appendChild(input);
    wrapper.appendChild(label);

    const container = document.createElement('div');
    container.className = 'field-checkbox-wrap';
    container.appendChild(wrapper);
    return container;
}

// Small visual preview that mirrors how a menu item will be rendered in the FE
// sidebar. Kept in-sync with templates/menu.php semantics (image when the value
// looks like a path, text glyph otherwise, dimmed when hidden).
export function createMenuPreview() {
    const wrapper = document.createElement('div');
    wrapper.className = 'form-group menu-preview';

    const label = document.createElement('label');
    label.textContent = 'Live sidebar preview';
    wrapper.appendChild(label);

    const item = document.createElement('div');
    item.className = 'menu-preview-item';
    item.style.cssText = 'display:flex; align-items:center; gap:10px; padding:10px 14px; background:#003366; color:#F0F6FA; border-radius:6px; font-size:14px; min-width:220px; max-width:320px; transition:opacity .15s;';

    const iconEl = document.createElement('span');
    iconEl.style.cssText = 'width:20px; height:20px; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;';

    const nameEl = document.createElement('span');
    nameEl.style.cssText = 'flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';

    const badgeEl = document.createElement('span');
    badgeEl.textContent = 'HIDDEN';
    badgeEl.style.cssText = 'font-size:10px; background:#d00000; color:#fff; padding:2px 6px; border-radius:3px; display:none; letter-spacing:.5px;';

    item.appendChild(iconEl);
    item.appendChild(nameEl);
    item.appendChild(badgeEl);
    wrapper.appendChild(item);

    const update = ({ name, icon, hidden }) => {
        nameEl.textContent = name || '';
        iconEl.innerHTML = '';
        if (icon) {
            const looksLikePath = icon.includes('/') || icon.includes('.');
            if (looksLikePath) {
                const img = document.createElement('img');
                img.src = '../' + icon;
                img.alt = '';
                img.style.cssText = 'max-width:20px; max-height:20px; filter:brightness(0) invert(1);';
                img.onerror = () => { iconEl.innerHTML = ''; iconEl.textContent = '?'; };
                iconEl.appendChild(img);
            } else {
                iconEl.textContent = icon;
            }
        }
        item.style.opacity = hidden ? '0.4' : '1';
        badgeEl.style.display = hidden ? 'inline-block' : 'none';
    };

    return { el: wrapper, update };
}

// Shared renderer for per-section global menu settings (Dashboard/Calendar/
// Workflows/Files). Replaces four near-identical copies and keeps the live
// sidebar preview consistent across sections.
export function renderGlobalSettings(ctx, options = {}) {
    const { workspaceEl, currentConfig } = ctx;
    const {
        title = 'Global Settings',
        defaultMenuName = '',
        includeHidden = true,
        onAfter,
    } = options;

    workspaceEl.innerHTML = '';
    const heading = document.createElement('h3');
    heading.textContent = title;
    workspaceEl.appendChild(heading);

    workspaceEl.appendChild(createTextInput('menu_name', 'Menu Display Name',
        currentConfig.menu_name || defaultMenuName, v => {
            currentConfig.menu_name = v;
        }));

    workspaceEl.appendChild(createIconPicker('menu_icon', 'Menu Icon',
        currentConfig.menu_icon || '', v => {
            if (v && v.trim() !== '') currentConfig.menu_icon = v;
            else delete currentConfig.menu_icon;
        }));

    if (includeHidden) {
        workspaceEl.appendChild(createCheckbox('hidden', 'Hide from Sidebar Menu',
            currentConfig.hidden, v => {
                if (v) currentConfig.hidden = true;
                else delete currentConfig.hidden;
            }, false));
    }

    if (typeof onAfter === 'function') onAfter(ctx);
}

// Full menu preview with drag-and-drop reordering and 1-level nesting.
// Mirrors the HTML structure of templates/menu.php so assets/css/styles.css
// applies the exact FE look. Auto-saves to api.php?action=menu_config on change.
// config shape: { items: [{ type, key, name, icon, hidden, children: [] }] }
export function createFullMenuPreview(config) {
    const wrap = document.createElement('div');
    wrap.className = 'menu-preview-wrap';

    // ── state ──────────────────────────────────────────────────────────────
    let state = { items: [] };
    let saveTimer = null;

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(async () => {
            const payload = {
                items: state.items.map(it => ({
                    type: it.type, key: it.key,
                    children: (it.children || []).map(c => ({ type: c.type, key: c.key, children: [] })),
                })),
            };
            try {
                await apiFetch('api.php?action=menu_config', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
            } catch (_) { /* silent */ }
        }, 350);
    }

    // ── DOM helpers ────────────────────────────────────────────────────────
    function buildIcon(icon) {
        if (!icon) {
            const img = document.createElement('img');
            img.src = '../assets/icons/database.png';
            img.alt = '';
            return img;
        }
        if (icon.includes('/') || icon.includes('.')) {
            const img = document.createElement('img');
            img.src = '../' + icon;
            img.alt = '';
            img.onerror = () => img.remove();
            return img;
        }
        const span = document.createElement('span');
        span.className = 'menu-icon-span';
        span.textContent = icon;
        return span;
    }

    function buildLink(item) {
        const a = document.createElement('a');
        a.href = '#';
        a.className = 'custom-nav-link' + (item.hidden ? ' preview-hidden' : '');
        a.addEventListener('click', e => e.preventDefault());
        a.appendChild(buildIcon(item.icon));
        const ns = document.createElement('span');
        ns.className = 'menu-text';
        ns.textContent = item.name || item.key;
        a.appendChild(ns);
        if (item.hidden) {
            const b = document.createElement('span');
            b.className = 'menu-preview-badge';
            b.textContent = 'HIDDEN';
            a.appendChild(b);
        }
        return a;
    }

    // ── drag state ─────────────────────────────────────────────────────────
    let dragKey = null;        // key of item being dragged
    let dragParent = null;     // parent key, or null if top-level

    // ── drop indicator ─────────────────────────────────────────────────────
    function clearIndicators() {
        wrap.querySelectorAll('.menu-drop-line').forEach(el => el.remove());
        wrap.querySelectorAll('.dnd-nest-target').forEach(el => el.classList.remove('dnd-nest-target'));
    }

    // Inspect cursor position within a li: 'before' | 'nest' | 'after'
    function hitZone(e, li, allowNest) {
        const r = li.getBoundingClientRect();
        const pct = (e.clientY - r.top) / r.height;
        if (allowNest && pct > 0.28 && pct < 0.72) return 'nest';
        return pct < 0.5 ? 'before' : 'after';
    }

    // ── state mutations ────────────────────────────────────────────────────
    function removeDragged(items) {
        if (dragParent === null) {
            return items.filter(i => i.key !== dragKey);
        }
        return items.map(p => p.key === dragParent
            ? { ...p, children: p.children.filter(c => c.key !== dragKey) }
            : p);
    }

    function findItem(items) {
        if (dragParent === null) return items.find(i => i.key === dragKey) || null;
        for (const p of items) {
            const c = (p.children || []).find(c => c.key === dragKey);
            if (c) return c;
        }
        return null;
    }

    function applyDrop(targetKey, zone) {
        const original = findItem(state.items);
        if (!original) return;
        const dragged = { ...original, children: original.children || [] };

        let items = removeDragged(state.items);

        if (zone === 'nest') {
            // Make dragged a child of targetKey (top-level only, no further depth)
            items = items.map(p => p.key === targetKey
                ? { ...p, children: [...(p.children || []), { ...dragged, children: [] }] }
                : p);
        } else {
            // Insert before/after targetKey (search top-level first, then children)
            const topIdx = items.findIndex(i => i.key === targetKey);
            if (topIdx !== -1) {
                const at = zone === 'before' ? topIdx : topIdx + 1;
                items.splice(at, 0, dragged);
            } else {
                items = items.map(p => {
                    const ci = p.children.findIndex(c => c.key === targetKey);
                    if (ci === -1) return p;
                    const nc = [...p.children];
                    nc.splice(zone === 'before' ? ci : ci + 1, 0, { ...dragged, children: [] });
                    return { ...p, children: nc };
                });
            }
        }

        state.items = items;
        rebuildDOM();
        scheduleSave();
    }

    // ── per-li drag wiring ─────────────────────────────────────────────────
    function wireDrag(li, key, parentKey) {
        li.draggable = true;

        li.addEventListener('dragstart', e => {
            dragKey = key;
            dragParent = parentKey;
            e.dataTransfer.effectAllowed = 'move';
            // Delay class add so drag image captures clean look
            requestAnimationFrame(() => li.classList.add('dnd-dragging'));
            e.stopPropagation();
        });

        li.addEventListener('dragend', () => {
            dragKey = null;
            dragParent = null;
            li.classList.remove('dnd-dragging');
            clearIndicators();
        });

        li.addEventListener('dragover', e => {
            if (!dragKey || dragKey === key) return;
            e.preventDefault();
            e.stopPropagation();
            e.dataTransfer.dropEffect = 'move';
            clearIndicators();

            // Nesting is allowed only when:
            // - both dragged and target are top-level
            // - target has no existing children
            // - target is not the dragged item itself
            const targetItem = state.items.find(i => i.key === key);
            const allowNest = parentKey === null &&
                              dragParent === null &&
                              !!targetItem &&
                              (targetItem.children || []).length === 0;

            const zone = hitZone(e, li, allowNest);

            if (zone === 'nest') {
                li.classList.add('dnd-nest-target');
            } else {
                const line = document.createElement('div');
                line.className = 'menu-drop-line';
                zone === 'before' ? li.before(line) : li.after(line);
            }
        });

        li.addEventListener('dragleave', e => {
            if (!li.contains(e.relatedTarget)) clearIndicators();
        });

        li.addEventListener('drop', e => {
            e.preventDefault();
            e.stopPropagation();
            if (!dragKey || dragKey === key) return;

            const targetItem = state.items.find(i => i.key === key);
            const allowNest = parentKey === null &&
                              dragParent === null &&
                              !!targetItem &&
                              (targetItem.children || []).length === 0;

            const zone = hitZone(e, li, allowNest);
            applyDrop(key, zone);
        });
    }

    // ── render ─────────────────────────────────────────────────────────────
    function rebuildDOM() {
        wrap.innerHTML = '';

        const items = state.items;
        if (!items.length) {
            const p = document.createElement('p');
            p.className = 'menu-preview-info';
            p.textContent = 'No menu items configured.';
            wrap.appendChild(p);
            return;
        }

        const nav = document.createElement('nav');
        nav.className = 'menu';
        const ul = document.createElement('ul');
        ul.className = 'menu-list';

        items.forEach(item => {
            const li = document.createElement('li');
            li.dataset.key = item.key;
            li.className = 'menu-dnd-item';

            const children = item.children || [];
            if (children.length > 0) {
                li.classList.add('menu-has-children');
                const details = document.createElement('details');
                details.className = 'menu-submenu-details';

                const summary = document.createElement('summary');
                summary.className = 'custom-nav-link' + (item.hidden ? ' preview-hidden' : '');
                summary.appendChild(buildIcon(item.icon));
                const ns = document.createElement('span');
                ns.className = 'menu-text';
                ns.textContent = item.name || item.key;
                summary.appendChild(ns);
                if (item.hidden) {
                    const b = document.createElement('span');
                    b.className = 'menu-preview-badge';
                    b.textContent = 'HIDDEN';
                    summary.appendChild(b);
                }
                const arrow = document.createElement('span');
                arrow.className = 'menu-arrow';
                arrow.textContent = '▾';
                summary.appendChild(arrow);
                details.appendChild(summary);

                const subUl = document.createElement('ul');
                subUl.className = 'menu-submenu';
                children.forEach(child => {
                    const cli = document.createElement('li');
                    cli.dataset.key = child.key;
                    cli.className = 'menu-dnd-item menu-dnd-child';
                    cli.appendChild(buildLink(child));
                    wireDrag(cli, child.key, item.key);
                    subUl.appendChild(cli);
                });
                details.appendChild(subUl);
                li.appendChild(details);
            } else {
                li.appendChild(buildLink(item));
            }

            wireDrag(li, item.key, null);
            ul.appendChild(li);
        });

        nav.appendChild(ul);
        wrap.appendChild(nav);
    }

    function update(cfg) {
        if (!cfg) {
            wrap.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'menu-preview-info';
            p.textContent = 'Loading…';
            wrap.appendChild(p);
            return;
        }
        state = { items: (cfg.items || []).map(i => ({ ...i, children: i.children || [] })) };
        rebuildDOM();
    }

    update(config);
    return { el: wrap, update };
}

// New function to handle multiple choices via checkboxes list
export function createMultiSelect(key, labelText, options, selectedValues, onChange) {
    const wrapper = document.createElement('div');
    wrapper.className = 'form-group';
    
    const label = document.createElement('label');
    label.textContent = labelText;
    wrapper.appendChild(label);

    const container = document.createElement('div');
    container.className = 'multiselect-box';

    const safeValues = Array.isArray(selectedValues) ? [...selectedValues] : [];

    if (options.length === 0) {
        const empty = document.createElement('span');
        empty.className = 'c-muted';
        empty.textContent = 'No options available';
        container.appendChild(empty);
    } else {
        options.forEach(opt => {
            const lbl = document.createElement('label');

            const chk = document.createElement('input');
            chk.type = 'checkbox';
            chk.value = opt.value;
            const optValNum = Number(opt.value);
            chk.checked = safeValues.includes(opt.value) || safeValues.includes(String(opt.value)) || safeValues.includes(optValNum);

            chk.addEventListener('change', () => {
                let current = [...safeValues];
                if (chk.checked) {
                    if (!current.includes(optValNum)) current.push(optValNum);
                } else {
                    current = current.filter(v => Number(v) !== optValNum);
                }
                safeValues.length = 0;
                safeValues.push(...current);
                onChange([...safeValues]);
            });

            lbl.appendChild(chk);
            lbl.appendChild(document.createTextNode(opt.label));
            container.appendChild(lbl);
        });
    }

    wrapper.appendChild(container);
    
    if (helpTexts[key]) {
        const help = document.createElement('span');
        help.className = 'help-text';
        help.textContent = helpTexts[key];
        wrapper.appendChild(help);
    }

    return wrapper;
}

// ── Shared inner-tab builder ──────────────────────────────────────────────────
// tabs = [{label}] — returns array of panel divs (caller fills each panel).
// Emits the same .item-panel-items / .item-btn markup as the schema item panel
// (see admin/style.css) so every inner-tab strip in the panel looks identical.
export function buildInnerTabs(container, tabs) {
    const bar = document.createElement('div');
    bar.className = 'item-panel-items';

    const panels = [];
    const btns   = [];

    tabs.forEach(({ label }) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'item-btn';
        btn.textContent = label;
        bar.appendChild(btn);
        btns.push(btn);

        const panel = document.createElement('div');
        panel.style.display = 'none';
        container.appendChild(panel);
        panels.push(panel);
    });

    container.insertBefore(bar, container.firstChild);

    function activate(i) {
        panels.forEach((p, j) => { p.style.display = j === i ? '' : 'none'; });
        btns.forEach((b, j) => b.classList.toggle('active', j === i));
    }
    btns.forEach((btn, i) => btn.addEventListener('click', () => activate(i)));
    activate(0);

    return panels;
}

// ── Standard page header ──────────────────────────────────────────────────────
// Returns an <h2 class="admin-page-title"> + optional <p class="admin-page-desc">.
// Use this instead of hand-rolling headings so title level, size and spacing are
// consistent across every tab. `description` may contain HTML (or be omitted).
export function createPageHeader(title, description) {
    const frag = document.createDocumentFragment();
    const h = document.createElement('h2');
    h.className = 'admin-page-title';
    h.textContent = title;
    frag.appendChild(h);
    if (description) {
        const p = document.createElement('p');
        p.className = 'admin-page-desc';
        p.innerHTML = description;
        frag.appendChild(p);
    }
    return frag;
}