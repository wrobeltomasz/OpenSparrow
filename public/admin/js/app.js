// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { moveArrayItem, moveObjectKey, renderGlobalSettings, createFullMenuPreview } from './ui.js';

import { syncSchemaTables, renderSchemaEditor, renderSchemaGlobalSettings } from './schema.js';
import { renderDashboardLayout, renderDashboardEditor } from './dashboard.js';
import { renderCalendarEditor } from './calendar.js';
import { renderBoardEditor } from './board.js';
import { renderWorkflowsEditor } from './workflows.js';

const PAGE_MODULES = {
    overview:      () => import('./overview.js').then(m => m.renderOverviewPage),
    health:        () => import('./health.js').then(m => m.renderHealthDashboard),
    docs:          () => import('./docs.js').then(m => m.renderDocumentation),
    users:         () => import('./users.js').then(m => m.renderUsersEditor),
    backup:        () => import('./backup.js').then(m => m.renderBackupPage),
    migrations:    () => import('./migrations.js').then(m => m.renderMigrationsPage),
    performance:   () => import('./performance.js').then(m => m.renderPerformancePage),
    cron:          () => import('./cron.js').then(m => m.renderCronPage),
    demo:          () => import('./demo.js').then(m => m.renderDemoPage),
    settings:      () => import('./settings.js').then(m => m.renderSettingsPage),
    csv_import:    () => import('./csv_import.js').then(m => m.renderCsvImportPage),
    rag:           () => import('./rag.js').then(m => m.renderRagPage),
    anonymization: () => import('./anonymization.js').then(m => m.renderAnonymizationPage),
    etl:           () => import('./etl.js').then(m => m.renderEtlPage),
    print:         () => import('./print_editor.js').then(m => m.renderPrintEditor),
    views:         () => import('./views_editor.js').then(m => m.renderViewsEditor),
    user_records:  () => import('./user_records_editor.js').then(m => m.renderUserRecordsEditor),
    clickstats:    () => import('./clickstats.js').then(m => m.renderClickstatsPage),
};

let currentConfig = null;
let currentFile = 'overview';
let currentItemKey = null;
let globalSchemaObject = null;
let isDirty = false;

let activeSaveHandler = null;
function setSaveHandler(fn) { activeSaveHandler = fn; }

const itemPanelElement = document.getElementById('itemPanel');
const workspaceElement = document.getElementById('editorForm');
const buttonSave = document.getElementById('btnSave');
const tabs = document.querySelectorAll('.admin-tab');

const NON_CONFIG_TABS = new Set(['overview', 'users', 'security', 'health', 'backup', 'migrations', 'performance', 'cron', 'demo', 'settings', 'csv_import', 'rag', 'etl', 'anonymization', 'clickstats']);

const NON_CONFIG_SCHEMA_KEYS = new Set(['MENU_PREVIEW', 'ADD_TABLE', 'M2M_BUILDER', 'SCHEMA_MAP']);

export function markDirty() {
    if (NON_CONFIG_TABS.has(currentFile)) return;
    if (currentFile === 'schema' && NON_CONFIG_SCHEMA_KEYS.has(currentItemKey)) return;
    isDirty = true;
}
export function markClean() { isDirty = false; }
function confirmDiscard() {
    return !isDirty || confirm('You have unsaved changes that will be lost. Continue?');
}

export function showStatusPill(anchor, message, variant = 'success') {
    if (!anchor) return;
    const existing = anchor.parentNode && anchor.parentNode.querySelector(':scope > .status-pill');
    if (existing) existing.remove();

    const pill = document.createElement('span');
    pill.className = 'status-pill status-pill-' + variant;
    pill.textContent = message;
    const colors = {
        success: { bg: 'var(--ok-light)', fg: 'var(--ok)', border: 'var(--ok)' },
        error:   { bg: 'var(--error-light)', fg: 'var(--error)', border: 'var(--error)' },
        info:    { bg: 'var(--accent-mid)', fg: 'var(--text)', border: 'var(--border)' },
    }[variant] || { bg: 'var(--accent-mid)', fg: 'var(--text)', border: 'var(--border)' };
    pill.style.cssText = `display:inline-flex; align-items:center; gap:6px; margin-left:10px; padding:4px 10px; background:${colors.bg}; color:${colors.fg}; border:1px solid ${colors.border}; border-radius:999px;  font-weight:600; transition:opacity .3s;`;
    anchor.insertAdjacentElement('afterend', pill);

    const ttl = variant === 'error' ? 6000 : 3000;
    setTimeout(() => {
        pill.style.opacity = '0';
        setTimeout(() => pill.remove(), 300);
    }, ttl);
}

import { escHtml } from '../../assets/js/util/esc.js';

