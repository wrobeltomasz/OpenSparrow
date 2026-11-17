// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const THEME_KEY = 'sparrow_theme';
const THEME_MODES = ['light', 'dark', 'system'];

function systemPrefersDark() {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function getThemeMode() {
    const stored = localStorage.getItem(THEME_KEY);
    return THEME_MODES.includes(stored) ? stored : 'system';
}

export function resolveTheme(mode) {
    return mode === 'system' ? (systemPrefersDark() ? 'dark' : 'light') : mode;
}

export function applyTheme(mode) {
    localStorage.setItem(THEME_KEY, mode);
    document.documentElement.dataset.theme = resolveTheme(mode);
    document.documentElement.dataset.themeMode = mode;
}

export function nextThemeMode() {
    const index = THEME_MODES.indexOf(getThemeMode());
    return THEME_MODES[(index + 1) % THEME_MODES.length];
}

export function initTheme() {
    const mode = getThemeMode();
    document.documentElement.dataset.theme = resolveTheme(mode);
    document.documentElement.dataset.themeMode = mode;

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', event => {
        if (getThemeMode() === 'system') {
            document.documentElement.dataset.theme = event.matches ? 'dark' : 'light';
        }
    });
}
