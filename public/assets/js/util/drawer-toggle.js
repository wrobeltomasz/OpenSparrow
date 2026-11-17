// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from '../i18n.js';

export function createDrawerToggle(panelElement) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'drawer-toggle';

    const icon = document.createElement('img');
    icon.alt = '';
    button.appendChild(icon);

    function render() {
        const wide = panelElement.classList.contains('drawer--wide');
        icon.src = wide
            ? 'assets/icons/material/close_fullscreen.svg'
            : 'assets/icons/material/open_in_full.svg';
        button.title = wide ? I18n.t('common.collapse') : I18n.t('common.expand');
        button.setAttribute('aria-label', button.title);
        button.setAttribute('aria-pressed', wide ? 'true' : 'false');
    }

    button.addEventListener('click', () => {
        panelElement.classList.toggle('drawer--wide');
        render();
    });

    render();
    return button;
}
