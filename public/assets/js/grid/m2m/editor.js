// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from '../../i18n.js';
import { showToast } from '../../toast.js';
import { apiFetch } from '../../util/api.js';
import { debugLog } from '../../debug.js';
import { state } from '../state.js';
import { setM2mItems, renderM2mChips } from './loader.js';
import { suppressM2mPopup, resumeM2mPopup } from './popup.js';

const SEARCH_THRESHOLD = 10;
const POPOVER_WIDTH = 280;

let popover = null;
let activeCell = null;
let suppressUntil = 0;

export function initM2mEditor() {
    if ((window.USER_ROLE || 'viewer') === 'viewer') return;

    popover = document.createElement('div');
    popover.className = 'm2m-editor';
    popover.hidden = true;
    document.body.appendChild(popover);

    document.addEventListener('click', event => {
        if (event.target.closest('.m2m-editor')) return;
        const td = event.target.closest('[data-m2m-row-id]');
        if (td) {
            openEditor(td);
        } else if (popover && !popover.hidden) {
            closeEditor();
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && popover && !popover.hidden) closeEditor();
    });

    window.addEventListener('resize', () => {
        if (popover && !popover.hidden && activeCell) positionPopover(activeCell);
    });
}

function closeEditor() {
    popover.hidden = true;
    popover.replaceChildren();
    activeCell = null;
    suppressUntil = Date.now() + 250;
    resumeM2mPopup();
}

function positionPopover(td) {
    const rect = td.getBoundingClientRect();
    const left = Math.min(Math.max(8, rect.left), Math.max(8, window.innerWidth - POPOVER_WIDTH - 8));
    popover.style.left = `${left}px`;
    popover.style.right = '';
    if (window.innerHeight - rect.bottom >= 300 || rect.top < 300) {
        popover.style.top = `${rect.bottom + 6}px`;
        popover.style.bottom = '';
    } else {
        popover.style.top = '';
        popover.style.bottom = `${window.innerHeight - rect.top + 6}px`;
    }
}

async function openEditor(td) {
    if (Date.now() < suppressUntil) return;
    if (popover.hidden === false && activeCell === td) {
        closeEditor();
        return;
    }

    const rowId = td.dataset.m2mRowId;
    const m2mIndex = parseInt(td.dataset.m2mIndex, 10);

    activeCell = td;
    suppressM2mPopup();
    popover.replaceChildren();
    renderLoading();
    positionPopover(td);
    popover.hidden = false;

    let payload = null;
    try {
        const url = `api.php?api=m2m_options&table=${encodeURIComponent(state.currentTable)}`
            + `&m2m_index=${m2mIndex}&row_id=${encodeURIComponent(rowId)}`;
        const result = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        payload = await result.json();
    } catch (error) {
        debugLog('m2m options load failed', error);
        closeEditor();
        showToast(I18n.t('common.error_generic'), 'error');
        return;
    }

    if (activeCell !== td) return;

    const options = payload?.options ?? [];
    const selected = new Set(payload?.selected ?? []);
    if (!options.length) {
        renderEmpty();
        positionPopover(td);
        return;
    }

    renderPicker(td, rowId, m2mIndex, options, selected);
    positionPopover(td);
    popover.querySelector('.m2m-editor-search')?.focus();
}

function renderLoading() {
    const loading = document.createElement('div');
    loading.className = 'm2m-editor-status';
    loading.textContent = I18n.t('common.loading');
    popover.appendChild(loading);
}

function renderEmpty() {
    popover.replaceChildren();
    const empty = document.createElement('div');
    empty.className = 'm2m-editor-status';
    empty.textContent = I18n.t('form.no_options');
    popover.appendChild(empty);
}

