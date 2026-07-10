// assets/js/dashboard/index.js — Dashboard entry point
// Imports all widget modules (they self-register into WidgetRegistry), loads i18n, then fetches dashboard.json widget data and renders #dashboardSection.
// Header controls (rendered in the app header by dashboard.php):
//   - #dashDateFilter: global period select (All time / Today / 7d / 30d / This month) — reloads all widgets
//   - chips: per-widget visibility (built from the loaded config), state persisted in localStorage

import { WidgetRegistry } from './registry.js';
import { buildExportButton } from './export.js';
import { I18n } from '../i18n.js';

// Import widgets so they self-register
import './widgets/kpi-card.js';
import './widgets/stat-card.js';
import './widgets/bar-chart.js';
import './widgets/vertical-bar-chart.js';
import './widgets/pie-chart.js';
import './widgets/list.js';

// ── Filters: widget visibility (chips in the app header) ─────────────────────
const FILTER_STORAGE_KEY = 'sparrow_dashboard_filters';
let hiddenWidgets = new Set();
let lastConfig = null;

function widgetKey(widget) {
    return String(widget.id ?? widget.title ?? '');
}

function loadFilterState() {
    try {
        const saved = JSON.parse(localStorage.getItem(FILTER_STORAGE_KEY) || '{}');
        hiddenWidgets = new Set(Array.isArray(saved.hiddenWidgets) ? saved.hiddenWidgets : []);
    } catch (_) {
        hiddenWidgets = new Set();
    }
}

function saveFilterState() {
    localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify({ hiddenWidgets: [...hiddenWidgets] }));
}

function buildWidgetChip(widget, container) {
    const key = widgetKey(widget);
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'filter-chip' + (hiddenWidgets.has(key) ? ' off' : '');

    const dot = document.createElement('span');
    dot.className = 'filter-dot';
    dot.style.backgroundColor = widget.color || '#3b82f6';
    chip.appendChild(dot);
    chip.appendChild(document.createTextNode(widget.title || key));

    chip.addEventListener('click', () => {
        if (hiddenWidgets.has(key)) {
            hiddenWidgets.delete(key);
        } else {
            hiddenWidgets.add(key);
        }
        saveFilterState();
        renderFilterBar(container);
        if (lastConfig) renderWidgets(container, lastConfig);
    });
    return chip;
}

function renderFilterBar(container) {
    const bar = document.getElementById('dashboardFilters');
    if (!bar) return;
    bar.innerHTML = '';
    (lastConfig?.widgets ?? []).forEach(w => bar.appendChild(buildWidgetChip(w, container)));
}

// ── Clear filters: header button unhides all widgets and resets the period ───
function updateClearButton() {
    const btn = document.getElementById('clearFilters');
    if (!btn) return;
    const dateSelect = document.getElementById('dashDateFilter');
    btn.hidden = hiddenWidgets.size === 0 && (!dateSelect || dateSelect.value === 'all');
}

function initClearFilters(container) {
    const btn = document.getElementById('clearFilters');
    if (!btn) return;
    btn.addEventListener('click', () => {
        hiddenWidgets.clear();
        saveFilterState();
        renderFilterBar(container);
        const dateSelect = document.getElementById('dashDateFilter');
        if (dateSelect && dateSelect.value !== 'all') {
            dateSelect.value = 'all';
            loadDashboardData(container, 'all', 'all');
        } else if (lastConfig) {
            renderWidgets(container, lastConfig);
        }
    });
}

async function initDashboard() {
    await I18n.load();

    const container = document.getElementById('dashboardSection');
    if (!container) {
        console.error('Error: Container #dashboardSection not found');
        return;
    }

    let globalConfig = null;
    try {
        const response = await fetch('api.php?api=dashboard', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        globalConfig = await response.json();
    } catch (e) {
        console.error('Error fetching initial dashboard config', e);
        container.replaceChildren();
        const msg = document.createElement('p');
        msg.className = 'dash-error';
        msg.textContent = 'Cannot load dashboard configuration. Try refreshing.';
        container.appendChild(msg);
        return;
    }

    // Global period select rendered server-side in the header (dashboard.php);
    // changing it reloads all widgets, count/sum cards also get prev_data deltas.
    const dateSelect = document.getElementById('dashDateFilter');
    if (dateSelect) {
        dateSelect.addEventListener('change', () => loadDashboardData(container, dateSelect.value, 'all'));
    }

    lastConfig = globalConfig;
    loadFilterState();
    renderFilterBar(container);
    initClearFilters(container);
    renderWidgets(container, globalConfig);
}

async function loadDashboardData(container, dateFilter, targetWidget) {
    const loading = document.createElement('div');
    loading.className = 'dash-loading';
    loading.textContent = 'Loading data...';
    container.replaceChildren(loading);

    try {
        const response = await fetch(
            `api.php?api=dashboard&date_filter=${dateFilter}&date_target=${targetWidget}`,
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
        );
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const config = await response.json();
        lastConfig = config;
        renderWidgets(container, config);
    } catch (error) {
        console.error('Error loading dashboard:', error);
        container.replaceChildren();
        const err = document.createElement('p');
        err.className = 'dash-error';
        err.textContent = 'Error occurred while loading dashboard data.';
        container.appendChild(err);
    }
}

function renderWidgets(container, config) {
    container.replaceChildren();
    updateClearButton();

    if (!config?.widgets?.length) {
        const p = document.createElement('p');
        p.style.gridColumn = '1/-1';
        p.textContent = 'No widgets configured.';
        container.appendChild(p);
        return;
    }

    container.className = 'dashboard-grid';
    if (config.layout?.gap) container.style.gap = config.layout.gap;

    config.widgets.forEach(widget => {
        if (hiddenWidgets.has(widgetKey(widget))) return;
        const widgetEl = document.createElement('div');
        widgetEl.className = 'dash-widget';
        widgetEl.dataset.w = widget.width  || 1;
        widgetEl.dataset.h = widget.height || 1;

        if (widget.type !== 'kpi_card' && widget.type !== 'stat_card') {
            const title = document.createElement('h3');
            title.className = 'dash-title';
            title.textContent = widget.title;
            widgetEl.appendChild(title);
        }

        widgetEl.appendChild(WidgetRegistry.render(widget));
        const exportBtn = buildExportButton(widget);
        if (exportBtn) widgetEl.appendChild(exportBtn);
        container.appendChild(widgetEl);
    });
}

document.addEventListener('DOMContentLoaded', initDashboard);
