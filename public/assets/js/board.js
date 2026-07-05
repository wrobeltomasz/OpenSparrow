// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// board.js — Board (Kanban) view (non-module classic script)
// Visualises records of a single table as cards laid out in lanes, one lane per
// value of the configured status column. Dragging a card to another lane updates
// that record's status via api.php (api=board). CSRF from meta tag; i18n via /api.php?action=i18n_bundle.

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

// ── i18n bridge ──────────────────────────────────────────────────────────────
let _i18nBundle = {};
async function fetchI18n() {
    try {
        const res = await fetch('/api.php?action=i18n_bundle', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) _i18nBundle = await res.json();
    } catch (_) { /* fall back to key tail */ }
}
function t(key, vars = {}) {
    const v = _i18nBundle[key];
    if (!v) return key.split('.').pop();
    return String(v).replace(/\{(\w+)\}/g, (_, k) => (k in vars ? String(vars[k]) : `{${k}}`));
}

const UNMATCHED = '__unmatched__';

let board = null;          // full payload from the API
let cards = [];            // working copy of cards (status mutated optimistically)
let canEdit = false;

document.addEventListener('DOMContentLoaded', async () => {
    canEdit = !!(window.USER_CAPS && window.USER_CAPS.canEdit);
    await fetchI18n();
    await fetchBoard();
    render();
});

async function fetchBoard() {
    try {
        const res = await fetch('api.php?api=board', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) {
            board = await res.json();
            cards = Array.isArray(board.cards) ? board.cards.map(c => ({ ...c })) : [];
        }
    } catch (err) {
        console.error('Failed to load board', err);
    }
}

function render() {
    const container = document.getElementById('boardContainer');
    const titleEl = document.getElementById('boardTitle');
    const metaEl = document.getElementById('boardMeta');
    if (!container) return;
    container.innerHTML = '';
    metaEl.textContent = '';

    if (!board) {
        renderNotice(container, t('board.load_error'));
        return;
    }

    titleEl.textContent = board.menu_name || t('board.title');

    if (!board.configured) {
        renderNotice(container, t('board.not_configured'));
        return;
    }

    // Subtitle: which table is shown and which column drives the lanes.
    if (board.table_label) {
        const lane = document.createElement('span');
        lane.textContent = board.table_label;
        metaEl.appendChild(lane);
        if (board.status_label) {
            const by = document.createElement('span');
            by.className = 'board-meta-by';
            by.textContent = t('board.grouped_by', { column: board.status_label });
            metaEl.appendChild(by);
        }
    }

    // Group cards by status value for quick lane population.
    const byStatus = {};
    const laneValues = new Set((board.columns || []).map(l => l.value));
    cards.forEach(card => {
        const key = laneValues.has(card.status) ? card.status : UNMATCHED;
        (byStatus[key] = byStatus[key] || []).push(card);
    });

    (board.columns || []).forEach(lane => {
        container.appendChild(buildLane(lane.value, lane.label, lane.color, byStatus[lane.value] || [], true));
    });

    // Records whose status matches no configured lane still need to be visible.
    if ((byStatus[UNMATCHED] || []).length > 0) {
        container.appendChild(buildLane(UNMATCHED, t('board.uncategorized'), '#94a3b8', byStatus[UNMATCHED], false));
    }

    // A configured board can still produce zero lanes (status column with no
    // lane values and no records) — never leave the container blank.
    if (container.children.length === 0) {
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

    // ── Lane header ──────────────────────────────────────────────────────────
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

    // ── Lane body (drop target) ────────────────────────────────────────────
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
    const el = document.createElement('article');
    el.className = 'board-card';
    el.style.borderLeftColor = laneColor;
    el.dataset.id = card.id;

    if (canEdit) {
        el.draggable = true;
        el.addEventListener('dragstart', (e) => {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('application/json', JSON.stringify({ id: card.id, status: card.status }));
            el.classList.add('dragging');
        });
        el.addEventListener('dragend', () => el.classList.remove('dragging'));
    }

    const title = document.createElement('div');
    title.className = 'board-card-title';
    title.textContent = card.title;
    el.appendChild(title);

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
        el.appendChild(fields);
    }

    const idTag = document.createElement('span');
    idTag.className = 'board-card-id';
    idTag.textContent = '#' + card.id;
    el.appendChild(idTag);

    // Open the record in the standard edit form.
    el.addEventListener('click', () => {
        window.location.href = `edit.php?table=${encodeURIComponent(board.table)}&id=${encodeURIComponent(card.id)}`;
    });

    return el;
}

// Optimistically move the card to the new lane, then persist. On any failure the
// status is reverted and the board re-rendered so the UI matches the database.
async function moveCard(id, newStatus, oldStatus) {
    const card = cards.find(c => String(c.id) === String(id));
    if (!card) return;

    card.status = newStatus;
    render();

    try {
        const res = await fetch('api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify({
                api: 'board',
                action: 'move_card',
                table: board.table,
                id,
                newStatus
            })
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.error) {
            card.status = oldStatus;
            render();
            console.error('Failed to move card:', data.error ?? res.status);
        }
    } catch (err) {
        card.status = oldStatus;
        render();
        console.error('Network error during card move:', err);
    }
}
