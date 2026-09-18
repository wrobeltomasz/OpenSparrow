// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from './util/api.js';
import { I18n } from './i18n.js';
import { showRecordTooltip, hideRecordTooltip, rowsFromRecord } from './util/record-tooltip.js';

const monthCount = 12;
const rowHeight = 44;
const headerRowHeight = 44;
const cellWidth = () => window.matchMedia('(max-width: 768px)').matches ? 72 : 88;
const labelWidth = () => window.matchMedia('(max-width: 768px)').matches ? 140 : 220;

const titleElement = document.getElementById('roadmapTitle');
const selectElement = document.getElementById('roadmapBoardSelect');
const gridElement = document.getElementById('roadmapGrid');
const filtersElement = document.getElementById('roadmapFilters');
const searchInputElement = document.getElementById('roadmapSearch');
const clearFiltersButton = document.getElementById('clearFilters');
const prevButtonElement = document.getElementById('roadmapPrev');
const nextButtonElement = document.getElementById('roadmapNext');

let roadmapData = null;
let appSchema = null;
let windowStart = null;
let searchTerm = '';
let hiddenCategories = new Set();

const FILTER_STORAGE_KEY = 'sparrow_roadmap_filters';

function translate(key) {
    return I18n.t(key);
}

function monthNames() {
    return [
        translate('roadmap.month_jan'), translate('roadmap.month_feb'), translate('roadmap.month_mar'),
        translate('roadmap.month_apr'), translate('roadmap.month_may'), translate('roadmap.month_jun'),
        translate('roadmap.month_jul'), translate('roadmap.month_aug'), translate('roadmap.month_sep'),
        translate('roadmap.month_oct'), translate('roadmap.month_nov'), translate('roadmap.month_dec'),
    ];
}

function loadFilterState() {
    try {
        const saved = JSON.parse(localStorage.getItem(FILTER_STORAGE_KEY) || '{}');
        hiddenCategories = new Set(Array.isArray(saved.hiddenCategories) ? saved.hiddenCategories : []);
    } catch (_) {
        hiddenCategories = new Set();
    }
}

function saveFilterState() {
    localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify({ hiddenCategories: [...hiddenCategories] }));
}

function rowMatchesSearch(row) {
    if (!searchTerm) return true;
    const haystack = [
        row.title,
        String(row.id),
        row.start,
        row.end,
        ...(Array.isArray(row.fields) ? row.fields.map(field => field.value) : [])
    ].join(' ').toLowerCase();
    return haystack.includes(searchTerm);
}

function initSearch() {
    if (!searchInputElement) return;
    searchInputElement.addEventListener('input', () => {
        searchTerm = searchInputElement.value.trim().toLowerCase();
        updateClearButton();
        renderGrid();
    });
}

function initClearFilters() {
    if (!clearFiltersButton) return;
    clearFiltersButton.addEventListener('click', () => {
        searchTerm = '';
        if (searchInputElement) searchInputElement.value = '';
        hiddenCategories.clear();
        saveFilterState();
        updateClearButton();
        renderFilterBar();
        renderGrid();
    });
}

function updateClearButton() {
    if (clearFiltersButton) clearFiltersButton.hidden = !searchTerm && hiddenCategories.size === 0;
}

function buildCategoryChip(category) {
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'filter-chip' + (hiddenCategories.has(category.value) ? ' off' : '');

    const dot = document.createElement('span');
    dot.className = 'filter-dot';
    dot.style.backgroundColor = category.color;
    chip.appendChild(dot);
    chip.appendChild(document.createTextNode(category.label));

    chip.addEventListener('click', () => {
        if (hiddenCategories.has(category.value)) {
            hiddenCategories.delete(category.value);
        } else {
            hiddenCategories.add(category.value);
        }
        saveFilterState();
        updateClearButton();
        renderFilterBar();
        renderGrid();
    });
    return chip;
}

function renderFilterBar() {
    if (!filtersElement) return;
    filtersElement.innerHTML = '';
    if (!roadmapData || !roadmapData.configured) return;
    (roadmapData.categories || []).forEach(category => filtersElement.appendChild(buildCategoryChip(category)));
}

function quarterOfYear(monthIndex) {
    return Math.floor(monthIndex / 3);
}

function isQuarterStart(monthIndex) {
    return monthIndex % 3 === 0;
}

function isQuarterShaded(monthIndex) {
    return quarterOfYear(monthIndex) % 2 === 1;
}

function monthsBetween(start, end) {
    return (end[0] - start[0]) * 12 + (end[1] - start[1]);
}

function offsetFromWindow(dateString) {
    const year = Number(dateString.slice(0, 4));
    const month = Number(dateString.slice(5, 7)) - 1;
    const offset = monthsBetween(windowStart, [year, month]);
    return offset >= 0 && offset < monthCount ? offset : null;
}