document.addEventListener('DOMContentLoaded', async () => {
    await getGlobalSchema();
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

            workspaceElement._renderId = (workspaceElement._renderId || 0) + 1;
            markClean();
            loadConfigFile(currentFile);
        });
    });

    workspaceElement.addEventListener('input', markDirty);
    workspaceElement.addEventListener('change', markDirty);

    window.addEventListener('beforeunload', (e) => {
        if (isDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
});

let globalSchemaPromise = null;

async function fetchGlobalSchema() {
    try {
        const result = await apiFetch('api.php?action=get&file=schema');
        if (!result.ok) throw new Error(`HTTP ${result.status}`);
        globalSchemaObject = await result.json();
    } catch (e) {
        globalSchemaObj: globalSchemaObject = null;
        console.warn('Could not load global schema', e);
        showStatusPill(document.getElementById('workspace'), 'Could not load the schema — table and column lists will be empty.', 'error');
    }
    return globalSchemaObject;
}

export function getGlobalSchema({ force = false } = {}) {
    if (force) invalidateGlobalSchema();
    if (globalSchemaObject) return Promise.resolve(globalSchemaObject);
    if (!globalSchemaPromise) {
        globalSchemaPromise = fetchGlobalSchema().finally(() => { globalSchemaPromise = null; });
    }
    return globalSchemaPromise;
}

export function invalidateGlobalSchema() {
    globalSchemaObj: globalSchemaObject    = null;
    globalSchemaPromise = null;
}

export async function getSchemaTables() {
    const schema = await getGlobalSchema();
    return Object.entries(schema?.tables ?? {})
        .filter(([, config]) => !config?.hidden)
        .map(([name, config]) => ({ name, label: config.display_name || name, columns: config.columns ?? {} }))
        .sort((a, b) => a.label.localeCompare(b.label));
}

function getTableOptions() {
    const options = [{ value: '', label: '-- Select Table --' }];
    if (globalSchemaObject && globalSchemaObject.tables) {
        for (const t in globalSchemaObject.tables) options.push({ value: t, label: globalSchemaObject.tables[t].display_name || t });
    }
    return options;
}

function getColumnOptionsForTable(tableName) {
    const options = [{ value: '', label: '-- Select Column --' }];
    if (tableName && globalSchemaObject && globalSchemaObject.tables[tableName] && globalSchemaObject.tables[tableName].columns) {
        const columns = globalSchemaObject.tables[tableName].columns;
        for (const c in columns) options.push({ value: c, label: columns[c].display_name || c });
    }
    return options;
}

function getEnumColumnsForTable(tableName) {
    const options = [];
    const columns = globalSchemaObject?.tables?.[tableName]?.columns;
    if (columns) {
        for (const c in columns) {
            if ((columns[c].type || '').toLowerCase() === 'enum') {
                options.push({ value: c, label: columns[c].display_name || c });
            }
        }
    }
    return options;
}

function getColumnMeta(tableName, columnName) {
    return globalSchemaObject?.tables?.[tableName]?.columns?.[columnName] || null;
}

async function loadConfigFile(fileName) {
    activeSaveHandler = null;
    if (fileName === 'overview' || fileName === 'health' || fileName === 'docs' || fileName === 'users' || fileName === 'backup' || fileName === 'migrations' || fileName === 'performance' || fileName === 'cron' || fileName === 'demo' || fileName === 'settings' || fileName === 'csv_import' || fileName === 'rag' || fileName === 'etl' || fileName === 'anonymization' || fileName === 'print') {
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
        } else if (fileName === 'dashboard') {
            if (!currentConfig.layout) currentConfig.layout = { columns: "repeat(auto-fit, minmax(300px, 1fr))", gap: "20px" };
            if (!currentConfig.widgets || !Array.isArray(currentConfig.widgets)) currentConfig.widgets = [];
            if (!currentConfig.menu_name) currentConfig.menu_name = 'Dashboard';
        } else if (fileName === 'calendar') {
            if (!currentConfig.sources || !Array.isArray(currentConfig.sources)) currentConfig.sources = [];
            if (!currentConfig.menu_name) currentConfig.menu_name = 'Calendar';
        } else if (fileName === 'board') {
            if (!Array.isArray(currentConfig.boards)) {
                currentConfig.boards = currentConfig.table ? [{
                    id: 'brd_' + Date.now().toString(36),
                    menu_name: currentConfig.menu_name || 'Board',
                    menu_icon: currentConfig.menu_icon || '',
                    hidden: !!currentConfig.hidden,
                    table: currentConfig.table,
                    status_column: currentConfig.status_column || '',
                    title_column: currentConfig.title_column || '',
                    card_columns: Array.isArray(currentConfig.card_columns) ? currentConfig.card_columns : [],
                    color: currentConfig.color || '#003366',
                }] : [];
                delete currentConfig.table;
                delete currentConfig.status_column;
                delete currentConfig.title_column;
                delete currentConfig.card_columns;
                delete currentConfig.color;
                delete currentConfig.menu_icon;
                delete currentConfig.hidden;
                currentConfig.menu_name = 'Board';
            }
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
            if (!currentConfig.menu_name) currentConfig.menu_name = 'Views';
        } else if (fileName === 'user_records') {
            if (!currentConfig.columns || typeof currentConfig.columns !== 'object' || Array.isArray(currentConfig.columns)) {
                currentConfig.columns = {};
            }
            if (typeof currentConfig.limit !== 'number' || currentConfig.limit < 0) {
                currentConfig.limit = 20;
            }
        } else if (fileName === 'security') {
            currentConfig = {};
        }

        if (fileName === 'schema' || fileName === 'dashboard' || fileName === 'calendar' || fileName === 'workflows' || fileName === 'board') {
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
        } else if (fileName === 'security' || fileName === 'views' || fileName === 'user_records') {
            renderSidebar();
            renderEditor('SETTINGS', currentConfig, false);
        } else {
            renderSidebar();
            workspaceElement.innerHTML = `<h2>Select an item from the left menu to edit</h2>`;
        }

        markClean();
    } catch (error) {
        showStatusPill(buttonSave, `Failed to load ${fileName}.json`, 'error');
    }
}

