// calendar.js — Calendar view (loaded as ES module by calendar.php)
// Renders records of a table as events positioned by a date column; dragging an
// event reschedules it via api.php (api=calendar). CSRF from meta tag; i18n via /api.php?action=i18n_bundle.
// Filter bar: per-source visibility chips (window.CALENDAR_SOURCES) and enum value
// dropdowns built from the public schema; state persisted in localStorage.

import { getCsrfToken } from './util/csrf.js';

// ── i18n bridge (calendar is a non-module script) ────────────────────────────
let _i18nBundle = {};
async function fetchI18n() {
    try {
        const res = await fetch('/api.php?action=i18n_bundle', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) _i18nBundle = await res.json();
    } catch (_) {}
}
function t(key, vars = {}) {
    const v = _i18nBundle[key];
    if (!v) return key.split('.').pop();
    return String(v).replace(/\{(\w+)\}/g, (_, k) => k in vars ? String(vars[k]) : `{${k}}`);
}

// Store current date state
let currentMonth = new Date().getMonth();
let currentYear = new Date().getFullYear();
let eventsData = [];
let appSchema = null;

// ── Filters: source visibility (chips) + enum column values (dropdowns) ──────
const FILTER_STORAGE_KEY = 'sparrow_calendar_filters';
let hiddenTables = new Set();
// Keyed "table|column" -> selected enum value; absent key means "all"
let enumFilters = {};

function loadFilterState() {
    try {
        const saved = JSON.parse(localStorage.getItem(FILTER_STORAGE_KEY) || '{}');
        hiddenTables = new Set(Array.isArray(saved.hiddenTables) ? saved.hiddenTables : []);
        enumFilters = (saved.enumFilters && typeof saved.enumFilters === 'object') ? saved.enumFilters : {};
    } catch (_) {
        hiddenTables = new Set();
        enumFilters = {};
    }
}

function saveFilterState() {
    localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify({
        hiddenTables: [...hiddenTables],
        enumFilters
    }));
}

function calendarSources() {
    return Array.isArray(window.CALENDAR_SOURCES) ? window.CALENDAR_SOURCES : [];
}

function tableLabel(table) {
    return appSchema?.tables?.[table]?.display_name || table;
}

// Enum columns of a source table (type=enum with a non-empty options list)
function enumColumnsFor(table) {
    const cols = appSchema?.tables?.[table]?.columns || {};
    return Object.entries(cols)
        .filter(([, def]) => (def.type || '').toLowerCase() === 'enum'
            && Array.isArray(def.options) && def.options.length > 0)
        .map(([name, def]) => ({ name, def }));
}

// Events that pass both the source chips and the enum dropdowns
function visibleEvents() {
    return eventsData.filter(ev => {
        if (hiddenTables.has(ev.table)) return false;
        return enumColumnsFor(ev.table).every(({ name }) => {
            const selected = enumFilters[`${ev.table}|${name}`] ?? '';
            return selected === '' || String(ev.rowData?.[name] ?? '') === selected;
        });
    });
}

function buildSourceChip(src) {
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'calendar-filter-chip' + (hiddenTables.has(src.table) ? ' off' : '');

    const dot = document.createElement('span');
    dot.className = 'calendar-filter-dot';
    dot.style.backgroundColor = src.color;
    chip.appendChild(dot);
    chip.appendChild(document.createTextNode(tableLabel(src.table)));

    chip.addEventListener('click', () => {
        if (hiddenTables.has(src.table)) {
            hiddenTables.delete(src.table);
        } else {
            hiddenTables.add(src.table);
        }
        saveFilterState();
        renderFilterBar();
        renderCalendar();
    });
    return chip;
}

function buildEnumSelect(table, name, def) {
    const key = `${table}|${name}`;
    const wrap = document.createElement('label');
    wrap.className = 'calendar-filter-enum';

    const caption = document.createElement('span');
    caption.textContent = `${tableLabel(table)}: ${def.display_name || name}`;
    wrap.appendChild(caption);

    const select = document.createElement('select');
    const allOpt = document.createElement('option');
    allOpt.value = '';
    allOpt.textContent = t('calendar.filter_all');
    select.appendChild(allOpt);
    def.options.forEach(val => {
        const opt = document.createElement('option');
        opt.value = String(val);
        opt.textContent = String(val);
        select.appendChild(opt);
    });
    select.value = enumFilters[key] ?? '';

    select.addEventListener('change', () => {
        if (select.value === '') {
            delete enumFilters[key];
        } else {
            enumFilters[key] = select.value;
        }
        saveFilterState();
        renderCalendar();
    });
    wrap.appendChild(select);
    return wrap;
}