function defaultWindow(rows) {
    const today = new Date();
    let candidate = [today.getFullYear(), today.getMonth() - 1];
    for (const row of rows) {
        const start = [Number(row.start.slice(0, 4)), Number(row.start.slice(5, 7)) - 1];
        if (monthsBetween(candidate, start) >= monthCount) {
            candidate = [start[0], start[1] - 1];
        }
    }
    if (candidate[1] < 0) {
        candidate = [candidate[0] - 1, candidate[1] + 12];
    }
    return candidate;
}

function shiftWindow(delta) {
    let month = windowStart[1] + delta;
    let year = windowStart[0];
    while (month < 0) {
        month += 12;
        year -= 1;
    }
    while (month > 11) {
        month -= 12;
        year += 1;
    }
    windowStart = [year, month];
    renderGrid();
}

function appendHeaderRow() {
    const names = monthNames();
    const corner = document.createElement('div');
    corner.className = 'roadmap-corner';
    corner.textContent = roadmapData.category_label || translate('roadmap.task');
    gridElement.appendChild(corner);
    for (let monthIndex = 0; monthIndex < monthCount; monthIndex += 1) {
        const cell = document.createElement('div');
        cell.className = 'roadmap-month' + (isQuarterStart(monthIndex) ? ' q-start' : '');
        const year = windowStart[0] + Math.floor((windowStart[1] + monthIndex) / 12);
        const month = (windowStart[1] + monthIndex) % 12;
        cell.textContent = names[month] + ' ' + year;
        gridElement.appendChild(cell);
    }
}

function recordHref(row) {
    return 'edit.php?table=' + encodeURIComponent(roadmapData.table) + '&id=' + encodeURIComponent(row.id);
}

function attachRecordTooltip(anchor, row) {
    anchor.addEventListener('mouseenter', () => {
        const columns = appSchema?.tables?.[roadmapData.table]?.columns || {};
        const rows = row.rowData
            ? rowsFromRecord(row.rowData, columns)
            : (Array.isArray(row.fields) ? row.fields.map(field => ({ label: field.label, value: field.value, color: null })) : []);
        showRecordTooltip(anchor, { title: row.title, rows });
    });
    anchor.addEventListener('mouseleave', hideRecordTooltip);
}

function appendEpicRow(row) {
    const label = document.createElement('div');
    label.className = 'roadmap-row-label';
    label.dataset.rowId = String(row.id);
    const dot = document.createElement('span');
    dot.className = 'row-dot';
    dot.style.backgroundColor = row.color;
    const nameSpan = document.createElement('span');
    nameSpan.textContent = row.title;
    label.append(dot, nameSpan);
    attachRecordTooltip(label, row);
    gridElement.appendChild(label);

    const cells = document.createElement('div');
    cells.className = 'roadmap-row-cells';
    for (let monthIndex = 0; monthIndex < monthCount; monthIndex += 1) {
        const cell = document.createElement('div');
        cell.className = 'roadmap-cell'
            + (isQuarterStart(monthIndex) ? ' q-start' : '')
            + (isQuarterShaded(monthIndex) ? ' q-shade' : '');
        cells.appendChild(cell);
    }
    gridElement.appendChild(cells);

    if (row.milestone) {
        const marker = document.createElement('a');
        marker.className = 'roadmap-milestone';
        marker.dataset.rowId = String(row.id);
        marker.dataset.offset = String(offsetFromWindow(row.start) ?? -1);
        marker.href = recordHref(row);
        marker.style.backgroundColor = row.color;
        attachRecordTooltip(marker, row);
        gridElement.appendChild(marker);
    } else {
        const bar = document.createElement('a');
        bar.className = 'roadmap-bar';
        bar.dataset.rowId = String(row.id);
        bar.dataset.startOffset = String(offsetFromWindow(row.start) ?? -1);
        bar.dataset.endOffset = String(offsetFromWindow(row.end) ?? -1);
        bar.href = recordHref(row);
        bar.style.backgroundColor = row.color;
        if (row.progress !== null) {
            const progress = document.createElement('span');
            progress.className = 'bar-progress';
            progress.style.width = row.progress + '%';
            if (row.progress === 100) {
                progress.style.borderRadius = 'var(--radius)';
            }
            const barLabel = document.createElement('span');
            barLabel.textContent = row.progress + '%';
            bar.append(progress, barLabel);
        }
        attachRecordTooltip(bar, row);
        gridElement.appendChild(bar);
    }
}

function appendTodayLine() {
    const today = new Date();
    const offset = monthsBetween(windowStart, [today.getFullYear(), today.getMonth()]);
    if (offset < 0 || offset >= monthCount) {
        return;
    }
    const line = document.createElement('div');
    line.className = 'roadmap-today';
    line.dataset.offset = String(offset);
    gridElement.appendChild(line);
}

function visibleRows() {
    if (!roadmapData) return [];
    return roadmapData.rows
        .filter(row => !hiddenCategories.has(row.category))
        .filter(rowMatchesSearch);
}

