// admin/app.js
import { moveArrayItem, moveObjectKey, renderGlobalSettings, createFullMenuPreview } from './ui.js';
import { syncSchemaTables, renderSchemaEditor, renderSchemaGlobalSettings } from './schema.js';
import { renderDashboardLayout, renderDashboardEditor, initDashboardUI } from './dashboard.js';
import { renderCalendarEditor } from './calendar.js';
import { renderDatabaseEditor } from './database.js';
import { renderSecurityEditor } from './security.js';
import { renderHealthDashboard } from './health.js';
import { renderDocumentation } from './docs.js';
import { renderUsersEditor } from './users.js';
import { renderWorkflowsEditor } from './workflows.js';
import { renderFilesEditor } from './files_render.js';
import { renderBackupPage } from './backup.js';
import { renderAuditEditor } from './audit.js';
import { renderAddTableEditor } from './add_table.js';
import { renderMigrationsPage } from './migrations.js';
import { renderPerformancePage } from './performance.js';
import { renderCronPage } from './cron.js';
import { renderM2mPage } from './m2m.js';
import { renderErdPage } from './erd.js';
import { renderViewsEditor } from './views_editor.js';
import { renderDemoPage } from './demo.js';
import { renderSettingsPage } from './settings.js';
import { renderCsvImportPage } from './csv_import.js';

let currentConfig = null;
let currentFile = 'schema';
let currentItemKey = null;
let globalSchemaObj = null;
let isDirty = false;

const itemListEl = document.getElementById('itemList');
const workspaceEl = document.getElementById('editorForm');
const btnSave = document.getElementById('btnSave');
const tabs = document.querySelectorAll('.admin-tab');

// Tabs that save immediately via API — no config file involved, never dirty.
const NON_CONFIG_TABS = new Set(['users', 'security', 'health', 'backup', 'database', 'audit', 'add_table', 'migrations', 'performance', 'cron', 'm2m', 'erd', 'demo', 'settings', 'csv_import']);

// Dirty-state guards: every edit marks the config dirty; navigation and reload
// refuse to drop pending changes silently.
export function markDirty() { if (!NON_CONFIG_TABS.has(currentFile)) isDirty = true; }
export function markClean() { isDirty = false; }
function confirmDiscard() {
    return !isDirty || confirm('You have unsaved changes that will be lost. Continue?');
}

// Inline status pill — lightweight replacement for alert() after async
// operations. The pill fades out on its own so the workflow is not blocked.
export function showStatusPill(anchor, message, variant = 'success') {
    if (!anchor) return;
    const existing = anchor.parentNode && anchor.parentNode.querySelector(':scope > .status-pill');
    if (existing) existing.remove();

    const pill = document.createElement('span');
    pill.className = 'status-pill status-pill-' + variant;
    pill.textContent = message;
    const colors = {
        success: { bg: '#dcfce7', fg: '#166534', border: '#86efac' },
        error:   { bg: '#fee2e2', fg: '#991b1b', border: '#fca5a5' },
        info:    { bg: '#e0f2fe', fg: '#075985', border: '#7dd3fc' },
    }[variant] || { bg: '#e2e8f0', fg: '#0f172a', border: '#cbd5e1' };
    pill.style.cssText = `display:inline-flex; align-items:center; gap:6px; margin-left:10px; padding:4px 10px; background:${colors.bg}; color:${colors.fg}; border:1px solid ${colors.border}; border-radius:999px; font-size:12px; font-weight:600; transition:opacity .3s;`;
    anchor.insertAdjacentElement('afterend', pill);

    const ttl = variant === 'error' ? 6000 : 3000;
    setTimeout(() => {
        pill.style.opacity = '0';
        setTimeout(() => pill.remove(), 300);
    }, ttl);
}

// Utility function to escape HTML strings safely against XSS
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
}

// Retrieve the CSRF token from the meta tag
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

