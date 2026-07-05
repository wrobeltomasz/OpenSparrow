// assets/js/dashboard/index.js — Dashboard entry point
// Imports all widget modules (they self-register into WidgetRegistry), loads i18n, then fetches dashboard.json widget data and renders #dashboardSection.

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

    container.before(buildFilterBar(container));
    renderWidgets(container, globalConfig);
}

// Global period filter (All time / Today / 7d / 30d / This month) — reloads
// all widgets via loadDashboardData; count/sum cards also get prev_data deltas.
function buildFilterBar(container) {
    const bar = document.createElement('div');
    bar.className = 'dash-filter-bar';

    const label = document.createElement('label');
    label.className = 'dash-filter-label';
    label.setAttribute('for', 'dashDateFilter');
    label.textContent = I18n.t('dashboard.filter_label');

    const select = document.createElement('select');
    select.id = 'dashDateFilter';
    const options = [
        ['all', 'dashboard.filter_all'],
        ['today', 'dashboard.filter_today'],
        ['7d', 'dashboard.filter_7d'],
        ['30d', 'dashboard.filter_30d'],
        ['this_month', 'dashboard.filter_month'],
    ];
    for (const [value, key] of options) {
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = I18n.t(key);
        select.appendChild(opt);
    }
    select.addEventListener('change', () => loadDashboardData(container, select.value, 'all'));

    bar.append(label, select);
    return bar;
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
