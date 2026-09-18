// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

function parseHexColor(color) {
    if (typeof color !== 'string') return null;
    const hexColor = color.trim().replace(/^#/, '');
    if (/^[0-9a-f]{3}$/i.test(hexColor)) {
        return hexColor.split('').map(part => parseInt(part + part, 16));
    }
    if (/^[0-9a-f]{6}$/i.test(hexColor)) {
        return [0, 2, 4].map(offset => parseInt(hexColor.slice(offset, offset + 2), 16));
    }
    return null;
}

export function readableTextColor(backgroundColor) {
    const channels = parseHexColor(backgroundColor);
    if (channels === null) return '';
    const yiq = (channels[0] * 299 + channels[1] * 587 + channels[2] * 114) / 1000;
    return yiq > 128 ? '#333' : '#fff';
}