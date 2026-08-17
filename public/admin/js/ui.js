// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
export const helpTexts = {
    display_name: "The name that will be shown to users in the interface.",
    icon: "Path to the icon image (e.g., assets/icons/my_icon.png) or emoji.",
    hidden: "If checked, this table will not be displayed in the main application's sidebar menu.",
    type: "Database data type (e.g., String(255), integer, boolean, date).",
    fk_ref: "Select a related table. If selected, specify the Reference Column (usually 'id') and Display Column (what users see).",
    url_template: "Template for the link when an event is clicked (e.g., edit.php?table=tasks&id={id}).",
    display_columns: "For 'list' widget type only: A comma-separated list of database columns to display in each row.",
    notified_users: "Select specific active users who will receive notifications.",
    validation_regexp: "Regular expression pattern for client and server side validation (e.g., ^[A-Z]{2}\\d{4}$).",
    validation_message: "Custom error message displayed when the input does not match the RegExp pattern."
};

export function moveArrayItem(array, index, direction) {
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= array.length) return false;
    const item = array.splice(index, 1)[0];
    array.splice(newIndex, 0, item);
    return true;
}

export function moveObjectKey(obj, key, direction) {
    const keys = Object.keys(obj);
    const index = keys.indexOf(key);
    if (index < 0) return obj;
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= keys.length) return obj;

    const temporary = keys[newIndex];
    keys[newIndex] = keys[index];
    keys[index] = temporary;

    const newObject = {};
    keys.forEach(k => newObject[k] = obj[k]);
    return newObject;
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

    const button = document.createElement('button');
    button.textContent = 'Browse';
    button.type = 'button';
    button.className = 'btn btn-secondary btn-sm';
    button.onclick = async () => {
        const modal = document.createElement('div');
        modal.style.cssText = `position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); display:flex; justify-content:center; align-items:center; z-index:10000;`;

        const content = document.createElement('div');
        content.style.cssText = `background:#fff; padding:20px; border-radius:8px; width:90%; max-width:600px; max-height:80vh; overflow-y:auto; position:relative; box-shadow: 0 4px 15px rgba(0,0,0,0.2);`;

        const closeButton = document.createElement('button');
        closeButton.textContent = 'Close';
        closeButton.className = 'btn btn-danger btn-xs';
        closeButton.style.cssText = 'position:absolute; top:15px; right:15px;';
        closeButton.onclick = () => modal.remove();
        content.appendChild(closeButton);

        content.innerHTML += '<h3 style="margin-top:0;">Select Icon</h3><p style="color:var(--muted); ">Icons are loaded from <code>assets/icons/</code>.</p>';

        const grid = document.createElement('div');
        grid.style.cssText = `display:grid; grid-template-columns:repeat(auto-fill, minmax(70px, 1fr)); gap:15px; margin-top:20px;`;

        try {
            const result = await apiFetch('api.php?action=list_icons');
            const data = await result.json();
            if (data.status === 'success' && data.icons.length > 0) {
                data.icons.forEach(iconPath => {
                    const imageBox = document.createElement('div');
                    imageBox.style.cssText = `cursor:pointer; text-align:center; padding:10px; border:1px solid var(--border); border-radius:6px; transition:0.2s; display:flex; align-items:center; justify-content:center; height: 70px;`;
                    imageBox.onmouseover = () => { imageBox.style.borderColor = 'var(--muted)'; imageBox.style.background = 'var(--accent-mid)'; };
                    imageBox.onmouseout = () => { imageBox.style.borderColor = 'var(--accent-mid)'; imageBox.style.background = 'transparent'; };

                    const image = document.createElement('img');
                    image.src = '../' + iconPath;
                    image.style.maxWidth = '100%';
                    image.style.maxHeight = '100%';
                    image.style.objectFit = 'contain';

                    imageBox.appendChild(image);
                    imageBox.onclick = () => {
                        input.value = iconPath;
                        onChange(iconPath);
                        modal.remove();
                    };
                    grid.appendChild(imageBox);
                });
            } else {
                grid.innerHTML = '<p style="grid-column: 1 / -1; color:var(--muted);">No icons found. Create an <code>assets/icons/</code> folder in the root directory and upload files (PNG, SVG, JPG) there.</p>';
            }
        } catch(e) {
            grid.innerHTML = '<p style="color:var(--error); grid-column: 1 / -1;">An error occurred while loading icons.</p>';
        }

        content.appendChild(grid);
        modal.appendChild(content);
        document.body.appendChild(modal);
    };

    inputGroup.appendChild(input);
    inputGroup.appendChild(button);
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
    options.forEach(option => {
        const o = document.createElement('option');
        o.value = option.value;
        o.textContent = option.label;
        if (option.value === value) o.selected = true;
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
    input.value = value || '#6E767F';
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

export function createMenuPreview() {
    const wrapper = document.createElement('div');
    wrapper.className = 'form-group menu-preview';

    const label = document.createElement('label');
    label.textContent = 'Live sidebar preview';
    wrapper.appendChild(label);

    const item = document.createElement('div');
    item.className = 'menu-preview-item';
    item.style.cssText = 'display:flex; align-items:center; gap:10px; padding:10px 14px; background:var(--accent-dark); color:var(--accent-light); border-radius:6px;  min-width:220px; max-width:320px; transition:opacity .15s;';

    const iconElement = document.createElement('span');
    iconElement.style.cssText = 'width:20px; height:20px; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;';

    const nameElement = document.createElement('span');
    nameElement.style.cssText = 'flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';

    const badgeElement = document.createElement('span');
    badgeElement.textContent = 'HIDDEN';
    badgeElement.style.cssText = ' background:var(--error); color:#fff; padding:2px 6px; border-radius:3px; display:none; ';

    item.appendChild(iconElement);
    item.appendChild(nameElement);
    item.appendChild(badgeElement);
    wrapper.appendChild(item);

    const update = ({ name, icon, hidden }) => {
        nameElement.textContent = name || '';
        iconElement.innerHTML = '';
        if (icon) {
            const looksLikePath = icon.includes('/') || icon.includes('.');
            if (looksLikePath) {
                const image = document.createElement('img');
                image.src = '../' + icon;
                image.alt = '';
                image.style.cssText = 'max-width:20px; max-height:20px; filter:brightness(0) invert(1);';
                image.onerror = () => { iconElement.innerHTML = ''; iconElement.textContent = '?'; };
                iconElement.appendChild(image);
            } else {
                iconElement.textContent = icon;
            }
        }
        item.style.opacity = hidden ? '0.4' : '1';
        badgeElement.style.display = hidden ? 'inline-block' : 'none';
    };

    return { el: wrapper, update };
}

export function renderGlobalSettings(context, options = {}) {
    const { workspaceEl: workspaceElement, currentConfig } = context;
    const {
        title = 'Global Settings',
        defaultMenuName = '',
        includeHidden = true,
        onAfter,
    } = options;

    workspaceElement.innerHTML = '';
    const heading = document.createElement('h3');
    heading.textContent = title;
    workspaceElement.appendChild(heading);

    workspaceElement.appendChild(createTextInput('menu_name', 'Menu Display Name',
        currentConfig.menu_name || defaultMenuName, v => {
            currentConfig.menu_name = v;
        }));

    workspaceElement.appendChild(createIconPicker('menu_icon', 'Menu Icon',
        currentConfig.menu_icon || '', v => {
            if (v && v.trim() !== '') currentConfig.menu_icon = v;
            else delete currentConfig.menu_icon;
        }));

    if (includeHidden) {
        workspaceElement.appendChild(createCheckbox('hidden', 'Hide from Sidebar Menu',
            currentConfig.hidden, v => {
                if (v) currentConfig.hidden = true;
                else delete currentConfig.hidden;
            }, false));
    }

    if (typeof onAfter === 'function') onAfter(context);
}

export function createFullMenuPreview(config) {
    const wrap = document.createElement('div');
    wrap.className = 'menu-preview-wrap';

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
            } catch (_) {  }
        }, 350);
    }

    function buildIcon(icon) {
        if (!icon) {
            const image = document.createElement('img');
            image.src = '../assets/icons/database.png';
            image.alt = '';
            return image;
        }
        if (icon.includes('/') || icon.includes('.')) {
            const image = document.createElement('img');
            image.src = '../' + icon;
            image.alt = '';
            image.onerror = () => image.remove();
            return image;
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

    let dragKey = null;
    let dragParent = null;

    function clearIndicators() {
        wrap.querySelectorAll('.menu-drop-line').forEach(el => el.remove());
        wrap.querySelectorAll('.dnd-nest-target').forEach(el => el.classList.remove('dnd-nest-target'));
    }

    function hitZone(e, li, allowNest) {
        const r = li.getBoundingClientRect();
        const pct = (e.clientY - r.top) / r.height;
        if (allowNest && pct > 0.28 && pct < 0.72) return 'nest';
        return pct < 0.5 ? 'before' : 'after';
    }

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
            items = items.map(p => p.key === targetKey
                ? { ...p, children: [...(p.children || []), { ...dragged, children: [] }] }
                : p);
        } else {
            const topIndex = items.findIndex(i => i.key === targetKey);
            if (topIndex !== -1) {
                const at = zone === 'before' ? topIndex : topIndex + 1;
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

    function wireDrag(li, key, parentKey) {
        li.draggable = true;

        li.addEventListener('dragstart', e => {
            dragKey = key;
            dragParent = parentKey;
            e.dataTransfer.effectAllowed = 'move';

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

    function update(config) {
        if (!config) {
            wrap.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'menu-preview-info';
            p.textContent = 'Loading…';
            wrap.appendChild(p);
            return;
        }
        state = { items: (config.items || []).map(i => ({ ...i, children: i.children || [] })) };
        rebuildDOM();
    }

    update(config);
    return { el: wrap, update };
}

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
        options.forEach(option => {
            const label = document.createElement('label');

            const chk = document.createElement('input');
            chk.type = 'checkbox';
            chk.value = option.value;
            const optionValueNumber = Number(option.value);
            chk.checked = safeValues.includes(option.value) || safeValues.includes(String(option.value)) || safeValues.includes(optionValueNumber);

            chk.addEventListener('change', () => {
                let current = [...safeValues];
                if (chk.checked) {
                    if (!current.includes(optionValueNumber)) current.push(optionValueNumber);
                } else {
                    current = current.filter(v => Number(v) !== optionValueNumber);
                }
                safeValues.length = 0;
                safeValues.push(...current);
                onChange([...safeValues]);
            });

            label.appendChild(chk);
            label.appendChild(document.createTextNode(option.label));
            container.appendChild(label);
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

export function buildInnerTabs(container, tabs) {
    const bar = document.createElement('div');
    bar.className = 'item-panel-items';

    const panels = [];
    const btns   = [];

    tabs.forEach(({ label, icon }) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'item-btn';
        if (icon) {
            const image = document.createElement('img');
            image.src = '../assets/icons/' + icon;
            image.alt = '';
            image.style.cssText = 'width:15px;height:15px;opacity:.6;';
            button.appendChild(image);
        }
        button.appendChild(document.createTextNode(label));
        bar.appendChild(button);
        btns.push(button);

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
    btns.forEach((button, i) => button.addEventListener('click', () => activate(i)));
    activate(0);

    return panels;
}

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

export function el(tag, className = '', text = '') {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== '' && text !== null && text !== undefined) node.textContent = text;
    return node;
}

export function mkTable() {
    return el('table', 'adm-tbl');
}

export function mkThead(table, columns) {
    const tr = table.createTHead().insertRow();
    columns.forEach(h => tr.appendChild(el('th', 'adm-th', h)));
    return tr;
}

export function td(text, extra = '') {
    const cell = el('td', 'adm-td');
    if (extra) cell.style.cssText = extra.replace(/^[;\s]+/, '');
    cell.textContent = text ?? '—';
    return cell;
}

export function tdEl(child, extra = '') {
    const cell = el('td', 'adm-td');
    if (extra) cell.style.cssText = extra.replace(/^[;\s]+/, '');
    if (child) cell.appendChild(child);
    return cell;
}

const STATUS_CLASS = { success: 'ok', error: 'danger', running: 'warn' };
export function tdStatus(status) {
    return tdEl(el('span', 'adm-badge adm-badge-' + (STATUS_CLASS[status] || 'muted'), status || ''));
}

export function tdError(text) {
    return td(
        text || '',
        'color:var(--error); max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;'
    );
}

export function buildSectionCard(title, description = '', id = '') {
    const card = el('div', 'adm-sec-card');
    if (id) card.id = id;

    const hdr = el('div', 'adm-sec-hdr');
    hdr.style.display = 'block';
    hdr.appendChild(el('h3', '', title)).style.cssText = 'margin:0 0 4px;';
    if (description) {
        hdr.appendChild(el('p', 'c-muted', description)).style.cssText = 'margin:0;';
    }
    card.appendChild(hdr);

    const body = el('div', 'adm-sec-body');
    card.appendChild(body);

    return { card, body };
}

export function buildModal({ title, subtitleLabel = '', subtitleValue = '', saveLabel = 'Save' }) {
    const overlay = el('div', 'adm-modal-overlay');

    const box = el('div', 'adm-modal');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.appendChild(el('h3', 'adm-modal-title', title));

    if (subtitleLabel || subtitleValue) {
        const sub = el('p', 'adm-modal-sub', subtitleLabel);
        sub.appendChild(el('strong', '', subtitleValue));
        box.appendChild(sub);
    }

    const body = el('div');
    box.appendChild(body);

    const messageElement = el('p', 'adm-modal-msg');
    box.appendChild(messageElement);

    const actions   = el('div', 'adm-modal-actions');
    const cancelButton = el('button', 'btn btn-secondary', 'Cancel');
    const saveButton   = el('button', 'btn btn-primary', saveLabel);
    actions.append(cancelButton, saveButton);
    box.appendChild(actions);

    overlay.appendChild(box);
    document.body.appendChild(overlay);

    const opener = document.activeElement;

    const focusables = () => [...box.querySelectorAll(
        'input:not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])'
    )];

    const onKey = (e) => {
        if (e.key === 'Escape') {
            close();
            return;
        }
        if (e.key !== 'Tab') {
            return;
        }
        const items = focusables();
        if (items.length === 0) {
            return;
        }

        const first  = items[0];
        const last   = items[items.length - 1];
        const active = document.activeElement;
        if (e.shiftKey && (active === first || !box.contains(active))) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && (active === last || !box.contains(active))) {
            e.preventDefault();
            first.focus();
        }
    };
    function close() {
        document.removeEventListener('keydown', onKey);
        overlay.remove();
        if (opener instanceof HTMLElement && document.contains(opener)) {
            opener.focus();
        }
    }
    document.addEventListener('keydown', onKey);

    cancelButton.addEventListener('click', close);
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });

    return { overlay, box, body, msgEl: messageElement, actions, cancelBtn: cancelButton, saveBtn: saveButton, close };
}
