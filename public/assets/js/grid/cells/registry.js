// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const renderers = new Map();

export const CellRenderer = {
    register(type, fn) {
        renderers.set(type, fn);
    },

    render(type, ctx) {
        const fn = renderers.get(type);
        if (!fn) throw new Error(`No cell renderer for type: "${type}"`);
        return fn(ctx);
    },

    has(type) {
        return renderers.has(type);
    },
};
