// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const DRAG_SLOP_PX = 12;

const dropTargets = [];
let activeDrag = null;

function targetAtPoint(x, y) {
    for (let i = dropTargets.length - 1; i >= 0; i--) {
        const entry = dropTargets[i];
        if (!entry.element.isConnected) continue;
        const bounds = entry.element.getBoundingClientRect();
        if (x >= bounds.left && x <= bounds.right && y >= bounds.top && y <= bounds.bottom) {
            return entry;
        }
    }
    return null;
}

function clearDropHighlights() {
    dropTargets.forEach(entry => {
        if (entry.element.isConnected) entry.element.classList.remove('drop-target');
    });
}

function pruneDetachedTargets() {
    for (let i = dropTargets.length - 1; i >= 0; i--) {
        if (!dropTargets[i].element.isConnected) dropTargets.splice(i, 1);
    }
}

function buildGhost(sourceElement, x, y) {
    const ghost = sourceElement.cloneNode(true);
    ghost.classList.add('touch-ghost');
    ghost.style.width = sourceElement.offsetWidth + 'px';
    positionGhost(ghost, x, y);
    document.body.appendChild(ghost);
    return ghost;
}

function positionGhost(ghost, x, y) {
    ghost.style.left = x + 'px';
    ghost.style.top = y + 'px';
}

function cleanupDragVisuals() {
    if (activeDrag.ghost && activeDrag.ghost.parentNode) activeDrag.ghost.remove();
    activeDrag.sourceElement.classList.remove('dragging');
    clearDropHighlights();
}

function finishDrag(cancelled) {
    if (!activeDrag) return;
    const drag = activeDrag;

    document.removeEventListener('pointermove', drag.onMove);
    document.removeEventListener('pointerup', drag.onUp);
    document.removeEventListener('pointercancel', drag.onCancel);

    cleanupDragVisuals();
    activeDrag = null;

    if (!cancelled && drag.highlightedTarget && drag.highlightedTarget.onDrop) {
        drag.highlightedTarget.onDrop(drag.payload, drag.highlightedTarget.element);
    }
}

function passesSlop(drag, event) {
    const dx = event.clientX - drag.startX;
    const dy = event.clientY - drag.startY;
    if (drag.direction === 'horizontal') {
        return Math.abs(dx) > DRAG_SLOP_PX && Math.abs(dx) > Math.abs(dy);
    }
    return Math.hypot(dx, dy) > DRAG_SLOP_PX;
}

function handleMove(drag, event) {
    if (activeDrag !== drag) return;
    const x = event.clientX;
    const y = event.clientY;

    if (!drag.started) {
        if (!passesSlop(drag, event)) return;
        drag.started = true;
        drag.ghost = buildGhost(drag.sourceElement, x, y);
        drag.sourceElement.classList.add('dragging');
    }

    positionGhost(drag.ghost, x, y);
    clearDropHighlights();
    const hit = targetAtPoint(x, y);
    if (hit) hit.element.classList.add('drop-target');
    drag.highlightedTarget = hit;
    event.preventDefault();
}

export function enablePointerDrag(element, options) {
    const direction = options.direction === 'horizontal' ? 'horizontal' : 'all';
    element.classList.add(direction === 'horizontal' ? 'touch-drag-h' : 'touch-drag-all');

    element.addEventListener('pointerdown', (event) => {
        if (event.pointerType !== 'touch' || activeDrag) return;
        if (event.button !== undefined && event.button !== 0) return;
        if (!element.isConnected) return;
        pruneDetachedTargets();

        const drag = {
            sourceElement: element,
            payload: options.payload,
            getPayload: options.getPayload,
            direction,
            startX: event.clientX,
            startY: event.clientY,
            started: false,
            ghost: null,
            highlightedTarget: null,
            onMove: null,
            onUp: null,
            onCancel: null
        };

        drag.onMove = (moveEvent) => {
            if (activeDrag === drag) handleMove(drag, moveEvent);
        };
        drag.onUp = () => {
            if (activeDrag === drag) finishDrag(false);
        };
        drag.onCancel = () => {
            if (activeDrag === drag) finishDrag(true);
        };

        activeDrag = drag;
        document.addEventListener('pointermove', drag.onMove);
        document.addEventListener('pointerup', drag.onUp);
        document.addEventListener('pointercancel', drag.onCancel);
    });
}

export function registerDropTarget(element, options) {
    const entry = {
        element,
        onDrop: options.onDrop
    };
    dropTargets.push(entry);
    return () => {
        const index = dropTargets.indexOf(entry);
        if (index !== -1) dropTargets.splice(index, 1);
    };
}