function addNewItem() {
    let newIndex = 0;
    if (currentFile === 'dashboard') {
        currentConfig.widgets.push({ id: "widget_" + Date.now(), type: "stat_card", title: "New Widget", table: "", query: { type: "count", column: "id" }, color: "#6E767F", display_columns: [] });
        newIndex = currentConfig.widgets.length - 1;
    } else if (currentFile === 'calendar') {
        currentConfig.sources.push({ table: "", date_column: "", title_column: "", color: "#6E767F", notify_before_days: 0, user_id_column: "", url_template: "" });
        newIndex = currentConfig.sources.length - 1;
    } else if (currentFile === 'workflows') {
        currentConfig.workflows.push({ id: "wf_" + Date.now(), title: "New Workflow", icon: "", steps: [] });
        newIndex = currentConfig.workflows.length - 1;
    } else if (currentFile === 'board') {
        currentConfig.boards.push({ id: "brd_" + Date.now(), menu_name: "New Board", menu_icon: "", hidden: false, table: "", status_column: "", title_column: "", card_columns: [], color: "#003366" });
        newIndex = currentConfig.boards.length - 1;
    }

    currentItemKey = newIndex;
    markDirty();
    renderSidebar();

    const items = currentFile === 'dashboard' ? currentConfig.widgets : currentFile === 'workflows' ? currentConfig.workflows : currentFile === 'board' ? currentConfig.boards : currentConfig.sources;
    renderEditor(newIndex, items[newIndex], true);
}

function clearConfig() {
    if (confirm(`Are you sure you want to completely clear the ${currentFile}.json configuration?`)) {
        if (currentFile === 'schema') currentConfig = { tables: {} };
        else if (currentFile === 'dashboard') currentConfig = { layout: { columns: "repeat(auto-fit, minmax(300px, 1fr))", gap: "20px" }, widgets: [], menu_name: 'Dashboard' };
        else if (currentFile === 'calendar') currentConfig = { sources: [], menu_name: 'Calendar' };
        else if (currentFile === 'workflows') currentConfig = { workflows: [], menu_name: 'Workflows' };
        else if (currentFile === 'board') currentConfig = { boards: [], menu_name: 'Board' };
        else if (currentFile === 'files') currentConfig = { menu_name: 'Files' };

        markDirty();
        renderSidebar();
        workspaceElement.innerHTML = `<h2>Configuration cleared. Click "Save config" to apply!</h2>`;
    }
}

function appendClearConfigButton(context) {
    const { workspaceEl: workspaceElement } = context;
    const dangerGroup = document.createElement('div');
    dangerGroup.className = 'form-group';
    dangerGroup.style.cssText = 'margin-top:28px; border-top:1px solid var(--border); padding-top:20px;';

    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'btn btn-danger';
    clearButton.textContent = 'Clear Entire Config';
    clearButton.onclick = clearConfig;
    dangerGroup.appendChild(clearButton);

    const clearHelp = document.createElement('span');
    clearHelp.className = 'help-text';
    clearHelp.textContent = 'Removes the entire configuration for this section. Press "Save config" in the top bar to apply.';
    dangerGroup.appendChild(clearHelp);

    workspaceElement.appendChild(dangerGroup);
}

function tabIcon(name) {
    const image = document.createElement('img');
    image.src = '../assets/icons/' + name;
    image.alt = '';
    image.style.cssText = 'width:15px;height:15px;opacity:.6;';
    return image;
}

function itemTabIcon() {
    const name = currentFile === 'schema'    ? 'data_table.png'
               : currentFile === 'dashboard' ? 'bar_chart.png'
               : currentFile === 'calendar'  ? 'calendar.png'
               : currentFile === 'workflows' ? 'build.png'
               : currentFile === 'board'     ? 'account_tree.png'
               : 'file_present.png';
    return tabIcon(name);
}

const CARD_MODULE_HEADER = {
    schema:    ['Schema', 'Define PostgreSQL tables, columns, and grid behavior. Use "Sync DB Tables" to discover existing tables, or add columns manually.'],
    dashboard: ['Dashboard', 'Build the dashboard from stat, bar, pie, and list widgets bound to your tables.'],
    calendar:  ['Calendar', 'Define one or more calendar sources — each maps a table\'s date column to calendar events.'],
    workflows: ['Workflows', 'Multi-step guided workflows that walk users through a sequence of record edits.'],
    files:     ['Files', 'Upload, browse, and configure file storage — max size, allowed types/extensions, and record-relation auto-linking.'],
    board:     ['Board', 'Define one or more Kanban boards — each maps a table\'s status column to lanes; users drag cards between lanes to update that column.'],
};

