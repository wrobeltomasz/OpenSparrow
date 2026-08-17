// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export function makeIconButton({ cy, title, icon, className = 'btn-icon', onClick }) {
    const button = document.createElement('button');
    button.className = className;
    button.dataset.cy = cy;
    button.title = title;
    const image = document.createElement('img');
    image.src = icon;
    image.alt = title;
    button.appendChild(image);
    button.addEventListener('click', onClick);
    return button;
}

export function makeInlineLink(href, text, { newTab = false, onClick } = {}) {
    const a = document.createElement('a');
    a.href = href;
    if (newTab) a.target = '_blank';
    a.className = 'cell-link';
    a.textContent = text;
    if (onClick) a.addEventListener('click', onClick);
    return a;
}
