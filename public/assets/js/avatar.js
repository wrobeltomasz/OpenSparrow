// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// avatar.js — renderAvatar(avatarId, username, size): returns an <img> (assets/img/avatar-N.png, 1..24) or an SVG with the username initial as fallback. Shared by comments, owners, user-menu, grid comment previews.

/**
 * Renders a user avatar element.
 * @param {number|null} avatarId  - 1..24 or null for initial fallback
 * @param {string}      username  - used for the initial letter when avatarId is null
 * @param {number}      [size=32] - width/height in px (applied via inline style)
 * @returns {HTMLElement}
 */
export function renderAvatar(avatarId, username, size = 32) {
    if (avatarId) {
        const img = document.createElement('img');
        img.className = 'avatar avatar-border';
        img.src = `assets/img/avatar-${parseInt(avatarId, 10)}.png`;
        img.alt = `Avatar ${avatarId}`;
        if (size !== 32) {
            img.style.width  = `${size}px`;
            img.style.height = `${size}px`;
        }
        return img;
    }

    const initial = ((username ?? '?')[0] ?? '?').toUpperCase();
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'avatar avatar-border avatar-initial');
    svg.setAttribute('viewBox', '0 0 32 32');
    svg.setAttribute('aria-hidden', 'true');
    if (size !== 32) {
        svg.style.width  = `${size}px`;
        svg.style.height = `${size}px`;
    }
    svg.innerHTML =
        `<circle cx="16" cy="16" r="16" fill="#364B60"/>` +
        `<text x="16" y="21" text-anchor="middle" fill="#fff" ` +
        `font-size="14" font-family="Inter,sans-serif" font-weight="600">${initial}</text>`;
    return svg;
}