function renderSidebar() {
    itemPanelElement.innerHTML = '';

    const fullPageTabs = new Set([
        'overview', 'security', 'health', 'docs', 'users', 'backup',
        'migrations', 'performance', 'cron',
        'demo', 'settings', 'csv_import', 'rag', 'views', 'etl', 'anonymization', 'print',
        'user_records', 'clickstats',
    ]);

    if (fullPageTabs.has(currentFile)) {
        return;
    }

    const isCardTab = currentFile === 'schema' || currentFile === 'dashboard' || currentFile === 'calendar' || currentFile === 'workflows' || currentFile === 'board';

    const itemsRow = document.createElement('div');
    itemsRow.className = 'item-panel-items';
    itemPanelElement.appendChild(itemsRow);

    if (CARD_MODULE_HEADER[currentFile]) {
        const [title, description] = CARD_MODULE_HEADER[currentFile];
        const h2 = document.createElement('h2');
        h2.className = 'admin-page-title';
        h2.textContent = title;
        const p = document.createElement('p');
        p.className = 'admin-page-desc';
        p.textContent = description;
        itemPanelElement.appendChild(h2);
        itemPanelElement.appendChild(p);
    }

    if (currentFile === 'schema') {
        const menuButton = document.createElement('button');
        menuButton.type = 'button';
        menuButton.className = 'item-btn' + (currentItemKey === 'MENU_PREVIEW' ? ' active' : '');
        menuButton.append(tabIcon('table_edit.png'), document.createTextNode('Menu Preview'));
        menuButton.onclick = () => { currentItemKey = 'MENU_PREVIEW'; renderSidebar(); renderEditor('MENU_PREVIEW', null, false); };
        itemsRow.appendChild(menuButton);

        const addTableButton = document.createElement('button');
        addTableButton.type = 'button';
        addTableButton.className = 'item-btn' + (currentItemKey === 'ADD_TABLE' ? ' active' : '');
        addTableButton.append(tabIcon('build.png'), document.createTextNode('Add New Table'));
        addTableButton.onclick = () => { currentItemKey = 'ADD_TABLE'; renderSidebar(); renderEditor('ADD_TABLE', null, false); };
        itemsRow.appendChild(addTableButton);

        const m2mButton = document.createElement('button');
        m2mButton.type = 'button';
        m2mButton.className = 'item-btn' + (currentItemKey === 'M2M_BUILDER' ? ' active' : '');
        m2mButton.append(tabIcon('account_tree.png'), document.createTextNode('M2M Builder'));
        m2mButton.onclick = () => { currentItemKey = 'M2M_BUILDER'; renderSidebar(); renderEditor('M2M_BUILDER', null, false); };
        itemsRow.appendChild(m2mButton);

        const mapButton = document.createElement('button');
        mapButton.type = 'button';
        mapButton.className = 'item-btn' + (currentItemKey === 'SCHEMA_MAP' ? ' active' : '');
        mapButton.append(tabIcon('account_tree.png'), document.createTextNode('Schema Map'));
        mapButton.onclick = () => { currentItemKey = 'SCHEMA_MAP'; renderSidebar(); renderEditor('SCHEMA_MAP', null, false); };
        itemsRow.appendChild(mapButton);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'item-btn' + (currentItemKey === 'GLOBAL_SCHEMA' ? ' active' : '');
        button.append(tabIcon('car_gear.png'), document.createTextNode('Global Grid Settings'));
        button.onclick = () => { currentItemKey = 'GLOBAL_SCHEMA'; renderSidebar(); renderEditor('GLOBAL_SCHEMA', null, false); };
        itemsRow.appendChild(button);
    }

    if (currentFile === 'files') {
        const explorerButton = document.createElement('button');
        explorerButton.type = 'button';
        explorerButton.className = 'item-btn' + (currentItemKey === 'MANAGER' ? ' active' : '');
        explorerButton.append(tabIcon('folder_open.png'), document.createTextNode('File Explorer'));
        explorerButton.onclick = () => { currentItemKey = 'MANAGER'; renderSidebar(); renderEditor('MANAGER', null, false); };
        itemsRow.appendChild(explorerButton);

        const settingsButton = document.createElement('button');
        settingsButton.type = 'button';
        settingsButton.className = 'item-btn' + (currentItemKey === 'LAYOUT' ? ' active' : '');
        settingsButton.append(tabIcon('car_gear.png'), document.createTextNode('Global Settings'));
        settingsButton.onclick = () => { currentItemKey = 'LAYOUT'; renderSidebar(); renderEditor('LAYOUT', null, false); };
        itemsRow.appendChild(settingsButton);
        return;
    }

    if (currentFile === 'dashboard' || currentFile === 'calendar' || currentFile === 'workflows' || currentFile === 'board' || currentFile === 'automations') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'item-btn' + (currentItemKey === 'LAYOUT' ? ' active' : '');
        button.append(tabIcon('car_gear.png'), document.createTextNode('Global Settings'));
        button.onclick = () => { currentItemKey = 'LAYOUT'; renderSidebar(); renderEditor('LAYOUT', null, false); };
        itemsRow.appendChild(button);
    }

    if (currentFile === 'automations') {
        const activeMode = currentItemKey === 'N8N' ? 'N8N'
                         : currentItemKey === 'LAYOUT' ? 'LAYOUT'
                         : 'ALL';

        [
            { key: 'N8N', label: 'n8n Automations',    icon: 'arrow_split.png' },
            { key: 'ALL', label: 'Record Automations', icon: 'automation.png' },
        ].forEach(mode => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'item-btn' + (activeMode === mode.key ? ' active' : '');
            button.append(tabIcon(mode.icon), document.createTextNode(mode.label));
            button.onclick = () => {
                currentItemKey = mode.key;
                renderSidebar();
                renderEditor(mode.key, null, false);
            };
            itemsRow.insertBefore(button, itemsRow.firstChild);
        });
        return;
    }

    if (isCardTab) {
        const buttonAll = document.createElement('button');
        buttonAll.type = 'button';
        buttonAll.className = 'item-btn' + (currentItemKey === null ? ' active' : '');
        const allIcon = currentFile === 'schema'       ? 'data_table.png'
                       : currentFile === 'dashboard'    ? 'dashboard.png'
                       : currentFile === 'workflows'    ? 'build.png'
                       : currentFile === 'board'        ? 'account_tree.png'
                       : 'calendar.png';
        const allLabel = currentFile === 'schema'       ? 'All PostgreSQL tables'
                           : currentFile === 'dashboard'    ? 'All Widgets'
                           : currentFile === 'workflows'    ? 'All Workflows'
                           : 'All Sources';
        buttonAll.append(tabIcon(allIcon), document.createTextNode(allLabel));
        buttonAll.onclick = () => {
            currentItemKey = null;
            renderSidebar();
            renderItemCards();
        };
        itemsRow.insertBefore(buttonAll, itemsRow.firstChild);
        return;
    }

    if (!currentConfig) {
        return;
    }

    let itemsToIterate = currentFile === 'board' ? (currentConfig.boards || []) : (currentConfig.sources || []);
    const isArray = Array.isArray(itemsToIterate);
    const keys = isArray ? itemsToIterate.map((_, i) => i) : Object.keys(itemsToIterate);

    keys.forEach((key, index) => {
        const item = itemsToIterate[key];
        const wrapper = document.createElement('div');
        wrapper.className = 'item-btn-wrapper';

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'item-btn' + (String(currentItemKey) === String(key) ? ' active' : '');
        const itemLabel = currentFile === 'workflows' ? (item.title || `Workflow ${key}`)
                         : currentFile === 'board'     ? (item.menu_name || `Board ${key}`)
                         : (item.table || `Source ${key}`);
        button.append(itemTabIcon(), document.createTextNode(itemLabel));
        button.onclick = () => { currentItemKey = key; renderSidebar(); renderEditor(key, item, isArray); };
        wrapper.appendChild(button);

        const buttonUp = document.createElement('button');
        buttonUp.type = 'button';
        buttonUp.className = 'item-order-btn';
        buttonUp.textContent = '^';
        buttonUp.disabled = index === 0;
        buttonUp.onclick = (e) => {
            e.stopPropagation();
            moveArrayItem(itemsToIterate, key, -1);
            if (currentItemKey === key) currentItemKey = key - 1; else if (currentItemKey === key - 1) currentItemKey = key;
            markDirty();
            renderSidebar();
        };

        const buttonDown = document.createElement('button');
        buttonDown.type = 'button';
        buttonDown.className = 'item-order-btn';
        buttonDown.textContent = 'v';
        buttonDown.disabled = index === keys.length - 1;
        buttonDown.onclick = (e) => {
            e.stopPropagation();
            moveArrayItem(itemsToIterate, key, 1);
            if (currentItemKey === key) currentItemKey = key + 1; else if (currentItemKey === key + 1) currentItemKey = key;
            markDirty();
            renderSidebar();
        };

        wrapper.appendChild(buttonUp);
        wrapper.appendChild(buttonDown);
        itemsRow.appendChild(wrapper);
    });
}

