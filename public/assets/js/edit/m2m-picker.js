// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const CHIP_LIMIT = 3;

function renderSummary(picker) {
    const summary = picker.querySelector('.m2m-summary');
    if (!summary) {
        return;
    }

    const labels = [...picker.querySelectorAll('.m2m-option input:checked')]
        .map(cb => cb.closest('.m2m-option').querySelector('.m2m-option-label').textContent);

    summary.textContent = '';

    if (labels.length === 0) {
        const empty = document.createElement('span');
        empty.className   = 'm2m-summary-empty';
        empty.textContent = picker.dataset.noneText || '';
        summary.appendChild(empty);
        return;
    }

    const overflow = labels.length > CHIP_LIMIT + 1 ? labels.length - CHIP_LIMIT : 0;
    const shown    = overflow ? labels.slice(0, CHIP_LIMIT) : labels;

    shown.forEach(label => {
        const chip = document.createElement('span');
        chip.className   = 'm2m-chip';
        chip.textContent = label;
        summary.appendChild(chip);
    });

    if (overflow) {
        const more = document.createElement('span');
        more.className   = 'm2m-chip m2m-chip-more';
        more.textContent = `+${overflow}`;
        summary.appendChild(more);
    }
}

function applyFilter(picker, needle) {
    const term = needle.trim().toLowerCase();
    let visible = 0;

    picker.querySelectorAll('.m2m-option').forEach(opt => {
        const label = opt.querySelector('.m2m-option-label').textContent.toLowerCase();
        const match = term === '' || label.includes(term);
        opt.hidden  = !match;
        if (match) {
            visible++;
        }
    });

    const empty = picker.querySelector('.m2m-no-matches');
    if (empty) {
        empty.hidden = visible > 0;
    }
}

function setVisible(picker, checked) {
    picker.querySelectorAll('.m2m-option:not([hidden]) input:not([disabled])').forEach(cb => {
        cb.checked = checked;
    });
    renderSummary(picker);
}

function initPicker(picker) {
    picker.addEventListener('change', e => {
        if (e.target.matches('.m2m-option input')) {
            renderSummary(picker);
        }
    });

    const search = picker.querySelector('.m2m-search');
    if (search) {
        search.addEventListener('input', () => applyFilter(picker, search.value));

        search.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
        picker.addEventListener('toggle', () => {
            if (picker.open) {
                search.focus();
            } else {
                search.value = '';
                applyFilter(picker, '');
            }
        });
    }

    picker.querySelector('[data-m2m-all]')?.addEventListener('click', () => setVisible(picker, true));
    picker.querySelector('[data-m2m-none]')?.addEventListener('click', () => setVisible(picker, false));

    picker.addEventListener('keydown', e => {
        if (e.key === 'Escape' && picker.open) {
            picker.open = false;
            picker.querySelector('.m2m-toggle')?.focus();
        }
    });

    renderSummary(picker);
}

document.addEventListener('DOMContentLoaded', () => {
    const pickers = [...document.querySelectorAll('.m2m-picker')];
    if (pickers.length === 0) {
        return;
    }

    pickers.forEach(initPicker);

    document.addEventListener('click', e => {
        pickers.forEach(picker => {
            if (picker.open && !picker.contains(e.target)) {
                picker.open = false;
            }
        });
    });
});