function renderFilterBar() {
    const bar = document.getElementById('calendarFilters');
    if (!bar) return;
    bar.innerHTML = '';

    const sources = calendarSources();
    sources.forEach(src => bar.appendChild(buildSourceChip(src)));

    // Enum dropdowns only for sources that are currently visible
    sources
        .filter(src => !hiddenTables.has(src.table))
        .forEach(src => {
            enumColumnsFor(src.table).forEach(({ name, def }) => {
                bar.appendChild(buildEnumSelect(src.table, name, def));
            });
        });
}

// Init calendar when DOM is ready
document.addEventListener('DOMContentLoaded', async () => {
    await fetchI18n();
    await fetchSchema();
    await fetchEvents(currentYear, currentMonth + 1);
    loadFilterState();
    renderFilterBar();
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

// Fetch secure schema definition from backend API
async function fetchSchema() {
    try {
        const res = await fetch('api/schema.php', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) {
            appSchema = await res.json();
            window.schema = appSchema; 
        } else {
            console.error('Failed to load secure schema');
        }
    } catch (err) {
        console.error('Failed to fetch schema in calendar', err);
    }
}

// Fetch calendar events for the given year/month (1-indexed) via API.
async function fetchEvents(year, month) {
    try {
        const res = await fetch(`api.php?api=calendar&year=${year}&month=${month}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) {
            const data = await res.json();
            eventsData = data.events || [];
        }
    } catch (err) {
        console.error('Failed to load calendar events', err);
    }
}

// Render the main calendar grid
function renderCalendar() {
    const container = document.getElementById('calendarContainer');
    const title = document.getElementById('calendarTitle');
    const monthEvents = visibleEvents();
    
    // Clear container safely
    container.innerHTML = '';

    // Initialize floating tooltip container
    let tooltip = document.getElementById('calendar-event-tooltip');
    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.id = 'calendar-event-tooltip';
        tooltip.style.cssText = 'position: absolute; display: none; background: #fff; border: 1px solid #ddd; padding: 12px; border-radius: 6px; box-shadow: 0 5px 15px rgba(0,0,0,0.2); font-size: 13px; z-index: 10000; pointer-events: none; min-width: 220px; color: #333;';
        document.body.appendChild(tooltip);
    }
    
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
        
        const dateNum = document.createElement('div');
        dateNum.className = 'calendar-date-num';
        dateNum.textContent = i;
        cell.appendChild(dateNum);
        
        const todayDate = new Date();
        if (i === todayDate.getDate() && 
            currentMonth === todayDate.getMonth() && 
            currentYear === todayDate.getFullYear()) {
            cell.classList.add('today');
        }

        const dateString = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
        
        // Handle dragover required for dropping
        cell.addEventListener('dragover', (e) => {
            e.preventDefault(); 
            e.dataTransfer.dropEffect = 'move';
            cell.style.outline = '2px solid #6366f1'; 
        });

        // Handle dragleave
        cell.addEventListener('dragleave', () => {
            cell.style.outline = '';
        });

        // Handle dropping the event into a new date cell
        cell.addEventListener('drop', async (e) => {
            e.preventDefault();
            cell.style.outline = '';

            let payload;
            try {
                payload = JSON.parse(e.dataTransfer.getData('application/json'));
            } catch {
                return;
            }

            // Do nothing if dropped on the exact same date
            if (payload.date === dateString) return;

            // Optimistic UI update immediately reflects changes
            const eventIndex = eventsData.findIndex(ev => ev.id === payload.id && ev.table === payload.table);
            const originalDate = payload.date;
            
            if (eventIndex !== -1) {
                eventsData[eventIndex].date = dateString;
                renderCalendar(); 
            }

            // Dispatch fetch to update backend
            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({
                        api: 'calendar',
                        action: 'move_event',
                        id: payload.id,
                        table: payload.table,
                        newDate: dateString
                    })
                });

                const data = await res.json();

                if (!res.ok || data.error) {
                    // Rollback optimistic update on error
                    if (eventIndex !== -1) {
                        eventsData[eventIndex].date = originalDate;
                        renderCalendar();
                    }
                    console.error('Failed to move event:', data.error ?? res.status);
                }
            } catch (err) {
                // Rollback optimistic update on network failure
                if (eventIndex !== -1) {
                    eventsData[eventIndex].date = originalDate;
                    renderCalendar();
                }
                console.error('Network error during event move:', err);
            }
        });

        const dayEvents = monthEvents.filter(e => e.date === dateString);
        dayEvents.forEach(ev => {
            const evEl = document.createElement('div');
            evEl.className = 'calendar-event';
            evEl.style.backgroundColor = ev.color;
            
            // Allow element to be dragged
            evEl.draggable = true;

            // Prepare payload and styles when drag starts
            evEl.addEventListener('dragstart', (e) => {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('application/json', JSON.stringify({
                    id: ev.id,
                    table: ev.table,
                    date: ev.date 
                }));
                evEl.style.opacity = '0.4'; 
            });

            // Clean up styles when drag ends
            evEl.addEventListener('dragend', () => {
                evEl.style.opacity = '';
            });

            // Build icon safely
            if (ev.icon) {
                if (ev.icon.includes('/') || ev.icon.includes('.')) {
                    const img = document.createElement('img');
                    img.src = ev.icon;
                    img.style.cssText = 'width:14px; height:14px; vertical-align:middle; margin-right:4px;';
                    evEl.appendChild(img);
                } else {
                    const iconSpan = document.createElement('span');
                    iconSpan.style.marginRight = '4px';
                    iconSpan.textContent = ev.icon;
                    evEl.appendChild(iconSpan);
                }
            }
            
            // Append title safely
            const titleText = document.createTextNode(ev.title);
            evEl.appendChild(titleText);
            
            // Securely encode URL parameters
            evEl.addEventListener('click', () => {
                window.location.href = `edit.php?table=${encodeURIComponent(ev.table)}&id=${encodeURIComponent(ev.id)}`;
            });

            // Handle tooltip hover event safely
            evEl.addEventListener('mouseenter', (e) => {
                tooltip.innerHTML = '';
                
                const headerDiv = document.createElement('div');
                headerDiv.style.cssText = 'font-weight: bold; font-size: 14px; margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 5px;';
                headerDiv.textContent = ev.title;
                tooltip.appendChild(headerDiv);
                
                if (ev.rowData) {
                    for (const [key, val] of Object.entries(ev.rowData)) {
                        // Skip raw IDs and foreign key base names
                        if (key.endsWith('__display')) continue;
                        if (key === 'id') continue; 

                        let displayVal = ev.rowData[key + '__display'] ?? val;
                        
                        if (displayVal !== null && displayVal !== '') {
                            let label = key;
                            
                            // Get friendly name from schema
                            if (appSchema && appSchema.tables[ev.table]?.columns?.[key]) {
                                label = appSchema.tables[ev.table].columns[key].display_name || key;
                            }

                            // Build tooltip row safely
                            const rowDiv = document.createElement('div');
                            rowDiv.style.marginBottom = '4px';

                            const strong = document.createElement('strong');
                            strong.style.color = '#555';
                            strong.textContent = `${label}: `;

                            const colDef = appSchema?.tables[ev.table]?.columns?.[key] || {};
                            const enumColor = (colDef.type || '').toLowerCase() === 'enum'
                                ? (colDef.enum_colors?.[String(displayVal)] ?? null)
                                : null;

                            if (enumColor) {
                                const swatch = document.createElement('span');
                                swatch.style.cssText = `display:inline-block;width:10px;height:10px;border-radius:2px;background:${enumColor};margin-right:4px;vertical-align:middle;`;
                                rowDiv.appendChild(strong);
                                rowDiv.appendChild(swatch);
                            } else {
                                rowDiv.appendChild(strong);
                            }

                            const spanVal = document.createElement('span');
                            spanVal.style.color = '#111';
                            spanVal.textContent = displayVal;

                            rowDiv.appendChild(spanVal);
                            tooltip.appendChild(rowDiv);
                        }
                    }
                }
                
                tooltip.style.display = 'block';

                const rect = evEl.getBoundingClientRect();
                
                // Position tooltip below or above the element dynamically
                let topPos = rect.bottom + window.scrollY + 5;
                if (topPos + tooltip.offsetHeight > window.innerHeight + window.scrollY) {
                    topPos = rect.top + window.scrollY - tooltip.offsetHeight - 5;
                }
                
                tooltip.style.left = (rect.left + window.scrollX) + 'px';
                tooltip.style.top = topPos + 'px';
            });

            // Hide tooltip on mouseleave
            evEl.addEventListener('mouseleave', () => {
                tooltip.style.display = 'none';
            });

            cell.appendChild(evEl);
        });

        container.appendChild(cell);
    }
}