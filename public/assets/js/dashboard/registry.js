// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const renderers = new Map();

export const WidgetRegistry = {
    register(type, fn) {
        renderers.set(type, fn);
    },

    render(widget) {
        const fn = renderers.get(widget.type);
        if (!fn) {
            const err = document.createElement('p');
            err.textContent = window.I18n.t('dashboard.unknown_widget_type', { type: widget.type });
            return err;
        }
        return fn(widget);
    },

    has(type) {
        return renderers.has(type);
    },
};
