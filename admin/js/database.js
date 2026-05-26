// admin/database.js
import { createTextInput } from './ui.js';

export function renderDatabaseEditor(key, itemData, isArray, ctx) {
    const { workspaceEl, currentConfig } = ctx;
    
    workspaceEl.innerHTML = `
        <h3>PostgreSQL Connection Settings</h3>
        <p style="color:#64748B; margin-bottom: 20px;">
            Configure your database connection. <strong>Click "Save File" in the top right corner before testing!</strong>
        </p>
    `;

    workspaceEl.appendChild(createTextInput('host', 'DB Host (e.g. localhost or IP)', currentConfig.host || 'localhost', v => currentConfig.host = v));
    workspaceEl.appendChild(createTextInput('port', 'DB Port (default 5432)', currentConfig.port || '5432', v => currentConfig.port = v));
    workspaceEl.appendChild(createTextInput('dbname', 'Database Name', currentConfig.dbname || '', v => currentConfig.dbname = v));
    workspaceEl.appendChild(createTextInput('user', 'DB User', currentConfig.user || 'postgres', v => currentConfig.user = v));
    workspaceEl.appendChild(createTextInput('password', 'DB Password', currentConfig.password || '', v => currentConfig.password = v));
    workspaceEl.appendChild(createTextInput('schema', 'System Schema (for spw_* tables, default: app)', currentConfig.schema || 'app', v => currentConfig.schema = v));

    const testBtn = document.createElement('button');
    testBtn.textContent = 'Test Saved Connection';
    testBtn.className = 'btn btn-primary';
    testBtn.style.marginTop = '20px';
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
                alert('✗ Connection failed:\n' + data.db_error + '\n\nDid you click "Save File" before testing?');
                testBtn.style.background = '#d00000';
            }
        } catch (e) {
            alert('✗ API Error: Cannot reach server.');
        }

        testBtn.textContent = 'Test Saved Connection';
        testBtn.style.opacity = '1';
    };

    workspaceEl.appendChild(testBtn);
}