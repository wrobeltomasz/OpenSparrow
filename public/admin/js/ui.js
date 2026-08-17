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
    keys.forEach(key => newObject[key] = obj[key]);
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
    input.addEventListener('input', (event) => onChange(event.target.value));
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
    input.addEventListener('input', (event) => onChange(event.target.value));
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
    const textarea = document.createElement('textarea');
    textarea.value = value || '';
    textarea.addEventListener('input', (event) => onChange(event.target.value));
    wrapper.appendChild(textarea);
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
    input.addEventListener('input', (event) => onChange(event.target.value));
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
    input.addEventListener('input', (event) => onChange(event.target.value));

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
        } catch(event) {
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
        const optionElement = document.createElement('option');
        optionElement.value = option.value;
        optionElement.textContent = option.label;
        if (option.value === value) optionElement.selected = true;
        select.appendChild(optionElement);
    });
    select.addEventListener('change', (event) => onChange(event.target.value));
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
    input.addEventListener('input', (event) => onChange(event.target.value));
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
    input.addEventListener('change', (event) => onChange(event.target.checked));

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
        currentConfig.menu_name || defaultMenuName, value => {
            currentConfig.menu_name = value;
        }));

    workspaceElement.appendChild(createIconPicker('menu_icon', 'Menu Icon',
        currentConfig.menu_icon || '', value => {
            if (value && value.trim() !== '') currentConfig.menu_icon = value;
            else delete currentConfig.menu_icon;
        }));

    if (includeHidden) {
        workspaceElement.appendChild(createCheckbox('hidden', 'Hide from Sidebar Menu',
            currentConfig.hidden, value => {
                if (value) currentConfig.hidden = true;
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
                items: state.items.map(menuItem => ({
                    type: menuItem.type, key: menuItem.key,
                    children: (menuItem.children || []).map(child => ({
                        type: child.type, key: child.key, children: [],
                    })),
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
        const link = document.createElement('a');
        link.href = '#';
        link.className = 'custom-nav-link' + (item.hidden ? ' preview-hidden' : '');
        link.addEventListener('click', event => event.preventDefault());
        link.appendChild(buildIcon(item.icon));
        const nameSpan = document.createElement('span');
        nameSpan.className = 'menu-text';
        nameSpan.textContent = item.name || item.key;
        link.appendChild(nameSpan);
        if (item.hidden) {
            const badge = document.createElement('span');
            badge.className = 'menu-preview-badge';
            badge.textContent = 'HIDDEN';
            link.appendChild(badge);
        }
        return link;
    }

    let dragKey = null;
    let dragParent = null;

    function clearIndicators() {
        wrap.querySelectorAll('.menu-drop-line').forEach(node => node.remove());
        wrap.querySelectorAll('.dnd-nest-target')
            .forEach(node => node.classList.remove('dnd-nest-target'));
    }

    function hitZone(event, li, allowNest) {
        const rect = li.getBoundingClientRect();
        const percent = (event.clientY - rect.top) / rect.height;
        if (allowNest && percent > 0.28 && percent < 0.72) return 'nest';
        return percent < 0.5 ? 'before' : 'after';
    }

    function removeDragged(items) {
        if (dragParent === null) {
            return items.filter(item => item.key !== dragKey);
        }
        return items.map(parent => parent.key === dragParent
            ? { ...p, children: parent.children.filter(child => child.key !== dragKey) }
            : parent);
    }

    function findItem(items) {
        if (dragParent === null) return items.find(item => item.key === dragKey) || null;
        for (const parent of items) {
            const found = (parent.children || []).find(child => child.key === dragKey);
            if (found) return found;
        }
        return null;
    }

    function applyDrop(targetKey, zone) {
        const original = findItem(state.items);
        if (!original) return;
        const dragged = { ...original, children: original.children || [] };

        let items = removeDragged(state.items);

        if (zone === 'nest') {
            items = items.map(parent => parent.key === targetKey
                ? { ...p, children: [...(parent.children || []), { ...dragged, children: [] }] }
                : parent);
        } else {
            const topIndex = items.findIndex(item => item.key === targetKey);
            if (topIndex !== -1) {
                const insertAt = zone === 'before' ? topIndex : topIndex + 1;
                items.splice(insertAt, 0, dragged);
            } else {
                items = items.map(parent => {
                    const childIndex = parent.children.findIndex(child => child.key === targetKey);
                    if (childIndex === -1) return parent;
                    const newChildren = [...p.children];
                    newChildren.splice(zone === 'before' ? childIndex : childIndex + 1, 0, { ...dragged, children: [] });
                    return { ...p, children: newChildren };
                });
            }
        }

        state.items = items;
        rebuildDOM();
        scheduleSave();
    }

    function wireDrag(li, key, parentKey) {
        li.draggable = true;

        li.addEventListener('dragstart', event => {
            dragKey = key;
            dragParent = parentKey;
            event.dataTransfer.effectAllowed = 'move';

            requestAnimationFrame(() => li.classList.add('dnd-dragging'));
            event.stopPropagation();
        });

        li.addEventListener('dragend', () => {
            dragKey = null;
            dragParent = null;
            li.classList.remove('dnd-dragging');
            clearIndicators();
        });

        li.addEventListener('dragover', event => {
            if (!dragKey || dragKey === key) return;
            event.preventDefault();
            event.stopPropagation();
            event.dataTransfer.dropEffect = 'move';
            clearIndicators();

            const targetItem = state.items.find(item => item.key === key);
            const allowNest = parentKey === null &&
                              dragParent === null &&
                              !!targetItem &&
                              (targetItem.children || []).length === 0;

            const zone = hitZone(event, li, allowNest);

            if (zone === 'nest') {
                li.classList.add('dnd-nest-target');
            } else {
                const line = document.createElement('div');
                line.className = 'menu-drop-line';
                zone === 'before' ? li.before(line) : li.after(line);
            }
        });

        li.addEventListener('dragleave', event => {
            if (!li.contains(event.relatedTarget)) clearIndicators();
        });

        li.addEventListener('drop', event => {
            event.preventDefault();
            event.stopPropagation();
            if (!dragKey || dragKey === key) return;

            const targetItem = state.items.find(item => item.key === key);
            const allowNest = parentKey === null &&
                              dragParent === null &&
                              !!targetItem &&
                              (targetItem.children || []).length === 0;

            const zone = hitZone(event, li, allowNest);
            applyDrop(key, zone);
        });
    }

    function rebuildDOM() {
        wrap.innerHTML = '';

        const items = state.items;
        if (!items.length) {
            const paragraph = document.createElement('p');
            paragraph.className = 'menu-preview-info';
            paragraph.textContent = 'No menu items configured.';
            wrap.appendChild(paragraph);
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
                const nameSpan = document.createElement('span');
                nameSpan.className = 'menu-text';
                nameSpan.textContent = item.name || item.key;
                summary.appendChild(nameSpan);
                if (item.hidden) {
                    const badge = document.createElement('span');
                    badge.className = 'menu-preview-badge';
                    badge.textContent = 'HIDDEN';
                    summary.appendChild(badge);
                }
                const arrow = document.createElement('span');
                arrow.className = 'menu-arrow';
                arrow.textContent = '▾';
                summary.appendChild(arrow);
                details.appendChild(summary);

                const subUl = document.createElement('ul');
                subUl.className = 'menu-submenu';
                children.forEach(child => {
                    const childListItem = document.createElement('li');
                    childListItem.dataset.key = child.key;
                    childListItem.className = 'menu-dnd-item menu-dnd-child';
                    childListItem.appendChild(buildLink(child));
                    wireDrag(childListItem, child.key, item.key);
                    subUl.appendChild(childListItem);
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
            const paragraph = document.createElement('p');
            paragraph.className = 'menu-preview-info';
            paragraph.textContent = 'Loading…';
            wrap.appendChild(paragraph);
            return;
        }
        state = { items: (cfg.items || []).map(item => ({ ...i, children: item.children || [] })) };
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
            const labelElement = document.createElement('label');

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = option.value;
            const optionValueNumber = Number(option.value);
            checkbox.checked = safeValues.includes(option.value) || safeValues.includes(String(option.value)) || safeValues.includes(optionValueNumber);

            checkbox.addEventListener('change', () => {
                let current = [...safeValues];
                if (checkbox.checked) {
                    if (!current.includes(optionValueNumber)) current.push(optionValueNumber);
                } else {
                    current = current.filter(entry => Number(entry) !== optionValueNumber);
                }
                safeValues.length = 0;
                safeValues.push(...current);
                onChange([...safeValues]);
            });

            labelElement.appendChild(checkbox);
            labelElement.appendChild(document.createTextNode(option.label));
            container.appendChild(labelElement);
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

    function activate(activeIndex) {
        panels.forEach((panel, index) => {
            panel.style.display = index === activeIndex ? '' : 'none';
        });
        btns.forEach((button, index) => button.classList.toggle('active', index === activeIndex));
    }
    btns.forEach((button, index) => button.addEventListener('click', () => activate(index)));
    activate(0);

    return panels;
}

export function createPageHeader(title, description) {
    const frag = document.createDocumentFragment();
    const heading = document.createElement('h2');
    heading.className = 'admin-page-title';
    heading.textContent = title;
    frag.appendChild(heading);
    if (description) {
        const paragraph = document.createElement('p');
        paragraph.className = 'admin-page-desc';
        paragraph.innerHTML = description;
        frag.appendChild(paragraph);
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
    columns.forEach(columnLabel => tr.appendChild(el('th', 'adm-th', columnLabel)));
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

    const header = el('div', 'adm-sec-hdr');
    header.style.display = 'block';
    header.appendChild(el('h3', '', title)).style.cssText = 'margin:0 0 4px;';
    if (description) {
        header.appendChild(el('p', 'c-muted', description)).style.cssText = 'margin:0;';
    }
    card.appendChild(header);

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

    const onKey = (event) => {
        if (event.key === 'Escape') {
            close();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        const items = focusables();
        if (items.length === 0) {
            return;
        }

        const first  = items[0];
        const last   = items[items.length - 1];
        const active = document.activeElement;
        if (event.shiftKey && (active === first || !box.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (active === last || !box.contains(active))) {
            event.preventDefault();
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
    overlay.addEventListener('click', event => { if (event.target === overlay) close(); });

    return { overlay, box, body, msgEl: messageElement, actions, cancelBtn: cancelButton, saveBtn: saveButton, close };
}