function renderItemCards() {
    workspaceElement.innerHTML = '';
    buttonSave.style.display = 'inline-block';

    if (!currentConfig) return;

    const isSchema    = currentFile === 'schema';
    const isDashboard = currentFile === 'dashboard';
    const isWorkflows = currentFile === 'workflows';
    const isBoard     = currentFile === 'board';

    const rawItems    = isSchema    ? (currentConfig.tables    || {})
                      : isDashboard ? (currentConfig.widgets   || [])
                      : isWorkflows ? (currentConfig.workflows || [])
                      : isBoard     ? (currentConfig.boards    || [])
                      : (currentConfig.sources || []);
    const isArray     = Array.isArray(rawItems);

    function getKeys(items) {
        return isArray ? items.map((_, i) => i) : Object.keys(items);
    }

    const bar = document.createElement('div');
    bar.style.marginBottom = '12px';

    if (isSchema) {
        const buttonSync = document.createElement('button');
        buttonSync.type = 'button';
        buttonSync.className = 'btn btn-success';
        buttonSync.textContent = 'Sync DB Tables';
        buttonSync.onclick = () => {
            const schemaName = prompt('Enter database schema name to sync:', 'public');
            if (schemaName) syncSchemaTables(currentConfig, schemaName,
                (added) => {
                    if (added > 0) markDirty();
                    showStatusPill(buttonSync, `Added ${added} new table${added === 1 ? '' : 's'}. Click "Save config" to persist.`, added > 0 ? 'success' : 'info');
                    fetchGlobalSchema();
                    currentItemKey = null;
                    renderItemCards();
                    setTimeout(() => renderSidebar(), 900);
                },
                (error) => showStatusPill(buttonSync, error, 'error'));
        };
        bar.appendChild(buttonSync);
    } else {
        const buttonAdd = document.createElement('button');
        buttonAdd.type = 'button';
        buttonAdd.className = 'btn btn-success';
        buttonAdd.textContent = isDashboard ? '+ Add New Widget' : isWorkflows ? '+ Add New Workflow' : isBoard ? '+ Add New Board' : '+ Add New Source';
        buttonAdd.onclick = addNewItem;
        bar.appendChild(buttonAdd);
    }
    workspaceElement.appendChild(bar);

    const list = document.createElement('div');
    list.style.cssText = 'max-width:900px;';
    workspaceElement.appendChild(list);

    function redraw() {
        const fresh    = isSchema    ? (currentConfig.tables    || {})
                       : isDashboard ? (currentConfig.widgets   || [])
                       : isWorkflows ? (currentConfig.workflows || [])
                       : isBoard     ? (currentConfig.boards    || [])
                       : (currentConfig.sources || []);
        const freshKeys = getKeys(fresh);
        list.innerHTML = '';
        if (freshKeys.length === 0) {
            const empty = document.createElement('p');
            empty.style.cssText = ' text-align:center; padding:40px;';
            empty.textContent = isSchema    ? 'No tables defined. Use "Sync DB Tables" to get started.'
                              : isDashboard ? 'No widgets yet. Click "+ Add New Widget".'
                              : isWorkflows ? 'No workflows yet. Click "+ Add New Workflow".'
                              : isBoard     ? 'No boards yet. Click "+ Add New Board".'
                              : 'No sources yet. Click "+ Add New Source".';
            list.appendChild(empty);
            return;
        }
        freshKeys.forEach((k, index) =>
            list.appendChild(buildItemCard(k, fresh[k], index, freshKeys.length, isArray, fresh, redraw))
        );
    }

    redraw();
}

