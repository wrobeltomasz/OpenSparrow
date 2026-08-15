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
        options.forEach(opt => {
            const lbl = document.createElement('label');
            lbl.style.cssText = 'display:flex; align-items:center; margin-bottom:5px; cursor:pointer; font-weight:normal;';

            const chk = document.createElement('input');
            chk.type = 'checkbox';
            chk.value = opt.value;
            chk.checked = selected.includes(opt.value);
            chk.style.marginRight = '8px';
            chk.addEventListener('change', () => {
                const idx = selected.indexOf(opt.value);
                if (chk.checked && idx === -1) selected.push(opt.value);
                else if (!chk.checked && idx !== -1) selected.splice(idx, 1);
                onChange([...selected]);
            });

            lbl.appendChild(chk);
            lbl.appendChild(document.createTextNode(opt.label));
            container.appendChild(lbl);
        });
    }

    wrapper.appendChild(container);
    return wrapper;
}

export function renderBoardEditor(key, itemData, isArray, ctx) {
    const {
        workspaceEl,
        getTableOptions,
        getColumnOptionsForTable,
        getEnumColumnsForTable,
        getColumnMeta,
        renderEditor,
    } = ctx;

    if (!Array.isArray(itemData.card_columns)) itemData.card_columns = [];

    workspaceEl.appendChild(createSelectInput('table', 'Source Table', getTableOptions(), itemData.table || '', v => {
        itemData.table = v;
        itemData.status_column = '';
        itemData.title_column = '';
        itemData.card_columns = [];
        renderEditor(key, itemData, isArray);
    }));

    if (itemData.table) {
        const enumCols = getEnumColumnsForTable(itemData.table);
        const hasEnum = enumCols.length > 0;

        const statusOptions = [{ value: '', label: '-- Select Status Column --' }]
            .concat(hasEnum ? enumCols : getColumnOptionsForTable(itemData.table).filter(o => o.value !== ''));

        workspaceEl.appendChild(createSelectInput('status_column', 'Status Column (defines lanes)', statusOptions, itemData.status_column || '', v => {
            itemData.status_column = v;
            renderEditor(key, itemData, isArray);
        }));

        if (!hasEnum) {
            const warn = document.createElement('p');
            warn.style.cssText = 'color:var(--warn); margin:-6px 0 14px; max-width:640px;';
            warn.textContent = 'This table has no enum columns. Lanes will be derived from the distinct '
                + 'values currently in the chosen column, and all lanes use the default color below. '
                + 'For a proper status workflow, define an enum column in the Schema editor.';
            workspaceEl.appendChild(warn);
        }

        if (itemData.status_column) {
            const meta = getColumnMeta(itemData.table, itemData.status_column);
            if (meta && (meta.type || '').toLowerCase() === 'enum' && Array.isArray(meta.options)) {
                const previewWrap = document.createElement('div');
                previewWrap.style.cssText = 'margin:-4px 0 18px;';
                const lbl = document.createElement('label');
                lbl.style.cssText = 'display:block; font-weight:600; margin-bottom:6px; color:var(--text);';
                lbl.textContent = 'Lane preview';
                previewWrap.appendChild(lbl);

                const chips = document.createElement('div');
                chips.style.cssText = 'display:flex; flex-wrap:wrap; gap:8px;';
                meta.options.forEach(opt => {
                    const color = (meta.enum_colors && meta.enum_colors[opt]) || itemData.color || '#003366';
                    const chip = document.createElement('span');
                    chip.style.cssText = 'display:inline-flex; align-items:center; gap:6px; padding:4px 10px; '
                        + 'border:1px solid var(--border); border-radius:999px; background:var(--panel);';
                    const dot = document.createElement('span');
                    dot.style.cssText = `width:10px; height:10px; border-radius:50%; background:${color};`;
                    chip.appendChild(dot);
                    chip.appendChild(document.createTextNode(opt));
                    chips.appendChild(chip);
                });
                previewWrap.appendChild(chips);
                workspaceEl.appendChild(previewWrap);
            }
        }

        workspaceEl.appendChild(createSelectInput('title_column', 'Card Title Column', getColumnOptionsForTable(itemData.table), itemData.title_column || '', v => {
            itemData.title_column = v;
        }));

        const fieldOpts = getColumnOptionsForTable(itemData.table)
            .filter(o => o.value !== '' && o.value !== itemData.status_column);
        workspaceEl.appendChild(createColumnMultiSelect('Card Detail Fields (shown on each card)', fieldOpts, itemData.card_columns || [], v => {
            itemData.card_columns = v;
        }));

        workspaceEl.appendChild(createColorInput('color', 'Default Lane / Card Color', itemData.color || '#003366', v => {
            itemData.color = v;
        }));
    }

    const hr = document.createElement('hr');
    hr.style.cssText = 'border:none; border-top:1px solid var(--border); margin:18px 0 14px;';
    workspaceEl.appendChild(hr);

    workspaceEl.appendChild(createTextInput('menu_name', 'Menu Display Name', itemData.menu_name || `Board ${key}`, v => {
        itemData.menu_name = v;
    }));

    workspaceEl.appendChild(createIconPicker('menu_icon', 'Menu Icon', itemData.menu_icon || '', v => {
        if (v && v.trim() !== '') itemData.menu_icon = v;
        else delete itemData.menu_icon;
    }));

    workspaceEl.appendChild(createCheckbox('hidden', 'Hide from Sidebar Menu', itemData.hidden, v => {
        if (v) itemData.hidden = true;
        else delete itemData.hidden;
    }, false));

    const note = document.createElement('p');
    note.className = 'c-muted';
    note.style.cssText = 'margin-top:8px;';
    note.textContent = 'This board only appears in the sidebar once a table and status column are set.';
    workspaceEl.appendChild(note);
}
