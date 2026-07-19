// admin/js/board.js — Board (Kanban) view configuration editor
// Edits the board config (status column, lanes, colors, icons) with ui.js field builders, plus a string-preserving column multi-select (ui.js createMultiSelect coerces values to numbers).
import {
    createTextInput,
    createSelectInput,
    createColorInput,
    createIconPicker,
    createCheckbox,
    createPageHeader,
} from './ui.js';

// String-preserving multi-select. ui.js's createMultiSelect coerces option
// values to numbers (it is built for user-ID lists), which would corrupt the
// string column names the board stores, so the board provides its own.
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

export function renderBoardEditor(ctx) {
    const {
        workspaceEl,
        currentConfig,
        getTableOptions,
        getColumnOptionsForTable,
        getEnumColumnsForTable,
        getColumnMeta,
        renderEditor,
    } = ctx;

    // Ensure a sane shape for the working config.
    if (!Array.isArray(currentConfig.card_columns)) currentConfig.card_columns = [];
    if (!currentConfig.menu_name) currentConfig.menu_name = 'Board';

    workspaceEl.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    workspaceEl.appendChild(wrap);

    wrap.appendChild(createPageHeader(
        'Board (Kanban) Configuration',
        'Pick a table and the status column whose values become the board lanes '
        + '(e.g. "To Do", "In Progress", "Done"). Users drag cards between lanes to update that '
        + 'column instantly. An enum column is recommended so lanes and their colors are well defined.'
    ));

    // ── Source table ───────────────────────────────────────────────────────────
    wrap.appendChild(createSelectInput('table', 'Source Table', getTableOptions(), currentConfig.table || '', v => {
        currentConfig.table = v;
        currentConfig.status_column = '';
        currentConfig.title_column = '';
        currentConfig.card_columns = [];
        renderEditor('SETTINGS', currentConfig, false);
    }));

    if (currentConfig.table) {
        const enumCols = getEnumColumnsForTable(currentConfig.table);
        const hasEnum = enumCols.length > 0;

        // ── Status column (the most important setting) ─────────────────────────
        const statusOptions = [{ value: '', label: '-- Select Status Column --' }]
            .concat(hasEnum ? enumCols : getColumnOptionsForTable(currentConfig.table).filter(o => o.value !== ''));

        wrap.appendChild(createSelectInput('status_column', 'Status Column (defines lanes)', statusOptions, currentConfig.status_column || '', v => {
            currentConfig.status_column = v;
            renderEditor('SETTINGS', currentConfig, false);
        }));

        if (!hasEnum) {
            const warn = document.createElement('p');
            warn.style.cssText = 'color:#a16207;  margin:-6px 0 14px; max-width:640px;';
            warn.textContent = 'This table has no enum columns. Lanes will be derived from the distinct '
                + 'values currently in the chosen column, and all lanes use the default color below. '
                + 'For a proper status workflow, define an enum column in the Schema editor.';
            wrap.appendChild(warn);
        }

        // Lane preview for enum status columns — shows the lanes and their colors.
        if (currentConfig.status_column) {
            const meta = getColumnMeta(currentConfig.table, currentConfig.status_column);
            if (meta && (meta.type || '').toLowerCase() === 'enum' && Array.isArray(meta.options)) {
                const previewWrap = document.createElement('div');
                previewWrap.style.cssText = 'margin:-4px 0 18px;';
                const lbl = document.createElement('label');
                lbl.style.cssText = 'display:block;  font-weight:600; margin-bottom:6px; color:var(--text);';
                lbl.textContent = 'Lane preview';
                previewWrap.appendChild(lbl);

                const chips = document.createElement('div');
                chips.style.cssText = 'display:flex; flex-wrap:wrap; gap:8px;';
                meta.options.forEach(opt => {
                    const color = (meta.enum_colors && meta.enum_colors[opt]) || currentConfig.color || '#005A9E';
                    const chip = document.createElement('span');
                    chip.style.cssText = 'display:inline-flex; align-items:center; gap:6px; padding:4px 10px; '
                        + 'border:1px solid var(--border); border-radius:999px;  background:var(--panel);';
                    const dot = document.createElement('span');
                    dot.style.cssText = `width:10px; height:10px; border-radius:50%; background:${color};`;
                    chip.appendChild(dot);
                    chip.appendChild(document.createTextNode(opt));
                    chips.appendChild(chip);
                });
                previewWrap.appendChild(chips);
                wrap.appendChild(previewWrap);
            }
        }

        // ── Card content ───────────────────────────────────────────────────────
        wrap.appendChild(createSelectInput('title_column', 'Card Title Column', getColumnOptionsForTable(currentConfig.table), currentConfig.title_column || '', v => {
            currentConfig.title_column = v;
        }));

        const fieldOpts = getColumnOptionsForTable(currentConfig.table)
            .filter(o => o.value !== '' && o.value !== currentConfig.status_column);
        wrap.appendChild(createColumnMultiSelect('Card Detail Fields (shown on each card)', fieldOpts, currentConfig.card_columns || [], v => {
            currentConfig.card_columns = v;
        }));

        wrap.appendChild(createColorInput('color', 'Default Lane / Card Color', currentConfig.color || '#005A9E', v => {
            currentConfig.color = v;
        }));
    }

    // ── Menu / sidebar settings ────────────────────────────────────────────────
    const hr = document.createElement('hr');
    hr.style.cssText = 'border:none; border-top:1px solid var(--border); margin:24px 0 18px;';
    wrap.appendChild(hr);

    const menuHeading = document.createElement('h4');
    menuHeading.style.cssText = 'margin:0 0 12px;';
    menuHeading.textContent = 'Menu Settings';
    wrap.appendChild(menuHeading);

    wrap.appendChild(createTextInput('menu_name', 'Menu Display Name', currentConfig.menu_name || 'Board', v => {
        currentConfig.menu_name = v;
    }));

    wrap.appendChild(createIconPicker('menu_icon', 'Menu Icon', currentConfig.menu_icon || '', v => {
        if (v && v.trim() !== '') currentConfig.menu_icon = v;
        else delete currentConfig.menu_icon;
    }));

    wrap.appendChild(createCheckbox('hidden', 'Hide from Sidebar Menu', currentConfig.hidden, v => {
        if (v) currentConfig.hidden = true;
        else delete currentConfig.hidden;
    }, false));

    const note = document.createElement('p');
    note.style.cssText = '  margin-top:8px;';
    note.textContent = 'The board only appears in the sidebar once a table and status column are set. Remember to click "Save config".';
    wrap.appendChild(note);
}
