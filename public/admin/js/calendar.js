// admin/js/calendar.js — Calendar view configuration editor (renderCalendarEditor): date/title columns, notified users, colors; includes legacy *_field -> *_column cleanup.
import { createTextInput, createSelectInput, createColorInput, createIconPicker, createMultiSelect } from './ui.js';

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
        renderEditor(key, itemData, isArray); 
    }));
    
    workspaceEl.appendChild(createSelectInput('date_column', 'Date Column Name', columnOptions, itemData.date_column, v => itemData.date_column = v));
    workspaceEl.appendChild(createSelectInput('title_column', 'Title Column Name', columnOptions, itemData.title_column, v => itemData.title_column = v));
    
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
    usersWrapper.innerHTML = '<p style="color:#64748B; font-size:13px;">Loading active users...</p>';
    workspaceEl.appendChild(usersWrapper);

    fetch('api.php?action=users_list')
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
                errP.style.cssText = 'color:var(--danger);font-size:13px;';
                errP.textContent = `Error loading users: ${data.error}`;
                usersWrapper.appendChild(errP);
            }
        })
        .catch(() => {
            usersWrapper.innerHTML = '<p style="color:var(--danger); font-size:13px;">Network error while fetching users.</p>';
        });

    workspaceEl.appendChild(createTextInput('url_template', 'URL Template', itemData.url_template, v => itemData.url_template = v));
}