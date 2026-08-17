// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from './util/api.js';
import { showRecordTooltip, hideRecordTooltip, rowsFromRecord } from './util/record-tooltip.js';

let _i18nBundle = {};
async function fetchI18n() {
    try {
        const result = await fetch('/api.php?action=i18n_bundle', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (result.ok) _i18nBundle = await result.json();
    } catch (_) {  }
}
function t(key, vars = {}) {
    const v = _i18nBundle[key];
    if (!v) return key.split('.').pop();
    return String(v).replace(/\{(\w+)\}/g, (_, k) => (k in vars ? String(vars[k]) : `{${k}}`));
}

const UNMATCHED = '__unmatched__';

let board = null;
let cards = [];
let canEdit = false;
let appSchema = null;

let searchTerm = '';

function cardMatchesSearch(card) {
    if (!searchTerm) return true;
    const haystack = [
        card.title,
        String(card.id),
        ...(Array.isArray(card.fields) ? card.fields.map(f => f.value) : [])
    ].join(' ').toLowerCase();
    return haystack.includes(searchTerm);
}

function initSearch() {
    const input = document.getElementById('boardSearch');
    if (!input) return;
    input.addEventListener('input', () => {
        searchTerm = input.value.trim().toLowerCase();
        render();
    });
}

function updateClearButton() {
    const button = document.getElementById('clearFilters');
    if (button) button.hidden = !searchTerm && hiddenLanes.size === 0;
}

function initClearFilters() {
    const button = document.getElementById('clearFilters');
    if (!button) return;
    button.addEventListener('click', () => {
        searchTerm = '';
        const input = document.getElementById('boardSearch');
        if (input) input.value = '';
        hiddenLanes.clear();
        saveFilterState();
        render();
    });
}

const FILTER_STORAGE_KEY = 'sparrow_board_filters';
let hiddenLanes = new Set();

function loadFilterState() {
    try {
        const saved = JSON.parse(localStorage.getItem(FILTER_STORAGE_KEY) || '{}');
        hiddenLanes = new Set(Array.isArray(saved.hiddenLanes) ? saved.hiddenLanes : []);
    } catch (_) {
        hiddenLanes = new Set();
    }
}

function saveFilterState() {
    localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify({ hiddenLanes: [...hiddenLanes] }));
}

function buildLaneChip(lane) {
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'filter-chip' + (hiddenLanes.has(lane.value) ? ' off' : '');

    const dot = document.createElement('span');
    dot.className = 'filter-dot';
    dot.style.backgroundColor = lane.color;
    chip.appendChild(dot);
    chip.appendChild(document.createTextNode(lane.label));

    chip.addEventListener('click', () => {
        if (hiddenLanes.has(lane.value)) {
            hiddenLanes.delete(lane.value);
        } else {
            hiddenLanes.add(lane.value);
        }
        saveFilterState();
        render();
    });
    return chip;
}

function renderFilterBar(lanes) {
    const bar = document.getElementById('boardFilters');
    if (!bar) return;
    bar.innerHTML = '';
    lanes.forEach(lane => bar.appendChild(buildLaneChip(lane)));
}

document.addEventListener('DOMContentLoaded', async () => {
    canEdit = !!(window.USER_CAPS && window.USER_CAPS.canEdit);
    await fetchI18n();
    await fetchSchema();
    await fetchBoard();
    loadFilterState();
    initSearch();
    initClearFilters();
    render();
});

