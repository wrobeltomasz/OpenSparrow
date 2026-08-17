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
    } catch (_) {}
}
function t(key, vars = {}) {
    const v = _i18nBundle[key];
    if (!v) return key.split('.').pop();
    return String(v).replace(/\{(\w+)\}/g, (_, k) => k in vars ? String(vars[k]) : `{${k}}`);
}

let currentMonth = new Date().getMonth();
let currentYear = new Date().getFullYear();
let eventsData = [];
let appSchema = null;
let canEdit = false;

const FILTER_STORAGE_KEY = 'sparrow_calendar_filters';
let hiddenTables = new Set();

function loadFilterState() {
    try {
        const saved = JSON.parse(localStorage.getItem(FILTER_STORAGE_KEY) || '{}');
        hiddenTables = new Set(Array.isArray(saved.hiddenTables) ? saved.hiddenTables : []);
    } catch (_) {
        hiddenTables = new Set();
    }
}

function saveFilterState() {
    localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify({
        hiddenTables: [...hiddenTables]
    }));
}

function calendarSources() {
    return Array.isArray(window.CALENDAR_SOURCES) ? window.CALENDAR_SOURCES : [];
}

function tableLabel(table) {
    return appSchema?.tables?.[table]?.display_name || table;
}

let searchTerm = '';

function eventMatchesSearch(ev) {
    if (!searchTerm) return true;
    const parts = [ev.title, String(ev.id)];
    for (const [key, value] of Object.entries(ev.rowData || {})) {
        if (key.endsWith('__display') || value === null || value === undefined) continue;
        parts.push(String(ev.rowData[key + '__display'] ?? value));
    }
    return parts.join(' ').toLowerCase().includes(searchTerm);
}

function initSearch() {
    const input = document.getElementById('calendarSearch');
    if (!input) return;
    input.addEventListener('input', () => {
        searchTerm = input.value.trim().toLowerCase();
        renderCalendar();
    });
}

function updateClearButton() {
    const button = document.getElementById('clearFilters');
    if (button) button.hidden = !searchTerm && hiddenTables.size === 0;
}

function initClearFilters() {
    const button = document.getElementById('clearFilters');
    if (!button) return;
    button.addEventListener('click', () => {
        searchTerm = '';
        const input = document.getElementById('calendarSearch');
        if (input) input.value = '';
        hiddenTables.clear();
        saveFilterState();
        renderFilterBar();
        renderCalendar();
    });
}

function visibleEvents() {
    return eventsData.filter(ev => !hiddenTables.has(ev.table) && eventMatchesSearch(ev));
}

function buildSourceChip(source) {
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'filter-chip' + (hiddenTables.has(source.table) ? ' off' : '');

    const dot = document.createElement('span');
    dot.className = 'filter-dot';
    dot.style.backgroundColor = source.color;
    chip.appendChild(dot);
    chip.appendChild(document.createTextNode(tableLabel(source.table)));

    chip.addEventListener('click', () => {
        if (hiddenTables.has(source.table)) {
            hiddenTables.delete(source.table);
        } else {
            hiddenTables.add(source.table);
        }
        saveFilterState();
        renderFilterBar();
        renderCalendar();
    });
    return chip;
}

function renderFilterBar() {
    const bar = document.getElementById('calendarFilters');
    if (!bar) return;
    bar.innerHTML = '';
    calendarSources().forEach(source => bar.appendChild(buildSourceChip(source)));
}

document.addEventListener('DOMContentLoaded', async () => {
    canEdit = !!(window.USER_CAPS && window.USER_CAPS.canEdit);
    await fetchI18n();
    await fetchSchema();
    await fetchEvents(currentYear, currentMonth + 1);
    loadFilterState();
    renderFilterBar();
    initSearch();
    initClearFilters();
    renderCalendar();

    document.getElementById('btnPrev').addEventListener('click', async () => {
        currentMonth--;
        if (currentMonth < 0) {
            currentMonth = 11;
            currentYear--;
        }
        await fetchEvents(currentYear, currentMonth + 1);
        renderCalendar();
    });

    document.getElementById('btnNext').addEventListener('click', async () => {
        currentMonth++;
        if (currentMonth > 11) {
            currentMonth = 0;
            currentYear++;
        }
        await fetchEvents(currentYear, currentMonth + 1);
        renderCalendar();
    });
});

