// admin/js/app.js — Admin panel SPA controller / router (loaded by admin/index.php)
// Builds the sidebar tabs and dispatches each to its render*() module (schema, dashboard, users, rag, performance, cron, ...); owns currentConfig, dirty-state tracking and the Save File action. Exports showStatusPill, markDirty, isMysqlTable.
import { apiFetch } from '../../assets/js/util/api.js';
import { moveArrayItem, moveObjectKey, renderGlobalSettings, createFullMenuPreview } from './ui.js';
import { syncSchemaTables, renderSchemaEditor, renderSchemaGlobalSettings, renderExternalTablesView } from './schema.js';
import { renderDashboardLayout, renderDashboardEditor, initDashboardUI } from './dashboard.js';
import { renderCalendarEditor } from './calendar.js';
import { renderBoardEditor } from './board.js';
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
import { renderUserRecordsEditor } from './user_records_editor.js';
import { renderPrintEditor } from './print_editor.js';
import { renderDemoPage } from './demo.js';
import { renderSettingsPage } from './settings.js';
import { renderCsvImportPage } from './csv_import.js';
import { renderRagPage } from './rag.js';
import { renderAutomationsPage, autoActions } from './automations.js';
import { renderOverviewPage } from './overview.js';
import { renderFdwPage } from './fdw.js';
import { renderAnonymizationPage } from './anonymization.js';

let currentConfig = null;
let currentFile = 'overview';
let currentItemKey = null;
let globalSchemaObj = null;
let isDirty = false;
// Set by a tab's render*() via ctx.setSaveHandler() when it needs #btnSave to
// call its own validating endpoint instead of the generic config_files.php save.
let activeSaveHandler = null;
function setSaveHandler(fn) { activeSaveHandler = fn; }

// Names of tables routed from external MySQL (config/mysql_gateway.json).
// Used to keep PostgreSQL and external MySQL tables in separate admin tabs.
let mysqlTableSet = new Set();
export function isMysqlTable(name) { return mysqlTableSet.has(name); }

async function refreshMysqlTableSet() {
    try {
        const res = await apiFetch('api_fdw.php?action=mysql_status');
        const data = await res.json();
        mysqlTableSet = new Set(data.mysql_tables || []);
    } catch {
        mysqlTableSet = new Set();
    }
}

const itemPanelEl = document.getElementById('itemPanel');
const workspaceEl = document.getElementById('editorForm');
const btnSave = document.getElementById('btnSave');
const tabs = document.querySelectorAll('.admin-tab');

// Tabs that save immediately via API — no config file involved, never dirty.
const NON_CONFIG_TABS = new Set(['overview', 'users', 'security', 'health', 'backup', 'database', 'audit', 'add_table', 'migrations', 'performance', 'cron', 'm2m', 'erd', 'demo', 'settings', 'csv_import', 'rag', 'fdw', 'anonymization']);

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
        success: { bg: 'rgba(43,147,72,0.12)', fg: '#2b9348', border: '#2b9348' },
        error:   { bg: 'rgba(208,0,0,0.08)', fg: '#a80000', border: '#d00000' },
        info:    { bg: '#DDEAF4', fg: '#1E293B', border: '#CBD5E1' },
    }[variant] || { bg: '#DDEAF4', fg: '#1E293B', border: '#CBD5E1' };
    pill.style.cssText = `display:inline-flex; align-items:center; gap:6px; margin-left:10px; padding:4px 10px; background:${colors.bg}; color:${colors.fg}; border:1px solid ${colors.border}; border-radius:999px; font-size:12px; font-weight:600; transition:opacity .3s;`;
    anchor.insertAdjacentElement('afterend', pill);

    const ttl = variant === 'error' ? 6000 : 3000;
    setTimeout(() => {
        pill.style.opacity = '0';
        setTimeout(() => pill.remove(), 300);
    }, ttl);
}

// Utility function to escape HTML strings safely against XSS
import { escHtml as escapeHtml } from '../../assets/js/util/esc.js';

