import { WidgetRegistry } from './registry.js';

// Import widgets so they self-register
import './widgets/kpi-card.js';
import './widgets/stat-card.js';
import './widgets/bar-chart.js';
import './widgets/vertical-bar-chart.js';
import './widgets/pie-chart.js';
import './widgets/list.js';

async function initDashboard() {
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

    renderWidgets(container, globalConfig);
}

async function loadDashboardData(container, dateFilter, targetWidget) {
    const loading = document.createElement('div');
    loading.className = 'dash-loading';
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
        container.appendChild(widgetEl);
    });
}

document.addEventListener('DOMContentLoaded', initDashboard);
