// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from './util/api.js';
import { showRecordTooltip, hideRecordTooltip, rowsFromRecord } from './util/record-tooltip.js';
import { enablePointerDrag, registerDropTarget } from './util/touch-dnd.js';

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
    const translation = _i18nBundle[key];
    if (!translation) return key.split('.').pop();
    return String(translation).replace(/\{(\w+)\}/g, (_, variableName) => variableName in vars ? String(vars[variableName]) : `{${variableName}}`);
}

let viewDate = new Date();
let viewMode = 'month';
let eventsData = [];
let appSchema = null;
let canEdit = false;

const FILTER_STORAGE_KEY = 'sparrow_calendar_filters';
const VIEW_STORAGE_KEY = 'sparrow_calendar_view';
const WEEK_SLOT_HEIGHT = 48;
let hiddenTables = new Set();

function pad2(value) {
    return String(value).padStart(2, '0');
}

function toDateString(date) {
    return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

function addDays(date, days) {
    const result = new Date(date);
    result.setDate(result.getDate() + days);
    return result;
}

function mondayOf(date) {
    const result = new Date(date);
    const weekdayOffset = (result.getDay() + 6) % 7;
    result.setDate(result.getDate() - weekdayOffset);
    return result;
}

function sameDate(first, second) {
    return first.getFullYear() === second.getFullYear()
        && first.getMonth() === second.getMonth()
        && first.getDate() === second.getDate();
}

function monthNames() {
    return [
        t('calendar.month_jan'), t('calendar.month_feb'), t('calendar.month_mar'),
        t('calendar.month_apr'), t('calendar.month_may'), t('calendar.month_jun'),
        t('calendar.month_jul'), t('calendar.month_aug'), t('calendar.month_sep'),
        t('calendar.month_oct'), t('calendar.month_nov'), t('calendar.month_dec'),
    ];
}

function dayNames() {
    return [
        t('calendar.day_mon'), t('calendar.day_tue'), t('calendar.day_wed'),
        t('calendar.day_thu'), t('calendar.day_fri'), t('calendar.day_sat'), t('calendar.day_sun'),
    ];
}

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

function loadViewMode() {
    viewMode = localStorage.getItem(VIEW_STORAGE_KEY) === 'week' ? 'week' : 'month';
}

function calendarSources() {
    return Array.isArray(window.CALENDAR_SOURCES) ? window.CALENDAR_SOURCES : [];
}

function tableLabel(table) {
    return appSchema?.tables?.[table]?.display_name || table;
}

let searchTerm = '';

function eventMatchesSearch(event) {
    if (!searchTerm) return true;
    const parts = [event.title, String(event.id)];
    for (const [key, fieldValue] of Object.entries(event.rowData || {})) {
        if (key.endsWith('__display') || fieldValue === null || fieldValue === undefined) continue;
        parts.push(String(event.rowData[key + '__display'] ?? fieldValue));
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
    return eventsData.filter(event => !hiddenTables.has(event.table) && eventMatchesSearch(event));
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

function currentRange() {
    if (viewMode === 'week') {
        const monday = mondayOf(viewDate);
        return { from: toDateString(monday), to: toDateString(addDays(monday, 6)) };
    }
    return {
        from: toDateString(new Date(viewDate.getFullYear(), viewDate.getMonth(), 1)),
        to: toDateString(new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 0)),
    };
}

async function fetchEvents() {
    const range = currentRange();
    try {
        const result = await fetch(`api.php?api=calendar&from=${range.from}&to=${range.to}`, {
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

async function navigate(direction) {
    if (viewMode === 'week') {
        viewDate = addDays(viewDate, direction * 7);
    } else {
        viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + direction, 1);
    }
    await fetchEvents();
    renderCalendar({ scrollToCurrentHour: true });
}

async function setViewMode(mode) {
    if (viewMode === mode) return;
    viewMode = mode;
    localStorage.setItem(VIEW_STORAGE_KEY, mode);
    updateViewToggle();
    await fetchEvents();
    renderCalendar({ scrollToCurrentHour: true });
}

function updateViewToggle() {
    const monthButton = document.getElementById('btnViewMonth');
    const weekButton = document.getElementById('btnViewWeek');
    if (monthButton) {
        monthButton.classList.toggle('active', viewMode === 'month');
        monthButton.setAttribute('aria-pressed', viewMode === 'month' ? 'true' : 'false');
    }
    if (weekButton) {
        weekButton.classList.toggle('active', viewMode === 'week');
        weekButton.setAttribute('aria-pressed', viewMode === 'week' ? 'true' : 'false');
    }
}

function initViewToggle() {
    const monthButton = document.getElementById('btnViewMonth');
    const weekButton = document.getElementById('btnViewWeek');
    if (!monthButton || !weekButton) return;
    monthButton.addEventListener('click', () => setViewMode('month'));
    weekButton.addEventListener('click', () => setViewMode('week'));
    updateViewToggle();
}

document.addEventListener('DOMContentLoaded', async () => {
    canEdit = !!(window.USER_CAPS && window.USER_CAPS.canEdit);
    await fetchI18n();
    await fetchSchema();
    loadFilterState();
    loadViewMode();
    renderFilterBar();
    initSearch();
    initClearFilters();
    initViewToggle();
    await fetchEvents();
    renderCalendar({ scrollToCurrentHour: true });

    document.getElementById('btnPrev').addEventListener('click', () => navigate(-1));
    document.getElementById('btnNext').addEventListener('click', () => navigate(1));
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

function buildEventPayload(event) {
    const source = calendarSources().find(sourceEntry => sourceEntry.table === event.table);
    const dateColumn = source?.date_column || '';
    const columnType = (appSchema?.tables?.[event.table]?.columns?.[dateColumn]?.type || '').toLowerCase();
    return {
        id: event.id,
        table: event.table,
        date: event.date,
        time: event.time || '',
        timed: event.time !== '' && columnType.startsWith('timestamp')
    };
}

function buildEventChip(event, options = {}) {
    const payload = buildEventPayload(event);

    const chip = document.createElement('div');
    chip.className = 'calendar-event';
    chip.style.backgroundColor = event.color;

    chip.draggable = true;

    chip.addEventListener('dragstart', (dragEvent) => {
        dragEvent.dataTransfer.effectAllowed = 'move';
        dragEvent.dataTransfer.setData('application/json', JSON.stringify(payload));
        chip.style.opacity = '0.4';
    });

    chip.addEventListener('dragend', () => {
        chip.style.opacity = '';
    });

    if (canEdit) {
        enablePointerDrag(chip, { payload, direction: 'all' });
    }

    if (event.icon) {
        if (event.icon.includes('/') || event.icon.includes('.')) {
            const image = document.createElement('img');
            image.src = event.icon;
            image.style.cssText = 'width:14px; height:14px; vertical-align:middle; margin-right:4px;';
            chip.appendChild(image);
        } else {
            const iconSpan = document.createElement('span');
            iconSpan.style.marginRight = '4px';
            iconSpan.textContent = event.icon;
            chip.appendChild(iconSpan);
        }
    }

    if (options.showTime && event.time) {
        const timeSpan = document.createElement('span');
        timeSpan.className = 'calendar-event-time';
        timeSpan.textContent = event.time;
        chip.appendChild(timeSpan);
    }

    chip.appendChild(document.createTextNode(event.title));

    if (!options.showTime && event.subtitle) {
        const subSpan = document.createElement('span');
        subSpan.className = 'calendar-event-sub';
        subSpan.textContent = ' · ' + event.subtitle;
        chip.appendChild(subSpan);
    }

    chip.addEventListener('click', () => {
        window.location.href = `edit.php?table=${encodeURIComponent(event.table)}&id=${encodeURIComponent(event.id)}`;
    });

    if (canEdit) {
        const delButton = document.createElement('button');
        delButton.type = 'button';
        delButton.className = 'calendar-event-del';
        delButton.textContent = '✕';
        delButton.title = t('calendar.delete_event');
        delButton.setAttribute('aria-label', t('calendar.delete_event'));
        delButton.addEventListener('click', (clickEvent) => {
            clickEvent.stopPropagation();
            deleteEvent(event);
        });
        chip.appendChild(delButton);
    }

    chip.addEventListener('mouseenter', () => {
        const columns = appSchema?.tables?.[event.table]?.columns || {};
        showRecordTooltip(chip, {
            title: event.title,
            rows: rowsFromRecord(event.rowData || {}, columns)
        });
    });
    chip.addEventListener('mouseleave', hideRecordTooltip);

    return chip;
}

function wireDayDropTarget(cell, dateString) {
    cell.addEventListener('dragover', (event) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        cell.classList.add('drop-target');
    });

    cell.addEventListener('dragleave', () => {
        cell.classList.remove('drop-target');
    });

    cell.addEventListener('drop', (event) => {
        event.preventDefault();
        cell.classList.remove('drop-target');

        let payload;
        try {
            payload = JSON.parse(event.dataTransfer.getData('application/json'));
        } catch {
            return;
        }

        moveEventTo(payload, dateString);
    });

    if (canEdit) {
        registerDropTarget(cell, {
            onDrop: (payload) => moveEventTo(payload, dateString)
        });
    }
}

function timeFromPointer(container, clientY) {
    const bounds = container.getBoundingClientRect();
    const fraction = Math.min(Math.max((clientY - bounds.top) / bounds.height, 0), 1);
    const minutes = Math.round((fraction * 1440) / 15) * 15;
    if (minutes >= 1440) return '23:45';
    return `${pad2(Math.floor(minutes / 60))}:${pad2(minutes % 60)}`;
}

function slotFromPointer(column, clientY) {
    const bounds = column.getBoundingClientRect();
    const fraction = Math.min(Math.max((clientY - bounds.top) / bounds.height, 0), 0.999);
    return column.children[Math.floor(fraction * 24)];
}

function timeFromSlotHour(payload, hour) {
    const originalMinutes = payload.time ? parseInt(payload.time.slice(3, 5), 10) : 0;
    return `${pad2(hour)}:${pad2(originalMinutes)}`;
}

function wireWeekColumn(column, dateString) {
    let highlightedSlot = null;

    function highlight(slot) {
        if (highlightedSlot === slot) return;
        if (highlightedSlot) highlightedSlot.classList.remove('drop-target');
        highlightedSlot = slot;
        if (highlightedSlot) highlightedSlot.classList.add('drop-target');
    }

    column.addEventListener('dragover', (event) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        highlight(slotFromPointer(column, event.clientY));
    });

    column.addEventListener('dragleave', () => highlight(null));

    column.addEventListener('drop', (event) => {
        event.preventDefault();
        highlight(null);

        let payload;
        try {
            payload = JSON.parse(event.dataTransfer.getData('application/json'));
        } catch {
            return;
        }

        moveEventTo(payload, dateString, timeFromPointer(column, event.clientY));
    });
}

function buildAddButton(dateString) {
    const addButton = document.createElement('button');
    addButton.type = 'button';
    addButton.className = 'calendar-add-btn';
    addButton.textContent = '+';
    addButton.title = t('calendar.add_event');
    addButton.setAttribute('aria-label', t('calendar.add_event'));
    addButton.addEventListener('click', (event) => {
        event.stopPropagation();
        openAddEventModal(dateString);
    });
    return addButton;
}

function renderCalendar(options = {}) {
    const container = document.getElementById('calendarContainer');
    const title = document.getElementById('calendarTitle');
    updateClearButton();

    container.innerHTML = '';
    container.classList.toggle('week-mode', viewMode === 'week');

    if (viewMode === 'week') {
        renderWeekTitle(title);
        renderWeek(container, visibleEvents());
        if (options.scrollToCurrentHour) scrollWeekToCurrentHour();
    } else {
        renderMonthTitle(title);
        renderMonth(container, visibleEvents());
    }
}

function renderMonthTitle(titleElement) {
    const names = monthNames();
    titleElement.textContent = `${names[viewDate.getMonth()]} ${viewDate.getFullYear()}`;
}

function renderWeekTitle(titleElement) {
    const names = monthNames();
    const monday = mondayOf(viewDate);
    const sunday = addDays(monday, 6);
    if (monday.getFullYear() === sunday.getFullYear()) {
        titleElement.textContent = `${monday.getDate()} ${names[monday.getMonth()]} – ${sunday.getDate()} ${names[sunday.getMonth()]} ${sunday.getFullYear()}`;
    } else {
        titleElement.textContent = `${monday.getDate()} ${names[monday.getMonth()]} ${monday.getFullYear()} – ${sunday.getDate()} ${names[sunday.getMonth()]} ${sunday.getFullYear()}`;
    }
}

function renderMonth(container, monthEvents) {
    const days = dayNames();
    days.forEach(day => {
        const wrapper = document.createElement('div');
        wrapper.className = 'calendar-day-name';
        wrapper.textContent = day;
        container.appendChild(wrapper);
    });

    const year = viewDate.getFullYear();
    const month = viewDate.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);

    let startDayOfWeek = firstDay.getDay() - 1;
    if (startDayOfWeek === -1) startDayOfWeek = 6;

    for (let i = 0; i < startDayOfWeek; i++) {
        const emptyCell = document.createElement('div');
        emptyCell.className = 'calendar-cell empty';
        container.appendChild(emptyCell);
    }

    const todayDate = new Date();

    for (let i = 1; i <= lastDay.getDate(); i++) {
        const cell = document.createElement('div');
        cell.className = 'calendar-cell';

        const dateNumber = document.createElement('div');
        dateNumber.className = 'calendar-date-num';
        dateNumber.textContent = i;
        cell.appendChild(dateNumber);

        const dateString = `${year}-${pad2(month + 1)}-${pad2(i)}`;

        if (canEdit) {
            cell.appendChild(buildAddButton(dateString));
        }

        if (i === todayDate.getDate() &&
            month === todayDate.getMonth() &&
            year === todayDate.getFullYear()) {
            cell.classList.add('today');
        }

        wireDayDropTarget(cell, dateString);

        const dayEvents = monthEvents.filter(event => event.date === dateString);
        dayEvents.forEach(event => {
            cell.appendChild(buildEventChip(event));
        });

        container.appendChild(cell);
    }
}

function scrollWeekToCurrentHour() {
    const now = new Date();
    const monday = mondayOf(viewDate);
    const isCurrentWeek = [...Array(7)].some((_, index) => sameDate(addDays(monday, index), now));
    if (!isCurrentWeek) return;
    const weekBody = document.querySelector('#calendarContainer .cal-week-body');
    if (!weekBody) return;
    const targetOffset = now.getHours() * WEEK_SLOT_HEIGHT - weekBody.clientHeight / 2;
    weekBody.scrollTop = Math.max(0, targetOffset);
}

function renderWeek(container, weekEvents) {
    const monday = mondayOf(viewDate);
    const weekDays = [...Array(7)].map((_, index) => addDays(monday, index));
    const days = dayNames();
    const today = new Date();

    const week = document.createElement('div');
    week.className = 'cal-week';

    const head = document.createElement('div');
    head.className = 'cal-week-head';

    const headGutter = document.createElement('div');
    headGutter.className = 'cal-week-gutter';
    head.appendChild(headGutter);

    weekDays.forEach((day, index) => {
        const headCell = document.createElement('div');
        headCell.className = 'cal-week-day' + (sameDate(day, today) ? ' today' : '');

        const dayName = document.createElement('span');
        dayName.className = 'cal-week-dayname';
        dayName.textContent = days[index];
        headCell.appendChild(dayName);

        const dayNumber = document.createElement('span');
        dayNumber.className = 'cal-week-daynum';
        dayNumber.textContent = day.getDate();
        headCell.appendChild(dayNumber);

        if (canEdit) {
            headCell.appendChild(buildAddButton(toDateString(day)));
        }

        head.appendChild(headCell);
    });
    week.appendChild(head);

    const allday = document.createElement('div');
    allday.className = 'cal-week-allday';

    const alldayGutter = document.createElement('div');
    alldayGutter.className = 'cal-week-gutter cal-week-allday-label';
    alldayGutter.textContent = t('calendar.all_day');
    allday.appendChild(alldayGutter);

    weekDays.forEach(day => {
        const dateString = toDateString(day);
        const alldayCell = document.createElement('div');
        alldayCell.className = 'cal-week-allday-cell' + (sameDate(day, today) ? ' today' : '');
        alldayCell.setAttribute('data-date', dateString);

        wireDayDropTarget(alldayCell, dateString);

        const alldayEvents = weekEvents.filter(event => event.date === dateString && (event.allDay || !event.time));
        alldayEvents.forEach(event => {
            alldayCell.appendChild(buildEventChip(event));
        });

        allday.appendChild(alldayCell);
    });
    week.appendChild(allday);

    const body = document.createElement('div');
    body.className = 'cal-week-body';

    const hourGutter = document.createElement('div');
    hourGutter.className = 'cal-week-gutter cal-week-hours';
    for (let hour = 0; hour < 24; hour++) {
        const hourLabel = document.createElement('div');
        hourLabel.className = 'cal-week-hour';
        hourLabel.textContent = `${pad2(hour)}:00`;
        hourGutter.appendChild(hourLabel);
    }
    body.appendChild(hourGutter);

    weekDays.forEach(day => {
        const dateString = toDateString(day);
        const column = document.createElement('div');
        column.className = 'cal-week-col' + (sameDate(day, today) ? ' today' : '');

        for (let hour = 0; hour < 24; hour++) {
            const slot = document.createElement('div');
            slot.className = 'cal-week-slot';
            slot.dataset.hour = String(hour);
            if (canEdit) {
                registerDropTarget(slot, {
                    onDrop: (payload) => moveEventTo(payload, dateString, timeFromSlotHour(payload, hour))
                });
            }
            column.appendChild(slot);
        }

        wireWeekColumn(column, dateString);

        let lastTop = -24;
        const timedEvents = weekEvents
            .filter(event => event.date === dateString && !event.allDay && event.time)
            .sort((first, second) => first.time.localeCompare(second.time));

        timedEvents.forEach(event => {
            const chip = buildEventChip(event, { showTime: true });
            const minutes = parseInt(event.time.slice(0, 2), 10) * 60 + parseInt(event.time.slice(3, 5), 10);
            let top = (minutes / 60) * WEEK_SLOT_HEIGHT;
            if (top < lastTop + 22) top = lastTop + 22;
            lastTop = top;
            chip.classList.add('cal-week-event');
            chip.style.top = `${top}px`;
            column.appendChild(chip);
        });

        body.appendChild(column);
    });
    week.appendChild(body);

    container.appendChild(week);
}

async function moveEventTo(payload, dateString, newTime = '') {
    if (payload.timed === false) newTime = '';
    if (payload.date === dateString && (newTime === '' || payload.time === newTime)) return;

    const eventIndex = eventsData.findIndex(event => event.id === payload.id && event.table === payload.table);
    const originalDate = payload.date;
    const originalTime = payload.time || '';

    if (eventIndex !== -1) {
        eventsData[eventIndex].date = dateString;
        if (newTime !== '') {
            eventsData[eventIndex].time = newTime;
        }
        renderCalendar();
    }

    const requestBody = {
        api: 'calendar',
        action: 'move_event',
        id: payload.id,
        table: payload.table,
        newDate: dateString
    };
    if (newTime !== '') requestBody.newTime = newTime;

    try {
        const result = await apiFetch('api.php', {
            method: 'POST',
            body: requestBody
        });

        const data = await result.json();

        if (!result.ok || data.error) {
            if (eventIndex !== -1) {
                eventsData[eventIndex].date = originalDate;
                eventsData[eventIndex].time = originalTime;
                renderCalendar();
            }
            console.error('Failed to move event:', data.error ?? result.status);
        }
    } catch (error) {
        if (eventIndex !== -1) {
            eventsData[eventIndex].date = originalDate;
            eventsData[eventIndex].time = originalTime;
            renderCalendar();
        }
        console.error('Network error during event move:', error);
    }
}

async function deleteEvent(event) {
    hideRecordTooltip();
    if (!window.confirm(t('calendar.delete_confirm'))) return;

    const eventIndex = eventsData.findIndex(item => item.id === event.id && item.table === event.table);
    if (eventIndex === -1) return;
    const removed = eventsData[eventIndex];

    eventsData.splice(eventIndex, 1);
    renderCalendar();

    try {
        const result = await apiFetch('api.php', {
            method: 'DELETE',
            body: { table: event.table, id: event.id }
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
        confirmButton = document.createElement('button');
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

    function onKeydown(event) {
        if (event.key === 'Escape') close();
    }

    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) close();
    });
    closeButton.addEventListener('click', close);
    cancelButton.addEventListener('click', close);
    document.addEventListener('keydown', onKeydown);

    if (confirmButton && select) {
        confirmButton.addEventListener('click', () => {
            const table = select.value;
            const source = sources.find(sourceEntry => sourceEntry.table === table);
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