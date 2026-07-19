// admin/js/database.js — PostgreSQL connection settings editor (renderDatabaseSection):
// self-contained tab panel (used inside Settings) — fetches/saves config/database.json
// directly via api.php (get&file=database / save&file=database), independent of the
// global currentConfig/"Save config" flow used by config_store-backed modules.
import { apiFetch } from '../../assets/js/util/api.js';
import { createTextInput } from './ui.js';
import { showStatusPill } from './app.js';

export async function renderDatabaseSection(panel) {
    panel.innerHTML = '<h3>Loading database settings…</h3>';

    let dbConfig;
    try {
        const res = await apiFetch('api.php?action=get&file=database');
        dbConfig = await res.json();
        if (!dbConfig.host) dbConfig = { host: 'localhost', port: '5432', dbname: '', user: 'postgres', password: '' };
    } catch (e) {
        panel.innerHTML = '<h3 style="color:var(--danger);">Error loading database settings. Check server logs.</h3>';
        return;
    }

    panel.innerHTML = '';

    const h3 = document.createElement('h3');
    h3.textContent = 'PostgreSQL Connection Settings';
    panel.appendChild(h3);

    const desc = document.createElement('p');
    desc.style.cssText = 'color:#64748B; margin-bottom: 20px;';
    desc.innerHTML = 'Configure your database connection. <strong>Click "Save configuration" before testing!</strong>';
    panel.appendChild(desc);

    panel.appendChild(createTextInput('host', 'DB Host (e.g. localhost or IP)', dbConfig.host || 'localhost', v => dbConfig.host = v));
    panel.appendChild(createTextInput('port', 'DB Port (default 5432)', dbConfig.port || '5432', v => dbConfig.port = v));
    panel.appendChild(createTextInput('dbname', 'Database Name', dbConfig.dbname || '', v => dbConfig.dbname = v));
    panel.appendChild(createTextInput('user', 'DB User', dbConfig.user || 'postgres', v => dbConfig.user = v));
    panel.appendChild(createTextInput('password', 'DB Password', dbConfig.password || '', v => dbConfig.password = v));
    panel.appendChild(createTextInput('schema', 'System Schema (for spw_* tables, default: app)', dbConfig.schema || 'app', v => dbConfig.schema = v));

    const saveRow = document.createElement('div');
    saveRow.style.cssText = 'display:flex; align-items:center; gap:12px; margin-top:20px;';

    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.textContent = 'Save configuration';
    saveBtn.className = 'btn btn-success';

    const pillAnchor = document.createElement('span');

    saveBtn.addEventListener('click', async () => {
        saveBtn.disabled = true;
        try {
            const res = await apiFetch('api.php?action=save&file=database', {
                method: 'POST',
                body: JSON.stringify(dbConfig),
            });
            const result = await res.json();
            if (result.status === 'success') {
                showStatusPill(pillAnchor, 'Database settings saved.', 'success');
            } else {
                showStatusPill(pillAnchor, result.error || 'Error saving settings.', 'error');
            }
        } catch (e) {
            showStatusPill(pillAnchor, 'Request failed.', 'error');
        }
        saveBtn.disabled = false;
    });

    saveRow.appendChild(saveBtn);
    saveRow.appendChild(pillAnchor);
    panel.appendChild(saveRow);

    const testBtn = document.createElement('button');
    testBtn.type = 'button';
    testBtn.textContent = 'Test Saved Connection';
    testBtn.className = 'btn btn-primary';
    testBtn.style.marginTop = '12px';
    testBtn.style.width = '100%';

    testBtn.onclick = async () => {
        testBtn.textContent = 'Testing...';
        testBtn.style.opacity = '0.7';

        try {
            const res = await fetch('api.php?action=health');
            const data = await res.json();

            if (data.db_connected) {
                alert('✓ Success! Successfully connected to the database.');
                testBtn.style.background = '#2b9348';
            } else {
                alert('✗ Connection failed:\n' + data.db_error + '\n\nDid you click "Save configuration" before testing?');
                testBtn.style.background = '#d00000';
            }
        } catch (e) {
            alert('✗ API Error: Cannot reach server.');
        }

        testBtn.textContent = 'Test Saved Connection';
        testBtn.style.opacity = '1';
    };

    panel.appendChild(testBtn);
}
