// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export const AVATAR_COLORS = [
    '#364B60', '#1F6F8B', '#2E7D6B', '#3F7D3F', '#6B7D2E', '#8A6D1F',
    '#A65A2E', '#B04A4A', '#A33F6B', '#7A4FA3', '#4F55A3', '#2F6FA3',
    '#455A64', '#00695C', '#2E7D32', '#558B2F', '#9E7B0A', '#C05621',
    '#B23A48', '#8E3B6B', '#5E35B1', '#3949AB', '#0277BD', '#00838F',
];

export function avatarColor(avatarId) {
    const parsedAvatarId = parseInt(avatarId, 10);
    if (!Number.isInteger(parsedAvatarId) || parsedAvatarId < 1 || parsedAvatarId > AVATAR_COLORS.length) return AVATAR_COLORS[0];
    return AVATAR_COLORS[parsedAvatarId - 1];
}

export function renderAvatar(avatarId, username, size = 32) {
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
    circle.setAttribute('fill', avatarColor(avatarId));
    svg.appendChild(circle);

    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    text.setAttribute('x', '16');
    text.setAttribute('y', '21');
    text.setAttribute('text-anchor', 'middle');
    text.setAttribute('fill', '#fff');
    text.setAttribute('font-size', '14');
    text.setAttribute('font-family', 'system-ui,sans-serif');
    text.setAttribute('font-weight', '600');
    text.textContent = initial;
    svg.appendChild(text);

    return svg;
}
