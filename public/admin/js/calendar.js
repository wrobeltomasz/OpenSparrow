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

    workspaceElement.appendChild(createSelectInput('table', 'Source Table', getTableOptions(), itemData.table, value => {
        itemData.table = value;
        itemData.date_column = "";
        itemData.title_column = "";
        delete itemData.subtitle_column;
        renderEditor(key, itemData, isArray);
    }));

    workspaceElement.appendChild(createSelectInput('date_column', 'Date Column Name', columnOptions, itemData.date_column, value => itemData.date_column = value));
    workspaceElement.appendChild(createSelectInput('title_column', 'Title Column Name', columnOptions, itemData.title_column, value => itemData.title_column = value));

    const subtitleOptions = columnOptions.map(columnOption => (columnOption.value === '' ? { value: '', label: '(None)' } : columnOption));
    workspaceElement.appendChild(createSelectInput('subtitle_column', 'Subtitle Column Name', subtitleOptions, itemData.subtitle_column || '', value => {
        if (value && value.trim() !== '') {
            itemData.subtitle_column = value;
        } else {
            delete itemData.subtitle_column;
        }
    }));

    workspaceElement.appendChild(createIconPicker('icon', 'Event Icon', itemData.icon || '', value => {
        if (value && value.trim() !== '') {
            itemData.icon = value;
        } else {
            delete itemData.icon;
        }
    }));

    workspaceElement.appendChild(createColorInput('color', 'Event Color', itemData.color || '#6E767F', value => itemData.color = value));
    workspaceElement.appendChild(createTextInput('notify_before_days', 'Notify Before (Days)', itemData.notify_before_days, value => itemData.notify_before_days = parseInt(value) || 0));

    const usersWrapper = document.createElement('div');
    usersWrapper.innerHTML = '<p style="color:var(--muted); ">Loading active users...</p>';
    workspaceElement.appendChild(usersWrapper);

    apiFetch('api.php?action=users_list')
        .then(result => result.json())
        .then(data => {
            usersWrapper.innerHTML = '';
            if (data.status === 'success') {
                const activeUsers = data.users
                    .filter(user => user.is_active === true)
                    .map(user => ({ value: user.id, label: user.username }));

                usersWrapper.appendChild(createMultiSelect(
                    'notified_users',
                    'Users to Notify (Multi-select)',
                    activeUsers,
                    itemData.notified_users,
                    value => itemData.notified_users = value
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

    workspaceElement.appendChild(createTextInput('url_template', 'URL Template', itemData.url_template, value => itemData.url_template = value));
}
