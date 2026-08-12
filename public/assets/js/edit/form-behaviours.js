// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// assets/js/edit/form-behaviours.js — behaviours shared by the record form pages
// (public/create.php and public/edit.php). All three used to be inline <script>
// blocks duplicated across both pages — in edit.php as plain HTML, in create.php
// as a PHP string with escaped apostrophes, so the two copies could not be diffed
// and only one of them was ever syntax-checkable.
//
//   [data-nav]                — cancel/navigate buttons; moved off inline onclick
//                               because a CSP nonce does not cover event-handler
//                               attributes.
//   select[data-enum-colors]  — paints the select with the colour configured for
//                               the selected enum value (schema config).
//   input[data-pattern]       — client mirror of the server-side validation_regexp
//                               check (validate_column_regexp() in api_helpers.php);
//                               data-message carries the schema's validation_message.
//
// Page-specific form logic (tabs, save-action toggle, delete, image gallery) stays
// inline in edit.php — it depends on server-rendered translations and window globals.

function initNavButtons() {
    document.querySelectorAll('[data-nav]').forEach(btn => {
        btn.addEventListener('click', () => { window.location.href = btn.dataset.nav; });
    });
}

function initEnumColors() {
    document.querySelectorAll('select[data-enum-colors]').forEach(sel => {
        let colors = {};
        try {
            colors = JSON.parse(sel.dataset.enumColors || '{}');
        } catch (e) {
            return;
        }
        const apply = () => { sel.style.background = colors[sel.value] || ''; };
        sel.addEventListener('change', apply);
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
            } catch (e) {
                console.error('Invalid RegExp in schema:', input.dataset.pattern, e);
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
