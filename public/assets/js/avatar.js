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
    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    circle.setAttribute('cx', '16');
    circle.setAttribute('cy', '16');
    circle.setAttribute('r', '16');
    circle.setAttribute('fill', '#364B60');
    svg.appendChild(circle);
    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    text.setAttribute('x', '16');
    text.setAttribute('y', '21');
    text.setAttribute('text-anchor', 'middle');
    text.setAttribute('fill', '#fff');
    text.setAttribute('font-size', '14');
    text.setAttribute('font-family', 'Inter,sans-serif');
    text.setAttribute('font-weight', '600');
    text.textContent = initial;
    svg.appendChild(text);
    return svg;
}
