// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export function createHoverPopup({ className, width, verticalThreshold, hideDelay = 150 }) {
    const el = document.createElement('div');
    el.className = className;
    el.hidden = true;
    document.body.appendChild(el);

    let hideTimer = null;
    el.addEventListener('mouseenter', () => clearTimeout(hideTimer));
    el.addEventListener('mouseleave', () => { el.hidden = true; });

    function position(anchor) {
        const rect = anchor.getBoundingClientRect();
        const left = Math.min(Math.max(8, rect.left), window.innerWidth - width);
        el.style.left = `${left}px`;
        if (window.innerHeight - rect.bottom >= verticalThreshold || rect.top < verticalThreshold) {
            el.style.top = `${rect.bottom + 6}px`;
            el.style.bottom = '';
        } else {
            el.style.top = '';
            el.style.bottom = `${window.innerHeight - rect.top + 6}px`;
        }
    }

    function show(anchor) {
        clearTimeout(hideTimer);
        position(anchor);
        el.hidden = false;
    }

    function scheduleHide() {
        hideTimer = setTimeout(() => { el.hidden = true; }, hideDelay);
    }

    return { el, show, scheduleHide };
}