// Retrieve the CSRF token from the meta tag

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
            // Invalidate any in-flight async render (e.g. overview awaiting its
            // stats fetch) so it cannot clobber the newly selected tab's DOM.
            workspaceEl._renderId = (workspaceEl._renderId || 0) + 1;
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
        content.style.cssText = 'overflow-y:auto;flex:1;font-size:13px;line-height:1.6;border:1px solid #CBD5E1;border-radius:4px;padding:12px;background:#F4F7F9;';
        content.textContent = 'Running…';
        box.append(header, content);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

        try {
            const res = await apiFetch('api.php?action=run_cron_notifications', {
                method: 'POST',
            });
            const data = await res.json();
            if (data.status === 'success') {
                content.innerHTML = data.output;
            } else {
                content.textContent = 'Error: ' + (data.error || 'Unknown error');
                content.style.color = '#a80000';
            }
        } catch (err) {
            content.textContent = 'Request failed: ' + err.message;
            content.style.color = '#a80000';
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
            const res = await apiFetch('api.php?action=import', {
                method: 'POST',
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
        const res = await apiFetch('api.php?action=get&file=schema');
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

// Enum-typed columns of a table — used by the Board editor to offer sensible
// status-column candidates whose values map cleanly onto board lanes.
function getEnumColumnsForTable(tableName) {
    const options = [];
    const cols = globalSchemaObj?.tables?.[tableName]?.columns;
    if (cols) {
        for (const c in cols) {
            if ((cols[c].type || '').toLowerCase() === 'enum') {
                options.push({ value: c, label: cols[c].display_name || c });
            }
        }
    }
    return options;
}

function getColumnMeta(tableName, colName) {
    return globalSchemaObj?.tables?.[tableName]?.columns?.[colName] || null;
}

async function loadConfigFile(fileName) {
    // A prior tab may have registered its own save routine; every tab switch
    // starts fresh so a stale handler can never fire for the wrong tab.
    activeSaveHandler = null;
    if (fileName === 'overview' || fileName === 'health' || fileName === 'docs' || fileName === 'users' || fileName === 'backup' || fileName === 'menu' || fileName === 'audit' || fileName === 'add_table' || fileName === 'migrations' || fileName === 'performance' || fileName === 'cron' || fileName === 'm2m' || fileName === 'erd' || fileName === 'demo' || fileName === 'settings' || fileName === 'csv_import' || fileName === 'rag' || fileName === 'fdw' || fileName === 'anonymization' || fileName === 'print') {
        currentConfig = null;
        renderSidebar();
        renderEditor(fileName.toUpperCase(), null, false);
        return;
    }

    try {
        const response = await apiFetch(`api.php?action=get&file=${fileName}`);
        currentConfig = await response.json();

        if (fileName === 'schema') {
            if (!currentConfig.tables || Array.isArray(currentConfig.tables)) currentConfig.tables = {};
            await refreshMysqlTableSet();
        } else if (fileName === 'dashboard') {
            if (!currentConfig.layout) currentConfig.layout = { columns: "repeat(auto-fit, minmax(300px, 1fr))", gap: "20px" };
            if (!currentConfig.widgets || !Array.isArray(currentConfig.widgets)) currentConfig.widgets = [];
            if (!currentConfig.menu_name) currentConfig.menu_name = 'Dashboard';
        } else if (fileName === 'calendar') {
            if (!currentConfig.sources || !Array.isArray(currentConfig.sources)) currentConfig.sources = [];
            if (!currentConfig.menu_name) currentConfig.menu_name = 'Calendar';
        } else if (fileName === 'board') {
            if (!Array.isArray(currentConfig.card_columns)) currentConfig.card_columns = [];
            if (!currentConfig.menu_name) currentConfig.menu_name = 'Board';
        } else if (fileName === 'workflows') {
            if (!currentConfig.workflows || !Array.isArray(currentConfig.workflows)) currentConfig.workflows = [];
            if (!currentConfig.menu_name) currentConfig.menu_name = 'Workflows';
        } else if (fileName === 'automations') {
            if (!currentConfig.automations || !Array.isArray(currentConfig.automations)) currentConfig.automations = [];
        } else if (fileName === 'files') {
            if (!currentConfig.menu_name) currentConfig.menu_name = 'Files';
        } else if (fileName === 'views') {
            if (!currentConfig.views || typeof currentConfig.views !== 'object' || Array.isArray(currentConfig.views)) {
                currentConfig.views = {};
            }
        } else if (fileName === 'user_records') {
            if (!currentConfig.columns || typeof currentConfig.columns !== 'object' || Array.isArray(currentConfig.columns)) {
                currentConfig.columns = {};
            }
            if (typeof currentConfig.limit !== 'number' || currentConfig.limit < 0) {
                currentConfig.limit = 20;
            }
        } else if (fileName === 'database') {
            if (!currentConfig.host) currentConfig = { host: 'localhost', port: '5432', dbname: '', user: 'postgres', password: '' };
        } else if (fileName === 'security') {
            currentConfig = {};
        }

        if (fileName === 'schema' || fileName === 'dashboard' || fileName === 'calendar' || fileName === 'workflows') {
            currentItemKey = null;
            renderSidebar();
            renderItemCards();
        } else if (fileName === 'automations') {
            currentItemKey = null;
            renderSidebar();
            renderEditor('ALL', null, false);
        } else if (fileName === 'files') {
            currentItemKey = 'LAYOUT';
            renderSidebar();
            renderEditor('LAYOUT', null, false);
        } else if (fileName === 'database' || fileName === 'security' || fileName === 'views' || fileName === 'board' || fileName === 'user_records') {
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
        currentConfig.widgets.push({ id: "widget_" + Date.now(), type: "stat_card", title: "New Widget", table: "", query: { type: "count", column: "id" }, color: "#64748B", display_columns: [] });
        newIndex = currentConfig.widgets.length - 1;
    } else if (currentFile === 'calendar') {
        currentConfig.sources.push({ table: "", date_column: "", title_column: "", color: "#64748B", notify_before_days: 0, user_id_column: "", url_template: "" });
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
    itemPanelEl.innerHTML = '';

    const fullPageTabs = new Set([
        'overview', 'database', 'security', 'health', 'docs', 'users', 'backup',
        'menu', 'audit', 'add_table', 'migrations', 'performance', 'cron',
        'm2m', 'erd', 'demo', 'settings', 'csv_import', 'rag', 'views', 'fdw', 'board', 'anonymization', 'print',
    ]);

    if (fullPageTabs.has(currentFile)) {
        return;
    }

    const isCardTab = currentFile === 'schema' || currentFile === 'dashboard' || currentFile === 'calendar' || currentFile === 'workflows';

    // ── Action buttons row ───────────────────────────────────────────────────
    const actionsRow = document.createElement('div');
    actionsRow.className = 'item-panel-actions';

    if (currentFile === 'schema') {
        const btnSync = document.createElement('button');
        btnSync.type = 'button';
        btnSync.className = 'btn-add';
        btnSync.textContent = 'Sync DB Tables';
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
        actionsRow.appendChild(btnSync);
        const btnClear = document.createElement('button');
        btnClear.type = 'button'; btnClear.className = 'btn-remove'; btnClear.style.float = 'none';
        btnClear.textContent = 'Clear Entire Config'; btnClear.onclick = clearConfig;
        actionsRow.appendChild(btnClear);
    } else if (currentFile === 'automations') {
        const btnNew = document.createElement('button');
        btnNew.type = 'button'; btnNew.className = 'btn-add';
        btnNew.textContent = '+ New Automation';
        btnNew.onclick = () => { if (autoActions.openNew) autoActions.openNew(null); };
        actionsRow.appendChild(btnNew);
    } else if (currentFile !== 'files') {
        const btnAdd = document.createElement('button');
        btnAdd.type = 'button'; btnAdd.className = 'btn-add';
        btnAdd.textContent = currentFile === 'dashboard' ? '+ Add New Widget' : currentFile === 'workflows' ? '+ Add New Workflow' : '+ Add New Source';
        btnAdd.onclick = addNewItem;
        actionsRow.appendChild(btnAdd);
        const btnClear = document.createElement('button');
        btnClear.type = 'button'; btnClear.className = 'btn-remove'; btnClear.style.float = 'none';
        btnClear.textContent = 'Clear Entire Config'; btnClear.onclick = clearConfig;
        actionsRow.appendChild(btnClear);
    }

    if (actionsRow.children.length > 0) {
        itemPanelEl.appendChild(actionsRow);
    }

    // ── Tab bar ──────────────────────────────────────────────────────────────
    const itemsRow = document.createElement('div');
    itemsRow.className = 'item-panel-items';

    if (currentFile === 'schema') {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'item-btn' + (currentItemKey === 'GLOBAL_SCHEMA' ? ' active' : '');
        btn.textContent = 'Global Grid Settings';
        btn.onclick = () => { currentItemKey = 'GLOBAL_SCHEMA'; renderSidebar(); renderEditor('GLOBAL_SCHEMA', null, false); };
        itemsRow.appendChild(btn);
    }

    if (currentFile === 'dashboard' || currentFile === 'calendar' || currentFile === 'workflows' || currentFile === 'files' || currentFile === 'automations') {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'item-btn' + (currentItemKey === 'LAYOUT' ? ' active' : '');
        btn.textContent = 'Global Settings';
        btn.onclick = () => { currentItemKey = 'LAYOUT'; renderSidebar(); renderEditor('LAYOUT', null, false); };
        itemsRow.appendChild(btn);
    }

    if (currentFile === 'files') {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'item-btn' + (currentItemKey === 'MANAGER' ? ' active' : '');
        btn.textContent = 'File Explorer';
        btn.onclick = () => { currentItemKey = 'MANAGER'; renderSidebar(); renderEditor('MANAGER', null, false); };
        itemsRow.appendChild(btn);
        itemPanelEl.appendChild(itemsRow);
        return;
    }

    // Card tabs and automations: prepend "All X" button then return
    if (isCardTab || currentFile === 'automations') {
        const btnAll = document.createElement('button');
        btnAll.type = 'button';
        btnAll.className = 'item-btn' + (currentItemKey === null ? ' active' : '');
        btnAll.textContent = currentFile === 'schema'       ? 'All PostgreSQL tables'
                           : currentFile === 'dashboard'    ? 'All Widgets'
                           : currentFile === 'workflows'    ? 'All Workflows'
                           : currentFile === 'automations'  ? 'All Automations'
                           : 'All Sources';
        btnAll.onclick = () => {
            currentItemKey = null;
            renderSidebar();
            if (currentFile === 'automations') renderEditor('ALL', null, false);
            else renderItemCards();
        };
        itemsRow.insertBefore(btnAll, itemsRow.firstChild);

        if (currentFile === 'schema') {
            const btnExt = document.createElement('button');
            btnExt.type = 'button';
            btnExt.className = 'item-btn' + (currentItemKey === 'EXTERNAL_TABLES' ? ' active' : '');
            btnExt.textContent = 'All External MySQL Tables';
            btnExt.onclick = () => {
                currentItemKey = 'EXTERNAL_TABLES';
                renderSidebar();
                renderEditor('EXTERNAL_TABLES', null, false);
            };
            btnAll.insertAdjacentElement('afterend', btnExt);
        }

        itemPanelEl.appendChild(itemsRow);
        return;
    }

    if (!currentConfig) {
        itemPanelEl.appendChild(itemsRow);
        return;
    }

    // Calendar sources: tab bar with up/down reorder buttons
    let itemsToIterate = (currentConfig.sources || []);
    const isArray = Array.isArray(itemsToIterate);
    const keys = isArray ? itemsToIterate.map((_, i) => i) : Object.keys(itemsToIterate);

    keys.forEach((key, index) => {
        const item = itemsToIterate[key];
        const wrapper = document.createElement('div');
        wrapper.className = 'item-btn-wrapper';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'item-btn' + (String(currentItemKey) === String(key) ? ' active' : '');
        btn.textContent = currentFile === 'workflows' ? (item.title || `Workflow ${key}`) : (item.table || `Source ${key}`);
        btn.onclick = () => { currentItemKey = key; renderSidebar(); renderEditor(key, item, isArray); };
        wrapper.appendChild(btn);

        const btnUp = document.createElement('button');
        btnUp.type = 'button';
        btnUp.className = 'item-order-btn';
        btnUp.textContent = '^';
        btnUp.disabled = index === 0;
        btnUp.onclick = (e) => {
            e.stopPropagation();
            moveArrayItem(itemsToIterate, key, -1);
            if (currentItemKey === key) currentItemKey = key - 1; else if (currentItemKey === key - 1) currentItemKey = key;
            markDirty();
            renderSidebar();
        };

        const btnDown = document.createElement('button');
        btnDown.type = 'button';
        btnDown.className = 'item-order-btn';
        btnDown.textContent = 'v';
        btnDown.disabled = index === keys.length - 1;
        btnDown.onclick = (e) => {
            e.stopPropagation();
            moveArrayItem(itemsToIterate, key, 1);
            if (currentItemKey === key) currentItemKey = key + 1; else if (currentItemKey === key + 1) currentItemKey = key;
            markDirty();
            renderSidebar();
        };

        wrapper.appendChild(btnUp);
        wrapper.appendChild(btnDown);
        itemsRow.appendChild(wrapper);
    });

    itemPanelEl.appendChild(itemsRow);
}

// ── Card-based item list (schema / dashboard / calendar) ─────────────────────

function renderItemCards() {
    workspaceEl.innerHTML = '';
    btnSave.style.display = 'inline-block';

    if (!currentConfig) return;

    const isSchema    = currentFile === 'schema';
    const isDashboard = currentFile === 'dashboard';
    const isWorkflows = currentFile === 'workflows';

    const rawItems    = isSchema    ? (currentConfig.tables    || {})
                      : isDashboard ? (currentConfig.widgets   || [])
                      : isWorkflows ? (currentConfig.workflows || [])
                      : (currentConfig.sources || []);
    const isArray     = Array.isArray(rawItems);

    function getKeys(items) {
        return isArray ? items.map((_, i) => i) : Object.keys(items);
    }

    const list = document.createElement('div');
    list.style.cssText = 'display:flex; flex-direction:column; gap:8px; max-width:900px;';
    workspaceEl.appendChild(list);

    function redraw() {
        const fresh    = isSchema    ? (currentConfig.tables    || {})
                       : isDashboard ? (currentConfig.widgets   || [])
                       : isWorkflows ? (currentConfig.workflows || [])
                       : (currentConfig.sources || []);
        // The schema "All PostgreSQL tables" view lists native PG tables only;
        // external MySQL tables live in their own "All External MySQL Tables" tab.
        const freshKeys = getKeys(fresh).filter(k => !(isSchema && isMysqlTable(k)));
        list.innerHTML = '';
        if (freshKeys.length === 0) {
            const empty = document.createElement('p');
            empty.style.cssText = 'color:var(--muted); text-align:center; padding:40px;';
            empty.textContent = isSchema    ? 'No tables defined. Use "Sync DB Tables" to get started.'
                              : isDashboard ? 'No widgets yet. Click "+ Add New Widget".'
                              : isWorkflows ? 'No workflows yet. Click "+ Add New Workflow".'
                              : 'No sources yet. Click "+ Add New Source".';
            list.appendChild(empty);
            return;
        }
        freshKeys.forEach((k, idx) =>
            list.appendChild(buildItemCard(k, fresh[k], idx, freshKeys.length, isArray, fresh, redraw))
        );
    }

    redraw();
}

function buildItemCard(key, item, index, total, isArray, itemsRef, redraw) {
    const isSchema    = currentFile === 'schema';
    const isDashboard = currentFile === 'dashboard';
    const isWorkflows = currentFile === 'workflows';

    const card = document.createElement('div');
    card.style.cssText = 'border:1px solid var(--border); border-radius:var(--radius); overflow:hidden;';

    // ── Header ───────────────────────────────────────────────────────────────
    const hdr = document.createElement('div');
    hdr.style.cssText = 'display:flex; align-items:center; gap:8px; padding:10px 14px; background:var(--panel); cursor:pointer;';

    const toggleBtn = document.createElement('button');
    toggleBtn.type = 'button';
    toggleBtn.textContent = '▶';
    toggleBtn.style.cssText = 'background:none; border:none; font-size:11px; cursor:pointer; color:var(--muted); padding:0 2px; flex-shrink:0; line-height:1; box-shadow:none;';

    const nameSpan = document.createElement('strong');
    nameSpan.style.cssText = 'font-size:14px; color:var(--text); flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;';
    nameSpan.textContent = isSchema    ? (item.display_name || key)
                         : isDashboard ? (item.title || `Widget ${key}`)
                         : isWorkflows ? (item.title || `Workflow ${key}`)
                         : (item.table || `Source ${key}`);

    hdr.appendChild(toggleBtn);
    hdr.appendChild(nameSpan);

    if (isSchema) {
        const keySpan = document.createElement('span');
        keySpan.style.cssText = 'font-size:11px; color:var(--muted); font-family:monospace; flex-shrink:0;';
        keySpan.textContent = `(${key})`;
        hdr.appendChild(keySpan);
    }

    const btnUp = document.createElement('button');
    btnUp.type = 'button';
    btnUp.title = 'Move up';
    btnUp.textContent = '▲';
    btnUp.style.cssText = 'background:none; border:none; cursor:pointer; font-size:11px; padding:2px 5px; color:var(--muted); flex-shrink:0; box-shadow:none;';
    if (index === 0) { btnUp.disabled = true; btnUp.style.opacity = '0.3'; }
    btnUp.onclick = e => {
        e.stopPropagation();
        if (isArray) moveArrayItem(itemsRef, key, -1);
        else currentConfig.tables = moveObjectKey(itemsRef, key, -1);
        markDirty();
        redraw();
    };

    const btnDown = document.createElement('button');
    btnDown.type = 'button';
    btnDown.title = 'Move down';
    btnDown.textContent = '▼';
    btnDown.style.cssText = 'background:none; border:none; cursor:pointer; font-size:11px; padding:2px 5px; color:var(--muted); flex-shrink:0; box-shadow:none;';
    if (index === total - 1) { btnDown.disabled = true; btnDown.style.opacity = '0.3'; }
    btnDown.onclick = e => {
        e.stopPropagation();
        if (isArray) moveArrayItem(itemsRef, key, 1);
        else currentConfig.tables = moveObjectKey(itemsRef, key, 1);
        markDirty();
        redraw();
    };

    // Delete — all card tabs except schema (which has its own delete button inside the editor)
    if (!isSchema) {
        const btnDel = document.createElement('button');
        btnDel.type = 'button';
        btnDel.title = 'Delete';
        btnDel.textContent = '✕';
        btnDel.style.cssText = 'background:none; border:none; cursor:pointer; font-size:13px; padding:2px 5px; color:var(--danger); flex-shrink:0; box-shadow:none;';
        btnDel.onclick = e => {
            e.stopPropagation();
            const label = isDashboard ? (item.title || `Widget ${key}`)
                        : isWorkflows ? (item.title || `Workflow ${key}`)
                        : (item.table || `Source ${key}`);
            if (!confirm(`Delete "${label}"?`)) return;
            if (isDashboard)      currentConfig.widgets.splice(key, 1);
            else if (isWorkflows) currentConfig.workflows.splice(key, 1);
            else                  currentConfig.sources.splice(key, 1);
            markDirty();
            redraw();
        };
        hdr.appendChild(btnUp);
        hdr.appendChild(btnDown);
        hdr.appendChild(btnDel);
    } else {
        hdr.appendChild(btnUp);
        hdr.appendChild(btnDown);
    }

    card.appendChild(hdr);

    // ── Body ─────────────────────────────────────────────────────────────────
    const body = document.createElement('div');
    body.style.cssText = 'display:none; padding:20px; border-top:1px solid var(--border);';
    card.appendChild(body);

    let rendered = false;

    function openCard() {
        body.style.display = 'block';
        toggleBtn.textContent = '▼';
        hdr.style.borderBottom = 'none';
        if (!rendered) {
            rendered = true;
            renderEditorIntoCard(key, item, isArray, body, nameSpan, redraw);
        }
    }

    function closeCard() {
        body.style.display = 'none';
        toggleBtn.textContent = '▶';
    }

    toggleBtn.addEventListener('click', e => {
        e.stopPropagation();
        body.style.display === 'block' ? closeCard() : openCard();
    });

    hdr.addEventListener('click', () => {
        body.style.display === 'block' ? closeCard() : openCard();
    });

    return card;
}

function renderEditorIntoCard(key, item, isArray, bodyEl, nameSpan, redraw) {
    const isSchema    = currentFile === 'schema';
    const isDashboard = currentFile === 'dashboard';
    const isWorkflows = currentFile === 'workflows';

    const cardCtx = {
        workspaceEl: bodyEl,
        currentConfig,
        getTableOptions,
        getColumnOptionsForTable,
        renderEditor: (k, d, arr) => {
            bodyEl.innerHTML = '';
            renderEditorIntoCard(k, d, arr !== undefined ? arr : isArray, bodyEl, nameSpan, redraw);
        },
        renderSidebar: isSchema
            ? redraw
            : () => {
                nameSpan.textContent = isDashboard ? (item.title || `Widget ${key}`)
                                     : isWorkflows ? (item.title || `Workflow ${key}`)
                                     : (item.table || `Source ${key}`);
            },
    };

    if (isSchema)         renderSchemaEditor(key, item, cardCtx);
    else if (isDashboard) renderDashboardEditor(key, item, isArray, cardCtx);
    else if (isWorkflows) renderWorkflowsEditor(key, item, isArray, cardCtx);
    else                  renderCalendarEditor(key, item, isArray, cardCtx);
}

function renderEditor(key, itemData, isArray) {
    workspaceEl.innerHTML = '';
    const ctx = { workspaceEl, currentConfig, getTableOptions, getColumnOptionsForTable, getEnumColumnsForTable, getColumnMeta, renderEditor, renderSidebar, setSaveHandler };

    if (['overview', 'health', 'docs', 'users', 'backup', 'menu', 'audit', 'add_table', 'migrations', 'performance', 'cron', 'm2m', 'erd', 'demo', 'settings', 'csv_import', 'rag', 'fdw', 'automations', 'anonymization'].includes(currentFile) || (currentFile === 'files' && key === 'MANAGER') || (currentFile === 'schema' && key === 'EXTERNAL_TABLES')) {
        btnSave.style.display = 'none';
    } else {
        btnSave.style.display = 'inline-block';
    }

    if (currentFile === 'overview') return renderOverviewPage(ctx);
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
    if (currentFile === 'rag') return renderRagPage(ctx);
    if (currentFile === 'fdw') return renderFdwPage(ctx);
    if (currentFile === 'anonymization') return renderAnonymizationPage(ctx);
    if (currentFile === 'print') return renderPrintEditor(ctx);
    if (currentFile === 'automations') {
        if (key === 'LAYOUT') {
            const msg = document.createElement('p');
            msg.style.cssText = 'color:var(--muted); padding:20px;';
            msg.textContent = 'Automations have no global configuration settings.';
            workspaceEl.appendChild(msg);
            return;
        }
        return renderAutomationsPage(ctx);
    }
    if (currentFile === 'views') return renderViewsEditor(ctx);
    if (currentFile === 'user_records') return renderUserRecordsEditor(ctx);
    if (currentFile === 'board') return renderBoardEditor(ctx);
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
                const res = await apiFetch('api.php?action=menu_config');
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
    
    if (currentFile === 'schema' && key === 'EXTERNAL_TABLES') return renderExternalTablesView(ctx);
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
        const res  = await apiFetch('api_migrations.php?action=scan');
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

// Validate a workflows config before saving — a workflow cannot be saved while
// any step is incomplete (missing name or target table) or it has no steps at
// all. This is database-agnostic (pure config check) so it guards PostgreSQL and
// MySQL-routed step tables alike. Returns an error string, or null when valid.
function validateWorkflowsConfig(config) {
    const workflows = config.workflows || [];
    for (let w = 0; w < workflows.length; w++) {
        const wf = workflows[w];
        const label = (wf.title && wf.title.trim()) || `Workflow ${w + 1}`;
        const steps = wf.steps || [];
        if (steps.length === 0) {
            return `"${label}" has no steps — add at least one step or remove the workflow.`;
        }
        for (let s = 0; s < steps.length; s++) {
            const step = steps[s] || {};
            if (!step.title || step.title.trim() === '') {
                return `"${label}" — Step ${s + 1} is missing a step name.`;
            }
            if (!step.table || step.table.trim() === '') {
                return `"${label}" — Step ${s + 1} ("${step.title.trim()}") has no target table.`;
            }
        }
    }
    return null;
}

btnSave.addEventListener('click', async () => {
    if (activeSaveHandler) {
        try {
            const result = await activeSaveHandler();
            if (result.status === 'success') {
                markClean();
                showStatusPill(btnSave, result.message || `${currentFile}.json saved`, 'success');
            } else {
                showStatusPill(btnSave, 'Error saving: ' + (result.error || 'Unknown error'), 'error');
            }
        } catch {
            showStatusPill(btnSave, 'Failed to save changes.', 'error');
        }
        return;
    }

    if (!currentConfig) return;

    if (currentFile === 'workflows') {
        const err = validateWorkflowsConfig(currentConfig);
        if (err) {
            showStatusPill(btnSave, err, 'error');
            return;
        }
    }

    try {
        const response = await apiFetch(`api.php?action=save&file=${currentFile}`, {
            method: 'POST',
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