// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// admin/js/calendar.js — Calendar view configuration editor (renderCalendarEditor): date/title columns, notified users, colors; includes legacy *_field -> *_column cleanup.
import { createTextInput, createSelectInput, createColorInput, createIconPicker, createMultiSelect } from './ui.js';
import { apiFetch } from '../../assets/js/util/api.js';

export function renderCalendarEditor(key, itemData, isArray, ctx) {
    const { workspaceEl, getTableOptions, getColumnOptionsForTable, renderEditor } = ctx;
    
    // Legacy cleanup mapping
    if (itemData.date_field !== undefined) { itemData.date_column = itemData.date_field; delete itemData.date_field; }
    if (itemData.title_field !== undefined) { itemData.title_column = itemData.title_field; delete itemData.title_field; }
    if (itemData.user_id_field !== undefined) { delete itemData.user_id_field; }
    if (itemData.user_id_column !== undefined) { delete itemData.user_id_column; }

    // Ensure array structure for selected users
    if (!Array.isArray(itemData.notified_users)) {
        itemData.notified_users = [];
    }

    const columnOptions = getColumnOptionsForTable(itemData.table);

    workspaceEl.appendChild(createSelectInput('table', 'Source Table', getTableOptions(), itemData.table, v => { 
        itemData.table = v; 
        itemData.date_column = "";
        itemData.title_column = "";
        delete itemData.subtitle_column;
        renderEditor(key, itemData, isArray); 
    }));
    
    workspaceEl.appendChild(createSelectInput('date_column', 'Date Column Name', columnOptions, itemData.date_column, v => itemData.date_column = v));
    workspaceEl.appendChild(createSelectInput('title_column', 'Title Column Name', columnOptions, itemData.title_column, v => itemData.title_column = v));

    // Optional second column shown after the title on the event tile
    // columnOptions already starts with an empty entry — relabel it, don't add a second one
    const subtitleOptions = columnOptions.map(o => (o.value === '' ? { value: '', label: '(None)' } : o));
    workspaceEl.appendChild(createSelectInput('subtitle_column', 'Subtitle Column Name', subtitleOptions, itemData.subtitle_column || '', v => {
        if (v && v.trim() !== '') {
            itemData.subtitle_column = v;
        } else {
            delete itemData.subtitle_column;
        }
    }));


    workspaceEl.appendChild(createIconPicker('icon', 'Event Icon', itemData.icon || '', v => {
        if (v && v.trim() !== '') {
            itemData.icon = v;
        } else {
            delete itemData.icon;
        }
    }));
    
    workspaceEl.appendChild(createColorInput('color', 'Event Color', itemData.color || '#64748B', v => itemData.color = v));
    workspaceEl.appendChild(createTextInput('notify_before_days', 'Notify Before (Days)', itemData.notify_before_days, v => itemData.notify_before_days = parseInt(v) || 0));

    // Async block for loading active users from database
    const usersWrapper = document.createElement('div');
    usersWrapper.innerHTML = '<p style="color:#64748B; ">Loading active users...</p>';
    workspaceEl.appendChild(usersWrapper);

    apiFetch('api.php?action=users_list')
        .then(res => res.json())
        .then(data => {
            usersWrapper.innerHTML = '';
            if (data.status === 'success') {
                // Filter only active users
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
                const errP = document.createElement('p');
                errP.style.cssText = 'color:var(--danger);';
                errP.textContent = `Error loading users: ${data.error}`;
                usersWrapper.appendChild(errP);
            }
        })
        .catch(() => {
            usersWrapper.innerHTML = '<p style="color:var(--danger); ">Network error while fetching users.</p>';
        });

    workspaceEl.appendChild(createTextInput('url_template', 'URL Template', itemData.url_template, v => itemData.url_template = v));
}