document.addEventListener('DOMContentLoaded', async () => {
    initDashboardUI();
    await fetchGlobalSchema(); 
    loadConfigFile(currentFile);

    const debugToggle = document.getElementById('debugToggle');
    if (debugToggle) {
        debugToggle.checked = localStorage.getItem('sparrow_debug_mode') === 'true';
        debugToggle.addEventListener('change', (e) => {
            localStorage.setItem('sparrow_debug_mode', e.target.checked);
            if (!e.target.checked) {
                const dbg = document.getElementById('debug');
                if (dbg) dbg.style.display = 'none';
            }
        });
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            if (!confirmDiscard()) return;
            tabs.forEach(t => t.classList.remove('active'));
            e.currentTarget.classList.add('active');
            currentFile = e.currentTarget.dataset.file;
            markClean();
            loadConfigFile(currentFile);
        });
    });

    // Any keyboard or widget change anywhere in the workspace counts as dirty.
    workspaceEl.addEventListener('input', markDirty);
    workspaceEl.addEventListener('change', markDirty);

    window.addEventListener('beforeunload', (e) => {
        if (isDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    document.getElementById('btnRunCron').addEventListener('click', async () => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;';
        const box = document.createElement('div');
        box.style.cssText = 'background:#fff;border-radius:8px;padding:24px;width:680px;max-width:95vw;max-height:80vh;display:flex;flex-direction:column;gap:12px;';
        const header = document.createElement('div');
        header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;';
        const title = document.createElement('strong');
        title.textContent = 'Run Notifications Cron';
        const closeBtn = document.createElement('button');
        closeBtn.textContent = '✕';
        closeBtn.style.cssText = 'background:none;border:none;font-size:18px;cursor:pointer;line-height:1;';
        closeBtn.addEventListener('click', () => overlay.remove());
        header.append(title, closeBtn);
        const content = document.createElement('div');
        content.style.cssText = 'overflow-y:auto;flex:1;font-size:13px;line-height:1.6;border:1px solid #e2e8f0;border-radius:4px;padding:12px;background:#f8fafc;';
        content.textContent = 'Running…';
        box.append(header, content);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

        try {
            const res = await fetch('api.php?action=run_cron_notifications', {
                method: 'POST',
                headers: { 'X-CSRF-Token': getCsrfToken() }
            });
            const data = await res.json();
            if (data.status === 'success') {
                content.innerHTML = data.output;
            } else {
                content.textContent = 'Error: ' + (data.error || 'Unknown error');
                content.style.color = '#991b1b';
            }
        } catch (err) {
            content.textContent = 'Request failed: ' + err.message;
            content.style.color = '#991b1b';
        }
    });

    document.getElementById('btnExport').addEventListener('click', () => {
        window.location.href = 'api.php?action=export';
    });

    const importBtn = document.getElementById('btnImport');
    const importInput = document.getElementById('importFileInput');
    
    importBtn.addEventListener('click', () => importInput.click());
    
    importInput.addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;
        
        const formData = new FormData();
        formData.append('backup_file', file);

        try {
            const res = await fetch('api.php?action=import', {
                method: 'POST',
                headers: { 'X-CSRF-Token': getCsrfToken() },
                body: formData
            });
            const data = await res.json();
            if (data.status === 'success') {
                markClean();
                showStatusPill(importBtn, 'Imported. Reloading…', 'success');
                setTimeout(() => window.location.reload(), 900);
            } else {
                showStatusPill(importBtn, 'Import error: ' + (data.error || 'Unknown error'), 'error');
            }
        } catch (err) {
            showStatusPill(importBtn, 'Failed to upload file.', 'error');
        }
        importInput.value = '';
    });
});

async function fetchGlobalSchema() {
    try {
        const res = await fetch('api.php?action=get&file=schema');
        globalSchemaObj = await res.json();
    } catch (e) { console.warn("Could not load global schema"); }
}

function getTableOptions() {
    const options = [{ value: '', label: '-- Select Table --' }];
    if (globalSchemaObj && globalSchemaObj.tables) {
        for (const t in globalSchemaObj.tables) options.push({ value: t, label: globalSchemaObj.tables[t].display_name || t });
    }
    return options;
}

