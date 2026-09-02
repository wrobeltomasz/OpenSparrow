// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

function resolveOption(input) {
    const list = document.getElementById(input.getAttribute('list'));
    if (!list) {
        return null;
    }
    return Array.from(list.options).find(option => option.value === input.value) ?? null;
}

function syncHidden(input) {
    const hidden = input.closest('form')?.querySelector(
        `input[type="hidden"][name="${input.dataset.fkName}"]`
    );
    if (!hidden) {
        return;
    }
    const option = resolveOption(input);
    if (input.value === '') {
        hidden.value = '';
    } else if (option) {
        hidden.value = option.dataset.id ?? '';
    }
}

function initFkSearch(input) {
    let originalLabel = input.value;

    input.addEventListener('focus', () => {
        originalLabel = input.value;
        setTimeout(() => input.select(), 0);
    });

    input.addEventListener('input', () => {
        const option = resolveOption(input);
        if (option) {
            syncHidden(input);
        }
    });

    input.addEventListener('blur', () => {
        const option = resolveOption(input);
        if (input.value === '') {
            syncHidden(input);
        } else if (!option) {
            input.value = originalLabel;
            syncHidden(input);
        } else {
            syncHidden(input);
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input.fk-search').forEach(initFkSearch);
});
