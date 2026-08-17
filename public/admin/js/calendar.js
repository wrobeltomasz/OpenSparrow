// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { createTextInput, createSelectInput, createColorInput, createIconPicker, createMultiSelect } from './ui.js';
import { apiFetch } from '../../assets/js/util/api.js';

export function renderCalendarEditor(key, itemData, isArray, context) {
    const { workspaceEl: workspaceElement, getTableOptions, getColumnOptionsForTable, renderEditor } = context;

    if (itemData.date_field !== undefined) { itemData.date_column = itemData.date_field; delete itemData.date_field; }
    if (itemData.title_field !== undefined) { itemData.title_column = itemData.title_field; delete itemData.title_field; }
    if (itemData.user_id_field !== undefined) { delete itemData.user_id_field; }
    if (itemData.user_id_column !== undefined) { delete itemData.user_id_column; }

    if (!Array.isArray(itemData.notified_users)) {
        itemData.notified_users = [];
    }

    const columnOptions = getColumnOptionsForTable(itemData.table);

    workspaceElement.appendChild(createSelectInput('table', 'Source Table', getTableOptions(), itemData.table, v => {
        itemData.table = v;
        itemData.date_column = "";
        itemData.title_column = "";
        delete itemData.subtitle_column;
        renderEditor(key, itemData, isArray);
    }));

    workspaceElement.appendChild(createSelectInput('date_column', 'Date Column Name', columnOptions, itemData.date_column, v => itemData.date_column = v));
    workspaceElement.appendChild(createSelectInput('title_column', 'Title Column Name', columnOptions, itemData.title_column, v => itemData.title_column = v));

    const subtitleOptions = columnOptions.map(o => (o.value === '' ? { value: '', label: '(None)' } : o));
    workspaceElement.appendChild(createSelectInput('subtitle_column', 'Subtitle Column Name', subtitleOptions, itemData.subtitle_column || '', v => {
        if (v && v.trim() !== '') {
            itemData.subtitle_column = v;
        } else {
            delete itemData.subtitle_column;
        }
    }));

    workspaceElement.appendChild(createIconPicker('icon', 'Event Icon', itemData.icon || '', v => {
        if (v && v.trim() !== '') {
            itemData.icon = v;
        } else {
            delete itemData.icon;
        }
    }));

    workspaceElement.appendChild(createColorInput('color', 'Event Color', itemData.color || '#6E767F', v => itemData.color = v));
    workspaceElement.appendChild(createTextInput('notify_before_days', 'Notify Before (Days)', itemData.notify_before_days, v => itemData.notify_before_days = parseInt(v) || 0));

    const usersWrapper = document.createElement('div');
    usersWrapper.innerHTML = '<p style="color:var(--muted); ">Loading active users...</p>';
    workspaceElement.appendChild(usersWrapper);

    apiFetch('api.php?action=users_list')
        .then(result => result.json())
        .then(data => {
            usersWrapper.innerHTML = '';
            if (data.status === 'success') {
                const activeUsers = data.users
                    .filter(u => u.is_active === true)
                    .map(u => ({ value: u.id, label: u.username }));

                usersWrapper.appendChild(createMultiSelect(
                    'notified_users',
                    'Users to Notify (Multi-select)',
                    activeUsers,
                    itemData.notified_users,
                    v => itemData.notified_users = v
                ));
            } else {
                const errorP = document.createElement('p');
                errorP.style.cssText = 'color:var(--error);';
                errorP.textContent = `Error loading users: ${data.error}`;
                usersWrapper.appendChild(errorP);
            }
        })
        .catch(() => {
            usersWrapper.innerHTML = '<p style="color:var(--error); ">Network error while fetching users.</p>';
        });

    workspaceElement.appendChild(createTextInput('url_template', 'URL Template', itemData.url_template, v => itemData.url_template = v));
}
