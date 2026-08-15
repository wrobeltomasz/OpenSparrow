// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export function makeIconButton({ cy, title, icon, className = 'btn-icon', onClick }) {
    const btn = document.createElement('button');
    btn.className = className;
    btn.dataset.cy = cy;
    btn.title = title;
    const img = document.createElement('img');
    img.src = icon;
    img.alt = title;
    btn.appendChild(img);
    btn.addEventListener('click', onClick);
    return btn;
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
