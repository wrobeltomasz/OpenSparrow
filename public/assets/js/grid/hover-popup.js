// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export function createHoverPopup({ className, width, verticalThreshold, hideDelay = 150 }) {
    const element = document.createElement('div');
    element.className = className;
    element.hidden = true;
    document.body.appendChild(element);

    let hideTimer = null;
    element.addEventListener('mouseenter', () => clearTimeout(hideTimer));
    element.addEventListener('mouseleave', () => { element.hidden = true; });

    function position(anchor) {
        const rect = anchor.getBoundingClientRect();
        const left = Math.min(Math.max(8, rect.left), window.innerWidth - width);
        element.style.left = `${left}px`;
        if (window.innerHeight - rect.bottom >= verticalThreshold || rect.top < verticalThreshold) {
            element.style.top = `${rect.bottom + 6}px`;
            element.style.bottom = '';
        } else {
            element.style.top = '';
            element.style.bottom = `${window.innerHeight - rect.top + 6}px`;
        }
    }

    function show(anchor) {
        clearTimeout(hideTimer);
        position(anchor);
        element.hidden = false;
    }

    function scheduleHide() {
        hideTimer = setTimeout(() => { element.hidden = true; }, hideDelay);
    }

    return { el: element, show, scheduleHide };
}