function buildItemCard(key, item, index, total, isArray, itemsReference, redraw) {
    const isSchema    = currentFile === 'schema';
    const isDashboard = currentFile === 'dashboard';
    const isWorkflows = currentFile === 'workflows';
    const isBoard     = currentFile === 'board';

    const card = document.createElement('div');
    card.className = 'column-block collapsed';

    const hdr = document.createElement('div');
    hdr.className = 'block-header';

    const chevron = document.createElement('span');
    chevron.className = 'block-chevron';
    chevron.textContent = '▶';

    const nameSpan = document.createElement('strong');
    nameSpan.className = 'block-title';
    nameSpan.textContent = isSchema    ? (item.display_name || key)
                         : isDashboard ? (item.title || `Widget ${key}`)
                         : isWorkflows ? (item.title || `Workflow ${key}`)
                         : isBoard     ? (item.menu_name || `Board ${key}`)
                         : (item.table || `Source ${key}`);

    if (isSchema) {
        const keySpan = document.createElement('span');
        keySpan.className = 'block-key';
        keySpan.textContent = ` (${key})`;
        nameSpan.appendChild(keySpan);
    }

    hdr.appendChild(chevron);
    hdr.appendChild(nameSpan);

    const buttonUp = document.createElement('button');
    buttonUp.type = 'button';
    buttonUp.title = 'Move up';
    buttonUp.textContent = '▲';
    buttonUp.className = 'icon-btn';
    if (index === 0) { buttonUp.disabled = true; buttonUp.style.opacity = '0.3'; }
    buttonUp.onclick = e => {
        e.stopPropagation();
        if (isArray) moveArrayItem(itemsReference, key, -1);
        else currentConfig.tables = moveObjectKey(itemsReference, key, -1);
        markDirty();
        redraw();
    };

    const buttonDown = document.createElement('button');
    buttonDown.type = 'button';
    buttonDown.title = 'Move down';
    buttonDown.textContent = '▼';
    buttonDown.className = 'icon-btn';
    if (index === total - 1) { buttonDown.disabled = true; buttonDown.style.opacity = '0.3'; }
    buttonDown.onclick = e => {
        e.stopPropagation();
        if (isArray) moveArrayItem(itemsReference, key, 1);
        else currentConfig.tables = moveObjectKey(itemsReference, key, 1);
        markDirty();
        redraw();
    };

    const buttonDel = document.createElement('button');
    buttonDel.type = 'button';
    buttonDel.title = 'Delete';
    buttonDel.textContent = '✕';
    buttonDel.className = 'icon-btn icon-btn-danger';
    buttonDel.onclick = e => {
        e.stopPropagation();
        const label = isSchema    ? (item.display_name || key)
                    : isDashboard ? (item.title || `Widget ${key}`)
                    : isWorkflows ? (item.title || `Workflow ${key}`)
                    : isBoard     ? (item.menu_name || `Board ${key}`)
                    : (item.table || `Source ${key}`);
        if (!confirm(`Delete "${label}"?`)) return;
        if (isSchema)         delete currentConfig.tables[key];
        else if (isDashboard) currentConfig.widgets.splice(key, 1);
        else if (isWorkflows) currentConfig.workflows.splice(key, 1);
        else if (isBoard)     currentConfig.boards.splice(key, 1);
        else                  currentConfig.sources.splice(key, 1);
        markDirty();
        redraw();
    };
    hdr.appendChild(buttonUp);
    hdr.appendChild(buttonDown);
    hdr.appendChild(buttonDel);

    card.appendChild(hdr);

    const body = document.createElement('div');
    body.className = 'block-body';
    card.appendChild(body);

    let rendered = false;

    function openCard() {
        card.classList.remove('collapsed');
        if (!rendered) {
            rendered = true;
            renderEditorIntoCard(key, item, isArray, body, nameSpan, redraw);
        }
    }

    function closeCard() {
        card.classList.add('collapsed');
    }

    hdr.addEventListener('click', (e) => {
        if (e.target.closest('button, input, label')) return;
        card.classList.contains('collapsed') ? openCard() : closeCard();
    });

    return card;
}