async function fetchSchema() {
    try {
        const result = await fetch('api/schema.php', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (result.ok) {
            appSchema = await result.json();
            window.schema = appSchema;
        } else {
            console.error('Failed to load secure schema');
        }
    } catch (error) {
        console.error('Failed to fetch schema in calendar', error);
    }
}

async function fetchEvents(year, month) {
    try {
        const result = await fetch(`api.php?api=calendar&year=${year}&month=${month}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (result.ok) {
            const data = await result.json();
            eventsData = data.events || [];
        }
    } catch (error) {
        console.error('Failed to load calendar events', error);
    }
}

function renderCalendar() {
    const container = document.getElementById('calendarContainer');
    const title = document.getElementById('calendarTitle');
    const monthEvents = visibleEvents();
    updateClearButton();

    container.innerHTML = '';

    const monthNames = [
        t('calendar.month_jan'), t('calendar.month_feb'), t('calendar.month_mar'),
        t('calendar.month_apr'), t('calendar.month_may'), t('calendar.month_jun'),
        t('calendar.month_jul'), t('calendar.month_aug'), t('calendar.month_sep'),
        t('calendar.month_oct'), t('calendar.month_nov'), t('calendar.month_dec'),
    ];
    title.textContent = `${monthNames[currentMonth]} ${currentYear}`;

    const days = [
        t('calendar.day_mon'), t('calendar.day_tue'), t('calendar.day_wed'),
        t('calendar.day_thu'), t('calendar.day_fri'), t('calendar.day_sat'), t('calendar.day_sun'),
    ];
    days.forEach(day => {
        const div = document.createElement('div');
        div.className = 'calendar-day-name';
        div.textContent = day;
        container.appendChild(div);
    });

    const firstDay = new Date(currentYear, currentMonth, 1);
    const lastDay = new Date(currentYear, currentMonth + 1, 0);

    let startDayOfWeek = firstDay.getDay() - 1;
    if (startDayOfWeek === -1) startDayOfWeek = 6;

    for (let i = 0; i < startDayOfWeek; i++) {
        const emptyCell = document.createElement('div');
        emptyCell.className = 'calendar-cell empty';
        container.appendChild(emptyCell);
    }

    for (let i = 1; i <= lastDay.getDate(); i++) {
        const cell = document.createElement('div');
        cell.className = 'calendar-cell';

        const dateNumber = document.createElement('div');
        dateNumber.className = 'calendar-date-num';
        dateNumber.textContent = i;
        cell.appendChild(dateNumber);

        const dateString = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;

        if (canEdit) {
            const addButton = document.createElement('button');
            addButton.type = 'button';
            addButton.className = 'calendar-add-btn';
            addButton.textContent = '+';
            addButton.title = t('calendar.add_event');
            addButton.setAttribute('aria-label', t('calendar.add_event'));
            addButton.addEventListener('click', (e) => {
                e.stopPropagation();
                openAddEventModal(dateString);
            });
            cell.appendChild(addButton);
        }

        const todayDate = new Date();
        if (i === todayDate.getDate() &&
            currentMonth === todayDate.getMonth() &&
            currentYear === todayDate.getFullYear()) {
            cell.classList.add('today');
        }

        cell.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            cell.style.outline = '2px solid var(--accent)';
        });

        cell.addEventListener('dragleave', () => {
            cell.style.outline = '';
        });

        cell.addEventListener('drop', async (e) => {
            e.preventDefault();
            cell.style.outline = '';

            let payload;
            try {
                payload = JSON.parse(e.dataTransfer.getData('application/json'));
            } catch {
                return;
            }

            if (payload.date === dateString) return;

            const eventIndex = eventsData.findIndex(ev => ev.id === payload.id && ev.table === payload.table);
            const originalDate = payload.date;

            if (eventIndex !== -1) {
                eventsData[eventIndex].date = dateString;
                renderCalendar();
            }

            try {
                const result = await apiFetch('api.php', {
                    method: 'POST',
                    body: {
                        api: 'calendar',
                        action: 'move_event',
                        id: payload.id,
                        table: payload.table,
                        newDate: dateString
                    }
                });

                const data = await result.json();

                if (!result.ok || data.error) {
                    if (eventIndex !== -1) {
                        eventsData[eventIndex].date = originalDate;
                        renderCalendar();
                    }
                    console.error('Failed to move event:', data.error ?? result.status);
                }
            } catch (error) {
                if (eventIndex !== -1) {
                    eventsData[eventIndex].date = originalDate;
                    renderCalendar();
                }
                console.error('Network error during event move:', error);
            }
        });

        const dayEvents = monthEvents.filter(e => e.date === dateString);
        dayEvents.forEach(ev => {
            const evElement = document.createElement('div');
            evElement.className = 'calendar-event';
            evElement.style.backgroundColor = ev.color;

            evElement.draggable = true;

            evElement.addEventListener('dragstart', (e) => {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('application/json', JSON.stringify({
                    id: ev.id,
                    table: ev.table,
                    date: ev.date
                }));
                evElement.style.opacity = '0.4';
            });

            evElement.addEventListener('dragend', () => {
                evElement.style.opacity = '';
            });

            if (ev.icon) {
                if (ev.icon.includes('/') || ev.icon.includes('.')) {
                    const image = document.createElement('img');
                    image.src = ev.icon;
                    image.style.cssText = 'width:14px; height:14px; vertical-align:middle; margin-right:4px;';
                    evElement.appendChild(image);
                } else {
                    const iconSpan = document.createElement('span');
                    iconSpan.style.marginRight = '4px';
                    iconSpan.textContent = ev.icon;
                    evElement.appendChild(iconSpan);
                }
            }

            const titleText = document.createTextNode(ev.title);
            evElement.appendChild(titleText);

            if (ev.subtitle) {
                const subSpan = document.createElement('span');
                subSpan.className = 'calendar-event-sub';
                subSpan.textContent = ' · ' + ev.subtitle;
                evElement.appendChild(subSpan);
            }

            evElement.addEventListener('click', () => {
                window.location.href = `edit.php?table=${encodeURIComponent(ev.table)}&id=${encodeURIComponent(ev.id)}`;
            });

            if (canEdit) {
                const delButton = document.createElement('button');
                delButton.type = 'button';
                delButton.className = 'calendar-event-del';
                delButton.textContent = '✕';
                delButton.title = t('calendar.delete_event');
                delButton.setAttribute('aria-label', t('calendar.delete_event'));
                delButton.addEventListener('click', (e) => {
                    e.stopPropagation();
                    deleteEvent(ev);
                });
                evElement.appendChild(delButton);
            }

            evElement.addEventListener('mouseenter', () => {
                const columns = appSchema?.tables?.[ev.table]?.columns || {};
                showRecordTooltip(evElement, {
                    title: ev.title,
                    rows: rowsFromRecord(ev.rowData || {}, columns)
                });
            });
            evElement.addEventListener('mouseleave', hideRecordTooltip);

            cell.appendChild(evElement);
        });

        container.appendChild(cell);
    }
}

async function deleteEvent(ev) {
    hideRecordTooltip();
    if (!window.confirm(t('calendar.delete_confirm'))) return;

    const eventIndex = eventsData.findIndex(e => e.id === ev.id && e.table === ev.table);
    if (eventIndex === -1) return;
    const removed = eventsData[eventIndex];

    eventsData.splice(eventIndex, 1);
    renderCalendar();

    try {
        const result = await apiFetch('api.php', {
            method: 'DELETE',
            body: { table: ev.table, id: ev.id }
        });
        const data = await result.json().catch(() => ({}));

        if (!result.ok || data.error) {
            eventsData.splice(eventIndex, 0, removed);
            renderCalendar();
            console.error('Failed to delete event:', data.error ?? result.status);
        }
    } catch (error) {
        eventsData.splice(eventIndex, 0, removed);
        renderCalendar();
        console.error('Network error during event delete:', error);
    }
}

function openAddEventModal(dateString) {
    const sources = calendarSources();

    const backdrop = document.createElement('div');
    backdrop.className = 'cal-modal-backdrop';

    const modal = document.createElement('div');
    modal.className = 'cal-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'cal-modal-close';
    closeButton.textContent = '✕';
    closeButton.setAttribute('aria-label', t('common.cancel'));
    modal.appendChild(closeButton);

    const title = document.createElement('h3');
    title.className = 'cal-modal-title';
    title.id = 'calModalTitle';
    title.textContent = t('calendar.add_event_title', { date: dateString });
    modal.setAttribute('aria-labelledby', title.id);
    modal.appendChild(title);

    let select = null;
    let confirmButton = null;

    if (sources.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'cal-modal-empty';
        empty.textContent = t('calendar.no_calendars_configured');
        modal.appendChild(empty);
    } else {
        const label = document.createElement('label');
        label.className = 'cal-modal-label';
        label.setAttribute('for', 'calModalSelect');
        label.textContent = t('calendar.select_calendar');
        modal.appendChild(label);

        select = document.createElement('select');
        select.id = 'calModalSelect';
        select.className = 'cal-modal-select';
        sources.forEach(source => {
            const option = document.createElement('option');
            option.value = source.table;
            option.textContent = tableLabel(source.table);
            select.appendChild(option);
        });
        modal.appendChild(select);
    }

    const actions = document.createElement('div');
    actions.className = 'cal-modal-actions';

    const cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'btn-cancel';
    cancelButton.textContent = t('common.cancel');
    actions.appendChild(cancelButton);

    if (sources.length > 0) {
        confirmBtn: confirmButton = document.createElement('button');
        confirmButton.type = 'button';
        confirmButton.className = 'btn-save';
        confirmButton.textContent = t('common.add');
        actions.appendChild(confirmButton);
    }

    modal.appendChild(actions);
    backdrop.appendChild(modal);
    document.body.appendChild(backdrop);

    function close() {
        document.removeEventListener('keydown', onKeydown);
        backdrop.remove();
    }

    function onKeydown(e) {
        if (e.key === 'Escape') close();
    }

    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) close();
    });
    closeButton.addEventListener('click', close);
    cancelButton.addEventListener('click', close);
    document.addEventListener('keydown', onKeydown);

    if (confirmButton && select) {
        confirmButton.addEventListener('click', () => {
            const table = select.value;
            const source = sources.find(s => s.table === table);
            if (!source) return;

            const columnType = (appSchema?.tables?.[table]?.columns?.[source.date_column]?.type || '').toLowerCase();
            const value = columnType === 'timestamp' ? `${dateString}T00:00:00` : dateString;

            window.location.href = `create.php?table=${encodeURIComponent(table)}&${encodeURIComponent(source.date_column)}=${encodeURIComponent(value)}`;
        });
        select.focus();
    } else {
        closeButton.focus();
    }
}
