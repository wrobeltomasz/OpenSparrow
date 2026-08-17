// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { WidgetRegistry } from './registry.js';
import { buildExportButton } from './export.js';
import { I18n } from '../i18n.js';

import './widgets/stat-card.js';
import './widgets/bar-chart.js';
import './widgets/vertical-bar-chart.js';
import './widgets/pie-chart.js';
import './widgets/line-chart.js';
import './widgets/list.js';

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
    dot.style.backgroundColor = widget.color || 'var(--accent)';
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

function updateClearButton() {
    const button = document.getElementById('clearFilters');
    if (!button) return;
    const dateSelect = document.getElementById('dashDateFilter');
    button.hidden = hiddenWidgets.size === 0 && (!dateSelect || dateSelect.value === 'all');
}

function initClearFilters(container) {
    const button = document.getElementById('clearFilters');
    if (!button) return;
    button.addEventListener('click', () => {
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
        const message = document.createElement('p');
        message.className = 'dash-error';
        message.textContent = I18n.t('dashboard.error_config');
        container.appendChild(message);
        return;
    }

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
    loading.textContent = I18n.t('dashboard.loading');
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
        err.textContent = I18n.t('dashboard.error_load');
        container.appendChild(err);
    }
}

function renderWidgets(container, config) {
    container.replaceChildren();
    updateClearButton();

    if (!config?.widgets?.length) {
        const p = document.createElement('p');
        p.style.gridColumn = '1/-1';
        p.textContent = I18n.t('dashboard.no_widgets');
        container.appendChild(p);
        return;
    }

    container.className = 'dashboard-grid';
    if (config.layout?.gap) container.style.gap = config.layout.gap;

    config.widgets.forEach(widget => {
        if (hiddenWidgets.has(widgetKey(widget))) return;
        const widgetElement = document.createElement('div');
        widgetElement.className = 'dash-widget';
        widgetElement.dataset.w = widget.width  || 1;
        widgetElement.dataset.h = widget.height || 1;

        if (widget.type !== 'stat_card') {
            const title = document.createElement('h3');
            title.className = 'dash-title';
            title.textContent = widget.title;
            widgetElement.appendChild(title);
        }

        widgetElement.appendChild(WidgetRegistry.render(widget));
        const exportButton = buildExportButton(widget);
        if (exportButton) widgetElement.appendChild(exportButton);
        container.appendChild(widgetElement);
    });
}

document.addEventListener('DOMContentLoaded', initDashboard);
