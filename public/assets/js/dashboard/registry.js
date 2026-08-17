// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const renderers = new Map();

export const WidgetRegistry = {
    register(type, handler) {
        renderers.set(type, handler);
    },

    render(widget) {
        const handler = renderers.get(widget.type);
        if (!handler) {
            const error = document.createElement('p');
            error.textContent = window.I18n.t('dashboard.unknown_widget_type', { type: widget.type });
            return error;
        }
        return handler(widget);
    },

    has(type) {
        return renderers.has(type);
    },
};