function renderGrid() {
    gridElement.textContent = '';
    if (!roadmapData) return;

    if (!roadmapData.configured) {
        const empty = document.createElement('div');
        empty.className = 'roadmap-empty';
        empty.textContent = translate('roadmap.not_configured');
        gridElement.appendChild(empty);
        return;
    }

    appendHeaderRow();
    const rows = visibleRows();
    if (rows.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'roadmap-empty';
        empty.textContent = translate('roadmap.empty');
        gridElement.appendChild(empty);
        return;
    }
    rows.forEach(row => appendEpicRow(row));
    appendTodayLine();
    positionOverlays();
}

function positionOverlays() {
    const rows = visibleRows();
    const rowLabels = [...gridElement.querySelectorAll('.roadmap-row-label')];
    const bars = [...gridElement.querySelectorAll('.roadmap-bar')];
    const milestones = [...gridElement.querySelectorAll('.roadmap-milestone')];
    const todayLine = gridElement.querySelector('.roadmap-today');

    rowLabels.forEach((label, rowIndex) => {
        const rowTop = headerRowHeight + rowIndex * rowHeight;
        const row = rows.find(candidate => String(candidate.id) === label.dataset.rowId);
        if (!row) return;

        bars.filter(bar => bar.dataset.rowId === label.dataset.rowId).forEach(bar => {
            const startOffset = Number(bar.dataset.startOffset);
            const endOffset = Number(bar.dataset.endOffset);
            if (startOffset < 0 || endOffset < 0) return;
            bar.style.top = (rowTop + rowHeight / 2 - 12) + 'px';
            bar.style.left = (labelWidth() + startOffset * cellWidth() + 3) + 'px';
            bar.style.width = ((endOffset - startOffset + 1) * cellWidth() - 6) + 'px';
        });

        milestones.filter(marker => marker.dataset.rowId === label.dataset.rowId).forEach(marker => {
            const offset = Number(marker.dataset.offset);
            if (offset < 0) return;
            marker.style.top = (rowTop + rowHeight / 2) + 'px';
            marker.style.left = (labelWidth() + offset * cellWidth() + cellWidth() / 2) + 'px';
        });
    });

    if (todayLine) {
        const offset = Number(todayLine.dataset.offset);
        todayLine.style.left = (labelWidth() + offset * cellWidth() + cellWidth() / 2) + 'px';
        todayLine.style.top = headerRowHeight + 'px';
        todayLine.style.height = (rowLabels.length * rowHeight) + 'px';
    }
}

function renderRoadmapList() {
    if (!window.ROADMAP_LIST || window.ROADMAP_LIST.length < 2) {
        selectElement.style.display = 'none';
        return;
    }
    selectElement.textContent = '';
    for (const entry of window.ROADMAP_LIST) {
        const option = document.createElement('option');
        option.value = entry.id;
        option.textContent = entry.menu_name;
        if (entry.id === window.ROADMAP_INITIAL) option.selected = true;
        selectElement.appendChild(option);
    }
    selectElement.addEventListener('change', () => {
        window.location.href = 'roadmap.php?roadmap=' + encodeURIComponent(selectElement.value);
    });
}

async function fetchSchema() {
    try {
        const result = await fetch('api/schema.php', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (result.ok) appSchema = await result.json();
    } catch (error) {
        console.error('Failed to load schema for roadmap', error);
    }
}

async function loadRoadmap() {
    gridElement.textContent = '';
    const loading = document.createElement('div');
    loading.className = 'roadmap-loading';
    loading.style.gridColumn = '1 / -1';
    loading.textContent = translate('common.loading');
    gridElement.appendChild(loading);

    const params = new URLSearchParams();
    params.set('api', 'roadmap');
    if (window.ROADMAP_INITIAL) params.set('roadmap', window.ROADMAP_INITIAL);

    try {
        const response = await apiFetch('api.php?' + params.toString());
        if (!response.ok) throw new Error('HTTP ' + response.status);
        roadmapData = await response.json();
        windowStart = defaultWindow(roadmapData.rows || []);
        if (roadmapData.menu_name) {
            titleElement.textContent = roadmapData.menu_name;
        }
        renderFilterBar();
        renderGrid();
    } catch (error) {
        gridElement.textContent = '';
        const empty = document.createElement('div');
        empty.className = 'roadmap-empty';
        empty.textContent = translate('roadmap.load_error');
        gridElement.appendChild(empty);
    }
}

prevButtonElement.addEventListener('click', () => shiftWindow(-3));
nextButtonElement.addEventListener('click', () => shiftWindow(3));
window.addEventListener('resize', () => renderGrid());

(async () => {
    await I18n.load();
    loadFilterState();
    initSearch();
    initClearFilters();
    renderRoadmapList();
    await fetchSchema();
    await loadRoadmap();
})();