function getColumnOptionsForTable(tableName) {
    const options = [{ value: '', label: '-- Select Column --' }];
    if (tableName && globalSchemaObj && globalSchemaObj.tables[tableName] && globalSchemaObj.tables[tableName].columns) {
        const cols = globalSchemaObj.tables[tableName].columns;
        for (const c in cols) options.push({ value: c, label: cols[c].display_name || c });
    }
    return options;
}

async function loadConfigFile(fileName) {
    if (fileName === 'health' || fileName === 'docs' || fileName === 'users' || fileName === 'backup' || fileName === 'menu' || fileName === 'audit' || fileName === 'add_table' || fileName === 'migrations' || fileName === 'performance' || fileName === 'cron' || fileName === 'm2m' || fileName === 'erd' || fileName === 'demo' || fileName === 'settings' || fileName === 'csv_import') {
        currentConfig = null;
        renderSidebar();
        renderEditor(fileName.toUpperCase(), null, false);
        return;
    }

    try {
        const response = await fetch(`api.php?action=get&file=${fileName}`);
        currentConfig = await response.json();

        if (fileName === 'schema') {
            if (!currentConfig.tables || Array.isArray(currentConfig.tables)) currentConfig.tables = {};
        } else if (fileName === 'dashboard') {
            if (!currentConfig.layout) currentConfig.layout = { columns: "repeat(auto-fit, minmax(300px, 1fr))", gap: "20px" };
            if (!currentConfig.widgets || !Array.isArray(currentConfig.widgets)) currentConfig.widgets = [];
            if (!currentConfig.menu_name) currentConfig.menu_name = 'Dashboard';
        } else if (fileName === 'calendar') {
            if (!currentConfig.sources || !Array.isArray(currentConfig.sources)) currentConfig.sources = [];
            if (!currentConfig.menu_name) currentConfig.menu_name = 'Calendar';
        } else if (fileName === 'workflows') {
            if (!currentConfig.workflows || !Array.isArray(currentConfig.workflows)) currentConfig.workflows = [];
            if (!currentConfig.menu_name) currentConfig.menu_name = 'Workflows';
        } else if (fileName === 'files') {
            if (!currentConfig.menu_name) currentConfig.menu_name = 'Files';
        } else if (fileName === 'views') {
            if (!currentConfig.views || typeof currentConfig.views !== 'object' || Array.isArray(currentConfig.views)) {
                currentConfig.views = {};
            }
        } else if (fileName === 'database') {
            if (!currentConfig.host) currentConfig = { host: 'localhost', port: '5432', dbname: '', user: 'postgres', password: '' };
        } else if (fileName === 'security') {
            currentConfig = {};
        }

        if (fileName === 'dashboard' || fileName === 'calendar' || fileName === 'workflows' || fileName === 'files') {
            currentItemKey = 'LAYOUT';
            renderSidebar();
            renderEditor('LAYOUT', null, false);
        } else if (fileName === 'schema') {
            currentItemKey = 'GLOBAL_SCHEMA';
            renderSidebar();
            renderEditor('GLOBAL_SCHEMA', null, false);
        } else if (fileName === 'database' || fileName === 'security' || fileName === 'views') {
            renderSidebar();
            renderEditor('SETTINGS', currentConfig, false);
        } else {
            renderSidebar();
            workspaceEl.innerHTML = `<h2>Select an item from the left menu to edit</h2>`;
        }
        // Freshly loaded config is clean; any subsequent edit flips the flag.
        markClean();
    } catch (err) {
        showStatusPill(btnSave, `Failed to load ${fileName}.json`, 'error');
    }
}

