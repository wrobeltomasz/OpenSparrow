// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

function initNavButtons() {
    document.querySelectorAll('[data-nav]').forEach(button => {
        button.addEventListener('click', () => { window.location.href = button.dataset.nav; });
    });
}

function initEnumColors() {
    document.querySelectorAll('select[data-enum-colors]').forEach(selectElement => {
        let colors = {};
        try {
            colors = JSON.parse(selectElement.dataset.enumColors || '{}');
        } catch (error) {
            return;
        }
        const apply = () => { selectElement.style.background = colors[selectElement.value] || ''; };
        selectElement.addEventListener('change', apply);
        apply();
    });
}

function initPatternValidation() {
    document.querySelectorAll('input[data-pattern]').forEach(input => {
        const validate = () => {
            if (!input.value) { input.setCustomValidity(''); return; }
            try {
                const regex = new RegExp(input.dataset.pattern);
                input.setCustomValidity(regex.test(input.value) ? '' : (input.dataset.message || 'Invalid format'));
            } catch (error) {
                console.error('Invalid RegExp in schema:', input.dataset.pattern, error);
            }
        };
        input.addEventListener('input', validate);
        validate();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initNavButtons();
    initEnumColors();
    initPatternValidation();
});