async function fetchSchema() {
    try {
        const result = await fetch('api/schema.php', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (result.ok) appSchema = await result.json();
    } catch (err) {
        console.error('Failed to load schema for board', err);
    }
}

async function fetchBoard() {
    try {
        const boardId = window.BOARD_INITIAL || '';
        const result = await fetch('api.php?api=board&board=' + encodeURIComponent(boardId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (result.ok) {
            board = await result.json();
            cards = Array.isArray(board.cards) ? board.cards.map(c => ({ ...c })) : [];
        }
    } catch (err) {
        console.error('Failed to load board', err);
    }
}

function render() {
    const container = document.getElementById('boardContainer');
    const titleElement = document.getElementById('boardTitle');
    const metaElement = document.getElementById('boardMeta');
    const filtersElement = document.getElementById('boardFilters');
    if (!container) return;
    container.innerHTML = '';
    metaElement.textContent = '';
    if (filtersElement) filtersElement.innerHTML = '';
    updateClearButton();

    if (!board) {
        renderNotice(container, t('board.load_error'));
        return;
    }

    titleElement.textContent = board.menu_name || t('board.title');

    if (!board.configured) {
        renderNotice(container, t('board.not_configured'));
        return;
    }

    if (board.table_label) {
        const lane = document.createElement('span');
        lane.textContent = board.table_label;
        metaElement.appendChild(lane);
        if (board.status_label) {
            const by = document.createElement('span');
            by.className = 'board-meta-by';
            by.textContent = t('board.grouped_by', { column: board.status_label });
            metaElement.appendChild(by);
        }
    }

    const byStatus = {};
    const laneValues = new Set((board.columns || []).map(l => l.value));
    cards.filter(cardMatchesSearch).forEach(card => {
        const key = laneValues.has(card.status) ? card.status : UNMATCHED;
        (byStatus[key] = byStatus[key] || []).push(card);
    });

    const filterLanes = (board.columns || []).map(l => ({ value: l.value, label: l.label, color: l.color }));
    const hasUnmatched = (byStatus[UNMATCHED] || []).length > 0;
    if (hasUnmatched) {
        filterLanes.push({ value: UNMATCHED, label: t('board.uncategorized'), color: '#8A9199' });
    }
    renderFilterBar(filterLanes);

    (board.columns || []).forEach(lane => {
        if (hiddenLanes.has(lane.value)) return;
        container.appendChild(buildLane(lane.value, lane.label, lane.color, byStatus[lane.value] || [], true));
    });

    if (hasUnmatched && !hiddenLanes.has(UNMATCHED)) {
        container.appendChild(buildLane(UNMATCHED, t('board.uncategorized'), '#8A9199', byStatus[UNMATCHED], false));
    }

    if (container.children.length === 0 && filterLanes.length === 0) {
        renderNotice(container, t('board.not_configured'));
    }
}

function renderNotice(container, message) {
    const p = document.createElement('p');
    p.className = 'board-notice';
    p.textContent = message;
    container.appendChild(p);
}

function buildLane(value, label, color, laneCards, droppable) {
    const lane = document.createElement('section');
    lane.className = 'board-lane';
    lane.dataset.status = value;

    const header = document.createElement('div');
    header.className = 'board-lane-header';
    header.style.borderTopColor = color;

    const dot = document.createElement('span');
    dot.className = 'board-lane-dot';
    dot.style.backgroundColor = color;

    const titleSpan = document.createElement('span');
    titleSpan.className = 'board-lane-title';
    titleSpan.textContent = label;

    const count = document.createElement('span');
    count.className = 'board-lane-count';
    count.textContent = String(laneCards.length);

    header.appendChild(dot);
    header.appendChild(titleSpan);
    header.appendChild(count);
    lane.appendChild(header);

    const body = document.createElement('div');
    body.className = 'board-lane-body';

    if (canEdit && droppable) {
        body.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            lane.classList.add('drag-over');
        });
        body.addEventListener('dragleave', (e) => {
            if (!body.contains(e.relatedTarget)) lane.classList.remove('drag-over');
        });
        body.addEventListener('drop', (e) => {
            e.preventDefault();
            lane.classList.remove('drag-over');
            let payload;
            try {
                payload = JSON.parse(e.dataTransfer.getData('application/json'));
            } catch {
                return;
            }
            if (payload.status === value) return;
            moveCard(payload.id, value, payload.status);
        });
    }

    if (laneCards.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'board-lane-empty';
        empty.textContent = t('board.empty_lane');
        body.appendChild(empty);
    } else {
        laneCards.forEach(card => body.appendChild(buildCard(card, color)));
    }

    lane.appendChild(body);
    return lane;
}

function buildCard(card, laneColor) {
    const element = document.createElement('article');
    element.className = 'board-card';
    element.style.borderLeftColor = laneColor;
    element.dataset.id = card.id;

    if (canEdit) {
        element.draggable = true;
        element.addEventListener('dragstart', (e) => {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('application/json', JSON.stringify({ id: card.id, status: card.status }));
            element.classList.add('dragging');
        });
        element.addEventListener('dragend', () => element.classList.remove('dragging'));
    }

    const title = document.createElement('div');
    title.className = 'board-card-title';
    title.textContent = card.title;
    element.appendChild(title);

    if (Array.isArray(card.fields) && card.fields.length > 0) {
        const fields = document.createElement('dl');
        fields.className = 'board-card-fields';
        card.fields.forEach(f => {
            const dt = document.createElement('dt');
            dt.textContent = f.label;
            const dd = document.createElement('dd');
            dd.textContent = f.value;
            fields.appendChild(dt);
            fields.appendChild(dd);
        });
        element.appendChild(fields);
    }

    const idTag = document.createElement('span');
    idTag.className = 'board-card-id';
    idTag.textContent = '#' + card.id;
    element.appendChild(idTag);

    element.addEventListener('click', () => {
        window.location.href = `edit.php?table=${encodeURIComponent(board.table)}&id=${encodeURIComponent(card.id)}`;
    });

    element.addEventListener('mouseenter', () => {
        const columns = appSchema?.tables?.[board.table]?.columns || {};
        const rows = card.rowData
            ? rowsFromRecord(card.rowData, columns)
            : (Array.isArray(card.fields) ? card.fields.map(f => ({ label: f.label, value: f.value, color: null })) : []);
        showRecordTooltip(element, { title: card.title, rows });
    });
    element.addEventListener('mouseleave', hideRecordTooltip);

    return element;
}

async function moveCard(id, newStatus, oldStatus) {
    const card = cards.find(c => String(c.id) === String(id));
    if (!card) return;

    card.status = newStatus;
    render();

    try {
        const result = await apiFetch('api.php', {
            method: 'POST',
            body: {
                api: 'board',
                action: 'move_card',
                board: window.BOARD_INITIAL || '',
                table: board.table,
                id,
                newStatus
            }
        });
        const data = await result.json().catch(() => ({}));
        if (!result.ok || data.error) {
            card.status = oldStatus;
            render();
            console.error('Failed to move card:', data.error ?? result.status);
        }
    } catch (err) {
        card.status = oldStatus;
        render();
        console.error('Network error during card move:', err);
    }
}