function addNewItem() {
    let newIndex = 0;
    if (currentFile === 'dashboard') {
        currentConfig.widgets.push({ id: "widget_" + Date.now(), type: "kpi_card", title: "New Widget", table: "", query: { type: "count", column: "id" }, icon: "", color: "#3b82f6", display_columns: [] });
        newIndex = currentConfig.widgets.length - 1;
    } else if (currentFile === 'calendar') {
        currentConfig.sources.push({ table: "", date_column: "", title_column: "", color: "#3b82f6", notify_before_days: 0, user_id_column: "", url_template: "" });
        newIndex = currentConfig.sources.length - 1;
    } else if (currentFile === 'workflows') {
        currentConfig.workflows.push({ id: "wf_" + Date.now(), title: "New Workflow", icon: "", steps: [] });
        newIndex = currentConfig.workflows.length - 1;
    }
    
    currentItemKey = newIndex;
    markDirty();
    renderSidebar();

    const items = currentFile === 'dashboard' ? currentConfig.widgets : currentFile === 'workflows' ? currentConfig.workflows : currentConfig.sources;
    renderEditor(newIndex, items[newIndex], true);
}

function clearConfig() {
    if (confirm(`Are you sure you want to completely clear the ${currentFile}.json configuration?`)) {
        if (currentFile === 'schema') currentConfig = { tables: {} };
        else if (currentFile === 'dashboard') currentConfig = { layout: { columns: "repeat(auto-fit, minmax(300px, 1fr))", gap: "20px" }, widgets: [], menu_name: 'Dashboard' };
        else if (currentFile === 'calendar') currentConfig = { sources: [], menu_name: 'Calendar' };
        else if (currentFile === 'workflows') currentConfig = { workflows: [], menu_name: 'Workflows' };
        else if (currentFile === 'files') currentConfig = { menu_name: 'Files' };

        markDirty();
        renderSidebar();
        workspaceEl.innerHTML = `<h2>Configuration cleared. Click "Save File" to apply!</h2>`;
    }
}