function renderPicker(td, rowId, m2mIndex, options, selected) {
    popover.replaceChildren();

    const title = document.createElement('div');
    title.className = 'm2m-editor-title';
    title.textContent = td.dataset.m2mLabel || 'Related';
    popover.appendChild(title);

    if (options.length > SEARCH_THRESHOLD) {
        const search = document.createElement('input');
        search.type = 'text';
        search.className = 'm2m-editor-search';
        search.placeholder = I18n.t('form.m2m_search');
        search.addEventListener('input', () => applyFilter(search));
        popover.appendChild(search);

        const links = document.createElement('div');
        links.className = 'm2m-editor-links';
        const allButton = document.createElement('button');
        allButton.type = 'button';
        allButton.className = 'm2m-editor-link';
        allButton.textContent = I18n.t('form.m2m_select_all');
        allButton.addEventListener('click', () => setAll(popover, true));
        const noneButton = document.createElement('button');
        noneButton.type = 'button';
        noneButton.className = 'm2m-editor-link';
        noneButton.textContent = I18n.t('form.m2m_clear');
        noneButton.addEventListener('click', () => setAll(popover, false));
        links.append(allButton, noneButton);
        popover.appendChild(links);
    }

    const optionsWrap = document.createElement('div');
    optionsWrap.className = 'm2m-editor-options';
    for (const option of options) {
        const label = document.createElement('label');
        label.className = 'm2m-editor-option';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.value = option.id;
        checkbox.checked = selected.has(String(option.id));
        const labelText = document.createElement('span');
        labelText.className = 'm2m-editor-option-label';
        labelText.textContent = option.label;
        label.append(checkbox, labelText);
        optionsWrap.appendChild(label);
    }
    const noMatches = document.createElement('div');
    noMatches.className = 'm2m-editor-status m2m-editor-no-matches';
    noMatches.hidden = true;
    noMatches.textContent = I18n.t('form.m2m_no_matches');
    optionsWrap.appendChild(noMatches);
    popover.appendChild(optionsWrap);

    const actions = document.createElement('div');
    actions.className = 'm2m-editor-actions';
    const cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'm2m-editor-btn';
    cancelButton.textContent = I18n.t('common.cancel');
    cancelButton.addEventListener('click', closeEditor);
    const saveButton = document.createElement('button');
    saveButton.type = 'button';
    saveButton.className = 'm2m-editor-btn m2m-editor-btn-primary';
    saveButton.textContent = I18n.t('common.save');
    saveButton.addEventListener('click', () => saveSelection(td, rowId, m2mIndex, saveButton));
    actions.append(saveButton, cancelButton);
    popover.appendChild(actions);
}

function applyFilter(search) {
    const needle = search.value.trim().toLowerCase();
    let anyMatch = false;
    popover.querySelectorAll('.m2m-editor-option').forEach(option => {
        const matches = option.querySelector('.m2m-editor-option-label').textContent.toLowerCase().includes(needle);
        option.hidden = !matches;
        if (matches) anyMatch = true;
    });
    const emptyNote = popover.querySelector('.m2m-editor-no-matches');
    if (emptyNote) emptyNote.hidden = anyMatch;
}

function setAll(container, checked) {
    container.querySelectorAll('.m2m-editor-option:not([hidden]) input').forEach(checkbox => {
        checkbox.checked = checked;
    });
}

async function saveSelection(td, rowId, m2mIndex, saveButton) {
    const ids = [...popover.querySelectorAll('.m2m-editor-option input:checked')]
        .map(checkbox => checkbox.value);

    saveButton.disabled = true;
    saveButton.textContent = I18n.t('common.loading');

    try {
        const result = await apiFetch('index.php?api=m2m_sync', {
            method: 'POST',
            body: {
                api: 'm2m_sync',
                table: state.currentTable,
                id: rowId,
                m2m_index: m2mIndex,
                ids,
            },
        });
        const payload = await result.json().catch(() => null);
        if (!result.ok || payload?.error) {
            showToast(payload?.error || I18n.t('common.error_generic'), 'error');
            saveButton.disabled = false;
            saveButton.textContent = I18n.t('common.save');
            return;
        }
        await refreshCell(td, rowId, m2mIndex);
        closeEditor();
        showToast(I18n.t('form.saved_ok'), 'success');
    } catch (error) {
        debugLog('m2m sync failed', error);
        showToast(I18n.t('common.error_generic'), 'error');
        saveButton.disabled = false;
        saveButton.textContent = I18n.t('common.save');
    }
}

async function refreshCell(td, rowId, m2mIndex) {
    try {
        const url = `api.php?api=m2m_rows&table=${encodeURIComponent(state.currentTable)}`
            + `&m2m_index=${m2mIndex}&ids=${encodeURIComponent(rowId)}`;
        const result = await fetch(url);
        const json = await result.json();
        const labels = json.data?.[rowId] ?? [];
        setM2mItems(rowId, m2mIndex, labels);
        renderM2mChips(td, labels);
    } catch (error) {
        debugLog('m2m refresh failed', error);
    }
}