function renderEditorIntoCard(key, item, isArray, bodyElement, nameSpan, redraw) {
    const isSchema    = currentFile === 'schema';
    const isDashboard = currentFile === 'dashboard';
    const isWorkflows = currentFile === 'workflows';
    const isBoard     = currentFile === 'board';

    const cardCtx = {
        workspaceEl: bodyElement,
        currentConfig,
        getTableOptions,
        getColumnOptionsForTable,
        getEnumColumnsForTable,
        getColumnMeta,
        renderEditor: (k, d, array) => {
            bodyElement.innerHTML = '';
            renderEditorIntoCard(k, d, array !== undefined ? array : isArray, bodyElement, nameSpan, redraw);
        },
        renderSidebar: isSchema
            ? redraw
            : () => {
                nameSpan.textContent = isDashboard ? (item.title || `Widget ${key}`)
                                     : isWorkflows ? (item.title || `Workflow ${key}`)
                                     : isBoard     ? (item.menu_name || `Board ${key}`)
                                     : (item.table || `Source ${key}`);
            },
    };

    if (isSchema)         renderSchemaEditor(key, item, cardCtx);
    else if (isDashboard) renderDashboardEditor(key, item, isArray, cardCtx);
    else if (isWorkflows) renderWorkflowsEditor(key, item, isArray, cardCtx);
    else if (isBoard)     renderBoardEditor(key, item, isArray, cardCtx);
    else                  renderCalendarEditor(key, item, isArray, cardCtx);
}

function renderMenuPreview(context) {
    const { workspaceEl: workspaceElement } = context;
    (async () => {
        workspaceElement.innerHTML = '';
        const h3 = document.createElement('h3');
        h3.style.marginTop = '0';
        h3.textContent = 'Menu Preview';
        workspaceElement.appendChild(h3);
        const description = document.createElement('p');
        description.style.cssText = '  margin-bottom:20px;';
        description.textContent = 'Drag to reorder. Drop onto an item to nest it (1 level). Changes save automatically.';
        workspaceElement.appendChild(description);
        const preview = createFullMenuPreview(null);
        workspaceElement.appendChild(preview.el);
        try {
            const result = await apiFetch('api.php?action=menu_config');
            if (!result.ok) throw new Error('HTTP ' + result.status);
            const data = await result.json();
            preview.update(data);
        } catch (error) {
            preview.el.remove();
            const message = document.createElement('p');
            message.style.color = 'var(--error)';
            message.textContent = 'Failed to load menu config: ' + escHtml(error.message);
            workspaceElement.appendChild(message);
        }
    })();
}

function loadAndRender(loader, context, invoke = null) {
    const myId = workspaceElement._renderId = (workspaceElement._renderId || 0) + 1;
    return loader().then(
        (fn) => {
            if (workspaceElement._renderId !== myId) return undefined;
            return invoke ? invoke(fn) : fn(context);
        },
        (error) => {
            if (workspaceElement._renderId !== myId) return undefined;
            console.error('Could not load tab module', error);
            workspaceElement.innerHTML = '';
            const message = document.createElement('p');
            message.className = 'admin-error';
            message.textContent = 'Could not load this section. Check your connection and reload the page.';
            workspaceElement.appendChild(message);
            return undefined;
        }
    );
}