function renderSidebar() {
    itemListEl.innerHTML = '';
    
    if (currentFile === 'database' || currentFile === 'security' || currentFile === 'health' || currentFile === 'docs' || currentFile === 'users' || currentFile === 'backup' || currentFile === 'menu' || currentFile === 'audit' || currentFile === 'add_table' || currentFile === 'migrations' || currentFile === 'performance' || currentFile === 'cron' || currentFile === 'm2m' || currentFile === 'erd' || currentFile === 'demo' || currentFile === 'settings' || currentFile === 'csv_import') {
        document.getElementById('sidebarTitle').textContent = currentFile.charAt(0).toUpperCase() + currentFile.slice(1);
        const actionDiv = document.getElementById('sidebarActions');
        if (actionDiv) actionDiv.innerHTML = ''; 

        const li = document.createElement('li');
        let title = "Settings";
        if (currentFile === 'health') {
            const healthSections = [
                'PHP Environment',
                'PHP Extensions',
                'Security Functions',
                'Database',
                'Filesystem',
                'Config Files',
            ];
            const hdr = document.createElement('li');
            hdr.textContent = 'Health Check';
            hdr.style.cssText = 'font-weight:bold; border-bottom:2px solid var(--accent);';
            itemListEl.appendChild(hdr);
            healthSections.forEach((name, i) => {
                const item = document.createElement('li');
                item.textContent = name;
                item.style.cssText = 'cursor:pointer; font-size:13px; padding:6px 8px;';
                item.addEventListener('mouseover', () => item.style.background = 'var(--accent-light)');
                item.addEventListener('mouseout',  () => item.style.background = '');
                item.addEventListener('click', () => {
                    const el = document.getElementById(`health-section-${i}`);
                    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
                itemListEl.appendChild(item);
            });
            return;
        }
        if (currentFile === 'docs') title = "Read Documentation";
        if (currentFile === 'users') title = "System Users";
        if (currentFile === 'backup') title = "Backup Tables";
        if (currentFile === 'menu') title = "View Preview";
        if (currentFile === 'audit') title = "Audit & Snapshots";
        if (currentFile === 'settings') title = "Application Settings";
        if (currentFile === 'csv_import') title = "CSV Import";
        if (currentFile === 'add_table') title = "Add New Table";
        if (currentFile === 'migrations') title = "Migration History";
        if (currentFile === 'performance') {
            const perfSections = [
                'Missing Index Advisor',
                'Unused Indexes',
                'Slow Query Analyzer',
                'Table Stats & Bloat',
                'Database Health',
                'Schema Warnings',
            ];
            const hdr = document.createElement('li');
            hdr.textContent = 'Performance';
            hdr.style.cssText = 'font-weight:bold; border-bottom:2px solid var(--accent);';
            itemListEl.appendChild(hdr);
            perfSections.forEach((name, i) => {
                const item = document.createElement('li');
                item.textContent = name;
                item.style.cssText = 'cursor:pointer; font-size:13px; padding:6px 8px;';
                item.addEventListener('mouseover', () => item.style.background = 'var(--accent-light)');
                item.addEventListener('mouseout',  () => item.style.background = '');
                item.addEventListener('click', () => {
                    const el = document.getElementById(`perf-section-${i}`);
                    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
                itemListEl.appendChild(item);
            });
            return;
        }
        if (currentFile === 'm2m')  title = "M2M Builder";
        if (currentFile === 'erd')  title = "Schema Map";
        if (currentFile === 'demo') title = "Demo Systems";
        if (currentFile === 'cron') {
            const cronSections = [
                'Manual Run',
                'Run History',
                'Notification Stats',
                'Cron Setup',
                'Log Cleanup',
            ];
            const hdr = document.createElement('li');
            hdr.textContent = 'Cron Notifications';
            hdr.style.cssText = 'font-weight:bold; border-bottom:2px solid var(--accent);';
            itemListEl.appendChild(hdr);
            cronSections.forEach((name, i) => {
                const item = document.createElement('li');
                item.textContent = name;
                item.style.cssText = 'cursor:pointer; font-size:13px; padding:6px 8px;';
                item.addEventListener('mouseover', () => item.style.background = 'var(--accent-light)');
                item.addEventListener('mouseout',  () => item.style.background = '');
                item.addEventListener('click', () => {
                    const el = document.getElementById(`cron-section-${i}`);
                    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
                itemListEl.appendChild(item);
            });
            return;
        }

        li.textContent = title;
        li.style.fontWeight = 'bold';
        li.style.borderBottom = '2px solid var(--accent)';
        li.classList.add('active');
        itemListEl.appendChild(li);
        return;
    }

    if (currentFile === 'views') {
        document.getElementById('sidebarTitle').textContent = 'Views';
        const vActionDiv = document.getElementById('sidebarActions');
        if (vActionDiv) vActionDiv.innerHTML = '';
        const vLi = document.createElement('li');
        vLi.textContent = 'Views Configuration';
        vLi.style.cssText = 'font-weight:bold; border-bottom:2px solid var(--accent);';
        vLi.classList.add('active');
        itemListEl.appendChild(vLi);
        return;
    }

    document.getElementById('sidebarTitle').textContent = currentFile === 'schema' ? 'Tables' : currentFile === 'dashboard' ? 'Widgets' : currentFile === 'workflows' ? 'Workflows' : currentFile === 'files' ? 'Files Config' : 'Sources';
    
    let actionDiv = document.getElementById('sidebarActions');
    if (!actionDiv) {
        actionDiv = document.createElement('div'); actionDiv.id = 'sidebarActions'; actionDiv.style.marginBottom = '15px';
        itemListEl.parentNode.insertBefore(actionDiv, itemListEl);
    }
    actionDiv.innerHTML = ''; 

    if (currentFile === 'schema') {
        const btnSync = document.createElement('button');
        btnSync.className = 'btn-add'; btnSync.style.width = '100%'; btnSync.innerHTML = 'Sync DB Tables';
        btnSync.onclick = () => {
            const schemaName = prompt("Enter database schema name to sync:", "public");
            if (schemaName) syncSchemaTables(currentConfig, schemaName,
                (added) => {
                    if (added > 0) markDirty();
                    showStatusPill(btnSync, `Added ${added} new table${added === 1 ? '' : 's'}.`, added > 0 ? 'success' : 'info');
                    renderSidebar();
                    fetchGlobalSchema();
                },
                (err) => showStatusPill(btnSync, err, 'error'));
        };
        actionDiv.appendChild(btnSync);
    } else if (currentFile !== 'files') {
        const btnAdd = document.createElement('button');
        btnAdd.className = 'btn-add'; btnAdd.style.width = '100%'; 
        btnAdd.innerHTML = currentFile === 'dashboard' ? '+ Add New Widget' : currentFile === 'workflows' ? '+ Add New Workflow' : '+ Add New Source';
        btnAdd.onclick = addNewItem;
        actionDiv.appendChild(btnAdd);
    }

    if (currentFile !== 'files') {
        const btnClear = document.createElement('button');
        btnClear.className = 'btn-remove'; btnClear.style.width = '100%'; btnClear.style.marginTop = '10px'; btnClear.style.float = 'none';
        btnClear.innerHTML = 'Clear Entire Config'; btnClear.onclick = clearConfig;
        actionDiv.appendChild(btnClear);
    }

    if (currentFile === 'schema') {
        const globalLi = document.createElement('li');
        globalLi.textContent = 'Global Grid Settings';
        globalLi.style.cssText = 'font-weight:bold; border-bottom:2px solid var(--accent); cursor:pointer;';
        if (currentItemKey === 'GLOBAL_SCHEMA') globalLi.classList.add('active');
        globalLi.onclick = () => {
            currentItemKey = 'GLOBAL_SCHEMA';
            renderSidebar();
            renderEditor('GLOBAL_SCHEMA', null, false);
        };
        itemListEl.appendChild(globalLi);
    }

    if (currentFile === 'dashboard' || currentFile === 'calendar' || currentFile === 'workflows' || currentFile === 'files') {
        const layoutLi = document.createElement('li');
        layoutLi.textContent = "Global Settings"; layoutLi.style.fontWeight = 'bold'; layoutLi.style.borderBottom = '2px solid var(--accent)';
        if (currentItemKey === 'LAYOUT') layoutLi.classList.add('active');
        layoutLi.onclick = () => {
            currentItemKey = 'LAYOUT';
            renderSidebar();
            renderEditor('LAYOUT', null, false);
        };
        itemListEl.appendChild(layoutLi);
    }

    if (currentFile === 'files') {
        const managerLi = document.createElement('li');
        managerLi.textContent = "File Explorer"; managerLi.style.fontWeight = 'bold'; managerLi.style.borderBottom = '2px solid var(--accent)';
        if (currentItemKey === 'MANAGER') managerLi.classList.add('active');
        managerLi.onclick = () => {
            currentItemKey = 'MANAGER';
            renderSidebar();
            renderEditor('MANAGER', null, false);
        };
        itemListEl.appendChild(managerLi);
    }

    if (!currentConfig) return;

    if (currentFile === 'files') return; // Specific iteration bypass

    let itemsToIterate = currentFile === 'schema' ? (currentConfig.tables || {}) : currentFile === 'dashboard' ? (currentConfig.widgets || []) : currentFile === 'workflows' ? (currentConfig.workflows || []) : (currentConfig.sources || []);
    const isArray = Array.isArray(itemsToIterate);
    const keys = isArray ? itemsToIterate.map((_, i) => i) : Object.keys(itemsToIterate);

    keys.forEach((key, index) => {
        const item = itemsToIterate[key];
        const li = document.createElement('li');
        li.style.display = 'flex'; li.style.justifyContent = 'space-between'; li.style.alignItems = 'center';

        const textSpan = document.createElement('span');
        textSpan.textContent = currentFile === 'schema' ? (item.display_name || key) : currentFile === 'dashboard' ? (item.title || `Widget ${key}`) : currentFile === 'workflows' ? (item.title || `Workflow ${key}`) : (item.table || `Source ${key}`);
        li.appendChild(textSpan);

        const controls = document.createElement('div');
        const btnUp = document.createElement('button');
        btnUp.innerHTML = '^'; btnUp.style.cssText = 'background:none; border:none; cursor:pointer; font-size:14px; padding:0 2px;';
        if (index === 0) { btnUp.disabled = true; btnUp.style.opacity = '0.3'; btnUp.style.cursor = 'default'; }
        btnUp.onclick = (e) => {
            e.stopPropagation();
            if (isArray) {
                moveArrayItem(itemsToIterate, key, -1);
                if (currentItemKey === key) currentItemKey = key - 1; else if (currentItemKey === key - 1) currentItemKey = key;
            } else { currentConfig.tables = moveObjectKey(itemsToIterate, key, -1); }
            markDirty();
            renderSidebar();
        };

        const btnDown = document.createElement('button');
        btnDown.innerHTML = 'v'; btnDown.style.cssText = 'background:none; border:none; cursor:pointer; font-size:14px; padding:0 2px;';
        if (index === keys.length - 1) { btnDown.disabled = true; btnDown.style.opacity = '0.3'; btnDown.style.cursor = 'default'; }
        btnDown.onclick = (e) => {
            e.stopPropagation();
            if (isArray) {
                moveArrayItem(itemsToIterate, key, 1);
                if (currentItemKey === key) currentItemKey = key + 1; else if (currentItemKey === key + 1) currentItemKey = key;
            } else { currentConfig.tables = moveObjectKey(itemsToIterate, key, 1); }
            markDirty();
            renderSidebar();
        };

        controls.appendChild(btnUp); controls.appendChild(btnDown); li.appendChild(controls);

        if (String(currentItemKey) === String(key)) li.classList.add('active');

        li.onclick = () => { currentItemKey = key; renderSidebar(); renderEditor(key, item, isArray); };
        itemListEl.appendChild(li);
    });
}

function renderEditor(key, itemData, isArray) {
    workspaceEl.innerHTML = '';
    const ctx = { workspaceEl, currentConfig, getTableOptions, getColumnOptionsForTable, renderEditor, renderSidebar };
    
    if (['health', 'docs', 'users', 'backup', 'menu', 'audit', 'add_table', 'migrations', 'performance', 'cron', 'm2m', 'erd', 'demo', 'settings', 'csv_import'].includes(currentFile) || (currentFile === 'files' && key === 'MANAGER')) {
        btnSave.style.display = 'none';
    } else {
        btnSave.style.display = 'inline-block';
    }

    if (currentFile === 'database') return renderDatabaseEditor(key, itemData, isArray, ctx);
    if (currentFile === 'security') return renderSecurityEditor(key, itemData, isArray, ctx);
    if (currentFile === 'health') return renderHealthDashboard(ctx);
    if (currentFile === 'docs') return renderDocumentation(ctx);
    if (currentFile === 'users') return renderUsersEditor(ctx);
    if (currentFile === 'backup') return renderBackupPage(ctx);
    if (currentFile === 'audit') return renderAuditEditor(ctx);
    if (currentFile === 'add_table') return renderAddTableEditor(ctx);
    if (currentFile === 'migrations') return renderMigrationsPage(ctx);
    if (currentFile === 'performance') return renderPerformancePage(ctx);
    if (currentFile === 'cron') return renderCronPage(ctx);
    if (currentFile === 'm2m')  return renderM2mPage(ctx);
    if (currentFile === 'erd')  return renderErdPage(ctx);
    if (currentFile === 'demo') return renderDemoPage(ctx);
    if (currentFile === 'settings') return renderSettingsPage(ctx);
    if (currentFile === 'csv_import') return renderCsvImportPage(ctx);
    if (currentFile === 'views') return renderViewsEditor(ctx);
    if (currentFile === 'files' && key === 'MANAGER') return renderFilesEditor(ctx);

    if (currentFile === 'menu') {
        (async () => {
            workspaceEl.innerHTML = '';
            const h3 = document.createElement('h3');
            h3.style.marginTop = '0';
            h3.textContent = 'Menu Preview';
            workspaceEl.appendChild(h3);
            const desc = document.createElement('p');
            desc.style.cssText = 'color:var(--muted); font-size:14px; margin-bottom:20px;';
            desc.textContent = 'Drag to reorder. Drop onto an item to nest it (1 level). Changes save automatically.';
            workspaceEl.appendChild(desc);
            const preview = createFullMenuPreview(null);
            workspaceEl.appendChild(preview.el);
            try {
                const res = await fetch('api.php?action=menu_config');
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                preview.update(data);
            } catch (err) {
                preview.el.remove();
                const msg = document.createElement('p');
                msg.style.color = 'var(--danger)';
                msg.textContent = 'Failed to load menu config: ' + escapeHtml(err.message);
                workspaceEl.appendChild(msg);
            }
        })();
        return;
    }

    if (key === 'LAYOUT') {
        if (currentFile === 'dashboard') return renderDashboardLayout(ctx);
        if (currentFile === 'calendar') {
            return renderGlobalSettings(ctx, { title: 'Calendar Global Settings', defaultMenuName: 'Calendar' });
        }
        if (currentFile === 'workflows') {
            return renderGlobalSettings(ctx, { title: 'Workflows Global Settings', defaultMenuName: 'Workflows' });
        }
        if (currentFile === 'files') {
            return renderGlobalSettings(ctx, { title: 'Files Global Settings', defaultMenuName: 'Files' });
        }
    }
    
    if (currentFile === 'schema' && key === 'GLOBAL_SCHEMA') return renderSchemaGlobalSettings(currentConfig, ctx);
    if (currentFile === 'schema') return renderSchemaEditor(key, itemData, ctx);

    const headerDiv = document.createElement('div');
    headerDiv.style.display = 'flex'; headerDiv.style.justifyContent = 'space-between'; headerDiv.style.alignItems = 'center';
    const title = document.createElement('h3'); 
    title.textContent = `Edit: ${isArray ? 'Item ' + key : key}`;
    headerDiv.appendChild(title);
    
    const btnDelete = document.createElement('button');
    btnDelete.className = 'btn-remove'; btnDelete.textContent = 'Delete this item';
    btnDelete.onclick = () => {
        if (confirm('Are you sure?')) {
            if (currentFile === 'dashboard') currentConfig.widgets.splice(key, 1);
            else if (currentFile === 'workflows') currentConfig.workflows.splice(key, 1);
            else currentConfig.sources.splice(key, 1);
            currentItemKey = null;
            markDirty();
            workspaceEl.innerHTML = '<h2>Item deleted. Save file to apply.</h2>';
            renderSidebar();
        }
    };
    headerDiv.appendChild(btnDelete);
    workspaceEl.appendChild(headerDiv);

    if (currentFile === 'dashboard') renderDashboardEditor(key, itemData, isArray, ctx);
    else if (currentFile === 'calendar') renderCalendarEditor(key, itemData, isArray, ctx);
    else if (currentFile === 'workflows') renderWorkflowsEditor(key, itemData, isArray, ctx);
}

// Show pending-release-migrations banner if any versions are unresolved
(async () => {
    try {
        const res  = await fetch('api_migrations.php?action=scan');
        const data = await res.json();
        if (data.status !== 'success') return;
        const pending = (data.versions || []).filter(v => v.status === 'pending');
        if (pending.length === 0) return;
        const banner = document.getElementById('mig-pending-banner');
        if (!banner) return;
        const noun = pending.length === 1 ? 'release' : 'releases';
        banner.querySelector('.mig-pending-banner-text').textContent =
            pending.length + ' pending release migration' + (pending.length > 1 ? 's' : '') +
            ' (' + pending.map(v => 'v' + v.version).join(', ') + '). Go to System → Migrations to apply.';
        banner.style.display = 'block';
    } catch {
        // silently ignore — banner is non-critical
    }
})();

btnSave.addEventListener('click', async () => {
    if (!currentConfig) return;
    try {
        const response = await fetch(`api.php?action=save&file=${currentFile}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify(currentConfig)
        });
        const result = await response.json();

        if (result.status === 'success') {
            markClean();
            showStatusPill(btnSave, `${currentFile}.json saved`, 'success');
            fetchGlobalSchema();
        } else {
            showStatusPill(btnSave, 'Error saving: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (err) {
        showStatusPill(btnSave, 'Failed to save changes.', 'error');
    }
});