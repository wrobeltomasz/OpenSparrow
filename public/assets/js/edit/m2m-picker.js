// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// assets/js/edit/m2m-picker.js — progressive enhancement for the many-to-many
// picker rendered by os_m2m_group() (includes/page_helpers.php) on create.php
// and edit.php. The markup is a plain <details> with real checkboxes, so without
// this module the picker still expands, selects and submits; this only adds the
// live summary, the search filter, select all / clear and outside-click closing.

const CHIP_LIMIT = 3; // KEEP IN SYNC with OS_M2M_SUMMARY_CHIPS in page_helpers.php

// Chips for the collapsed summary: up to CHIP_LIMIT labels, then a "+N" chip.
// Mirrors os_m2m_summary() so the server-rendered and JS-rendered states match.
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

// Hides the options whose label does not contain the needle; shows the
// "no matches" line when the filter empties the list.
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

// Select all / clear act on the currently visible options only, so they combine
// with the search box ("filter to Acme, select all") instead of fighting it.
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
        // The picker lives inside the record form — Enter here would submit it.
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

    // Click outside any open picker closes it, like a native select dropdown.
    document.addEventListener('click', e => {
        pickers.forEach(picker => {
            if (picker.open && !picker.contains(e.target)) {
                picker.open = false;
            }
        });
    });
});
