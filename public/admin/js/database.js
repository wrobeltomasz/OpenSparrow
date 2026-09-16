// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { createTextInput, buildSectionCard } from './ui.js';
import { showStatusPill } from './app.js';

export async function renderDatabaseSection(panel) {
    const content = document.createElement('div');
    panel.appendChild(content);
    content.innerHTML = '<h3>Loading database settings…</h3>';

    let dbConfig;
    try {
        const response = await apiFetch('api.php?action=get&file=database');
        dbConfig = await response.json();
        if (!dbConfig.host) dbConfig = { host: 'localhost', port: '5432', dbname: '', user: 'postgres', password: '' };
    } catch (error) {
        content.innerHTML = '<h3 style="color:var(--error);">Error loading database settings. Check server logs.</h3>';
        return;
    }

    content.innerHTML = '';

    const { card, body } = buildSectionCard(
        'PostgreSQL Connection Settings',
        'Configure your database connection. Click "Save configuration" before testing!'
    );
    content.appendChild(card);

    body.appendChild(createTextInput('host', 'DB Host (e.g. localhost or IP)', dbConfig.host || 'localhost', value => dbConfig.host = value));
    body.appendChild(createTextInput('port', 'DB Port (default 5432)', dbConfig.port || '5432', value => dbConfig.port = value));
    body.appendChild(createTextInput('dbname', 'Database Name', dbConfig.dbname || '', value => dbConfig.dbname = value));
    body.appendChild(createTextInput('user', 'DB User', dbConfig.user || 'postgres', value => dbConfig.user = value));
    body.appendChild(createTextInput('password', 'DB Password', dbConfig.password || '', value => dbConfig.password = value));
    body.appendChild(createTextInput('schema', 'System Schema (for spw_* tables, default: app)', dbConfig.schema || 'app', value => dbConfig.schema = value));

    const saveRow = document.createElement('div');
    saveRow.style.cssText = 'display:flex; align-items:center; gap:12px; margin-top:20px;';

    const saveButton = document.createElement('button');
    saveButton.type = 'button';
    saveButton.textContent = 'Save configuration';
    saveButton.className = 'btn btn-success';

    const pillAnchor = document.createElement('span');

    saveButton.addEventListener('click', async () => {
        saveButton.disabled = true;
        try {
            const response = await apiFetch('api.php?action=save&file=database', {
                method: 'POST',
                body: JSON.stringify(dbConfig),
            });
            const result = await response.json();
            if (result.status === 'success') {
                showStatusPill(pillAnchor, 'Database settings saved.', 'success');
            } else {
                showStatusPill(pillAnchor, result.error || 'Error saving settings.', 'error');
            }
        } catch (error) {
            showStatusPill(pillAnchor, 'Request failed.', 'error');
        }
        saveButton.disabled = false;
    });

    saveRow.appendChild(saveButton);
    saveRow.appendChild(pillAnchor);
    body.appendChild(saveRow);

    const testButton = document.createElement('button');
    testButton.type = 'button';
    testButton.textContent = 'Test Saved Connection';
    testButton.className = 'btn btn-primary';
    testButton.style.marginTop = '12px';

    testButton.onclick = async () => {
        testButton.textContent = 'Testing...';
        testButton.style.opacity = '0.7';

        try {
            const response = await apiFetch('api.php?action=health');
            const data = await response.json();

            if (data.db_connected) {
                showStatusPill(testButton, 'Connected to the database.', 'success');
                testButton.style.background = 'var(--ok)';
            } else {
                showStatusPill(
                    testButton,
                    'Connection failed: ' + (data.db_error || 'unknown error')
                    + ' — save the configuration before testing.',
                    'error'
                );
                testButton.style.background = 'var(--error)';
            }
        } catch (error) {
            showStatusPill(testButton, 'Cannot reach the server.', 'error');
        }

        testButton.textContent = 'Test Saved Connection';
        testButton.style.opacity = '1';
    };

    body.appendChild(testButton);
}
