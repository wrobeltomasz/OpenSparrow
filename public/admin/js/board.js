// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import {
    createTextInput,
    createSelectInput,
    createColorInput,
    createIconPicker,
    createCheckbox,
} from './ui.js';

function createColumnMultiSelect(labelText, options, selectedValues, onChange) {
    const wrapper = document.createElement('div');
    wrapper.className = 'form-group';

    const label = document.createElement('label');
    label.textContent = labelText;
    wrapper.appendChild(label);

    const container = document.createElement('div');
    container.style.cssText = 'max-height:150px; overflow-y:auto; border:1px solid var(--border); '
        + 'padding:10px; border-radius:4px; background:var(--panel);';

    const selected = Array.isArray(selectedValues) ? [...selectedValues] : [];

    if (options.length === 0) {
        container.innerHTML = '<span style=" ">No columns available</span>';
    } else {
        options.forEach(option => {
            const labelElement = document.createElement('label');
            labelElement.style.cssText = 'display:flex; align-items:center; margin-bottom:5px; cursor:pointer; font-weight:normal;';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = option.value;
            checkbox.checked = selected.includes(option.value);
            checkbox.style.marginRight = '8px';
            checkbox.addEventListener('change', () => {
                const index = selected.indexOf(option.value);
                if (checkbox.checked && index === -1) selected.push(option.value);
                else if (!checkbox.checked && index !== -1) selected.splice(index, 1);
                onChange([...selected]);
            });

            labelElement.appendChild(checkbox);
            labelElement.appendChild(document.createTextNode(option.label));
            container.appendChild(labelElement);
        });
    }

    wrapper.appendChild(container);
    return wrapper;
}

export function renderBoardEditor(key, itemData, isArray, context) {
    const {
        workspaceEl: workspaceElement,
        getTableOptions,
        getColumnOptionsForTable,
        getEnumColumnsForTable,
        getColumnMeta,
        renderEditor,
    } = context;

    if (!Array.isArray(itemData.card_columns)) itemData.card_columns = [];

    workspaceElement.appendChild(createSelectInput('table', 'Source Table', getTableOptions(), itemData.table || '', value => {
        itemData.table = value;
        itemData.status_column = '';
        itemData.title_column = '';
        itemData.card_columns = [];
        renderEditor(key, itemData, isArray);
    }));

    if (itemData.table) {
        const enumColumns = getEnumColumnsForTable(itemData.table);
        const hasEnum = enumColumns.length > 0;

        const statusOptions = [{ value: '', label: '-- Select Status Column --' }]
            .concat(hasEnum ? enumColumns : getColumnOptionsForTable(itemData.table).filter(columnOption => columnOption.value !== ''));

        workspaceElement.appendChild(createSelectInput('status_column', 'Status Column (defines lanes)', statusOptions, itemData.status_column || '', value => {
            itemData.status_column = value;
            renderEditor(key, itemData, isArray);
        }));

        if (!hasEnum) {
            const warn = document.createElement('p');
            warn.style.cssText = 'color:var(--warn); margin:-6px 0 14px; max-width:640px;';
            warn.textContent = 'This table has no enum columns. Lanes will be derived from the distinct '
                + 'values currently in the chosen column, and all lanes use the default color below. '
                + 'For a proper status workflow, define an enum column in the Schema editor.';
            workspaceElement.appendChild(warn);
        }

        if (itemData.status_column) {
            const meta = getColumnMeta(itemData.table, itemData.status_column);
            if (meta && (meta.type || '').toLowerCase() === 'enum' && Array.isArray(meta.options)) {
                const previewWrap = document.createElement('div');
                previewWrap.style.cssText = 'margin:-4px 0 18px;';
                const labelElement = document.createElement('label');
                labelElement.style.cssText = 'display:block; font-weight:600; margin-bottom:6px; color:var(--text);';
                labelElement.textContent = 'Lane preview';
                previewWrap.appendChild(labelElement);

                const chips = document.createElement('div');
                chips.style.cssText = 'display:flex; flex-wrap:wrap; gap:8px;';
                meta.options.forEach(option => {
                    const color = (meta.enum_colors && meta.enum_colors[option]) || itemData.color || '#003366';
                    const chip = document.createElement('span');
                    chip.style.cssText = 'display:inline-flex; align-items:center; gap:6px; padding:4px 10px; '
                        + 'border:1px solid var(--border); border-radius:999px; background:var(--panel);';
                    const dot = document.createElement('span');
                    dot.style.cssText = `width:10px; height:10px; border-radius:50%; background:${color};`;
                    chip.appendChild(dot);
                    chip.appendChild(document.createTextNode(option));
                    chips.appendChild(chip);
                });
                previewWrap.appendChild(chips);
                workspaceElement.appendChild(previewWrap);
            }
        }

        workspaceElement.appendChild(createSelectInput('title_column', 'Card Title Column', getColumnOptionsForTable(itemData.table), itemData.title_column || '', value => {
            itemData.title_column = value;
        }));

        const fieldOptions = getColumnOptionsForTable(itemData.table)
            .filter(columnOption => columnOption.value !== '' && columnOption.value !== itemData.status_column);
        workspaceElement.appendChild(createColumnMultiSelect('Card Detail Fields (shown on each card)', fieldOptions, itemData.card_columns || [], value => {
            itemData.card_columns = value;
        }));

        workspaceElement.appendChild(createColorInput('color', 'Default Lane / Card Color', itemData.color || '#003366', value => {
            itemData.color = value;
        }));
    }

    const hr = document.createElement('hr');
    hr.style.cssText = 'border:none; border-top:1px solid var(--border); margin:18px 0 14px;';
    workspaceElement.appendChild(hr);

    workspaceElement.appendChild(createTextInput('menu_name', 'Menu Display Name', itemData.menu_name || `Board ${key}`, value => {
        itemData.menu_name = value;
    }));

    workspaceElement.appendChild(createIconPicker('menu_icon', 'Menu Icon', itemData.menu_icon || '', value => {
        if (value && value.trim() !== '') itemData.menu_icon = value;
        else delete itemData.menu_icon;
    }));

    workspaceElement.appendChild(createCheckbox('hidden', 'Hide from Sidebar Menu', itemData.hidden, value => {
        if (value) itemData.hidden = true;
        else delete itemData.hidden;
    }, false));

    const note = document.createElement('p');
    note.className = 'c-muted';
    note.style.cssText = 'margin-top:8px;';
    note.textContent = 'This board only appears in the sidebar once a table and status column are set.';
    workspaceElement.appendChild(note);
}