function renderEditor(key, itemData, isArray) {
    workspaceElement.innerHTML = '';
    const context = { workspaceEl: workspaceElement, currentConfig, getTableOptions, getColumnOptionsForTable, getEnumColumnsForTable, getColumnMeta, renderEditor, renderSidebar, setSaveHandler };

    if (['overview', 'health', 'docs', 'users', 'backup', 'migrations', 'performance', 'cron', 'demo', 'settings', 'csv_import', 'rag', 'etl', 'automations', 'anonymization', 'clickstats'].includes(currentFile) || (currentFile === 'files' && key === 'MANAGER') || (currentFile === 'schema' && (key === 'MENU_PREVIEW' || key === 'ADD_TABLE' || key === 'M2M_BUILDER' || key === 'SCHEMA_MAP'))) {
        buttonSave.style.display = 'none';
    } else {
        buttonSave.style.display = 'inline-block';
    }

    const pageLoader = PAGE_MODULES[currentFile];
    if (pageLoader) return loadAndRender(pageLoader, context);

    if (currentFile === 'security') {
        return loadAndRender(
            () => import('./security.js').then(m => m.renderSecurityEditor),
            context,
            (fn) => fn(key, itemData, isArray, context)
        );
    }
    if (currentFile === 'automations') {
        if (key === 'LAYOUT') {
            const message = document.createElement('p');
            message.style.cssText = ' padding:20px;';
            message.textContent = 'Automations have no global configuration settings.';
            workspaceElement.appendChild(message);
            return;
        }

        return loadAndRender(
            () => import('./automations.js').then(m => m.renderAutomationsPage),
            context,
            (fn) => fn(context, key === 'N8N' ? 'n8n' : 'record')
        );
    }
    if (currentFile === 'files' && key === 'MANAGER') {
        return loadAndRender(() => import('./files_render.js').then(m => m.renderFilesEditor), context);
    }

    if (currentFile === 'schema' && key === 'MENU_PREVIEW') {
        renderMenuPreview(context);
        return;
    }
    if (currentFile === 'schema' && key === 'ADD_TABLE') {
        return loadAndRender(() => import('./add_table.js').then(m => m.renderAddTableEditor), context);
    }
    if (currentFile === 'schema' && key === 'M2M_BUILDER') {
        return loadAndRender(() => import('./m2m.js').then(m => m.renderM2mPage), context);
    }
    if (currentFile === 'schema' && key === 'SCHEMA_MAP') {
        return loadAndRender(() => import('./erd.js').then(m => m.renderErdPage), context);
    }

    if (key === 'LAYOUT') {
        if (currentFile === 'dashboard') { renderDashboardLayout(context); appendClearConfigButton(context); return; }
        if (currentFile === 'calendar') {
            renderGlobalSettings(context, { title: 'Calendar Global Settings', defaultMenuName: 'Calendar' });
            appendClearConfigButton(context);
            return;
        }
        if (currentFile === 'workflows') {
            renderGlobalSettings(context, { title: 'Workflows Global Settings', defaultMenuName: 'Workflows' });
            appendClearConfigButton(context);
            return;
        }
        if (currentFile === 'files') {
            return renderGlobalSettings(context, { title: 'Files Global Settings', defaultMenuName: 'Files' });
        }
        if (currentFile === 'board') {
            renderGlobalSettings(context, { title: 'Board Global Settings', defaultMenuName: 'Board' });
            appendClearConfigButton(context);
            return;
        }
    }

    if (currentFile === 'schema' && key === 'GLOBAL_SCHEMA') { renderSchemaGlobalSettings(currentConfig, context); appendClearConfigButton(context); return; }
    if (currentFile === 'schema') return renderSchemaEditor(key, itemData, context);

    const headerDiv = document.createElement('div');
    headerDiv.style.display = 'flex'; headerDiv.style.justifyContent = 'space-between'; headerDiv.style.alignItems = 'center';
    const title = document.createElement('h3');
    title.textContent = `Edit: ${isArray ? 'Item ' + key : key}`;
    headerDiv.appendChild(title);

    const buttonDelete = document.createElement('button');
    buttonDelete.className = 'btn btn-danger'; buttonDelete.textContent = 'Delete this item';
    buttonDelete.onclick = () => {
        if (confirm('Are you sure?')) {
            if (currentFile === 'dashboard') currentConfig.widgets.splice(key, 1);
            else if (currentFile === 'workflows') currentConfig.workflows.splice(key, 1);
            else if (currentFile === 'board') currentConfig.boards.splice(key, 1);
            else currentConfig.sources.splice(key, 1);
            currentItemKey = null;
            markDirty();
            workspaceElement.innerHTML = '<h2>Item deleted. Click "Save config" to apply.</h2>';
            renderSidebar();
        }
    };
    headerDiv.appendChild(buttonDelete);
    workspaceElement.appendChild(headerDiv);

    if (currentFile === 'dashboard') renderDashboardEditor(key, itemData, isArray, context);
    else if (currentFile === 'calendar') renderCalendarEditor(key, itemData, isArray, context);
    else if (currentFile === 'workflows') renderWorkflowsEditor(key, itemData, isArray, context);
    else if (currentFile === 'board') renderBoardEditor(key, itemData, isArray, context);
}

(async () => {
    try {
        const result  = await apiFetch('api_migrations.php?action=scan');
        const data = await result.json();
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
    }
})();

function validateWorkflowsConfig(config) {
    const workflows = config.workflows || [];
    for (let w = 0; w < workflows.length; w++) {
        const workflow = workflows[w];
        const label = (workflow.title && workflow.title.trim()) || `Workflow ${w + 1}`;
        const steps = workflow.steps || [];
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

            const proc = step.procedure;
            if (proc && proc.enabled) {
                const stepLabel = `"${label}" — Step ${s + 1} ("${step.title.trim()}")`;
                if (!proc.schema || !proc.name) {
                    return `${stepLabel} has "call procedure" enabled but no procedure selected.`;
                }
                const parameters = proc.params || [];
                for (let p = 0; p < parameters.length; p++) {
                    const parameter = parameters[p] || {};
                    if (parameter.source === 'literal') continue;
                    if (!parameter.field || parameter.field.trim() === '') {
                        return `${stepLabel} — procedure parameter ${p + 1} has no source field selected.`;
                    }
                }
            }
        }
    }
    return null;
}

buttonSave.addEventListener('click', async () => {
    if (buttonSave.disabled) return;
    buttonSave.disabled = true;

    try {
        if (activeSaveHandler) {
            try {
                const result = await activeSaveHandler();
                if (result.status === 'success') {
                    markClean();
                    showStatusPill(buttonSave, result.message || `${currentFile}.json saved`, 'success');
                } else {
                    showStatusPill(buttonSave, 'Error saving: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch {
                showStatusPill(buttonSave, 'Failed to save changes.', 'error');
            }
            return;
        }

        if (!currentConfig) return;

        if (currentFile === 'workflows') {
            const error = validateWorkflowsConfig(currentConfig);
            if (error) {
                showStatusPill(buttonSave, error, 'error');
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
                showStatusPill(buttonSave, `${currentFile}.json saved`, 'success');

                if (currentFile === 'schema') getGlobalSchema({ force: true });
            } else {
                showStatusPill(buttonSave, 'Error saving: ' + (result.error || 'Unknown error'), 'error');
            }
        } catch (error) {
            showStatusPill(buttonSave, 'Failed to save changes.', 'error');
        }
    } finally {
        buttonSave.disabled = false;
    }
});
